"""Real source/lock/journal handoff with a controlled Docker/SQL boundary."""
import copy
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

class Contract(unittest.TestCase):
    def test_operator_exists_and_default_has_no_host_access(self):
        self.assertTrue((ROOT/'scripts/infra/route_access_update.py').is_file(),'Missing guarded route access updater')
        m=importlib.import_module('route_access_update')
        with patch.object(m,'Update',side_effect=AssertionError('host touched')):
            with patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(0,m.main([]))
                self.assertFalse(json.loads(out.getvalue())['host_access'])
        for args in (['--upgrade'],['--recover']):
            with self.assertRaises(SystemExit),patch('sys.stderr',new_callable=io.StringIO):m.main(args)

    def test_cron_patch_proves_the_original2000_source_and_preserves_record(self):
        m=importlib.import_module('route_access_update')
        old=b'# exact fixture\n'+m.CRON_2000+b'\n'
        raw=old.replace(m.CRON_2000,m.CRON_2900)
        record={'schema':1,'versions':m.BEFORE_VERSIONS,'script_sha256':m.sha(raw),'retain':'yes'}
        with patch.object(m,'CRON_ORIGINAL_SHA',m.sha(old)):
            after,updated=m.patch_cron(raw,record)
            self.assertIn(b'2026110100',after);self.assertEqual('yes',updated['retain'])
            with self.assertRaises(m.Refusal):m.patch_cron(raw+b'# drift',record)

class Crash(BaseException):pass

class Host:
    def __init__(self):self.calls=[];self.running=True;self.version='2026102900';self.fail_upgrade=False
    def identity(self):return {'web':'fixed-web','db':'fixed-db'}
    def services(self):return {'ustar-connect-notify.service':True,'ustar-connect-receiver.service':True}
    def database(self,prefix):return {'version':self.version}
    def dump(self,path):path.write_bytes(b'complete-private-dump');path.chmod(0o600);self.calls.append('dump')
    def lint(self,raw):self.calls.append('lint')
    def stop_workers(self,states):self.calls.append('stop_workers')
    def restore_workers(self,states):self.calls.append('restore_workers')
    def stop(self):self.running=False;self.calls.append('stop')
    def start(self):self.running=True;self.calls.append('start')
    def upgrade(self):
        self.calls.append('upgrade')
        if self.fail_upgrade:self.fail_upgrade=False;raise RuntimeError('upgrade interrupted')
        self.version='2026110100'
    def login(self):return self.running
    def healthy(self):return self.running

class Transaction(unittest.TestCase):
    def setUp(self):
        self.m=importlib.import_module('route_access_update');self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.host=Host();self.web=self.root/self.m.WEB
        self.web.mkdir(parents=True);(self.root/self.m.DATA).mkdir(parents=True)
        self.old={'local/ustar/version.php':b'old2900','local/ustar/course_launch.php':b'old launch'}
        self.new={**self.old,'local/ustar/version.php':b'new110100','local/ustar/course_launch.php':b'new launch',
            'local/ustar/classes/review.php':b'new class'}
        for p,raw in self.old.items():
            path=self.web/p;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw);path.chmod(0o640)
        (self.web/'local/ustar/classes').mkdir()
        config=self.root/self.m.CONFIG;config.parent.mkdir(parents=True,exist_ok=True);config.write_text("<?php\n$CFG->prefix='mdl_';\n");config.chmod(0o600)
        for p in self.m.LOCKS:
            path=self.root/p;path.parent.mkdir(parents=True,exist_ok=True);path.touch(mode=0o600)
        cron=b'# accepted fixture\n'+self.m.CRON_2900+b'\n'
        self.cron_patch=patch.object(self.m,'CRON_ORIGINAL_SHA',self.m.sha(cron.replace(self.m.CRON_2900,self.m.CRON_2000)))
        self.cron_patch.start();self.addCleanup(self.cron_patch.stop)
        schedule=self.root/self.m.CRON_SCHEDULE;schedule.parent.mkdir(parents=True,exist_ok=True);schedule.write_bytes(self.m.CRON_TEXT);schedule.chmod(0o644)
        for name,raw in [(self.m.CRON,cron),(self.m.CRON_INFO,json.dumps({'schema':1,'versions':self.m.BEFORE_VERSIONS,'script_sha256':self.m.sha(cron)}).encode())]:
            path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw);path.chmod(0o600)
        pointer=self.root/'opt/ustar-connect/current';pointer.parent.mkdir(parents=True,exist_ok=True)
        pointer.symlink_to('/opt/ustar-connect/releases/'+self.m.WORKER)
        self.manifest={'schema':1,'application_commit':self.m.APPLICATION,'tool_commit':'a'*40,
            'baselines':{self.m.BASELINES[0]:{'files':{p:self.m.sha(raw) for p,raw in self.old.items()}}},
            'candidate':{'files':{p:self.m.sha(raw) for p,raw in self.new.items()}},'preserved':['opt/ustar-connect/current',self.m.CONFIG]}
        self.tx=self.m.Update(self.root,self.manifest,self.new,self.host)
        # The SQL boundary has separate real-PG coverage; filesystem transactions remain real here.
        self.verify=patch.object(self.m,'verify_database',side_effect=lambda row,profile,baseline=None:'xml_install_indexes')
        self.verify.start();self.addCleanup(self.verify.stop)

    def test_apply_and_replay_preserve_metadata_and_worker_pointer(self):
        before=(self.web/'local/ustar/course_launch.php').stat()
        self.assertEqual('ready',self.tx.check()['phase']);self.assertFalse(self.tx.base.exists())
        self.assertEqual('active',self.tx.apply()['phase'])
        after=(self.web/'local/ustar/course_launch.php').stat()
        self.assertEqual((before.st_uid,before.st_gid,before.st_mode),(after.st_uid,after.st_gid,after.st_mode))
        self.assertFalse(self.tx.maintenance.exists());self.assertTrue(self.host.running)
        self.assertEqual(self.new['local/ustar/classes/review.php'],(self.web/'local/ustar/classes/review.php').read_bytes())
        self.tx.apply();self.assertEqual(1,self.host.calls.count('stop'));self.assertEqual(2,self.host.calls.count('upgrade'))
        self.assertEqual('active',self.tx.check()['phase'])

    def test_unknown_source_refused_before_outage(self):
        (self.web/'local/ustar/course_launch.php').write_bytes(b'foreign')
        with self.assertRaises(self.m.Refusal):self.tx.apply()
        self.assertNotIn('stop',self.host.calls);self.assertTrue(self.host.running)

    def test_native_upgrade_failure_keeps_maintenance_and_recovery_moves_forward(self):
        self.host.fail_upgrade=True
        with self.assertRaises(RuntimeError):self.tx.apply()
        self.assertTrue(self.tx.maintenance.exists())
        business=self.root/'later-business';business.write_bytes(b'keep learning results')
        self.assertEqual('active',self.tx.recover()['phase']);self.assertEqual(b'keep learning results',business.read_bytes())
        self.assertNotIn('restore_database',self.host.calls)

    def test_process_loss_between_code_files_resumes_without_database_restore(self):
        original=self.tx.checkpoint
        def crash(state,phase):
            original(state,phase)
            if phase=='source_switching':raise Crash()
        with patch.object(self.tx,'checkpoint',side_effect=crash),self.assertRaises(Crash):self.tx.apply()
        self.assertEqual('active',self.tx.recover()['phase']);self.assertTrue(self.host.running)

    def test_corrupt_private_image_is_rejected_during_recovery(self):
        self.host.fail_upgrade=True
        with self.assertRaises(RuntimeError):self.tx.apply()
        state=self.tx.load();item=next(i for i in state['files'].values() if i['before']['kind']=='file')
        (self.tx.base/item['backup']).write_bytes(b'corrupt')
        with self.assertRaises(self.m.Refusal):self.tx.recover()
        self.assertTrue(self.tx.maintenance.exists())

    def test_changed_target_permissions_are_not_adopted_on_readonly_check(self):
        self.tx.apply()
        (self.web/'local/ustar/classes/review.php').chmod(0o666)
        with self.assertRaises(self.m.Refusal):self.tx.check()

    def test_explicit_real_uid33_and_root_group_are_retained(self):
        if os.geteuid()!=0 or os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')!='1':self.skipTest('delegated to mandatory sudo CI')
        p=self.web/'local/ustar/course_launch.php';os.chown(p,33,0);p.chmod(0o640)
        self.tx.apply();s=p.stat();self.assertEqual((33,0,0o640),(s.st_uid,s.st_gid,s.st_mode&0o777))

class Package(unittest.TestCase):
    def test_exact_git_archive_is_deterministic_private_and_offline(self):
        import subprocess,tarfile
        builder=importlib.import_module('build_route_access_update')
        commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
        with tempfile.TemporaryDirectory() as td:
            first=builder.build(commit,Path(td)/'first');second=builder.build(commit,Path(td)/'second')
            a=Path(first['archive']);self.assertEqual(a.read_bytes(),Path(second['archive']).read_bytes())
            with tarfile.open(a) as archive:
                self.assertEqual({'update.py','INSTALL.json','SHA256SUMS'},set(archive.getnames()))
                self.assertTrue(all(item.isfile() and item.mode==0o600 for item in archive.getmembers()))
                script=archive.extractfile('update.py').read()
            folder=Path(td)/'isolated';folder.mkdir();(folder/'update.py').write_bytes(script)
            (folder/'route_access_update.py').write_text('raise RuntimeError("adjacent poison loaded")')
            result=subprocess.run([sys.executable,'-I','-S',str(folder/'update.py')],cwd=folder,capture_output=True,text=True,check=True)
            self.assertFalse(json.loads(result.stdout)['host_access'])
            self.assertIn('PLAN_ONLY',result.stdout)

if __name__=='__main__':unittest.main()
