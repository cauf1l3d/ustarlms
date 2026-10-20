#!/usr/bin/env python3
"""Real Apache/OpenSSL on isolated high ports and temporary paths. Never production."""
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import tempfile
import time
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('native_academy_tls', ROOT / 'scripts/infra/academy_tls_probe.py')
x = importlib.util.module_from_spec(spec); spec.loader.exec_module(x)


def run(args):
    result = subprocess.run(args, capture_output=True, timeout=60)
    if result.returncode:
        raise RuntimeError('NATIVE_TLS_COMMAND_FAILED: ' + result.stderr.decode(errors='replace')[-4000:])
    return result.stdout + result.stderr


def request(port, servername, ca, path):
    ctx = ssl.create_default_context(cafile=str(ca))
    with socket.create_connection(('127.0.0.1', port), timeout=5) as sock:
        with ctx.wrap_socket(sock, server_hostname=servername) as stream:
            fingerprint = hashlib.sha256(stream.getpeercert(binary_form=True)).hexdigest()
            stream.sendall(f'GET {path} HTTP/1.1\r\nHost: {servername}\r\nConnection: close\r\n\r\n'.encode())
            response = http.client.HTTPResponse(stream); response.begin()
            return response.status, response.read(8193), fingerprint


def main():
    if os.geteuid() != 0 or os.environ.get('USTAR_ACADEMY_TLS_FIXTURE') != '1' or Path('/opt/ustar').exists():
        raise RuntimeError('ISOLATED_ROOT_TLS_FIXTURE_REQUIRED')
    binary = shutil.which('apache2')
    if not binary: raise RuntimeError('APACHE_BINARY_REQUIRED')
    with tempfile.TemporaryDirectory(prefix='ustar-tls-native-') as temp:
        root = Path(temp); root.chmod(0o755)
        p = x.Paths(root)
        for folder in (p.ca.parent, p.config.parent, p.site.parent, p.enabled.parent):
            folder.mkdir(parents=True, mode=0o755)
        original = b'<?php\n$CFG->wwwroot = $native_fixture_origin;\n'
        p.config.write_bytes(original); p.config.chmod(0o600)
        run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','90','-subj','/CN=Synthetic Native TLS CA',
             '-addext','basicConstraints=critical,CA:TRUE','-addext','keyUsage=critical,keyCertSign,cRLSign',
             '-keyout',str(p.ca_key),'-out',str(p.ca)])
        p.ca_key.chmod(0o600); p.ca.chmod(0o600)
        key, csr, cert, ext = [root / name for name in ('default.key','default.csr','default.crt','default.ext')]
        ext.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:other.invalid\n')
        run(['openssl','req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=other.invalid','-keyout',str(key),'-out',str(csr)])
        run(['openssl','x509','-req','-in',str(csr),'-CA',str(p.ca),'-CAkey',str(p.ca_key),'-set_serial','1234',
             '-days','30','-extfile',str(ext),'-out',str(cert)])
        site = root / 'baseline'; (site / 'login').mkdir(parents=True, mode=0o755)
        (site / 'login/index.php').write_text('SYNTHETIC_HTTP_BASELINE'); (site / 'index.html').write_text('DEFAULT_TLS_UNCHANGED')
        conf = root / 'apache.conf'; pidfile = root / 'apache.pid'
        modules = ('mpm_event', 'authz_core', 'ssl', 'socache_shmcb', 'proxy', 'proxy_http')
        lines = [f'ServerRoot "{root}"', f'PidFile "{pidfile}"', 'ServerName other.invalid',
                 'Listen 127.0.0.1:18080', 'Listen 127.0.0.1:18443',
                 *[f'LoadModule {module}_module /usr/lib/apache2/modules/mod_{module}.so' for module in modules],
                 'User www-data', 'Group www-data', f'ErrorLog "{root}/error.log"', 'LogLevel warn',
                 '<VirtualHost *:18080>', 'ServerName ustar.local', f'DocumentRoot "{site}"',
                 f'<Directory "{site}">', 'Require all granted', '</Directory>', '</VirtualHost>',
                 '<VirtualHost *:18443>', 'ServerName other.invalid', f'DocumentRoot "{site}"',
                 f'<Directory "{site}">', 'Require all granted', '</Directory>', 'SSLEngine on',
                 f'SSLCertificateFile "{cert}"', f'SSLCertificateKeyFile "{key}"', '</VirtualHost>',
                 f'IncludeOptional "{p.enabled.parent}/*.conf"']
        conf.write_text('\n'.join(lines) + '\n')
        run([binary, '-f', str(conf), '-t'])
        log = (root / 'process.log').open('wb')
        process = subprocess.Popen([binary, '-f', str(conf), '-DFOREGROUND'], stdout=log, stderr=log)
        try:
            for _ in range(50):
                if process.poll() is not None: raise RuntimeError('APACHE_FIXTURE_EXITED')
                if pidfile.exists():
                    try:
                        with socket.create_connection(('127.0.0.1',18080),timeout=0.1): break
                    except OSError: pass
                time.sleep(0.1)
            else: raise RuntimeError('APACHE_FIXTURE_START_TIMEOUT')

            class NativeHost(x.Host):
                HTTP_PORT, HTTPS_PORT = 18080, 18443
                fail_after_reload = False
                def units(self):
                    if process.poll() is not None: raise x.Refusal('fixture_Apache_stopped')
                    return {'apache2': int(pidfile.read_text().strip()), 'caddy': 1000001, 'ustar-console': 1000002}
                def vhosts(self):
                    return x.parse_vhosts(run([binary, '-f', str(conf), '-t', '-D', 'DUMP_VHOSTS']).decode())
                def test_config(self):
                    run([binary, '-f', str(conf), '-t']); return self.vhosts()
                def reload(self):
                    run([binary, '-f', str(conf), '-k', 'graceful'])
                    if self.fail_after_reload:
                        self.fail_after_reload = False
                        raise x.Refusal('simulated_lost_reload_ack')

            host = NativeHost(); manager = x.Manager(p, host, expected_config=hashlib.sha256(original).hexdigest())
            before = host.vhosts(); default = request(18443,'other.invalid',p.ca,'/index.html')
            assert default[:2] == (200,b'DEFAULT_TLS_UNCHANGED')
            ca_material = {v.name:v.read_bytes() for v in (p.ca,p.ca_key)}
            previous_umask = os.umask(0o077)
            try: report = manager.prepare()
            finally: os.umask(previous_umask)
            assert report['phase'] == 'tls_probe_ready'
            ready = request(18443,'ustar.local',p.ca,x.PROBE_PATH)
            assert ready[0] == 200 and ready[2] == report['leaf']['fingerprint']
            assert request(18443,'ustar.local',p.ca,'/login/index.php')[0] == 403
            assert request(18443,'other.invalid',p.ca,'/index.html') == default
            assert host.http_status() == 200 and p.config.read_bytes() == original
            for v in (p.ca,p.ca_key): assert v.read_bytes() == ca_material[v.name]
            assert not p.ca.with_suffix('.srl').exists()
            assert manager.prepare()['phase'] == 'tls_probe_ready'
            assert manager.rollback()['phase'] == 'rolled_back'
            assert host.vhosts() == before and host.http_status() == 200
            assert request(18443,'other.invalid',p.ca,'/index.html') == default

            original_render = x.render_vhost
            with patch.object(x,'render_vhost',side_effect=lambda *args: original_render(*args) + '\nINVALID_FIXTURE_DIRECTIVE On\n'):
                try: manager.prepare()
                except Exception: pass
                else: raise AssertionError('INVALID_APACHE_CONFIG_ACCEPTED')
            assert not p.enabled.exists() and host.vhosts() == before
            host.fail_after_reload = True
            try: manager.prepare()
            except x.Refusal: pass
            else: raise AssertionError('LOST_RELOAD_ACK_ACCEPTED')
            assert not p.enabled.exists() and host.vhosts() == before and host.http_status() == 200
            assert request(18443,'other.invalid',p.ca,'/index.html') == default
            evidence = {'result':'PASS','native_Apache_OpenSSL':True,'TLS_and_Hostname_verification':True,
                'SNI_leaf_and_default_host_unchanged':True,'HTTP_and_config_unchanged':True,
                'other_paths_403':True,'invalid_config_and_lost_reload_ack_recovery':True,
                'CA_key_and_serial_files_unchanged':True,'production_access':False}
            target=ROOT/'artifacts/academy-tls-probe-native.json';target.parent.mkdir(exist_ok=True)
            target.write_text(json.dumps(evidence,indent=2)+'\n')
            print('ACADEMY_NATIVE_TLS_PROBE=PASS; SNI/default/HTTP/CA/rollback; isolated_fixture_only')
        finally:
            process.terminate()
            try: process.wait(timeout=10)
            except subprocess.TimeoutExpired: process.kill();process.wait(timeout=10)
            log.close()


if __name__ == '__main__': main()
