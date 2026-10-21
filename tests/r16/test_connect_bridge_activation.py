"""Coordinated deployment on real files; systemd/provider and Docker are modeled."""
import importlib.util,io,json,os,sys,tarfile,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from tests.r16 import test_academy_https as tls
from tests.r16 import test_connect_deploy as receiver
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
import connect_private_bridge as bridge
spec=importlib.util.spec_from_file_location('connect_install',ROOT/'connect-worker/deploy/install.py')
installer=importlib.util.module_from_spec(spec);spec.loader.exec_module(installer)
sys.modules['connect_install']=installer
FILE=ROOT/'scripts/infra/connect_bridge_activation.py'
if FILE.exists():
    spec=importlib.util.spec_from_file_location('activation',FILE)
    x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x)
else:x=SimpleNamespace()

class Activation(unittest.TestCase):
    def setUp(self):
        tls.Transaction.setUp(self)
        self.manager.cutover()
        self.active=True;self.calls=[];self.fail=''
        h=installer.Host();h.ensure_user=lambda _:None
        h.active=lambda unit: self.active if unit=='ustar-connect-receiver.service' else False
        def run(args):
            self.calls.append(args)
            if args[:2]==['systemctl','stop']:self.active=False
            if args[:2]==['systemctl','start']:
                if args[-1]=='ustar-connect-probe.service' and self.fail=='probe':
                    self.fail='';raise RuntimeError('scope_probe_failed')
                if args[-1]=='ustar-connect-receiver.service':self.active=True
            return ''
        h.run=run
        self.inst=installer.Installation(self.root,h)
        self.archive=self.root/'receiver.tar.gz';self.digest=receiver.archive(self.archive)
        self.inst.install(self.archive,self.digest)
        values={**receiver.FILES,'SOURCE_COMMIT':(('2'*40)+'\n').encode(),'receiver.pyz':b'new receiver'}
        manifest={'schema':1,'source_commit':'2'*40,'files':{k:tls.x.sha(v) for k,v in values.items()}}
        with tarfile.open(self.archive,'w:gz') as tar:
            for name,raw in {**values,'manifest.json':json.dumps(manifest).encode()}.items():
                info=tarfile.TarInfo(name);info.size=len(raw);info.mode=0o644
                tar.addfile(info,io.BytesIO(raw))
        self.digest=tls.x.sha(self.archive.read_bytes())
        self.active=False;self.inst.configure('123456:'+'x'*40);self.active=True
        self.journal=self.root/'var/lib/ustar-connect-receiver/state.sqlite3'
        self.journal.parent.mkdir(parents=True);self.journal.write_bytes(b'LIVE_CURSOR_CANARY')
        self.before={str(p):p.read_bytes() for p in [self.p.old.config,self.p.tool,self.inst.config/'telegram.token',
            self.inst.config/'receiver.json',self.inst.config/'probe.json',self.journal]}
        self.ref={'schema':1,'tool_commit':'2'*40,'payload_commit':bridge.PAYLOAD,'files':{}}
        for name in bridge.SECURITY_FILES:
            p=self.p.old.config.parent/'public'/name;p.parent.mkdir(parents=True,exist_ok=True)
            p.write_bytes(b'fixed');self.ref['files'][name]=tls.x.sha(b'fixed')
        self.reader=bridge.Readiness(self.ref,self.manager,self.inst,
            SimpleNamespace(gateway=lambda rt:bridge.PEER,port_free=lambda:True),self.root,uid=os.geteuid(),lib=tls.old.x)
        self.addCleanup(patch.stopall)
        patch.object(bridge,'LEGACY_HTTPS_SHA',tls.x.sha(self.p.tool.read_bytes())).start()
        if hasattr(x,'WEB_GID') and os.environ.get('USTAR_REQUIRE_ACTIVATION_OWNER')!='1':
            patch.object(x,'WEB_GID',os.getgid()).start()
        if os.environ.get('USTAR_REQUIRE_ACTIVATION_OWNER')=='1':
            os.chown(self.p.old.config,33,0)
        self.calls.clear()
    def engine(self):
        self.assertTrue(hasattr(x,'Deployment'),'coordinated bridge deployment missing')
        return x.Deployment(self.reader,b'NEW_HTTPS_TOOL',self.archive,self.digest,progress=lambda _:None)
    def test_activation_preserves_token_journal_and_starts_only_after_signed_probe(self):
        e=self.engine();result=e.activate()
        self.assertEqual('bridge_active',result['phase']);self.assertTrue(self.active)
        self.assertTrue(self.manager.load()['bridge_enabled'])
        self.assertIn(bridge.MARKER,self.p.old.config.read_bytes())
        value=json.loads((self.inst.config/'receiver.json').read_bytes())
        self.assertTrue(value['linking_enabled']);self.assertFalse(value['onboarding_enabled'])
        for p in (self.inst.config/'telegram.token',self.journal):self.assertEqual(self.before[str(p)],p.read_bytes())
        starts=[c[-1] for c in self.calls if c[:2]==['systemctl','start']]
        self.assertEqual(['ustar-connect-probe.service','ustar-connect-receiver.service'],starts)
        self.assertNotIn('secret',json.dumps(result));self.assertEqual('bridge_active',e.activate()['phase'])
    def test_probe_failure_restores_application_receiver_and_https_without_data_restore(self):
        e=self.engine();self.fail='probe'
        with self.assertRaises(RuntimeError):e.activate()
        self.assertEqual('bridge_inactive',e.status()['phase']);self.assertTrue(self.active)
        for p,raw in self.before.items():self.assertEqual(raw,Path(p).read_bytes())
        self.assertFalse(self.manager.load().get('bridge_enabled',False))
        self.assertFalse((self.inst.config/'bridge.key').exists())
    def test_deactivate_after_certificate_renewal_preserves_renewal_and_business_data(self):
        e=self.engine();e.activate();self.host.due_now=True;self.manager.renew()
        self.journal.write_bytes(b'NEW_LIVE_CURSOR')
        self.assertEqual('bridge_inactive',e.deactivate()['phase'])
        self.assertEqual(b'NEW_LIVE_CURSOR',self.journal.read_bytes())
        self.assertEqual('active',self.manager.status()['phase'])
        self.assertEqual(self.before[str(self.p.old.config)],self.p.old.config.read_bytes())
    def test_unknown_config_edit_blocks_deactivation_before_overwrite(self):
        e=self.engine();e.activate();self.p.old.config.write_bytes(b'ADMIN_CHANGE')
        with self.assertRaises(Exception):e.deactivate()
        self.assertEqual(b'ADMIN_CHANGE',self.p.old.config.read_bytes())
    def test_interruption_at_every_durable_phase_recovers_without_reverting_cursor(self):
        e=self.engine()
        for phase in ('tool_handoff','private_TLS','private_files','config_handoff','receiver_switch','scope_verified'):
            with self.subTest(phase=phase):
                def interrupt(name):
                    if name==phase:raise KeyboardInterrupt()
                e.progress=interrupt
                with self.assertRaises(KeyboardInterrupt):e.activate()
                e.progress=lambda _:None
                self.assertEqual('bridge_inactive',e.status()['phase'])
                self.assertEqual(b'LIVE_CURSOR_CANARY',self.journal.read_bytes())
    def test_partial_config_write_and_lost_https_state_ack_recover(self):
        e=self.engine();original=self.manager.save;raised=False
        def lost(s):
            nonlocal raised
            original(s)
            if bridge.MARKER in self.p.old.config.read_bytes() and not raised:
                raised=True;raise OSError('lost_config_handoff_ack')
        with patch.object(self.manager,'save',side_effect=lost):
            with self.assertRaises(OSError):e.activate()
        self.assertEqual(self.before[str(self.p.old.config)],self.p.old.config.read_bytes())
        self.assertEqual('bridge_inactive',e.status()['phase'])
    def test_receiver_stop_failure_prevents_all_application_mutation(self):
        e=self.engine();original=self.inst.host.run
        def fail(args):
            if args[:2]==['systemctl','stop']:raise RuntimeError('cannot_stop_receiver')
            return original(args)
        with patch.object(self.inst.host,'run',side_effect=fail):
            with self.assertRaises(RuntimeError):e.activate()
        for p,raw in self.before.items():self.assertEqual(raw,Path(p).read_bytes())
    def test_process_loss_requires_recovery_and_preserves_current_cursor(self):
        e=self.engine()
        for phase in ('tool_handoff','private_TLS','private_files','config_handoff','receiver_switch','scope_verified'):
            with self.subTest(phase=phase):
                def interrupt(name):
                    if name==phase:raise KeyboardInterrupt()
                e.progress=interrupt
                with patch.object(e,'deactivate',side_effect=KeyboardInterrupt('process_lost')):
                    with self.assertRaises(KeyboardInterrupt):e.activate()
                self.journal.write_bytes(b'CURSOR_AFTER_PROCESS_LOSS')
                fresh=self.engine()
                self.assertEqual('bridge_inactive',fresh.deactivate()['phase'])
                self.assertEqual(b'CURSOR_AFTER_PROCESS_LOSS',self.journal.read_bytes())
    def test_active_release_pointer_drift_cannot_report_success(self):
        e=self.engine();e.activate()
        # A syntactically valid pointer to another immutable release is still drift.
        p=self.i if hasattr(self,'i') else self.inst
        release=p.prefix/'releases'/('1'*40)
        p.switch(release)
        with self.assertRaises(Exception):e.status()
    def test_unknown_apache_change_blocks_recovery_before_any_configuration_write(self):
        e=self.engine();e.activate();before=self.p.old.config.read_bytes()
        self.p.http.write_bytes(b'ADMIN_APACHE_CHANGE')
        with self.assertRaises(Exception):e.deactivate()
        self.assertEqual(before,self.p.old.config.read_bytes())
        self.assertEqual(b'ADMIN_APACHE_CHANGE',self.p.http.read_bytes())
    def test_plan_mode_does_not_load_host(self):
        self.assertTrue(hasattr(x,'main'),'activation CLI missing')
        self.assertEqual(0,x.main([]))

class Build(unittest.TestCase):
    def test_exact_bundle_is_repeatable_plan_only_and_rejects_tampering(self):
        import subprocess,zipfile
        module=ROOT/'scripts/infra/build_connect_bridge_activation.py'
        self.assertTrue(module.exists(),'activation builder missing')
        spec=importlib.util.spec_from_file_location('build_activation',module)
        b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
        with tempfile.TemporaryDirectory() as td:
            repo=Path(td)/'repo';subprocess.run(['git','clone','-q','--shared',str(ROOT),str(repo)],check=True)
            # Legacy artifacts retain their reviewed2000 application, not the new release.
            subprocess.run(['git','-C',str(repo),'checkout','--detach','-q','38d81ef8d273a3c5efc9ea10c35c7906968d78ba'],check=True)
            names=('connect_bridge_activation','build_connect_bridge_activation','connect_private_bridge')
            for name in names:(repo/('scripts/infra/'+name+'.py')).write_bytes((ROOT/('scripts/infra/'+name+'.py')).read_bytes())
            subprocess.run(['git','-C',str(repo),'add','scripts/infra'],check=True)
            subprocess.run(['git','-C',str(repo),'-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--allow-empty','-qm','activation fixture'],check=True)
            a=b.build('HEAD',Path(td)/'a',repo=repo);c=b.build('HEAD',Path(td)/'b',repo=repo)
            self.assertEqual(a.read_bytes(),c.read_bytes());x.bundle(a)
            result=subprocess.run([sys.executable,'-I',str(a)],check=True,capture_output=True,text=True)
            self.assertEqual({'phase':'plan_only','host_access':False},json.loads(result.stdout))
            with zipfile.ZipFile(a) as z:files={n:z.read(n) for n in z.namelist()}
            files['receiver.tar.gz']+=b'corruption'
            bad=Path(td)/'bad.pyz'
            with zipfile.ZipFile(bad,'w') as z:
                for name,raw in files.items():z.writestr(name,raw)
            with self.assertRaisesRegex(bridge.Refusal,'bundle_digest_changed'):x.bundle(bad)
