"""Real immutable files and Git bundle; host service/Docker/SQL responses are modeled."""
import contextlib
from datetime import datetime, timezone
import hashlib
import importlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT/'scripts/infra'), str(ROOT/'scripts')]


class ConnectDeliveryTests(unittest.TestCase):
    def setUp(self):
        self.m = importlib.import_module('connect_delivery_readiness')
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.now = datetime(2026, 10, 20, 17, 40, tzinfo=timezone.utc)
        self.calls = []
        self.wrapper = b'fixture cron source\n'
        self.tool = b'fixture HTTPS source\n'
        self.patchers = [patch.object(self.m, 'CRON_SHA', self.m.sha(self.wrapper)),
                         patch.object(self.m, 'HTTPS_SHA', self.m.sha(self.tool))]
        for p in self.patchers: p.start(); self.addCleanup(p.stop)
        def write(path, raw, private=False):
            p = self.root/path; p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(raw if isinstance(raw, bytes) else json.dumps(raw).encode())
            p.chmod(0o600 if private else 0o644); return p
        self.write = write
        self.owned = {'config':'opt/ustar/data/moodle/public/config.php',
            'http':'etc/apache2/sites-available/ustar.conf',
            'tls':'etc/apache2/sites-available/zzzz-ustar-academy-tls-probe.conf',
            'health':'srv/ustar-academy-tls-probe/__ustar_tls_probe__/ready.html'}
        files = {}
        for name, path in self.owned.items():
            raw = ('PRIVATE_CONFIG_CANARY' if name == 'config' else name).encode()
            file=write(path, raw); info=file.stat()
            files[name] = {'after':self.m.sha(raw),'uid':info.st_uid,'gid':info.st_gid,'mode':info.st_mode & 0o7777}
        write('usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py', self.tool, True)
        inc = write('etc/apache2/ustar-console-observability.conf', b'PRIVATE_INCLUDE_CANARY')
        unit_hashes = {}
        for unit in ('service', 'timer'):
            name = 'ustar-academy-cert-renew.' + unit
            write('etc/systemd/system/'+name, name.encode()); unit_hashes[name] = self.m.sha(name.encode())
        for name, target in [('ustar.conf', 'http'), ('zzzz-ustar-academy-tls-probe.conf', 'tls')]:
            link = self.root/'etc/apache2/sites-enabled'/name; link.parent.mkdir(parents=True, exist_ok=True)
            link.symlink_to(self.root/self.owned[target])
        self.https = {'schema':1,'phase':'active','tool_sha':self.m.sha(self.tool),
            'files':files,'unit_hashes':unit_hashes,'include_sha':self.m.sha(inc.read_bytes()),
            'private':'PRIVATE_STATE_CANARY'}
        write('var/lib/ustar-academy-https/state.json', self.https, True)
        write('usr/local/sbin/USTAR_CRON_SETUP_20261005.py', self.wrapper, True)
        write('etc/cron.d/ustar_moodle', self.m.CRON_TEXT)
        self.installation = {'versions': {'local_ustar':'2026101905','theme_ustar':'2026100202'},
            'script_sha256':self.m.sha(self.wrapper),'private':'PRIVATE_INSTALL_CANARY'}
        write('var/lib/ustar-cron/installation.json', self.installation, True)
        self.receipt = {'trigger':'scheduled','cron_exit_code':0,'finished_utc':'2026-10-20T17:37:23+00:00',
            'after':{'scheduled_overdue_10m':18,'scheduled_failed':2,'ustar_failed':0},'private':'PRIVATE_RECEIPT_CANARY'}
        write('var/lib/ustar-cron/last-run.json', self.receipt, True)
        self.network = {'running':True,'ports':{'80/tcp':[{'HostIp':'127.0.0.1','HostPort':'8082'}]},
            'networks':{'private-net':{'Gateway':'172.18.0.1','IPAddress':'172.18.0.2'}}}
        self.busy = ''
        self.units = {'cron.service':'active','ustar-academy-cert-renew.timer':'active',
            'ustar-connect-receiver.service':'active','ustar-connect-notify.service':'inactive'}

    def runner(self, args, input_text=None, timeout=30):
        self.calls.append(args)
        if args[:2] == ['docker','inspect']: return json.dumps(self.network)
        if args[:2] == ['ss','-H']: return self.busy
        if args[:2] == ['systemctl','show']: return self.units[args[-1]]+'\n'
        raise AssertionError('Unexpected host command')

    def contracts(self):
        return self.m.contracts(root=self.root, runner=self.runner, now=self.now, uid=os.getuid())

    def test_good_contracts_are_readonly_and_private_fields_never_exported(self):
        before = {str(p):p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        value = self.contracts()
        self.assertEqual('measured', value['status'])
        self.assertEqual(['172.18.0.1'], value['transport']['gateway_candidates'])
        self.assertFalse(value['transport']['effective_peer_proven'])
        self.assertEqual(18, value['cron']['residual_overdue'])
        self.assertNotIn('PRIVATE_', json.dumps(value))
        self.assertEqual(before, {str(p):p.read_bytes() for p in self.root.rglob('*') if p.is_file()})
        self.assertTrue(all(a[:2] in (['docker','inspect'],['ss','-H'],['systemctl','show']) for a in self.calls))

    def test_https_owned_drift_and_renewal_transition_are_fixed_refusals(self):
        for path in self.owned.values():
            p=self.root/path; old=p.read_bytes(); p.write_bytes(b'PRIVATE_DRIFT')
            with self.subTest(path=path):
                value=self.contracts(); self.assertEqual('review_required',value['status'])
                self.assertEqual('https_owned_file_changed',value['https']['reason'])
            p.write_bytes(old)
        self.https['phase']='renewing'; self.write('var/lib/ustar-academy-https/state.json',self.https,True)
        self.assertEqual('https_not_active',self.contracts()['https']['reason'])

    def test_config_metadata_must_match_accepted_https_capture(self):
        self.https['files']['config']['uid'] += 1
        self.write('var/lib/ustar-academy-https/state.json',self.https,True)
        self.assertEqual('https_config_metadata_changed',self.contracts()['https'].get('reason'))

    @unittest.skipUnless(os.getuid()==0 and os.environ.get('USTAR_CONNECT_OWNERSHIP_FIXTURE')=='1','actual UID33 ownership runs only on disposable privileged CI')
    def test_accepted_uid33_config_and_code_parent_do_not_require_chown(self):
        p=self.root/self.owned['config'];os.chown(p,33,0);os.chown(p.parent,33,0)
        self.https['files']['config'].update(uid=33,gid=0)
        self.write('var/lib/ustar-academy-https/state.json',self.https,True)
        self.assertEqual('measured',self.contracts()['https']['status'])
        self.assertEqual((33,0),(p.stat().st_uid,p.stat().st_gid))

    def test_old_cron_or_unpaired_installation_cannot_look_ready(self):
        p=self.root/'usr/local/sbin/USTAR_CRON_SETUP_20261005.py'; p.write_bytes(b'legacy')
        self.assertEqual('cron_source_changed',self.contracts()['cron']['reason'])
        p.write_bytes(self.wrapper); self.installation['versions']['local_ustar']='2026102000'
        self.write('var/lib/ustar-cron/installation.json',self.installation,True)
        self.assertEqual('cron_installation_mismatch',self.contracts()['cron']['reason'])

    def test_stale_future_or_failed_scheduled_receipt_blocks_readiness(self):
        for change in ({'finished_utc':'2026-10-20T17:01:00Z'}, {'finished_utc':'2026-10-20T18:01:00Z'},
                       {'cron_exit_code':1}, {'trigger':'manual'}):
            receipt={**self.receipt,**change}; self.write('var/lib/ustar-cron/last-run.json',receipt,True)
            with self.subTest(change=change): self.assertEqual('review_required',self.contracts()['cron']['status'])

    def test_symlink_world_readable_private_state_and_duplicate_json_refused(self):
        p=self.root/'var/lib/ustar-academy-https/state.json'; p.chmod(0o644)
        self.assertEqual('unsafe_control_input',self.contracts()['https']['reason'])
        p.chmod(0o600); p.unlink(); p.symlink_to(self.root/'var/lib/ustar-cron/installation.json')
        self.assertEqual('unsafe_control_input',self.contracts()['https']['reason'])
        p.unlink(); p.write_bytes(b'{"schema":1,"schema":1}'); p.chmod(0o600)
        self.assertEqual('invalid_control_json',self.contracts()['https']['reason'])

    def test_existing_port_wrong_backend_and_missing_gateway_block_transport(self):
        self.busy='LISTEN 0 4096 0.0.0.0:18443 0.0.0.0:*\n'
        self.assertEqual('bridge_port_in_use',self.contracts()['transport']['reason'])
        self.busy=''; self.network['ports']['80/tcp'][0]['HostIp']='0.0.0.0'
        self.assertEqual('backend_binding_changed',self.contracts()['transport']['reason'])
        self.network['ports']['80/tcp'][0]['HostIp']='127.0.0.1'; self.network['networks']={}
        self.assertEqual('gateway_unavailable',self.contracts()['transport']['reason'])

    def test_unit_failure_or_notify_already_active_needs_review(self):
        self.units['ustar-academy-cert-renew.timer']='inactive'
        self.assertEqual('managed_unit_state_changed',self.contracts()['services']['reason'])
        self.units['ustar-academy-cert-renew.timer']='active'; self.units['ustar-connect-notify.service']='active'
        self.assertEqual('managed_unit_state_changed',self.contracts()['services']['reason'])

    def test_unexpected_command_error_is_redacted(self):
        with patch.object(self,'runner',side_effect=RuntimeError('PRIVATE_PROVIDER_CANARY')):
            result=self.contracts()
        self.assertEqual('review_required',result['status']);self.assertNotIn('PRIVATE_',json.dumps(result))

    def test_check_keeps_baseline_drift_and_contracts_separate_and_disallows_activation(self):
        reference={'baseline_commit':self.m.BASELINE,'baseline':{'versions':{'local_ustar':2026101905,'theme_ustar':2026100202}},
            'candidate':{'versions':{'local_ustar':2026102000,'theme_ustar':2026100202}}}
        baseline={'status':'baseline_measured','source_commit':'a'*40,'code_config_db_or_service_changes':False}
        with patch.object(self.m.readiness,'check',return_value=baseline),patch.object(self.m,'contracts',return_value={'status':'measured'}):
            result=self.m.check(reference)
            self.assertEqual('delivery_baseline_measured',result['status']);self.assertFalse(result['deployment_approved'])
            self.assertFalse(result['php_or_moodle_bootstrap']);self.assertIn('coordinated_cron_target_update',result['remaining_gates'])
            baseline['status']='review_required'; self.assertEqual('review_required',self.m.check(reference)['status'])

    def test_plan_never_reads_host_and_exception_is_redacted(self):
        with patch.object(self.m.readiness,'load_reference',return_value={'source_commit':'a'*40}),patch.object(self.m,'check',side_effect=AssertionError('host read')),contextlib.redirect_stdout(io.StringIO()) as out:
            self.assertEqual(0,self.m.main([]))
        self.assertEqual('plan_only',json.loads(out.getvalue())['status'])

    def test_deadline_is_not_swallowed_by_individual_contract_collection(self):
        with patch.object(self.m,'https_contract',side_effect=self.m.Deadline()):
            with self.assertRaises(self.m.Deadline):self.contracts()

    def test_profile_builder_pins_accepted_baseline_and_is_exact_git_deterministic(self):
        b=importlib.import_module('build_connect_readiness')
        repo=self.root/'repo';repo.mkdir()
        def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL).decode().strip()
        def commit(msg):
            git('add','.');git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-m',msg)
            return git('rev-parse','HEAD')
        git('init')
        for scope,version in [('local/ustar',2026101905),('theme/ustar',2026100202)]:
            p=repo/'moodle'/scope/'version.php';p.parent.mkdir(parents=True);p.write_text(f'<?php\n$plugin->version = {version};\n')
        p=repo/'moodle/local/ustar/db/install.xml';p.parent.mkdir();p.write_text('<XMLDB><TABLES><TABLE NAME="local_ustar_test"/></TABLES></XMLDB>')
        base=commit('owner-installed source')
        names=['scripts/infra/connect_readiness.py','scripts/infra/connect_delivery_readiness.py',
               'scripts/infra/cron_release_update.py','scripts/production_manifest.py','scripts/infra/production_patch_readiness.py']
        for name in names:
            p=repo/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes())
        p=repo/'moodle/local/ustar/version.php';p.write_text('<?php\n$plugin->version = 2026102000;\n'); head=commit('candidate')
        with patch.object(b,'CHAT_LINK_BASELINE',base),contextlib.redirect_stdout(io.StringIO()):
            target=b.build(head,self.root/'out',repo=repo,profile='chat-link');raw=target.read_bytes()
            (repo/names[1]).write_text('PRIVATE_DIRTY_CANARY')
            self.assertEqual(raw,b.build(head,self.root/'out',repo=repo,profile='chat-link').read_bytes())
        with zipfile.ZipFile(target) as archive:
            ref=json.loads(archive.read('connect_preflight_data/reference.json'))
            self.assertEqual(base,ref['baseline_commit']);self.assertEqual(2026101905,ref['baseline']['versions']['local_ustar'])
            self.assertIn(b'connect_delivery_readiness',archive.read('__main__.py'))
            self.assertNotIn(b'PRIVATE_DIRTY_CANARY',archive.read('connect_delivery_readiness.py'))
        result=subprocess.run([sys.executable,'-I',str(target)],capture_output=True,text=True,timeout=10)
        self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('plan_only',json.loads(result.stdout)['status'])
