"""A bounded two-file tracking fix must preserve the frozen overlay and all controls."""
import importlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))

def module():
    if not (ROOT/'scripts/infra/scorm_tracking_patch.py').is_file():
        raise AssertionError('Missing bounded1100 SCORM tracking delivery')
    return importlib.import_module('scorm_tracking_patch')

class Host:
    def __init__(self):self.running=True;self.calls=[];self.fail=False
    def identity(self):return {'Id':'fixed-web','State':{'Running':self.running}}
    def lint(self,raw):self.calls.append('lint')
    def stop(self):self.calls.append('stop');self.running=False
    def start(self):
        self.calls.append('start')
        if self.fail:self.fail=False;raise RuntimeError('startup interrupted')
        self.running=True

class Contract(unittest.TestCase):
    def test_backend_start_retries_connection_failure_but_rejects_redirects(self):
        fix=module();m=fix.runtime();host=m.Host();host.container_id='fixed'
        remaining=[m.Refusal('host_command_failed'),b'200']
        def response(args,**kwargs):
            if args[:2]==['docker','start']:return b'fixed'
            self.assertEqual('http://127.0.0.1:8082/login/index.php',args[-1])
            self.assertNotIn('--location',args)
            value=remaining.pop(0) if remaining else b'302'
            if isinstance(value,Exception):raise value
            return value
        with patch.object(host,'run',side_effect=response),patch.object(fix.time,'sleep'):
            host.start()
            with self.assertRaisesRegex(m.Refusal,'moodle_backend_login_failed'):host.start()

    def test_default_is_offline_and_old_overlay_remains_unchanged(self):
        fix=module()
        with patch.object(fix,'runtime',side_effect=AssertionError('host source read')):
            with patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(0,fix.main([]));self.assertFalse(json.loads(out.getvalue())['host_access'])
        old=importlib.import_module('route_scorm_patch')
        before=(old.BASELINE,old.APPLICATION,old.FILES)
        fix.runtime()
        self.assertEqual(before,(old.BASELINE,old.APPLICATION,old.FILES))

    def test_exact_package_is_private_offline_and_repeatable(self):
        import subprocess,tarfile
        fix=module();builder=importlib.import_module('build_scorm_tracking_patch')
        commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
        with tempfile.TemporaryDirectory() as td:
            first=builder.build(commit,Path(td)/'first');second=builder.build(commit,Path(td)/'second')
            self.assertEqual(Path(first['archive']).read_bytes(),Path(second['archive']).read_bytes())
            with tarfile.open(first['archive']) as tar:
                self.assertEqual({'update.py','finish.py','INSTALL.json','SHA256SUMS'},set(tar.getnames()))
                self.assertTrue(all(m.isfile() and m.mode==0o600 for m in tar.getmembers()))
                folder=Path(td)/'isolated';folder.mkdir()
                (folder/'update.py').write_bytes(tar.extractfile('update.py').read())
                self.assertEqual(fix.FINISH_SHA,fix.sha256(tar.extractfile('finish.py').read()))
            (folder/'scorm_tracking_patch.py').write_text('raise RuntimeError("adjacent poison")')
            result=subprocess.run([sys.executable,'-I','-S',str(folder/'update.py')],capture_output=True,text=True,check=True)
            self.assertFalse(json.loads(result.stdout)['host_access'])

@unittest.skipUnless(os.geteuid()==0,'private root source images run in mandatory sudo CI')
class Transaction(unittest.TestCase):
    def setUp(self):
        self.fix=module();self.m=self.fix.runtime()
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.web=self.root/self.m.WEB;self.web.mkdir(parents=True)
        self.old={p:('old '+p).encode() for p in self.m.FILES}
        self.new={p:('new '+p).encode() for p in self.m.FILES}
        for p,raw in self.old.items():
            path=self.web/p;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw);path.chmod(0o640)
        for name in self.m.LOCKS:
            p=self.root/name;p.parent.mkdir(parents=True,exist_ok=True);p.touch(mode=0o600)
        for name in self.fix.PRESERVED:
            p=self.root/name;p.parent.mkdir(parents=True,exist_ok=True)
            if name=='opt/ustar-connect/current':p.symlink_to('/opt/ustar-connect/releases/'+self.fix.WORKER)
            else:p.write_bytes(b'preserved control');p.chmod(0o600)
        self.manifest={'schema':1,'baseline_commit':self.fix.BASELINE,'application_commit':self.fix.APPLICATION,
            'before':{p:self.m.Patch.image(raw) for p,raw in self.old.items()},
            'after':{p:self.m.Patch.image(raw) for p,raw in self.new.items()},'preserved':list(self.fix.PRESERVED)}
        self.host=Host();self.operation=self.m.Patch(self.root,self.manifest,self.new,self.host)

    def test_apply_and_replay_preserve_source_metadata_and_controls(self):
        p=self.web/self.m.FILES[0];os.setxattr(p,'user.ustar-fixture',b'keep')
        before=p.stat();self.assertEqual('ready',self.operation.check()['phase'])
        self.assertEqual('active',self.operation.apply()['phase'])
        self.assertEqual(b'keep',os.getxattr(p,'user.ustar-fixture'))
        after=p.stat();self.assertEqual((before.st_uid,before.st_gid,before.st_mode),(after.st_uid,after.st_gid,after.st_mode))
        self.operation.apply();self.assertEqual(1,self.host.calls.count('stop'))
        self.assertEqual('active',self.operation.check()['phase'])

    def test_interruption_restores_files_without_database_restore(self):
        self.host.fail=True
        business=self.root/'later-learning';business.write_bytes(b'preserve')
        with self.assertRaises(RuntimeError):self.operation.apply()
        self.assertEqual('rolled_back',self.operation.check()['phase'])
        self.assertEqual(self.old[self.m.FILES[0]],(self.web/self.m.FILES[0]).read_bytes())
        self.assertEqual(b'preserve',business.read_bytes())

    def test_foreign_source_is_refused_before_stop(self):
        (self.web/self.m.FILES[0]).write_bytes(b'foreign')
        with self.assertRaises(self.m.Refusal):self.operation.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_changed_preserved_control_blocks_recovery(self):
        self.operation.apply()
        (self.root/'etc/ustar-connect/receiver.json').write_bytes(b'changed')
        with self.assertRaises(self.m.Refusal):self.operation.recover()
        self.assertEqual(1,self.host.calls.count('stop'))

    def test_changed_source_permissions_are_not_silently_adopted(self):
        self.operation.apply();(self.web/self.m.FILES[0]).chmod(0o666)
        with self.assertRaises(self.m.Refusal):self.operation.recover()
        self.assertEqual(1,self.host.calls.count('stop'))

    def test_completed_previous_update_does_not_restart_its_workers(self):
        script=self.root/'update.py';script.write_bytes(b'fixture');script.chmod(0o600)
        (self.root/'finish.py').write_bytes((ROOT/'scripts/infra/route_review_finish.py').read_bytes())
        (self.root/'finish.py').chmod(0o600)
        folder=self.root/('var/lib/ustar-route-review-update-'+self.fix.BASELINE[:12]);folder.mkdir(mode=0o700)
        (folder/'state.json').write_text('{"phase":"active"}\n');(folder/'state.json').chmod(0o600)
        with patch('sys.argv',[str(script)]),patch.object(self.fix.subprocess,'run',side_effect=AssertionError('Historical task/worker state must not be replayed')):
            self.assertEqual('active',self.fix.previous_finish(self.root,apply=True)['phase'])

    def test_only_unfinished_committed_previous_update_uses_qualified_recovery(self):
        from types import SimpleNamespace
        script=self.root/'update.py';script.write_bytes(b'fixture');script.chmod(0o600)
        (self.root/'finish.py').write_bytes((ROOT/'scripts/infra/route_review_finish.py').read_bytes());(self.root/'finish.py').chmod(0o600)
        folder=self.root/('var/lib/ustar-route-review-update-'+self.fix.BASELINE[:12]);folder.mkdir(mode=0o700)
        journal=folder/'state.json';journal.write_text('{"phase":"publication_committed"}\n');journal.chmod(0o600)
        def finish(args,**kwargs):
            if '--recover' in args:
                self.assertIn('--acknowledge-outage',args)
                journal.write_text('{"phase":"active"}\n')
                return SimpleNamespace(returncode=0,stdout=b'{"phase":"active"}\n')
            return SimpleNamespace(returncode=0,stdout=b'{"phase":"ready_to_finish"}\n')
        with patch('sys.argv',[str(script)]),patch.object(self.fix.subprocess,'run',side_effect=finish):
            self.assertEqual('ready_to_finish',self.fix.previous_finish(self.root,apply=False)['phase'])
            self.assertEqual('publication_committed',json.loads(journal.read_text())['phase'])
            self.assertEqual('active',self.fix.previous_finish(self.root,apply=True)['phase'])
            self.assertEqual('active',json.loads(journal.read_text())['phase'])

    def test_actual_uid33_and_root_group_survive(self):
        if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')!='1':self.skipTest('mandatory sudo CI checks actual UID33')
        p=self.web/self.m.FILES[0];os.chown(p,33,0);p.chmod(0o640)
        self.operation.apply();s=p.stat();self.assertEqual((33,0,0o640),(s.st_uid,s.st_gid,s.st_mode&0o777))

if __name__=='__main__':unittest.main()
