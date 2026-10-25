"""Recovery/security boundaries for the additional infrastructure batch."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT/'scripts/infra/infra_one_pass.py'
sys.path.insert(0,str(SOURCE.parent))

def module(case):
    case.assertTrue(SOURCE.exists(),'one-pass operator is not implemented')
    spec=importlib.util.spec_from_file_location('infra_one_pass_fixture',SOURCE)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value

@unittest.skipUnless(os.geteuid()==0,'filesystem root checks run in mandatory runtime CI')
class ExportBoundaries(unittest.TestCase):
    def setUp(self):
        self.m=module(self)
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.home=self.root/'home/aduk';self.home.mkdir(parents=True)
        self.export=self.home/'ustar-backup-20260928';self.export.mkdir()
        self.dump=self.export/'postgres.dump';self.dump.write_bytes(b'PRIVATE_SENTINEL');self.dump.chmod(0o664)
        (self.home/'unrelated.txt').write_text('unrelated');(self.home/'unrelated.txt').chmod(0o644)
        self.tx=self.m.Exports(self.root/'private')
    def plan(self):return self.m.export_plan(self.root)
    def test_restricts_dump_without_reading_removing_or_changing_ownership(self):
        before=self.dump.stat();self.tx.prepare(self.plan());self.tx.apply()
        self.assertEqual(self.dump.read_bytes(),b'PRIVATE_SENTINEL')
        self.assertEqual(self.dump.stat().st_mode&0o777,0o600)
        self.assertEqual((self.dump.stat().st_uid,self.dump.stat().st_gid),(before.st_uid,before.st_gid))
        self.assertEqual((self.home/'unrelated.txt').stat().st_mode&0o777,0o644)
        self.assertNotIn('PRIVATE_SENTINEL',(self.root/'private/state.json').read_text())
    def test_undo_restores_original_mode_and_retains_file(self):
        self.tx.prepare(self.plan());self.tx.apply();self.tx.undo()
        self.assertEqual(self.dump.stat().st_mode&0o777,0o664)
        self.assertEqual(self.dump.read_bytes(),b'PRIVATE_SENTINEL')
    def test_symlink_export_and_symlink_parent_never_followed(self):
        target=self.root/'outside';target.write_text('private');target.chmod(0o644)
        (self.home/'USTAR_symlink.tar.gz').symlink_to(target)
        (self.home/'ustar-linked').symlink_to(self.export,target_is_directory=True)
        plan=self.plan();self.tx.prepare(plan);self.tx.apply()
        self.assertEqual(target.stat().st_mode&0o777,0o644)
        self.assertEqual(len(plan['skipped_links']),2)
    def test_new_inode_refuses_before_any_permission_change(self):
        self.tx.prepare(self.plan());self.dump.rename(self.export/'old')
        self.dump.write_bytes(b'PRIVATE_SENTINEL');self.dump.chmod(0o664)
        with self.assertRaises(self.m.Refusal):self.tx.apply()
        self.assertEqual(self.dump.stat().st_mode&0o777,0o664)
    def test_interrupted_chmod_is_recoverable(self):
        self.tx.prepare(self.plan())
        def interrupt(_):raise KeyboardInterrupt()
        with self.assertRaises(KeyboardInterrupt):self.tx.apply(progress=interrupt)
        self.assertEqual(self.dump.stat().st_mode&0o777,0o600)
        self.tx.undo();self.assertEqual(self.dump.stat().st_mode&0o777,0o664)
    def test_unknown_content_change_refuses_undo(self):
        self.tx.prepare(self.plan());self.tx.apply();self.dump.write_bytes(b'foreign change')
        with self.assertRaises(self.m.Refusal):self.tx.undo()
        self.assertEqual(self.dump.stat().st_mode&0o777,0o600)
    def test_hardlink_is_not_mutated(self):
        os.link(self.dump,self.home/'other.dump')
        with self.assertRaises(self.m.Refusal):self.plan()
        self.assertEqual(self.dump.stat().st_mode&0o777,0o664)
    def test_limited_scan_refuses_instead_of_claiming_complete(self):
        with self.assertRaises(self.m.Refusal):self.m.export_plan(self.root,limit=1)
    def test_owner_group_writable_backup_directory_is_supported_safely(self):
        self.export.chmod(0o775)
        self.tx.prepare(self.plan());self.tx.apply()
        self.assertEqual(self.dump.stat().st_mode&0o777,0o600)
    def test_completed_operation_rejects_reopened_world_read_permission(self):
        self.tx.prepare(self.plan());self.tx.apply()
        state=self.tx.store.load();state['phase']='active';self.tx.store.save(state)
        self.dump.chmod(0o664)
        with self.assertRaises(self.m.Refusal):self.tx.guard()
    def test_fully_undone_attempt_can_resume_without_changing_before_images(self):
        plan=self.plan();self.tx.prepare(plan);self.tx.apply();self.tx.undo()
        self.assertTrue(hasattr(self.tx,'resume'),'known complete metadata rollback cannot be retried')
        before=self.tx.store.load()['exports'];self.tx.resume(plan);self.tx.apply()
        self.assertEqual(self.tx.store.load()['exports'],before)
        self.assertEqual(self.dump.stat().st_mode&0o777,0o600)
    def test_retry_refuses_a_new_export_instead_of_silently_extending_scope(self):
        self.tx.prepare(self.plan());self.tx.apply();self.tx.undo()
        new=self.home/'ustar-new.dump';new.write_text('new');new.chmod(0o664)
        self.assertTrue(hasattr(self.tx,'resume'),'known complete metadata rollback cannot be retried')
        with self.assertRaises(self.m.Refusal):self.tx.resume(self.plan())
        self.assertEqual(new.stat().st_mode&0o777,0o664)
    def test_live_env_keys_and_unrelated_app_sql_are_outside_export_scope(self):
        env=self.home/'.env';env.write_text('PRIVATE_ENV');env.chmod(0o664)
        key=self.home/'ustar-service.key';key.write_text('PRIVATE_KEY');key.chmod(0o664)
        app=self.home/'other-app';app.mkdir();sql=app/'schema.sql';sql.write_text('SELECT 1');sql.chmod(0o644)
        self.tx.prepare(self.plan());self.tx.apply()
        self.assertEqual([p.stat().st_mode&0o777 for p in (env,key,sql)],[0o664,0o664,0o644])

@unittest.skipUnless(os.geteuid()==0,'filesystem root checks run in mandatory runtime CI')
class PayloadBoundaries(unittest.TestCase):
    def setUp(self):
        self.m=module(self);self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        for name,raw in [('etc/ustar-backup/recovery.key',b'SECRET_PRIVATE_KEY'),
                         ('etc/ustar-backup/recipients.txt',b'age1public'),
                         ('var/lib/ustar-code-hardening/archive/old.php',b'legacy bytes')]:
            p=self.root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw);p.chmod(0o600)
    def test_recovery_private_key_is_excluded_even_if_explicitly_requested(self):
        payload=self.m.payload(self.root,('etc/ustar-backup','var/lib/ustar-code-hardening'))
        self.assertIn('var/lib/ustar-code-hardening/archive/old.php',payload)
        self.assertNotIn('etc/ustar-backup/recovery.key',payload)
        self.assertNotIn('SECRET_PRIVATE_KEY',repr(payload))
    def test_special_files_and_symlinks_refuse_without_opening_target(self):
        p=self.root/'var/lib/ustar-code-hardening/archive/link';p.symlink_to('/dev/zero')
        with self.assertRaises(self.m.Refusal):self.m.payload(self.root,('var/lib/ustar-code-hardening',))
    def test_tar_readback_detects_changed_member(self):
        import io
        data=self.m.payload(self.root,('var/lib/ustar-code-hardening',))
        output=io.BytesIO();self.m.write_tar(output,data)
        self.m.verify_tar(io.BytesIO(output.getvalue()),data)
        broken={**data};key=next(iter(broken));broken[key]={**broken[key],'raw':b'changed'}
        with self.assertRaises(self.m.Refusal):self.m.verify_tar(io.BytesIO(output.getvalue()),broken)
    def test_manifests_include_file_ownership_and_original_path(self):
        import io,tarfile
        data=self.m.payload(self.root,('var/lib/ustar-code-hardening',));out=io.BytesIO();self.m.write_tar(out,data)
        with tarfile.open(fileobj=io.BytesIO(out.getvalue()),mode='r:gz') as archive:
            manifest=json.load(archive.extractfile('manifest.json'))
            row=manifest['files'][0]
            self.assertEqual(row['path'],'var/lib/ustar-code-hardening/archive/old.php')
            self.assertEqual(row['mode'],0o600);self.assertEqual(row['bytes'],12)
            self.assertNotIn('raw',row)

class DiagnosisAndDefault(unittest.TestCase):
    def test_default_operator_does_not_touch_host(self):
        self.assertTrue(SOURCE.exists(),'one-pass operator is not implemented')
        r=subprocess.run([sys.executable,'-I','-B',str(SOURCE)],capture_output=True,timeout=5)
        self.assertEqual(r.returncode,0,r.stderr.decode())
        self.assertEqual(json.loads(r.stdout),{'phase':'PLAN_ONLY','configuration_changes':False,'provider_called':False})
    def test_failed_probe_never_becomes_measured_zero_events(self):
        m=module(self)
        self.assertIsNone(m.journal_adjustment({'reason':'unknown'}))
        self.assertIsNone(m.journal_adjustment({'reason':'process_failed','exit_code':1}))
        self.assertIsNone(m.journal_adjustment({'reason':'measured','categories':0}))
    def test_only_reproduced_timeout_or_permission_select_a_scoped_adjustment(self):
        m=module(self)
        self.assertEqual(m.journal_adjustment({'reason':'timeout'}),'CPUQuota=100%\n')
        self.assertEqual(m.journal_adjustment({'reason':'permission_denied'}),'SupplementaryGroups=systemd-journal\n')

@unittest.skipUnless(os.geteuid()==0,'filesystem root checks run in mandatory runtime CI')
class ManagedBoundaries(unittest.TestCase):
    def setUp(self):
        self.m=module(self);self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.store=self.m.Store(self.root/'private')
        self.m.Exports(self.store.base).prepare(dict(rows=[],visited=0,skipped_links=[]))
    def test_existing_foreign_configuration_is_not_overwritten(self):
        path=self.root/'existing.conf';path.write_bytes(b'foreign')
        self.assertTrue(hasattr(self.m,'Managed'),'managed file transaction is missing')
        with self.assertRaises(self.m.Refusal):self.m.Managed(self.store).write(path,b'new')
        self.assertEqual(path.read_bytes(),b'foreign')
    def test_all_recorded_partial_versions_are_recoverable(self):
        self.assertTrue(hasattr(self.m,'Managed'),'managed file transaction is missing')
        path=self.root/'managed.conf';manager=self.m.Managed(self.store)
        manager.write(path,b'probe');manager.write(path,b'final');manager.restore()
        self.assertFalse(path.exists())
    def test_foreign_drift_blocks_remove(self):
        self.assertTrue(hasattr(self.m,'Managed'),'managed file transaction is missing')
        path=self.root/'managed.conf';manager=self.m.Managed(self.store);manager.write(path,b'known')
        path.write_bytes(b'foreign')
        with self.assertRaises(self.m.Refusal):manager.restore()
        self.assertEqual(path.read_bytes(),b'foreign')
    def test_active_state_rejects_missing_current_file_and_old_probe_version(self):
        self.assertTrue(hasattr(self.m,'Managed'),'managed file transaction is missing')
        path=self.root/'managed.conf';manager=self.m.Managed(self.store)
        manager.write(path,b'probe');manager.write(path,b'final')
        state=self.store.load();state['phase']='active';self.store.save(state)
        path.write_bytes(b'probe')
        with self.assertRaises(self.m.Refusal):manager.guard(path)
        path.unlink()
        with self.assertRaises(self.m.Refusal):manager.guard(path)

@unittest.skipUnless(os.geteuid()==0,'filesystem root checks run in mandatory runtime CI')
class JournalTransaction(unittest.TestCase):
    def setUp(self):
        self.m=module(self);self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.store=self.m.Store(self.root/'private')
        self.m.Exports(self.store.base).prepare(dict(rows=[],visited=0,skipped_links=[]))
        self.dropin=self.root/'unit.d/90-own.conf'
    def test_unsuccessful_adjustment_is_removed_and_timer_resumed(self):
        self.assertTrue(hasattr(self.m,'repair_journal'),'service sandbox transaction is missing')
        class Host:
            enabled=True
            def active(self,unit):return self.enabled
            def systemctl(self,*args):
                if args[0]=='stop':self.enabled=False
                if args[0]=='start' and args[1]==self_module.COLLECT_TIMER:self.enabled=True
            def snapshot(self):return {'events_status':'no_data','fresh':True}
            def probe(self):return {'reason':'timeout'}
        self_module=self.m;host=Host()
        result=self.m.repair_journal(self.store,host,self.dropin)
        self.assertEqual(result['status'],'unresolved');self.assertFalse(self.dropin.exists());self.assertTrue(host.enabled)
    def test_success_requires_fresh_service_snapshot(self):
        self.assertTrue(hasattr(self.m,'repair_journal'),'service sandbox transaction is missing')
        class Host:
            calls=0
            def active(self,unit):return True
            def systemctl(self,*args):
                if args[0]=='start' and args[1]==self_module.COLLECTOR:self.calls+=1
            def snapshot(self):return {'events_status':'ok' if self.calls else 'no_data','fresh':True}
            def probe(self):return {'reason':'permission_denied'}
        self_module=self.m;host=Host()
        result=self.m.repair_journal(self.store,host,self.dropin)
        self.assertEqual(result['status'],'measured');self.assertIn(b'SupplementaryGroups=systemd-journal',self.dropin.read_bytes())
        self.assertNotIn(b'ExecStartPost',self.dropin.read_bytes())

@unittest.skipUnless(os.geteuid()==0,'real encrypted root snapshots run in mandatory runtime CI')
class CipherBoundary(unittest.TestCase):
    def test_real_age_roundtrip_and_corruption_is_rejected(self):
        import shutil
        m=module(self)
        self.assertTrue(hasattr(m,'encrypted_copy'),'encrypted readback producer is missing')
        if not shutil.which('age') or not shutil.which('age-keygen'):
            if os.environ.get('USTAR_REQUIRE_INFRA_NATIVE')=='1':self.fail('native age tools required')
            self.skipTest('real age roundtrip runs in mandatory runtime CI')
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory);key=base/'recovery.key';recipients=base/'recipients.txt'
            p=subprocess.run(['age-keygen','-o',str(key)],capture_output=True,check=True)
            public=subprocess.run(['age-keygen','-y',str(key)],capture_output=True,check=True).stdout
            recipients.write_bytes(public);recipients.chmod(0o600)
            source=base/'source';source.mkdir();(source/'state.json').write_bytes(b'PRIVATE_SENTINEL');(source/'state.json').chmod(0o600)
            data=m.payload(base,('source',));archive=base/'copy.age'
            m.encrypted_copy(archive,data,recipients,key)
            self.assertNotIn(b'PRIVATE_SENTINEL',archive.read_bytes())
            m.verify_encrypted(archive,data,key)
            raw=bytearray(archive.read_bytes());raw[-1]^=1;archive.write_bytes(raw)
            with self.assertRaises(m.Refusal):m.verify_encrypted(archive,data,key)

@unittest.skipUnless(os.geteuid()==0,'filesystem root checks run in mandatory runtime CI')
class MountNamespace(unittest.TestCase):
    def test_host_readwrite_and_target_readwrite_are_checked_separately(self):
        from unittest.mock import patch
        m=module(self)
        with tempfile.TemporaryDirectory() as directory:
            target=Path(directory)/'target';target.mkdir(mode=0o700)
            class Host:
                def command(self,argv):
                    # A restricted service sees /srv read-only but its explicit
                    # ReadWritePaths target is writable. PID1 still sees host rw.
                    is_host='--task' in argv
                    uuid=m.HDD_UUID
                    target_name=str(m.MOUNT) if is_host else str(target)
                    row=dict(target=target_name,uuid=uuid,fstype='ext4',options='rw,nosuid,nodev,noexec')
                    return type('Result',(),{'stdout':json.dumps({'filesystems':[row]}).encode()})()
            self.assertTrue(hasattr(m,'MOUNT'),'host/target namespace distinction is missing')
            with patch.object(m,'TARGET',target):m.hdd_target(Host())

class ActiveCertificate(unittest.TestCase):
    def test_live_leaf_is_verified_for_the_academy_hostname(self):
        import shutil,socket,ssl,threading
        m=module(self)
        self.assertTrue(hasattr(m,'active_leaf'),'active TLS connection metadata is missing')
        if not shutil.which('openssl'):self.skipTest('openssl fixture unavailable')
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory);key=base/'key.pem';cert=base/'cert.pem'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                '-subj','/CN=ustar.local','-addext','subjectAltName=DNS:ustar.local',
                '-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key)
            with socket.socket() as listener:
                listener.bind(('127.0.0.1',0));listener.listen();listener.settimeout(5)
                def serve():
                    try:
                        connection,_=listener.accept()
                        with context.wrap_socket(connection,server_side=True):pass
                    except ssl.SSLError:pass
                thread=threading.Thread(target=serve);thread.start()
                try:result=m.active_leaf(cert,listener.getsockname())
                finally:thread.join(6)
                self.assertFalse(thread.is_alive());self.assertTrue(result['verified'])
                self.assertRegex(result['sha256'],'^[0-9a-f]{64}$');self.assertIn('not_after',result)

if __name__=='__main__':unittest.main()
