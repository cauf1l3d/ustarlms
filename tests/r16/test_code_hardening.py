import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
import code_hardening as c

class Filesystem(unittest.TestCase):
    def setUp(self):
        if os.geteuid()!=0:self.skipTest("root filesystem fixture runs in mandatory runtime CI")
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name);self.root=self.base/'code';self.root.mkdir()
        (self.root/'public').mkdir();(self.root/'public/plugin.php').write_bytes(b'code')
        (self.root/'public/plugin.php').chmod(0o775)
        (self.root/'gradehelper.php').write_bytes(b'private legacy bytes')
        self.tx=c.Transaction(self.root,self.base/'private')
    def test_roundtrip_keeps_bytes_and_archives_inactive_extra(self):
        before=c.snapshot(self.root)
        self.tx.prepare();self.tx.apply()
        self.assertFalse((self.root/'gradehelper.php').exists())
        self.assertEqual((self.root/'public/plugin.php').stat().st_mode&0o777,0o755)
        self.assertEqual((self.base/'private/archive/gradehelper.php').read_bytes(),b'private legacy bytes')
        self.assertEqual((self.base/'private/archive/gradehelper.php').stat().st_mode&0o777,0o600)
        self.tx.undo();self.assertEqual(c.snapshot(self.root),before)
    def test_unknown_file_drift_refuses_before_mutation(self):
        self.tx.prepare();(self.root/'public/plugin.php').write_bytes(b'changed')
        with self.assertRaises(c.Refusal):self.tx.apply()
        self.assertTrue((self.root/'gradehelper.php').exists())
    def test_new_file_blocks_undo(self):
        self.tx.prepare();self.tx.apply();(self.root/'public/new.php').write_bytes(b'new')
        with self.assertRaises(c.Refusal):self.tx.undo()
    def test_unknown_reports_refused(self):
        (self.root/'public/reports').mkdir();(self.root/'public/reports/new.pdf').write_bytes(b'pdf')
        with self.assertRaises(c.Refusal):self.tx.prepare()
    def test_symlink_and_hardlink_refused(self):
        p=self.root/'link';p.symlink_to(self.root/'public/plugin.php')
        with self.assertRaises(c.Refusal):self.tx.prepare()
        p.unlink();os.link(self.root/'public/plugin.php',p)
        with self.assertRaises(c.Refusal):self.tx.prepare()
    def test_archive_tamper_refuses_undo(self):
        self.tx.prepare();self.tx.apply();(self.base/'private/archive/gradehelper.php').write_bytes(b'bad')
        with self.assertRaises(c.Refusal):self.tx.undo()
    def test_interrupted_apply_can_restore_each_mutation(self):
        for checkpoint in ('archive_ready','removed','permissions'):
            with self.subTest(checkpoint=checkpoint):
                # Each previous iteration was fully restored.
                before=c.snapshot(self.root)
                tx=c.Transaction(self.root,self.base/('private-'+checkpoint))
                tx.prepare()
                def interrupt(point):
                    if point==checkpoint:raise KeyboardInterrupt()
                tx.progress=interrupt
                with self.assertRaises(KeyboardInterrupt):tx.apply()
                tx.progress=lambda _:None
                tx.undo();self.assertEqual(c.snapshot(self.root),before)


class RecoveryBoundaries(unittest.TestCase):
    setUp=Filesystem.setUp
    def test_interruption_between_real_chown_and_chmod_restores_uid33(self):
        try:os.chown(self.root/'public/plugin.php',33,33)
        except OSError:
            if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')=='1':raise
            self.skipTest('local namespace cannot map UID33')
        before=c.snapshot(self.root);self.tx.prepare()
        def interrupt(point):
            if point=='ownership':raise KeyboardInterrupt()
        self.tx.progress=interrupt
        with self.assertRaises(KeyboardInterrupt):self.tx.apply()
        self.assertEqual((self.root/'public/plugin.php').stat().st_uid,0)
        self.tx.progress=lambda _:None
        self.tx.undo();self.assertEqual(c.snapshot(self.root),before)

    def test_partial_restore_before_timestamp_is_recoverable(self):
        before=c.snapshot(self.root);self.tx.prepare();self.tx.apply()
        state=self.tx.load()
        self.tx.save_phase('restoring')
        c.h.replace_image(state['extras']['gradehelper.php'])
        self.tx.undo();self.assertEqual(c.snapshot(self.root),before)

    def test_actual_uid33_cannot_read_archive_or_modify_code(self):
        mapped=Path('/proc/self/uid_map').read_text().split()
        if os.geteuid()!=0 or int(mapped[-1])<=33:
            if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')=='1':self.fail('UID33 mapping required')
            self.skipTest('local namespace cannot map UID33')
        self.base.chmod(0o755)
        os.chown(self.root/'public',33,33);os.chown(self.root/'public/plugin.php',33,33)
        self.tx.prepare();self.tx.apply()
        readfd,writefd=os.pipe();pid=os.fork()
        if pid==0:
            try:
                os.close(readfd);os.setgroups([33]);os.setgid(33);os.setuid(33)
                def denied(action):
                    try:
                        fd=action()
                        if isinstance(fd,int):os.close(fd)
                        return False
                    except PermissionError:return True
                code=self.root/'public/plugin.php'
                checks=[os.access(code,os.R_OK),
                        denied(lambda:os.open(code,os.O_WRONLY)),
                        denied(lambda:os.open(self.root/'public/new.php',os.O_WRONLY|os.O_CREAT,0o600)),
                        denied(lambda:os.unlink(code)),
                        denied(lambda:os.open(self.base/'private/archive/gradehelper.php',os.O_RDONLY))]
                os.write(writefd,b'OK' if all(checks) else b'FAIL')
            finally:os._exit(0)
        os.close(writefd)
        try:self.assertEqual(os.read(readfd,16),b'OK')
        finally:os.close(readfd);os.waitpid(pid,0)

class ServiceBoundary(unittest.TestCase):
    setUp=Filesystem.setUp
    def coordinator(self, failure=None):
        from contextlib import nullcontext
        from types import SimpleNamespace
        calls=[]
        class Host:
            runtime_validation=False
            def services(self):return {'worker':True}
            def policies(self):return {'restart':'unless-stopped'}
            def legacy_images(self):return []
            def backup(self,run):
                calls.append('backup')
                if failure=='backup':raise RuntimeError('fixture backup failure')
                return {'accepted':True}
            def cold(self):return nullcontext()
            def stop(self):calls.append('stop')
            def start_web(self):calls.append('start_web')
            def resume(self,*args):calls.append('resume')
            def recover_backup(self):calls.append('recover_backup')
        p=self.base/'db-state';p.write_bytes(b'accepted state');p.chmod(0o600)
        class Coordinator(c.Coordinator):
            failed=False
            def controls(self,*args,**kwargs):calls.append('controls')
            def verify(self):
                calls.append('verify')
                if failure=='verify' and not self.failed:
                    self.failed=True;raise RuntimeError('fixture health failure')
                self.tx.guard()
        return Coordinator(self.tx,Host(),SimpleNamespace(path=p)),calls
    def test_success_backup_precedes_stop_and_resume_follows_verification(self):
        op,calls=self.coordinator();self.assertEqual(op.apply()['phase'],'code_hardened')
        self.assertLess(calls.index('backup'),calls.index('stop'))
        self.assertLess(calls.index('verify'),calls.index('resume'))
        self.assertEqual(self.tx.load()['phase'],'active')
    def test_health_failure_restores_files_and_services(self):
        before=c.snapshot(self.root);op,calls=self.coordinator('verify');result=op.apply()
        self.assertEqual(result['phase'],'baseline_restored');self.assertFalse(result['apply_succeeded'])
        self.assertEqual(c.snapshot(self.root),before);self.assertEqual(calls[-2:],['resume','verify'])
    def test_backup_failure_retains_source(self):
        before=c.snapshot(self.root);op,calls=self.coordinator('backup');result=op.apply()
        self.assertFalse(result['apply_succeeded']);self.assertEqual(c.snapshot(self.root),before)
    def test_unknown_code_drift_blocks_recovery_before_host_actions(self):
        op,calls=self.coordinator();op.apply();calls.clear()
        (self.root/'public/plugin.php').write_bytes(b'unknown')
        with self.assertRaises(c.Refusal):op.recover(undo=True)
        self.assertEqual(calls,[])

class Packaging(unittest.TestCase):
    def test_standalone_is_repeatable_offline_and_outage_is_explicit(self):
        import build_code_hardening as builder
        import subprocess,json
        raw=builder.build();self.assertEqual(raw,builder.build())
        with tempfile.TemporaryDirectory() as folder:
            script=Path(folder)/'operator.py';script.write_bytes(raw)
            result=subprocess.run([sys.executable,'-I','-B',str(script)],capture_output=True,timeout=5)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(json.loads(result.stdout),{'phase':'PLAN_ONLY','host_changes':False})
            result=subprocess.run([sys.executable,'-I','-B',str(script),'--apply'],capture_output=True,timeout=5)
            self.assertEqual(result.returncode,2)
            self.assertIn(b'outage acknowledgement required',result.stderr)


class GuardReview(unittest.TestCase):
    setUp=Filesystem.setUp
    def test_completed_state_rejects_return_to_writable_modes(self):
        self.tx.prepare();self.tx.apply();self.tx.save_phase('active')
        (self.root/'public/plugin.php').chmod(0o775)
        with self.assertRaises(c.Refusal):self.tx.guard()
    def test_replaced_code_inode_with_same_bytes_and_mtime_refused(self):
        self.tx.prepare();p=self.root/'public/plugin.php';st=p.stat()
        replacement=self.root/'replacement';replacement.write_bytes(p.read_bytes())
        replacement.chmod(st.st_mode&0o777)
        os.utime(replacement,ns=(st.st_mtime_ns,st.st_mtime_ns));os.replace(replacement,p)
        with self.assertRaises(c.Refusal):self.tx.apply()
    def test_replaced_directory_inode_refused(self):
        self.tx.prepare();old=self.base/'retained';(self.root/'public').rename(old)
        (self.root/'public').mkdir();(old/'plugin.php').rename(self.root/'public/plugin.php')
        with self.assertRaises(c.Refusal):self.tx.apply()
    def test_backup_recovery_precedes_postgres_start_and_catalogue(self):
        from types import SimpleNamespace
        from unittest.mock import patch
        calls=[];p=self.root/'config.php';p.write_bytes(b'fixture config')
        dbfile=self.base/'db-state';dbfile.write_bytes(b'private fixture state');dbfile.chmod(0o600)
        class DB:
            def load(self):return {'phase':'app_role_active'}
            def guard_files(self):calls.append('file_guards')
            def guard(self):calls.append('catalogue_guard')
        class Host:
            def config_metadata(self,image):calls.append('parent_guards')
            def legacy_images(self):return []
            def recover_backup(self):calls.append('backup_recovery')
            def ensure_pg(self):calls.append('pg_start')
            def verify_live(self,*args):calls.append('live_guard')
        op=c.Coordinator(self.tx,Host(),SimpleNamespace(path=dbfile));op.db=DB()
        state={'runtime':{'db_state_sha':c.h.capture(dbfile)['sha256'],'legacy_images':[]}}
        with patch.object(c.h,'CONFIG',p):op.controls(state,recovery=True)
        self.assertEqual(calls,['file_guards','parent_guards','backup_recovery','pg_start','catalogue_guard','live_guard'])


class DeploymentCompatibility(unittest.TestCase):
    def test_existing_installer_preserves_root_owner_for_existing_and_new_files(self):
        if os.geteuid()!=0:self.skipTest('root fixture runs in mandatory runtime CI')
        import hashlib
        from tests.r16 import test_connect_academy_installer as fixture
        f=fixture.Transaction('test_success_changes_only_addons_and_migrates_twice')
        try:
            f.setUp()
            plugin=f.d.web/'local/ustar'
            plugin.chmod(0o775);(plugin/'version.php').chmod(0o775)
            tx=c.Transaction(f.d.p.CODE,f.root/'hardening')
            tx.prepare();tx.apply()
            f.payload['local/ustar/new/sub/file.php']=b'new release content'
            f.d.reference['candidate']['files']={k:hashlib.sha256(v).hexdigest() for k,v in f.payload.items()}
            self.assertEqual(f.run_update(),0)
            for p in (plugin,plugin/'version.php',plugin/'new',plugin/'new/sub/file.php'):
                self.assertEqual(p.stat().st_uid,0)
                self.assertFalse(p.stat().st_mode&0o022)
        finally:f.doCleanups()

if __name__=='__main__':unittest.main()
