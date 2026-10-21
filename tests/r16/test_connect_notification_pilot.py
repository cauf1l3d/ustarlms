"""Pilot transaction on real files; host/provider modeled, no employee writes."""
import importlib.util,io,json,os,sys,tarfile,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from tests.r16 import test_connect_bridge_activation as base
ROOT=Path(__file__).resolve().parents[2]
FILE=ROOT/'scripts/infra/connect_notification_pilot.py'
if FILE.exists():
    spec=importlib.util.spec_from_file_location('notification_pilot',FILE)
    x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x)
else:x=SimpleNamespace()


class Pilot(unittest.TestCase):
    def setUp(self):
        self.assertTrue(hasattr(x,'Pilot'),'guarded notification pilot is missing')
        base.Activation.setUp(self)
        self.link=base.Activation.engine(self);self.link.activate()
        self.failure_phase='';del self.fail
        self.notify_active=False
        original=self.inst.host.run
        def active(name):
            return self.notify_active if name=='ustar-connect-notify.service' else self.active if name==base.x.SERVICE else False
        def run(args):
            if args==['systemctl','start',base.x.PROBE] and self.failure_phase=='probe':
                self.calls.append(args);self.failure_phase='';raise RuntimeError('scope_probe_failed')
            if args[:2] in (['systemctl','start'],['systemctl','stop']) and args[-1]=='ustar-connect-notify.service':
                self.calls.append(args)
                if args[1]=='start' and self.failure_phase=='notify':self.failure_phase='';raise RuntimeError('notification_probe_failed')
                self.notify_active=args[1]=='start';return ''
            return original(args)
        self.inst.host.active=active;self.inst.host.run=run
        self.policy=self.p.old.config.parent/'public/local/ustar/classes/connect/notification_delivery.php'
        self.policy.write_bytes(b'<?php // accepted policy\n');self.policy.chmod(0o640)
        if os.environ.get('USTAR_REQUIRE_ACTIVATION_OWNER')=='1':os.chown(self.policy,33,0)
        os.setxattr(self.policy,'user.pilot_fixture',b'preserve policy attributes')
        self.policy_after=b'<?php // reviewed pilot fence\n'
        self.reference={'schema':1,'tool_commit':'3'*40,'bridge_release':'2'*40,'policy':{'path':str(self.policy.relative_to(self.reader.root)),
            'before':base.tls.x.sha(self.policy.read_bytes()),'after':base.tls.x.sha(self.policy_after)}}
        values={**base.receiver.FILES,'SOURCE_COMMIT':(('3'*40)+'\n').encode(),'receiver.pyz':b'neutral start receiver'}
        manifest={'schema':1,'source_commit':'3'*40,'files':{k:base.tls.x.sha(v) for k,v in values.items()}}
        with tarfile.open(self.archive,'w:gz') as tar:
            for name,raw in {**values,'manifest.json':json.dumps(manifest).encode()}.items():
                info=tarfile.TarInfo(name);info.size=len(raw);info.mode=0o644;tar.addfile(info,io.BytesIO(raw))
        self.digest=base.tls.x.sha(self.archive.read_bytes())
        self.host=SimpleNamespace(lookup=lambda uid:{'userid':uid,'subject':'77777'},enabled=lambda:False)
        self.original={str(p):p.read_bytes() for p in [self.policy,self.link.state,
            self.link.base/'include.after',self.link.paths['include'],self.inst.config/'telegram.token',self.journal]}
        self.calls.clear()
    def engine(self):
        return x.Pilot(self.link,self.reference,self.archive,self.digest,self.policy_after,host=self.host,progress=lambda _:None)
    def test_service_can_read_staged_release_with_private_umask(self):
        op=self.engine();previous=os.umask(0o077)
        try:result=op.activate(12)
        finally:os.umask(previous)
        self.assertEqual('notification_pilot_active',result['phase'])
        release=self.inst.current()
        self.assertEqual(0o755,release.stat().st_mode&0o777)
        for name in ('receiver.pyz','notifications.pyz'):
            self.assertEqual(0o644,(release/name).stat().st_mode&0o777)
        if os.environ.get('USTAR_REQUIRE_ACTIVATION_OWNER')=='1':
            import subprocess
            result=subprocess.run(['/usr/bin/python3','-I','-c',
                "from pathlib import Path; assert Path('receiver.pyz').read_bytes() == b'neutral start receiver'; "
                "assert Path('notifications.pyz').read_bytes()"],cwd=release,user=33,group=33,extra_groups=[],
                capture_output=True,text=True,check=False)
            self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual(0o600,(self.inst.config/'telegram.token').stat().st_mode&0o777)
    def test_activation_fences_both_sides_preserves_cursor_and_link(self):
        op=self.engine();result=op.activate(12)
        self.assertEqual('notification_pilot_active',result['phase']);self.assertTrue(self.notify_active)
        self.assertEqual([12],result['pilot_userids']);self.assertFalse(result['historical_replay'])
        self.assertEqual(self.policy_after,self.policy.read_bytes())
        if os.environ.get('USTAR_REQUIRE_ACTIVATION_OWNER')=='1':
            self.assertEqual((33,0,0o640),(self.policy.stat().st_uid,self.policy.stat().st_gid,self.policy.stat().st_mode&0o777))
        self.assertEqual(b'preserve policy attributes',os.getxattr(self.policy,'user.pilot_fixture'))
        settings=self.link.paths['include'].read_bytes()
        self.assertIn(b"['notification.lease','notification.begin','notification.finish']",settings)
        self.assertIn(b'ustar_connect_notify_userids=[12]',settings)
        cfg=json.loads((self.inst.config/'notifications.json').read_bytes())
        self.assertEqual(['77777'],cfg['notification_subjects']);self.assertFalse(cfg['onboarding_enabled'])
        self.assertEqual('notification_pilot_active',op.activate(12)['phase'])
        with self.assertRaises(Exception):op.activate(13)
        for p in (self.inst.config/'telegram.token',self.journal):self.assertEqual(self.original[str(p)],p.read_bytes())
        self.assertNotIn('77777',json.dumps(result));self.assertNotIn('secret',json.dumps(result))
    def test_disable_keeps_new_journal_and_existing_link_active(self):
        op=self.engine();op.activate(12);self.journal.write_bytes(b'NEW_CURSOR')
        self.assertEqual('notification_pilot_inactive',op.deactivate()['phase'])
        self.assertFalse(self.notify_active);self.assertTrue(self.active)
        self.assertEqual('bridge_active',self.link.status()['phase'])
        self.assertEqual(b'NEW_CURSOR',self.journal.read_bytes())
        for p in (self.policy,self.link.state,self.link.base/'include.after',self.link.paths['include']):
            self.assertEqual(self.original[str(p)],p.read_bytes())
    def test_failure_or_process_loss_at_each_write_recovers_without_data_restore(self):
        for phase in ('receiver_stopped','policy','private_files','link_handoff','receiver_switch','sender_started'):
            with self.subTest(phase=phase):
                op=self.engine()
                def lost(name):
                    if name==phase:raise SystemExit('simulated killed process')
                op.progress=lost
                with self.assertRaises(SystemExit):op.activate(12)
                self.assertEqual('notification_pilot_inactive',self.engine().deactivate()['phase'])
                self.assertEqual('bridge_active',self.link.status()['phase'])
                self.assertEqual(self.original[str(self.journal)],self.journal.read_bytes())
    def test_drift_refuses_before_overwriting_admin_file(self):
        op=self.engine();op.activate(12);self.policy.write_bytes(b'ADMIN_CHANGE')
        with self.assertRaises(Exception):op.deactivate()
        self.assertEqual(b'ADMIN_CHANGE',self.policy.read_bytes())
    def test_invalid_pilot_or_probe_failure_does_not_enable_audience(self):
        self.failure_phase='probe'
        with self.assertRaises(Exception):self.engine().activate(12)
        self.assertTrue(self.active);self.assertFalse(self.notify_active)
        self.assertNotIn(['systemctl','stop',base.x.SERVICE],self.calls)
        self.assertFalse((self.inst.config/'notifications.json').exists())
        self.host.lookup=lambda uid:(_ for _ in ()).throw(ValueError('pilot_not_eligible'))
        with self.assertRaises(Exception):self.engine().activate(12)
        self.assertFalse((self.inst.config/'notifications.json').exists());self.assertFalse(self.notify_active)
        self.host.lookup=lambda uid:{'userid':uid,'subject':'77777'};self.failure_phase='notify'
        with self.assertRaises(Exception):self.engine().activate(12)
        self.assertEqual('bridge_active',self.link.status()['phase']);self.assertFalse(self.notify_active)
    def test_unreviewed_transport_or_receiver_release_refuses_before_writes(self):
        self.reference['bridge_release']='9'*40
        with self.assertRaisesRegex(base.bridge.Refusal,'accepted_link_release_required'):self.engine().activate(12)
        self.reference['bridge_release']='2'*40
        path=self.p.old.config.parent/'public'/base.bridge.SECURITY_FILES[0]
        path.write_bytes(b'UNREVIEWED_APPLICATION_CHANGE')
        with self.assertRaisesRegex(base.bridge.Refusal,'transport_source_drift'):self.engine().activate(12)
        self.assertFalse((self.inst.config/'notifications.json').exists());self.assertFalse(self.notify_active)


class Plan(unittest.TestCase):
    def test_default_mode_has_no_host_access(self):
        self.assertTrue(hasattr(x,'main'),'pilot CLI is missing')
        self.assertEqual(0,x.main([]))

class Build(unittest.TestCase):
    def test_exact_bundle_is_deterministic_and_tampering_refuses(self):
        import subprocess,zipfile
        module=ROOT/'scripts/infra/build_connect_notification_pilot.py'
        self.assertTrue(module.exists(),'pilot package builder is missing')
        spec=importlib.util.spec_from_file_location('pilot_builder',module)
        builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);repo=root/'repo'
            subprocess.run(['git','clone','-q','--shared',str(ROOT),str(repo)],check=True)
            # Legacy artifacts retain their reviewed2000 application, not the new release.
            subprocess.run(['git','-C',str(repo),'checkout','--detach','-q','38d81ef8d273a3c5efc9ea10c35c7906968d78ba'],check=True)
            names=('connect_notification_pilot','build_connect_notification_pilot','connect_bridge_activation','connect_private_bridge')
            for name in names:(repo/('scripts/infra/'+name+'.py')).write_bytes((ROOT/('scripts/infra/'+name+'.py')).read_bytes())
            path=base.bridge.NOTIFICATION_POLICY;(repo/path).write_bytes((ROOT/path).read_bytes())
            subprocess.run(['git','-C',str(repo),'add','.'],check=True)
            subprocess.run(['git','-C',str(repo),'-c','user.name=Fixture','-c','user.email=fixture@example.invalid',
                'commit','--allow-empty','-qm','pilot fixture'],check=True)
            a=builder.build('HEAD',root/'a',repo=repo);b=builder.build('HEAD',root/'b',repo=repo)
            self.assertEqual(a.read_bytes(),b.read_bytes());x.bundle(a)
            result=subprocess.run([sys.executable,'-I',str(a)],check=True,capture_output=True,text=True)
            self.assertEqual({'phase':'plan_only','host_access':False},json.loads(result.stdout))
            with zipfile.ZipFile(a) as z:files={n:z.read(n) for n in z.namelist()}
            files['notification_delivery.php']+=b'tamper'
            bad=root/'tampered.pyz'
            with zipfile.ZipFile(bad,'w') as z:
                for n,raw in files.items():z.writestr(n,raw)
            with self.assertRaisesRegex(base.bridge.Refusal,'bundle_digest_changed'):x.bundle(bad)
