import importlib.util
from pathlib import Path
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[2]
FILE = ROOT / 'scripts/infra/academy_https.py'
if FILE.exists():
    spec=importlib.util.spec_from_file_location('academy_https',FILE)
    x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x)
else:x=SimpleNamespace()

CONFIG=b"<?php\n$prepared_origin='http://ustar.local';\n$CFG->wwwroot = $prepared_origin;\nrequire_once(__DIR__ . '/lib/setup.php');\n"
HTTP='''<VirtualHost *:80>
 ServerName ustar.local
 ProxyPreserveHost On
 ProxyPass / http://127.0.0.1:8082/
 ProxyPassReverse / http://127.0.0.1:8082/
 Include /etc/apache2/ustar-console-observability.conf
</VirtualHost>
'''

class Transform(unittest.TestCase):
    def test_bridge_profile_binds_only_loopback_denies_public_path_and_preserves_include(self):
        paths=SimpleNamespace(health=Path('/srv/probe/ready.html'))
        public,redirect=x.proxy_and_redirect(HTTP.encode(),paths,Path('/private/cert.crt'),Path('/private/key.key'),bridge_port=18443)
        for raw in (public,redirect):
            self.assertIn(b'connect_bridge[.]php',raw)
            self.assertIn(b'Require all denied',raw)
            self.assertIn(b'Include /etc/apache2/ustar-console-observability.conf',raw)
        self.assertIn(b'Listen 127.0.0.1:18443',public)
        self.assertIn(b'<VirtualHost 127.0.0.1:18443>',public)
        self.assertIn(b'http://127.0.0.1:8082/local/ustar/connect_bridge.php',public)
        self.assertIn(b'<LimitExcept POST>',public)
        self.assertNotIn(b'Listen 0.0.0.0',public)
        self.assertNotIn(b'X-Forwarded-Proto',public)
        with self.assertRaises(x.Refusal):
            x.proxy_and_redirect(HTTP.encode(),paths,Path('/private/cert.crt'),Path('/private/key.key'),bridge_port='18443\nListen 0.0.0.0:9443')
    def test_indirect_original_config_is_preserved_and_overridden_before_bootstrap(self):
        result=x.patch_config(CONFIG)
        self.assertIn(b'$CFG->wwwroot = $prepared_origin;',result)
        self.assertIn(b"$CFG->wwwroot = 'https://ustar.local';",result)
        self.assertLess(result.index(b'$CFG->sslproxy = true;'),result.index(b'require_once'))
        self.assertIn(b'$CFG->cookiesecure = true;',result)
        for bad in (result,CONFIG+b'echo "after";\n',CONFIG.replace(b'require_once',b'// require_once')):
            with self.assertRaises(x.Refusal):x.patch_config(bad)

    def test_proxy_preserves_opaque_include_and_redirect_removes_HTTP_backend(self):
        paths=SimpleNamespace(health=Path('/srv/probe/ready.html'))
        proxy,redirect=x.proxy_and_redirect(HTTP.encode(),paths,Path('/private/cert.crt'),Path('/private/key.key'))
        self.assertIn(b'Include /etc/apache2/ustar-console-observability.conf',proxy)
        self.assertIn(b'Include /etc/apache2/ustar-console-observability.conf',redirect)
        self.assertIn(b'ProxyPass / http://127.0.0.1:8082/',proxy)
        self.assertNotIn(b'ProxyPass /',redirect)
        self.assertIn(b'Redirect 302 / https://ustar.local/',redirect)
        self.assertIn(b'<VirtualHost *:443>',proxy)
        self.assertNotIn(b'Listen ',proxy)
        for bad in (HTTP+HTTP,HTTP.replace('ustar.local','other.invalid'),HTTP+'Define outside true\n'):
            with self.assertRaises(x.Refusal):x.proxy_and_redirect(bad.encode(),paths,Path('/a.crt'),Path('/a.key'))

import contextlib
import hashlib
import io
import json
import os
import tempfile
from unittest.mock import patch
from tests.r16 import test_academy_tls_probe as old

class Host(old.FakeHost):
    HTTP_PORT,HTTPS_PORT,BACKEND_PORT,BRIDGE_PORT=80,443,8082,18443
    def bridge_available(self):pass
    def __init__(self,p,http_enabled):
        super().__init__(p);self.http_enabled=http_enabled;self.new=False;self.timer=False
        self.fail='';self.due_now=False
    def test_config(self):
        self.calls.append('configtest')
        text=old.BASE.replace('/etc/apache2/sites-enabled/academy.conf',str(self.http_enabled))
        if self.p.enabled.is_symlink():text+=f' port 443 namevhost ustar.local ({self.p.enabled}:1)\n'
        if self.p.site.exists() and b'Listen 127.0.0.1:18443' in self.p.site.read_bytes():
            text+=f'127.0.0.1:18443 localhost ({self.p.enabled}:50)\n'
        if self.fail=='config':self.fail='';raise x.Refusal('bad_config')
        return old.x.parse_vhosts(text)
    def certificate(self,ca,key,folder):
        (folder/'server.key').write_text('KEY');(folder/'server.key').chmod(0o600)
        raw=folder.name.encode();(folder/'server.crt').write_bytes(raw);(folder/'server.crt').chmod(0o600)
        return {'fingerprint':x.sha(raw),'not_after':'fixture'}
    def tls_check(self,ca,body):
        if self.fail=='TLS':self.fail='';raise x.Refusal('tls_failed')
        import re
        cert=re.search(r'SSLCertificateFile "([^"]+)"',self.p.site.read_text())[1]
        if self.p.health.read_bytes()!=body:raise x.Refusal('body_mismatch')
        return x.sha(Path(cert).read_bytes())
    def reload(self):
        self.calls.append('reload');self.loaded=self.test_config()
        if self.fail=='reload':self.fail='';raise x.Refusal('lost_reload_ack')
    def runtime(self,path):return {'id':'fixture','config':str(path)}
    def metadata(self,rt):
        enabled=x.MARKER in Path(rt['config']).read_bytes()
        return {'url':x.ORIGIN if enabled else 'http://ustar.local','sslproxy':enabled,'cookiesecure':enabled}
    def lint(self,rt,raw):
        if self.fail=='lint':raise x.Refusal('lint')
    def purge(self,rt):
        self.calls.append('purge')
        if self.fail=='purge':self.fail='';raise x.Refusal('purge_failed')
    def unit_available(self):return not self.timer
    def timer_start(self):
        self.timer=True
        if self.fail=='timer':self.fail='';raise x.Refusal('timer_failed')
    def timer_probe(self):
        if self.fail=='service':self.fail='';raise x.Refusal('service_probe_failed')
    def timer_stop(self):self.timer=False
    def daemon_reload(self):pass
    def due(self,cert):return self.due_now
    def redirect_check(self):
        if self.fail=='redirect':self.fail='';raise x.Refusal('redirect_failed')
    def app_check(self,ca,leaf):
        if self.fail=='app':self.fail='';raise x.Refusal('app_failed')

class Transaction(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.p=x.Paths(old.x,self.root)
        for folder in (self.p.old.site.parent,self.p.old.enabled.parent,self.p.old.config.parent,self.p.old.ca.parent,self.p.tool.parent,self.p.service.parent):
            folder.mkdir(parents=True,exist_ok=True)
        self.p.old.config.write_bytes(CONFIG);self.p.old.config.chmod(0o640)
        self.p.old.ca.write_text('CA');self.p.old.ca.chmod(0o600)
        self.p.old.ca_key.write_text('CA_KEY');self.p.old.ca_key.chmod(0o600)
        self.p.tool.write_text('INSTALLED_EXACT_TOOL');self.p.tool.chmod(0o600)
        self.p.include.parent.mkdir(parents=True,exist_ok=True);self.p.include.write_text('CustomLog /dev/null common\n');self.p.include.chmod(0o644)
        self.http=HTTP.replace('/etc/apache2/ustar-console-observability.conf',str(self.p.include)).encode()
        self.p.http.write_bytes(self.http);self.p.http.chmod(0o644);self.p.http_enabled.symlink_to(self.p.http)
        self.host=Host(self.p.old,self.p.http_enabled)
        self.host.loaded=self.host.test_config()
        baseline=x.sha(CONFIG)
        self.probe=old.x.Manager(self.p.old,self.host,uid=os.geteuid(),expected_config=baseline)
        self.probe.prepare()
        self.manager=x.Manager(old.x,self.p,self.host,uid=os.geteuid(),baseline_config=baseline,ca_sha='FAKE_PUBLIC_CA_FINGERPRINT')
        self.originals={name:path.read_bytes() for name,path in self.manager.files().items()}
    def assert_restored(self):
        for name,path in self.manager.files().items():self.assertEqual(self.originals[name],path.read_bytes(),name)
        self.assertFalse(self.host.timer)
        self.assertEqual('rolled_back',self.manager.load()['phase'])
    def test_apply_repeat_rollback_and_preserve_modes_include_and_private_backups(self):
        self.assertEqual('active',self.manager.cutover()['phase'])
        self.assertTrue(self.host.timer)
        self.assertEqual(0o640,self.p.old.config.stat().st_mode&0o777)
        self.assertEqual('CustomLog /dev/null common\n',self.p.include.read_text())
        reloads=self.host.calls.count('reload')
        self.assertEqual('active',self.manager.cutover()['phase'])
        self.assertEqual(reloads,self.host.calls.count('reload'))
        self.assertNotIn('CA_KEY',json.dumps(self.manager.status()))
        self.manager.rollback();self.assert_restored();self.probe.verify(self.probe.load())
    def test_failures_restore_only_configurations_and_keep_private_CA(self):
        for failure in ('config','purge','reload','timer','service','app','redirect'):
            with self.subTest(failure=failure):
                self.host.fail=failure
                with self.assertRaises((x.Refusal,old.x.Refusal)):self.manager.cutover()
                # A preflight config failure occurs before the durable transaction.
                if failure!='config':self.assert_restored()
                self.assertEqual('CA_KEY',self.p.old.ca_key.read_text())
    def test_administrator_changed_owned_config_prevents_rollback(self):
        self.manager.cutover();self.p.old.config.write_bytes(b'ADMIN_CHANGE')
        with self.assertRaises(x.Refusal):self.manager.rollback()
        self.assertEqual(b'ADMIN_CHANGE',self.p.old.config.read_bytes())
    def test_renew_noop_due_generation_and_explicit_rollback(self):
        self.manager.cutover();before=self.manager.load()['leaf']
        self.assertEqual('renewal_not_due',self.manager.renew()['phase'])
        self.host.due_now=True
        self.assertEqual('renewed',self.manager.renew()['phase'])
        self.assertNotEqual(before,self.manager.load()['leaf'])
        self.manager.rollback();self.assert_restored()
    def test_failed_renew_restores_previous_leaf_and_durable_active_state(self):
        self.manager.cutover();before=self.p.old.site.read_bytes();leaf=self.manager.load()['leaf']
        self.host.due_now=True;self.host.fail='reload'
        with self.assertRaises(x.Refusal):self.manager.renew()
        self.assertEqual(before,self.p.old.site.read_bytes());self.assertEqual(leaf,self.manager.load()['leaf'])
        self.assertEqual('active',self.manager.status()['phase'])
    def test_interrupted_cutover_can_be_rolled_back_after_process_restart(self):
        self.manager.cutover();s=self.manager.load();s['phase']='preparing';self.manager.save(s)
        another=x.Manager(old.x,self.p,self.host,uid=os.geteuid(),baseline_config=x.sha(CONFIG),ca_sha='FAKE_PUBLIC_CA_FINGERPRINT')
        another.rollback();self.assert_restored()
    def test_rollback_after_container_recreation_uses_current_verified_container(self):
        self.manager.cutover()
        self.host.runtime=lambda path:{'id':'recreated','config':str(path)}
        def purge(rt):
            self.assertEqual('recreated',rt['id'])
        self.host.purge=purge
        self.manager.rollback();self.assert_restored()

    def test_default_plan_and_outage_refusal_have_no_host_access(self):
        for args,expected in (([],0),(['--cutover'],2)):
            with patch.object(x,'Manager',side_effect=AssertionError('host access')),contextlib.redirect_stdout(io.StringIO()) as out:
                self.assertEqual(expected,x.main(args))
            self.assertIn('phase',json.loads(out.getvalue()))
    def test_unit_uses_fixed_installed_script_and_persistent_daily_timer(self):
        service,timer=x.unit_texts()
        self.assertIn(b'/usr/bin/python3 -I -B /usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py --renew',service)
        self.assertIn(b'Persistent=true',timer)
        self.assertIn(b'OnCalendar=*-*-* 03:20:00',timer)

    def test_interrupted_renewal_recovers_known_before_image_without_Moodle_access(self):
        self.manager.cutover();s=self.manager.load();before=self.p.old.site.read_bytes()
        snapshot=self.p.base/'interrupted.before';old.x.atomic(snapshot,before)
        pending=before+b'\n# interrupted generation\n';self.p.old.site.write_bytes(pending)
        s['phase']='renewing';s['pending']={'before':str(snapshot),'site_sha':x.sha(pending)};self.manager.save(s)
        self.host.runtime=lambda *_:(_ for _ in ()).throw(AssertionError('renewal accessed Moodle'))
        self.assertEqual('renewal_not_due',self.manager.renew()['phase'])
        self.assertEqual(before,self.p.old.site.read_bytes());self.assertEqual('active',self.manager.load()['phase'])

    def test_changed_CA_refuses_renewal_before_any_config_write(self):
        self.manager.cutover();before=self.p.old.site.read_bytes();self.host.due_now=True
        self.host.authority=lambda *_:'OTHER_CA'
        with self.assertRaises(x.Refusal):self.manager.renew()
        self.assertEqual(before,self.p.old.site.read_bytes())

    def test_changed_enabled_link_refuses_renewal_without_overwriting_site(self):
        self.manager.cutover();before=self.p.old.site.read_bytes()
        self.p.old.enabled.unlink();self.p.old.enabled.symlink_to(self.p.http)
        with self.assertRaises(x.Refusal):self.manager.renew()
        self.assertEqual(before,self.p.old.site.read_bytes())

    def test_bridge_profile_survives_renewal_and_blocks_HTTP_rollback_until_deactivated(self):
        self.manager.cutover();originals={k:v.copy() for k,v in self.manager.load()['files'].items()}
        self.assertEqual('active',self.manager.set_bridge_enabled(True)['phase'])
        self.assertIn(b'Listen 127.0.0.1:18443',self.p.old.site.read_bytes())
        with self.assertRaisesRegex(x.Refusal,'deactivate_bridge_before_HTTP_rollback'):self.manager.rollback()
        self.host.due_now=True
        self.assertEqual('renewed',self.manager.renew()['phase'])
        self.assertIn(b'Listen 127.0.0.1:18443',self.p.old.site.read_bytes())
        self.assertIn(b'connect_bridge[.]php',self.p.http.read_bytes())
        self.assertEqual(originals['config'],self.manager.load()['files']['config'])
        self.manager.set_bridge_enabled(False)
        self.assertNotIn(b'Listen 127.0.0.1:18443',self.p.old.site.read_bytes())
        self.manager.rollback();self.assert_restored()

    def test_failed_profile_change_restores_current_TLS_HTTP_and_state_without_touching_Moodle(self):
        self.manager.cutover();before=self.manager.load()
        http=self.p.http.read_bytes();tls=self.p.old.site.read_bytes()
        self.host.purge=lambda *_:(_ for _ in ()).throw(AssertionError('profile must not purge Moodle'))
        self.host.fail='reload'
        with self.assertRaisesRegex(x.Refusal,'lost_reload_ack'):self.manager.set_bridge_enabled(True)
        self.assertEqual(before,self.manager.load())
        self.assertEqual(http,self.p.http.read_bytes());self.assertEqual(tls,self.p.old.site.read_bytes())

    def test_profile_recovery_does_not_overwrite_administrator_drift(self):
        self.manager.cutover()
        original_reload=self.host.reload
        def changed():
            self.p.http.write_bytes(b'ADMIN_CHANGE')
            raise x.Refusal('lost_reload_ack')
        self.host.reload=changed
        with self.assertRaisesRegex(x.Refusal,'owned_http_changed'):self.manager.set_bridge_enabled(True)
        self.assertEqual(b'ADMIN_CHANGE',self.p.http.read_bytes())
        self.assertEqual('profile_changing',self.manager.load()['phase'])
        self.host.reload=original_reload

class RuntimeBoundary(unittest.TestCase):
    def test_readonly_directory_bind_is_valid_but_file_bind_is_not(self):
        path=Path('/opt/ustar/data/moodle/public/config.php');raw=b'fixture config'
        data={'id':'a'*64,'running':True,'mounts':[{'Type':'bind','Source':str(path.parent),'Destination':'/var/www/html','RW':False}],
              'ports':{'80/tcp':[{'HostIp':'127.0.0.1','HostPort':'8082'}]}}
        def command(args):
            return json.dumps(data).encode() if args[1]=='inspect' else x.sha(raw).encode()
        lib=SimpleNamespace(Host=lambda:SimpleNamespace(),command=command,read_file=lambda _:raw)
        host=x.Host(lib)
        self.assertEqual('/var/www/html/config.php',host.runtime(path)['config'])
        data['mounts'][0].update(Source=str(path),Destination='/var/www/html/config.php')
        with self.assertRaises(x.Refusal):host.runtime(path)
