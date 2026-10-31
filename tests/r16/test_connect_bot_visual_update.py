"""Worker-only atomic switch: actual files/flock/SQLite, modeled systemd/probe, no production traffic."""
import contextlib
import copy
import gzip
import importlib.util
import io
import json
import os
from pathlib import Path
import sqlite3
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
import connect_bot_visual_update as visual
import connect_con02_contract as c
import connect_con02_state as s
from connect_con02_runtime import stage_worker,worker_files


def archive(files,commit):
    files={**files,'SOURCE_COMMIT':(commit+'\n').encode()}
    files['manifest.json']=json.dumps({'schema':1,'source_commit':commit,'files':{n:c.sha(v) for n,v in files.items()}}).encode()
    raw=io.BytesIO()
    with tarfile.open(fileobj=raw,mode='w:gz') as tar:
        for name,value in files.items():
            entry=tarfile.TarInfo(name);entry.size=len(value);entry.mode=0o644
            tar.addfile(entry,io.BytesIO(value))
    return raw.getvalue()


class FakeHost:
    def __init__(self,root,paths):
        self.root,self.paths=root,paths;self.fail_once=False
        self.states={n:{'active':True,'enabled':True} for n in c.SERVICES}
        self.states[c.SERVICES[0]]={'active':False,'enabled':False}
        spec=importlib.util.spec_from_file_location('visual_fixture_install',ROOT/'connect-worker/deploy/install.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        self.installation=module.Installation(root,self)
    def control_paths(self):return self.paths
    def legacy(self):return SimpleNamespace(installation=self.installation)
    def validate_units(self,values):pass
    def https_snapshot(self):return {'verified':True,'owner':'fixture'}
    def service_active(self,n):return self.states[n]['active']
    def service_enabled(self,n):return self.states[n]['enabled']
    def service(self,action,n):
        if action in ('start','stop'):self.states[n]['active']=action=='start'
        elif action in ('enable','disable'):self.states[n]['enabled']=action=='enable'
    def probe(self,profile):
        if self.fail_once:self.fail_once=False;raise c.Refusal('synthetic_probe_failure')


class WorkerUpdate(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory();self.addCleanup(temporary.cleanup)
        self.root=Path(temporary.name)
        releases=self.root/'opt/ustar-connect/releases';releases.mkdir(parents=True)
        files={'receiver.pyz':b'old-receiver','notifications.pyz':b'unchanged-sender','admin.pyz':b'unchanged-admin'}
        files.update({'units/ustar-connect-'+n+'.service':('unit '+n).encode() for n in ('receiver','probe','notify')})
        self.before=SimpleNamespace(reference={'tool_commit':'9'*40,'app_commit':'5'*40,'worker_commit':'5'*40},worker_archive=archive(files,'5'*40))
        self.after=SimpleNamespace(reference={'worker_commit':'f'*40},worker_archive=archive({**files,'receiver.pyz':b'new-receiver'},'f'*40))
        config=self.root/'etc/ustar-connect/receiver.json';config.parent.mkdir(parents=True);config.write_bytes(b'private fixture');config.chmod(0o600)
        self.paths={'pointer':releases.parent/'current','receiver':config}
        for n in ('receiver','probe','notify'):
            (self.root/'etc/systemd/system'/('ustar-connect-'+n+'.service.d')).mkdir(parents=True)
        self.host=FakeHost(self.root,self.paths)
        self.ctx=c.Context(self.before,self.root,os.getuid(),self.host,SimpleNamespace(base=self.root/'role'))
        stage_worker(self.ctx);self.host.installation.switch(releases/('5'*40))
        self.old=s.Journal(self.ctx)
        self.old.begin('app',{'files':{},'controls':{}},{'files':{}},generation='a'*32);self.old.checkpoint('app','app_ready')
        images={n:s.capture(p) for n,p in self.paths.items()}
        self.old.begin('runtime',{'files':images,'https':self.host.https_snapshot()},{'files':images},generation='b'*32)
        self.old.checkpoint('runtime','runtime_active')
        self.oldraw=self.old.path.read_bytes()
        self.db=self.root/'receiver.sqlite3'
        with sqlite3.connect(self.db) as db:
            db.execute('CREATE TABLE journal (cursor INTEGER,receipt TEXT)');db.execute("INSERT INTO journal VALUES(88,'uncertain')")
        self.op=visual.VisualUpdate(self.ctx,self.after)
        self.op.verify_application=lambda saved:None  # App/SQL are unchanged; real predecessor verifier is used in owner tool.
        for path in self.op.locks.paths[1:]:path.parent.mkdir(parents=True,exist_ok=True);path.touch(mode=0o600)
        self.services=copy.deepcopy(self.host.states)

    def assert_preserved(self):
        self.assertEqual(self.oldraw,self.old.path.read_bytes())
        self.assertEqual(self.services,self.host.states)
        self.assertEqual(b'private fixture',self.paths['receiver'].read_bytes())
        with sqlite3.connect(self.db) as db:self.assertEqual([(88,'uncertain')],db.execute('SELECT * FROM journal').fetchall())

    def test_check_is_read_only_and_success_repeat_recovery_preserve_business_and_private_state(self):
        self.assertEqual('ready_for_visual_update',self.op.check()['phase']);self.assertFalse(self.op.base.exists())
        self.assertEqual('visuals_active',self.op.run()['phase'])
        self.assertTrue(self.op.run()['verify_only']);self.assertEqual('visuals_ready',self.op.check()['phase'])
        self.assert_preserved()
        self.assertEqual('rolled_back',self.op.run(recover=True)['phase'])
        self.assertEqual('5'*40,self.paths['pointer'].resolve().name);self.assert_preserved()

    def test_definitive_probe_failure_returns_old_worker_with_role_bot_still_enabled(self):
        self.host.fail_once=True
        result=self.op.run();self.assertEqual('rolled_back',result['phase']);self.assertFalse(result['update_succeeded'])
        self.assertEqual('5'*40,self.paths['pointer'].resolve().name);self.assert_preserved()

    def test_changed_private_config_and_unknown_dropin_are_refused_before_service_changes(self):
        self.paths['receiver'].write_bytes(b'changed')
        with self.assertRaises(c.Refusal):self.op.run()
        self.assertEqual(self.services,self.host.states)
        self.paths['receiver'].write_bytes(b'private fixture')
        path=self.root/'etc/systemd/system/ustar-connect-receiver.service.d/99-unknown.conf';path.write_bytes(b'unknown')
        with self.assertRaises(c.Refusal):self.op.check()
        self.assertEqual(self.services,self.host.states)

    def test_process_loss_at_each_mutation_boundary_can_recover_without_journal_rewind(self):
        for boundary in ('prepared','workers_stopped','pointer_switched','visuals_active'):
            with self.subTest(boundary=boundary):
                pid=os.fork()
                if pid==0:
                    original=self.op.save
                    def crash(state,phase):
                        result=original(state,phase)
                        if phase==boundary:os._exit(73)
                        return result
                    self.op.save=crash;self.op.run();os._exit(74)
                _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
                self.assertEqual('rolled_back',self.op.run(recover=True)['phase']);self.assert_preserved()

    def test_unrecognized_pointer_is_not_overwritten_by_recovery(self):
        self.op.run();self.paths['pointer'].unlink();self.paths['pointer'].symlink_to(self.root/'unknown')
        with self.assertRaises(c.Refusal):self.op.run(recover=True)
        self.assertEqual(str(self.root/'unknown'),os.readlink(self.paths['pointer']))


if __name__=='__main__':unittest.main()
