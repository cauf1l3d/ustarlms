"""Real filesystem transactions; modeled service boundary, real OpenSSL separately."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[2]
FILE = ROOT / 'scripts/infra/academy_tls_probe.py'
if FILE.exists():
    spec = importlib.util.spec_from_file_location('academy_tls_probe', FILE)
    x = importlib.util.module_from_spec(spec); spec.loader.exec_module(x)
else:
    x = SimpleNamespace()

BASE = '''VirtualHost configuration:
*:80 is a NameVirtualHost
         default server other.invalid (/etc/apache2/sites-enabled/000-other.conf:1)
         port 80 namevhost other.invalid (/etc/apache2/sites-enabled/000-other.conf:1)
         port 80 namevhost ustar.local (/etc/apache2/sites-enabled/academy.conf:1)
*:443 is a NameVirtualHost
         default server other.invalid (/etc/apache2/sites-enabled/000-other.conf:8)
         port 443 namevhost other.invalid (/etc/apache2/sites-enabled/000-other.conf:8)
                 alias www.other.invalid
'''


class Vhosts(unittest.TestCase):
    def test_addition_preserves_every_old_binding_alias_and_default_order(self):
        before = x.parse_vhosts(BASE)
        after = x.parse_vhosts(BASE + '         port 443 namevhost ustar.local (/managed/probe.conf:1)\n')
        x.assert_additive(before, after, '/managed/probe.conf', 443)
        for bad in (BASE.replace('other.invalid', 'changed.invalid', 1),
                    BASE.replace('www.other.invalid', 'changed.invalid')):
            with self.assertRaises(x.Refusal):
                x.assert_additive(before, x.parse_vhosts(bad + ' port 443 namevhost ustar.local (/managed/probe.conf:1)\n'), '/managed/probe.conf', 443)

    def test_single_host_dump_and_ambiguous_input(self):
        one = x.parse_vhosts('*:443 other.invalid (/etc/apache2/site.conf:1)\n')
        self.assertEqual('other.invalid', one['*:443'][0]['name'])
        for raw in ('', 'malformed output', '*:443 is a NameVirtualHost\n'):
            with self.assertRaises(x.Refusal): x.parse_vhosts(raw)

    def test_new_host_must_not_become_default_or_shadow_existing_name(self):
        before = x.parse_vhosts(BASE)
        with self.assertRaises(x.Refusal):
            after = x.parse_vhosts(BASE.replace('         port 443 namevhost other.invalid',
                '         port 443 namevhost ustar.local (/managed/probe.conf:1)\n         port 443 namevhost other.invalid'))
            x.assert_additive(before, after, '/managed/probe.conf', 443)
        with self.assertRaises(x.Refusal): x.assert_name_available(x.parse_vhosts(BASE.replace('www.other.invalid', '*.local')), 443)

    def test_probe_vhost_has_no_backend_HSTS_PHP_or_new_listener(self):
        paths = x.Paths(Path('/fixture'))
        text = x.render_vhost(paths, Path('/private/server.crt'), Path('/private/server.key'), 443)
        self.assertIn('ServerName ustar.local', text)
        self.assertIn('ProxyPassInherit Off', text)
        self.assertIn('ProxyPass / !', text)
        self.assertIn('Require all denied', text)
        self.assertIn('SetHandler default-handler', text)
        self.assertIn('TLSv1.3', text)
        for forbidden in ('Listen ', '8082', 'Strict-Transport-Security', 'X-Forwarded', 'php-fpm', 'SSLCACertificateFile'):
            self.assertNotIn(forbidden, text)


class FakeHost:
    HTTP_PORT = 80
    HTTPS_PORT = 443
    def __init__(self, paths):
        self.p = paths; self.calls = []; self.loaded = x.parse_vhosts(BASE)
        self.invalid = self.reload_fail = self.tls_fail = self.pid_change = self.http_change = False
        self.transient_tls = 0
        self.wrong_leaf = False
    def units(self):
        return {'apache2': 101, 'caddy': 102, 'ustar-console': 999 if self.pid_change and self.calls else 103}
    def vhosts(self): return self.loaded
    def test_config(self):
        self.calls.append('configtest')
        if self.invalid: raise x.Refusal('apache_config_invalid')
        return x.parse_vhosts(BASE + (f' port 443 namevhost ustar.local ({self.p.enabled}:1)\n' if self.p.enabled.is_symlink() else ''))
    def reload(self):
        self.calls.append('reload')
        if self.reload_fail:
            self.reload_fail = False
            raise x.Refusal('apache_reload_failed')
        self.loaded = self.test_config()
    def http_status(self): return 503 if self.http_change and self.p.enabled.is_symlink() else 200
    def authority(self, ca, key): return 'FAKE_PUBLIC_CA_FINGERPRINT'
    def certificate(self, ca, key, folder):
        (folder / 'server.key').write_text('PRIVATE_SENTINEL_KEY'); (folder / 'server.key').chmod(0o600)
        (folder / 'server.crt').write_text('PUBLIC_LEAF'); (folder / 'server.crt').chmod(0o600)
        return {'fingerprint': 'FAKE_PUBLIC_LEAF_FINGERPRINT', 'not_after': 'fixture_only'}
    def tls_check(self, ca, body):
        self.calls.append('TLS_verified')
        if self.transient_tls:
            self.transient_tls -= 1
            raise x.Refusal('tls_probe_failed')
        if self.tls_fail: raise x.Refusal('tls_probe_failed')
        if not self.p.enabled.is_symlink() or self.p.health.read_bytes() != body:
            raise x.Refusal('tls_probe_failed')
        return 'OTHER_LEAF' if self.wrong_leaf else 'FAKE_PUBLIC_LEAF_FINGERPRINT'


class Transaction(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name); self.p = x.Paths(self.root)
        for folder in (self.p.site.parent, self.p.enabled.parent, self.p.config.parent, self.p.ca.parent):
            folder.mkdir(parents=True, exist_ok=True)
        self.original = b'<?php\n$CFG->wwwroot = $prepared_origin;\n'
        self.p.config.write_bytes(self.original); self.p.config.chmod(0o600)
        self.p.ca.write_text('PUBLIC_CA'); self.p.ca.chmod(0o600)
        self.p.ca_key.write_text('PRIVATE_SENTINEL_CA_KEY'); self.p.ca_key.chmod(0o600)
        self.host = FakeHost(self.p)
        self.manager = x.Manager(self.p, self.host, uid=os.geteuid(), expected_config=hashlib.sha256(self.original).hexdigest())

    def test_private_CLI_umask_keeps_static_probe_readable_by_Apache(self):
        previous = os.umask(0o077)
        try:
            self.assertEqual('tls_probe_ready', self.manager.prepare()['phase'])
        finally:
            os.umask(previous)
        for folder in (self.p.web, self.p.health.parent):
            self.assertEqual(0o755, folder.stat().st_mode & 0o777)
        self.assertEqual(0o700, self.p.base.stat().st_mode & 0o777)
        self.assertEqual(0o644, self.p.health.stat().st_mode & 0o777)

    def test_prepare_retry_and_rollback_preserve_HTTP_config_CA_and_unrelated_services(self):
        result = self.manager.prepare()
        self.assertEqual('tls_probe_ready', result['phase'])
        self.assertTrue(self.p.enabled.is_symlink())
        self.assertEqual(self.original, self.p.config.read_bytes())
        self.assertEqual('PRIVATE_SENTINEL_CA_KEY', self.p.ca_key.read_text())
        self.assertNotIn('PRIVATE_SENTINEL', json.dumps(result))
        self.assertEqual(0o700, self.p.base.stat().st_mode & 0o777)
        count = self.host.calls.count('reload')
        self.assertEqual('tls_probe_ready', self.manager.prepare()['phase'])
        self.assertEqual(count, self.host.calls.count('reload'))
        result = self.manager.rollback()
        self.assertEqual('rolled_back', result['phase'])
        self.assertFalse(self.p.enabled.exists())
        self.assertEqual(x.parse_vhosts(BASE), self.host.loaded)

    def test_changed_config_or_unsafe_signer_refuses_before_reload(self):
        self.p.config.write_bytes(self.original + b'// changed')
        with self.assertRaises(x.Refusal): self.manager.prepare()
        self.assertNotIn('reload', self.host.calls)
        self.p.config.write_bytes(self.original); self.p.ca_key.chmod(0o644)
        with self.assertRaises(x.Refusal): self.manager.prepare()
        self.assertNotIn('reload', self.host.calls)

    def test_foreign_existing_site_is_never_overwritten(self):
        self.p.site.write_text('DO_NOT_CHANGE')
        with self.assertRaises(x.Refusal): self.manager.prepare()
        self.assertEqual('DO_NOT_CHANGE', self.p.site.read_text())
        self.assertNotIn('reload', self.host.calls)

    def test_failed_configtest_unloads_nothing_and_never_reloads(self):
        self.host.invalid = True
        with self.assertRaises(x.Refusal): self.manager.prepare()
        self.assertFalse(self.p.enabled.exists())
        self.assertNotIn('reload', self.host.calls)

    def test_failed_reload_or_TLS_automatically_restores_previous_vhosts(self):
        for kind in ('reload_fail', 'tls_fail'):
            setattr(self.host, kind, True)
            with self.assertRaises(x.Refusal): self.manager.prepare()
            self.assertFalse(self.p.enabled.exists())
            self.assertEqual(x.parse_vhosts(BASE), self.host.loaded)
            setattr(self.host, kind, False)

    def test_HTTP_or_other_service_change_prevents_acceptance(self):
        for kind in ('http_change', 'pid_change'):
            self.host.calls.clear()
            setattr(self.host, kind, True)
            with self.assertRaises(x.Refusal): self.manager.prepare()
            self.assertFalse(self.p.enabled.exists())
            setattr(self.host, kind, False)

    def test_check_does_not_recreate_missing_Apache_directory(self):
        self.p.site.parent.rmdir()
        with self.assertRaises(x.Refusal): self.manager.check()
        self.assertFalse(self.p.site.parent.exists())

    def test_crypto_failure_has_durable_recoverable_receipt_before_artifact_creation(self):
        def interrupted(*args):
            self.assertEqual('preparing', json.loads(self.p.state.read_text())['phase'])
            raise x.Refusal('crypto_failed')
        self.host.certificate = interrupted
        with self.assertRaises(x.Refusal): self.manager.prepare()
        self.assertEqual('rolled_back', self.manager.status()['phase'])
        self.assertNotIn('reload', self.host.calls)

    def test_readiness_wait_only_repeats_safe_TLS_read_until_new_vhost_is_ready(self):
        self.host.transient_tls = 1
        self.assertEqual('tls_probe_ready', self.manager.prepare()['phase'])

    def test_native_dump_uses_the_enabled_include_path(self):
        self.assertEqual('tls_probe_ready', self.manager.prepare()['phase'])

    def test_valid_CA_with_wrong_live_leaf_cannot_be_accepted(self):
        self.host.wrong_leaf = True
        with self.assertRaises(x.Refusal): self.manager.prepare()
        self.assertFalse(self.p.enabled.exists())

    def test_modified_owned_site_is_not_deleted_by_rollback(self):
        self.manager.prepare(); self.p.site.write_text('ADMIN_CHANGED')
        with self.assertRaises(x.Refusal): self.manager.rollback()
        self.assertTrue(self.p.enabled.is_symlink())
        self.assertEqual('ADMIN_CHANGED', self.p.site.read_text())

    def test_interrupted_preparing_receipt_can_be_rolled_back_after_restart(self):
        self.manager.prepare()
        state = json.loads(self.p.state.read_text()); state['phase'] = 'preparing'
        self.p.state.write_text(json.dumps(state)); self.p.state.chmod(0o600)
        again = x.Manager(self.p, self.host, uid=os.geteuid(), expected_config=hashlib.sha256(self.original).hexdigest())
        self.assertEqual('rolled_back', again.rollback()['phase'])
        self.assertFalse(self.p.enabled.exists())

    def test_default_CLI_has_no_host_access(self):
        from unittest.mock import patch
        with patch.object(x, 'Manager', side_effect=AssertionError('host access')), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, x.main([]))
        self.assertEqual('plan_only', json.loads(output.getvalue())['phase'])


@unittest.skipUnless(shutil.which('openssl'), 'OpenSSL required for real certificate test')
class Certificates(unittest.TestCase):
    def test_real_CA_key_match_leaf_DNS_and_signer_files_unchanged(self):
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder); ca, key = p/'ca.crt', p/'ca.key'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','45',
                '-subj','/CN=Synthetic TLS Test CA','-addext','basicConstraints=critical,CA:TRUE',
                '-addext','keyUsage=critical,keyCertSign,cRLSign','-keyout',str(key),'-out',str(ca)],
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
            originals = {v.name:v.read_bytes() for v in (ca,key)}
            host = x.Host()
            self.assertTrue(host.authority(ca,key))
            target=p/'leaf';target.mkdir(mode=0o700)
            host.certificate(ca,key,target)
            for v in (ca,key): self.assertEqual(originals[v.name],v.read_bytes())
            self.assertFalse((p/'ca.srl').exists())
            good=subprocess.run(['openssl','verify','-CAfile',str(ca),'-purpose','sslserver','-verify_hostname','ustar.local',str(target/'server.crt')],capture_output=True)
            self.assertEqual(0,good.returncode)
            bad=subprocess.run(['openssl','verify','-CAfile',str(ca),'-verify_hostname','other.invalid',str(target/'server.crt')],capture_output=True)
            self.assertNotEqual(0,bad.returncode)
