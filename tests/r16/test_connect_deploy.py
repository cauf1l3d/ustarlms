"""Install contracts on real temporary files; systemd/user actions are modeled here, native fixture separate."""
import hashlib,importlib.util,io,json,os,stat,tarfile,tempfile,unittest
from unittest.mock import patch
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
MODULE=ROOT/'connect-worker/deploy/install.py'
SHA='1'*40
FILES={'SOURCE_COMMIT':(SHA+'\n').encode(),'receiver.pyz':b'fixture-receiver','notifications.pyz':b'fixture-notify','admin.pyz':b'fixture-admin',
       **{f'units/ustar-connect-{n}.service':b'# USTAR Connect managed\n' for n in ('receiver','probe','notify')}}

def archive(path,files=FILES,change=None):
    manifest={'schema':1,'source_commit':SHA,'files':{k:hashlib.sha256(v).hexdigest() for k,v in files.items()}}
    data={**files,'manifest.json':json.dumps(manifest).encode()}
    with tarfile.open(path,'w:gz') as tar:
        for name,value in data.items():
            entry=tarfile.TarInfo(name);entry.size=len(value);entry.mode=0o644
            if change:change(entry)
            tar.addfile(entry,io.BytesIO(value))
    return hashlib.sha256(path.read_bytes()).hexdigest()

class DeployTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not MODULE.is_file():raise AssertionError('Connect installer missing')
        spec=importlib.util.spec_from_file_location('connect_install',MODULE);cls.mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(cls.mod)
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name)
        self.tar=self.root/'release.tar.gz';self.digest=archive(self.tar)
    def test_manifest_hash_and_archive_identity(self):
        sha,files=self.mod.validate_archive(self.tar,self.digest)
        self.assertEqual(SHA,sha);self.assertEqual(FILES,files)
    def test_wrong_hash_traversal_symlink_mode_and_extra_files_refuse(self):
        with self.assertRaises(ValueError):self.mod.validate_archive(self.tar,'0'*64)
        for mutation in (lambda t:setattr(t,'name','../outside'),lambda t:setattr(t,'type',tarfile.SYMTYPE),lambda t:setattr(t,'mode',0o777)):
            digest=archive(self.tar,change=mutation)
            with self.assertRaises(ValueError):self.mod.validate_archive(self.tar,digest)
        files={**FILES,'unexpected.py':b'never executed'};digest=archive(self.tar,files)
        with self.assertRaises(ValueError):self.mod.validate_archive(self.tar,digest)
    def test_private_write_refuses_links_and_preserves_mode(self):
        secret=self.root/'token';self.mod.write_file(secret,b'PRIVATE_CANARY',0o600)
        self.assertEqual(0o600,stat.S_IMODE(secret.stat().st_mode))
        link=self.root/'alias';link.symlink_to(secret)
        with self.assertRaises(ValueError):self.mod.write_file(link,b'other',0o600)
        self.assertEqual(b'PRIVATE_CANARY',secret.read_bytes())
    def test_install_is_offline_and_does_not_start_initial_service(self):
        host=self.mod.Host();calls=[]
        host.ensure_user=lambda name:calls.append(('user',name))
        host.run=lambda args:calls.append(tuple(args)) or ''
        host.active=lambda name:False
        installer=self.mod.Installation(self.root/'host',host)
        installer.install(self.tar,self.digest)
        self.assertEqual(SHA,installer.current().name)
        self.assertTrue((installer.current()/'receiver.pyz').is_file())
        self.assertFalse(any('start' in c or 'enable' in c for c in calls))
        self.assertFalse((self.root/'host/etc/ustar-connect/telegram.token').exists())
    def test_unmanaged_unit_rejected_before_any_host_mutation(self):
        host=self.mod.Host();calls=[];host.ensure_user=lambda name:calls.append(name);host.active=lambda name:False
        unit=self.root/'host/etc/systemd/system/ustar-connect-receiver.service';unit.parent.mkdir(parents=True);unit.write_text('unmanaged')
        installer=self.mod.Installation(self.root/'host',host)
        with self.assertRaises(ValueError):installer.install(self.tar,self.digest)
        self.assertEqual([],calls);self.assertEqual('unmanaged',unit.read_text())
    def test_failed_unit_reload_restores_original_pointer_and_units(self):
        host=self.mod.Host();host.ensure_user=lambda name:None;host.active=lambda name:False
        host.run=lambda args:''
        installer=self.mod.Installation(self.root/'host',host);installer.install(self.tar,self.digest)
        before=installer.current()
        files=dict(FILES);second='2'*40;files['SOURCE_COMMIT']=(second+'\n').encode()
        # Package helper accepts explicit source from SOURCE_COMMIT in the real packager.
        with tarfile.open(self.tar,'w:gz') as t:
            manifest={'schema':1,'source_commit':second,'files':{k:hashlib.sha256(v).hexdigest() for k,v in files.items()}}
            for n,b in {**files,'manifest.json':json.dumps(manifest).encode()}.items():
                e=tarfile.TarInfo(n);e.size=len(b);e.mode=0o644;t.addfile(e,io.BytesIO(b))
        def failing(args):
            if args==['systemctl','daemon-reload']:raise RuntimeError('synthetic unit reload failed')
            return ''
        host.run=failing
        with self.assertRaises(RuntimeError):installer.install(self.tar,hashlib.sha256(self.tar.read_bytes()).hexdigest())
        self.assertEqual(before,installer.current())
        self.assertEqual(FILES['units/ustar-connect-receiver.service'],(self.root/'host/etc/systemd/system/ustar-connect-receiver.service').read_bytes())
    def test_start_without_private_configuration_cannot_succeed_on_skipped_unit(self):
        host=self.mod.Host();calls=[];host.run=lambda args:calls.append(args) or ''
        installer=self.mod.Installation(self.root/'host',host)
        with self.assertRaises(ValueError):installer.start()
        self.assertEqual([],calls)
    def test_existing_runtime_unit_override_refused_before_host_mutations(self):
        host=self.mod.Host();calls=[];host.ensure_user=lambda name:calls.append(name);host.active=lambda name:False
        path=self.root/'host/run/systemd/system/ustar-connect-receiver.service.d';path.mkdir(parents=True)
        (path/'override.conf').write_text('[Service]\nUser=root\n')
        with self.assertRaises(ValueError):self.mod.Installation(self.root/'host',host).install(self.tar,self.digest)
        self.assertEqual([],calls)

    def test_nonroot_validation_trusts_root_owned_ancestors(self):
        with patch.object(self.mod.os,'geteuid',return_value=12345):
            self.assertEqual(Path('/'),self.mod.safe(Path('/')))
    def test_install_sets_directory_modes_independently_of_caller_umask(self):
        host=self.mod.Host();host.ensure_user=lambda name:None;host.active=lambda name:False;host.run=lambda args:''
        (self.root/'host/opt').mkdir(parents=True)
        (self.root/'host/etc/systemd/system').mkdir(parents=True)
        previous=os.umask(0o002)
        try:
            installer=self.mod.Installation(self.root/'host',host);installer.install(self.tar,self.digest)
        finally:os.umask(previous)
        self.assertEqual(0o755,stat.S_IMODE((installer.current()/'units').stat().st_mode))
