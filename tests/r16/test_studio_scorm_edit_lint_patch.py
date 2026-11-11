"""Studio SCORM successor retains all prior journals and unrelated source bytes."""
import importlib
import io
import json
import os
import shutil
import subprocess
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
MODULE=os.environ.get('USTAR_SCORM_LINT_MODULE','studio_scorm_edit_lint_patch')

class Host:
    def __init__(self):self.running=True;self.calls=[];self.fail=False
    def identity(self):return {'Id':'fixed-web','State':{'Running':self.running}}
    def lint(self,raw):self.calls.append('lint')
    def stop(self):self.running=False;self.calls.append('stop')
    def start(self):
        self.calls.append('start')
        if self.fail:self.fail=False;raise RuntimeError('restart interrupted')
        self.running=True

class Contract(unittest.TestCase):
    def test_plan_is_offline_and_frozen_operators_are_not_modified(self):
        fix=importlib.import_module(MODULE)
        with patch.object(fix,'runtime',side_effect=AssertionError('host accessed')):
            with patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(0,fix.main([]));self.assertFalse(json.loads(out.getvalue())['host_access'])
        old=importlib.import_module('scorm_tracking_patch');before=(old.APPLICATION,old.BASELINE,old.FILES)
        fix.runtime();self.assertEqual(before,(old.APPLICATION,old.BASELINE,old.FILES))

    def test_web_start_purges_mustache_cache_as_uid33_and_reports_failure(self):
        fix=importlib.import_module(MODULE);m=fix.runtime();host=m.Host();host.container_id='fixed'
        calls=[]
        def run(args,**kwargs):
            calls.append(args)
            return b'200' if args[0]=='curl' else b''
        with patch.object(host,'run',side_effect=run):host.start()
        self.assertTrue(host.cache_purged)
        purge=next(args for args in calls if args[-1].endswith('/purge_caches.php'))
        self.assertEqual(['docker','exec','-u','33:33','fixed'],purge[:5])
        host.cache_purged=False
        def fail(args,**kwargs):
            if args[-1].endswith('/purge_caches.php'):raise m.Refusal('host_command_failed')
            return b'200' if args[0]=='curl' else b''
        with patch.object(host,'run',side_effect=fail),self.assertRaises(m.Refusal):host.start()
        self.assertFalse(host.cache_purged)

class Transaction(unittest.TestCase):
    def setUp(self):
        self.fix=importlib.import_module(MODULE);self.m=self.fix.runtime()
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.web=self.root/self.m.WEB;self.web.mkdir(parents=True)
        self.old={name:('baseline '+name).encode() for name in self.fix.FILES}
        self.old['local/ustar/untouched.php']=b'untouched'
        self.new={name:('target '+name).encode() if name in self.fix.FILES else raw for name,raw in self.old.items()}
        for name,raw in self.old.items():
            path=self.web/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw);path.chmod(0o640)
        for name in self.m.LOCKS:
            path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True);path.touch(mode=0o600)
        for name in self.fix.PRESERVED:
            path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True)
            if name=='opt/ustar-connect/current':path.symlink_to('/opt/ustar-connect/releases/'+self.fix.WORKER)
            else:path.write_bytes(b'preserved');path.chmod(0o600)
        journal=self.root/self.fix.LIBRARY_JOURNAL;journal.parent.chmod(0o700)
        journal.write_text('{"phase":"active"}\n')
        self.manifest={'schema':1,'baseline_commit':self.fix.BASELINE,'application_commit':self.fix.APPLICATION,
            'before':{p:self.m.Patch.image(raw) for p,raw in self.old.items()},
            'after':{p:self.m.Patch.image(raw) for p,raw in self.new.items()},'preserved':list(self.fix.PRESERVED)}
        self.host=Host();self.op=self.m.Patch(self.root,self.manifest,{name:self.new[name] for name in self.fix.FILES},self.host)

    def test_apply_repeat_and_recover_preserve_source_metadata_xattrs_and_old_journal(self):
        path=self.web/self.fix.FILES[0];os.setxattr(path,'user.ustar',b'keep');before=path.stat()
        journal=self.root/self.fix.LIBRARY_JOURNAL;oldjournal=journal.read_bytes()
        self.assertEqual('ready',self.op.check()['phase']);self.assertFalse(self.op.base.exists())
        self.assertEqual('active',self.op.apply()['phase']);self.assertEqual('active',self.op.check()['phase'])
        self.op.apply();self.assertEqual(1,self.host.calls.count('stop'))
        after=path.stat();self.assertEqual((before.st_uid,before.st_gid,before.st_mode),(after.st_uid,after.st_gid,after.st_mode))
        self.assertEqual(b'keep',os.getxattr(path,'user.ustar'));self.assertEqual(oldjournal,journal.read_bytes())
        self.assertEqual('rolled_back',self.op.recover()['phase']);self.assertEqual(self.old[self.fix.FILES[0]],path.read_bytes())
        self.assertEqual(oldjournal,journal.read_bytes())

    def test_process_loss_between_six_files_recovers_complete_baseline(self):
        class Crash(BaseException):pass
        original=self.op.write;written=[]
        def stop_after_third(name,raw,metadata):
            original(name,raw,metadata);written.append(name)
            if len(written)==3:raise Crash()
        with patch.object(self.op,'write',side_effect=stop_after_third),self.assertRaises(Crash):self.op.apply()
        self.assertEqual('recovery_required',self.op.check()['phase'])
        self.assertFalse(self.host.running)
        self.assertEqual('rolled_back',self.op.recover()['phase'])
        self.assertTrue(self.host.running)
        for name,raw in self.old.items():self.assertEqual(raw,(self.web/name).read_bytes())

    def test_corrupt_source_backup_refuses_recovery_before_stopping_web(self):
        self.op.apply();state=self.op.saved();row=state['files'][self.fix.FILES[0]]
        (self.op.base/row['backup']).write_bytes(b'corrupt')
        with self.assertRaisesRegex(self.m.Refusal,'backup_changed'):self.op.recover()
        self.assertEqual(1,self.host.calls.count('stop'));self.assertTrue(self.host.running)

    def test_unfinished_library_is_rejected_before_outage(self):
        (self.root/self.fix.LIBRARY_JOURNAL).write_text('{"phase":"publication_committed"}')
        with self.assertRaisesRegex(self.m.Refusal,'previous_library_update_not_active'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_maintenance_is_not_overwritten(self):
        path=self.root/self.fix.MAINTENANCE;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(b'other maintenance')
        with self.assertRaisesRegex(self.m.Refusal,'existing_maintenance'):self.op.apply()
        self.assertEqual(b'other maintenance',path.read_bytes());self.assertNotIn('stop',self.host.calls)

    def test_foreign_unrelated_source_is_refused_before_outage(self):
        (self.web/'local/ustar/untouched.php').write_bytes(b'later fix')
        with self.assertRaises(self.m.Refusal):self.op.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_failed_start_restores_source_and_keeps_later_learning_data(self):
        business=self.root/'later-results';business.write_bytes(b'keep results');self.host.fail=True
        with self.assertRaisesRegex(RuntimeError,'restart interrupted'):self.op.apply()
        self.assertEqual('rolled_back',self.op.check()['phase']);self.assertEqual(b'keep results',business.read_bytes())

    def test_shared_lock_blocks_write_without_truncating(self):
        import fcntl
        path=self.root/self.m.LOCKS[-1];path.write_bytes(b'held')
        with path.open('rb') as stream:
            fcntl.flock(stream,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(self.m.Refusal,'managed_operation_busy'):self.op.apply()
        self.assertEqual(b'held',path.read_bytes());self.assertNotIn('stop',self.host.calls)

    @unittest.skipUnless(os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')=='1','mandatory sudo CI checks actual UID33')
    def test_actual_uid33_root_group_and_xattrs_survive(self):
        path=self.web/self.fix.FILES[0];os.chown(path,33,0);path.chmod(0o640)
        os.setxattr(path,'user.ustar-owner',b'keep')
        self.op.apply();self.op.recover();info=path.stat()
        self.assertEqual((33,0,0o640),(info.st_uid,info.st_gid,info.st_mode&0o777))
        self.assertEqual(b'keep',os.getxattr(path,'user.ustar-owner'))

class RealPhp(unittest.TestCase):
    setUp=Transaction.setUp
    def prepare_real_php_host(self,invalid=False):
        binary=os.environ.get('USTAR_PHP') or shutil.which('php')
        if not binary:self.skipTest('PHP parser required; mandatory in dedicated CI')
        for name in self.fix.FILES:self.new[name]=(ROOT/'moodle'/name).read_bytes()
        if invalid:self.new[self.fix.FILES[0]]=b'<?php function invalid( {'
        self.manifest['after']={p:self.m.Patch.image(raw) for p,raw in self.new.items()}
        owner=self
        class PhpHost(Host):
            def lint(self,raw):
                self.calls.append(('php-lint',raw))
                # Match php -n on server1, including its default short tags.
                result=subprocess.run([binary,'-n','-d','short_open_tag=1','-l'],input=raw,
                    stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
                owner.m.require(result.returncode==0,'host_command_failed')
        self.host=PhpHost();self.op=self.m.Patch(self.root,self.manifest,
            {name:self.new[name] for name in self.fix.FILES},self.host)
        return binary

    def test_actual_php_stdin_accepts_the_six_file_application_without_parsing_js_as_php(self):
        self.prepare_real_php_host()
        self.assertEqual('ready',self.op.check()['phase'])
        self.assertEqual('active',self.op.apply()['phase'])
        expected=[self.new[p] for p in self.fix.FILES if p.endswith('.php')]
        actual=[c[1] for c in self.host.calls if isinstance(c,tuple) and c[0]=='php-lint']
        self.assertCountEqual(expected,actual)
        self.assertEqual(3,len(actual))
        self.assertEqual('rolled_back',self.op.recover()['phase'])

    def test_invalid_php_is_still_refused_before_the_outage(self):
        self.prepare_real_php_host(invalid=True)
        with self.assertRaisesRegex(self.m.Refusal,'php_lint_failed:local/ustar/classes/course_studio_material.php'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)
        self.assertTrue(self.host.running)
        self.assertFalse((self.op.base/'state.json').exists())

if __name__=='__main__':unittest.main()
