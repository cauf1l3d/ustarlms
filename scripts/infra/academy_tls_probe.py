#!/usr/bin/env python3
"""Add a static Academy TLS probe; Moodle origin and HTTP remain unchanged."""
import argparse
from contextlib import contextmanager
import fcntl
import fnmatch
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import signal
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import time
import uuid

EXPECTED_CONFIG = '96058835f27c21b99db7bde137677921223a5b0e013b1c6fa3a1a3415ac4361d'
PROBE_PATH = '/__ustar_tls_probe__/ready.html'


class Refusal(Exception):
    pass


def require(value, reason):
    if not value:
        raise Refusal(reason)


class Paths:
    def __init__(self, root=Path('/')):
        self.root = Path(root)
        self.config = self.root / 'opt/ustar/data/moodle/public/config.php'
        self.ca = self.root / 'etc/ustar-console/authority/ca.crt'
        self.ca_key = self.ca.with_name('ca.key')
        self.base = self.root / 'var/lib/ustar-academy-tls-probe'
        self.state = self.base / 'stage.json'
        self.web = self.root / 'srv/ustar-academy-tls-probe'
        self.health = self.web / PROBE_PATH.lstrip('/')
        self.site = self.root / 'etc/apache2/sites-available/zzzz-ustar-academy-tls-probe.conf'
        self.enabled = self.root / 'etc/apache2/sites-enabled' / self.site.name


def digest(value):
    return hashlib.sha256(value).hexdigest()


def read_file(path, uid=None, private=False, maximum=131072):
    require(not any(p.is_symlink() for p in (path, *path.parents)), 'unsafe_file_link')
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= maximum, 'unsafe_file_type')
    if uid is not None:
        require(before.st_uid == uid and not before.st_mode & (0o077 if private else 0o022), 'unsafe_file_permissions')
    signature = lambda st: (st.st_dev, st.st_ino, st.st_mode, st.st_uid, st.st_gid,
                            st.st_nlink, st.st_size, st.st_mtime_ns, st.st_ctime_ns)
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), 'rb') as stream:
        require(signature(before) == signature(os.fstat(stream.fileno())), 'file_changed')
        raw = stream.read(maximum + 1)
        require(len(raw) <= maximum and signature(before) == signature(os.fstat(stream.fileno()))
                and signature(before) == signature(path.lstat()), 'file_changed')
    return raw


def directory(path, mode, uid, scope):
    require(path.is_relative_to(scope), 'path_outside_scope')
    for p in [scope, *[scope.joinpath(*path.relative_to(scope).parts[:i])
                        for i in range(1, len(path.relative_to(scope).parts) + 1)]]:
        require(not p.is_symlink(), 'unsafe_directory_link')
        if not p.exists():
            creation_mode = mode if p == path else 0o755
            p.mkdir(mode=creation_mode)
            p.chmod(creation_mode)
        info = p.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == uid and not info.st_mode & 0o022, 'unsafe_directory_permissions')
    require(stat.S_IMODE(path.stat().st_mode) == mode, 'directory_mode_mismatch')


def atomic(path, raw, mode=0o600):
    fd, name = tempfile.mkstemp(prefix='.tls-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            os.fchmod(stream.fileno(), mode); stream.write(raw); stream.flush(); os.fsync(stream.fileno())
        os.replace(name, path)
        descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(descriptor)
        finally: os.close(descriptor)
    finally:
        if os.path.exists(name): os.unlink(name)


def sync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try: os.fsync(descriptor)
    finally: os.close(descriptor)


def parse_vhosts(raw):
    require(isinstance(raw, str) and len(raw) <= 131072, 'apache_dump_invalid')
    result, defaults, binding, last = {}, {}, None, None
    for text in raw.splitlines():
        line = text.strip()
        group = re.fullmatch(r'(\S+):(\d+)\s+is a NameVirtualHost', line)
        single = re.fullmatch(r'(\S+):(\d+)\s+(\S+) \((.+):([0-9]+)\)', line)
        row = re.fullmatch(r'port (\d+) namevhost (\S+) \((.+):([0-9]+)\)', line)
        alias = re.fullmatch(r'(?:wild )?alias (\S+)', line)
        default = re.fullmatch(r'default server (\S+) \((.+):([0-9]+)\)', line)
        if group or single:
            found = group or single
            address = '*' if found[1] == '_default_' else found[1]
            binding = address + ':' + found[2]
            require(binding not in result, 'apache_dump_ambiguous')
            result[binding] = []; last = None
            if single:
                last = {'name': single[3].lower(), 'source': single[4], 'line': int(single[5]), 'aliases': []}
                result[binding].append(last)
        elif row:
            require(binding is not None and binding.rsplit(':', 1)[1] == row[1], 'apache_dump_ambiguous')
            last = {'name': row[2].lower(), 'source': row[3], 'line': int(row[4]), 'aliases': []}
            result[binding].append(last)
        elif default:
            require(binding is not None and binding not in defaults, 'apache_dump_ambiguous')
            defaults[binding] = (default[1].lower(), default[2], int(default[3]))
        elif alias:
            require(last is not None, 'apache_dump_ambiguous')
            last['aliases'].append(alias[1].lower())
    require(result and all(result.values()), 'apache_dump_invalid')
    for key, value in defaults.items():
        first = result[key][0]
        require((first['name'], first['source'], first['line']) == value, 'apache_dump_default_mismatch')
    return result


def assert_name_available(before, port, *, first_tls=False):
    bindings = [key for key in before if key.rsplit(':', 1)[1] == str(port)]
    if first_tls:
        require(not bindings, 'first_TLS_requires_no_existing_vhosts')
    else:
        require('*:' + str(port) in before, 'wildcard_TLS_binding_required')
    for binding, rows in before.items():
        if binding.rsplit(':', 1)[1] != str(port): continue
        for row in rows:
            require(not any(fnmatch.fnmatchcase('ustar.local', name) for name in [row['name'], *row['aliases']]),
                    'existing_Academy_TLS_name')


def assert_additive(before, after, source, port, *, first_tls=False):
    assert_name_available(before, port, first_tls=first_tls)
    target = '*:' + str(port)
    require(set(after) == set(before) | ({target} if first_tls else set()), 'apache_bindings_changed')
    if first_tls:
        new = after[target]
        require(len(new) == 1 and new[0]['name'] == 'ustar.local'
                and new[0]['source'] == source and not new[0]['aliases'], 'unexpected_first_TLS_vhost')
    for binding, rows in before.items():
        new = after[binding]
        if binding == target:
            require(len(new) == len(rows) + 1 and new[:-1] == rows and new[-1]['name'] == 'ustar.local'
                    and new[-1]['source'] == source and not new[-1]['aliases'], 'apache_default_or_vhosts_changed')
        else:
            require(rows == new, 'apache_default_or_vhosts_changed')


def render_vhost(paths, cert, key, port=443):
    for value in (paths.web, cert, key):
        require(re.fullmatch(r'/[A-Za-z0-9_./-]+', str(value)) is not None, 'unsupported_path')
    return f'''# USTAR managed TLS probe; no Moodle proxy or canonical origin change.
<VirtualHost *:{port}>
    ServerName ustar.local
    SSLEngine on
    SSLCertificateFile "{cert}"
    SSLCertificateKeyFile "{key}"
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLUseStapling off
    ProxyPassInherit Off
    ProxyPass / !
    <IfModule mod_rewrite.c>
        RewriteEngine Off
    </IfModule>
    DocumentRoot "{paths.web}"
    ErrorLog /dev/null
    CustomLog /dev/null common
    <Directory "{paths.web}">
        Options None
        AllowOverride None
        Require all denied
    </Directory>
    <Location "/">
        SetHandler default-handler
        Require all denied
    </Location>
    <LocationMatch "^/__ustar_tls_probe__/ready\\.html$">
        SetHandler default-handler
        ForceType text/html
        Require all granted
    </LocationMatch>
</VirtualHost>
'''


def command(args, timeout=30, combined=False):
    try:
        value = subprocess.run(args, capture_output=True, timeout=timeout,
            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C'}, check=False)
        require(value.returncode == 0 and len(value.stdout) + len(value.stderr) <= 131072, 'host_command_failed')
        return value.stdout + value.stderr if combined else value.stdout
    except (OSError, subprocess.SubprocessError):
        raise Refusal('host_command_failed') from None


class Host:
    HTTP_PORT = 80
    HTTPS_PORT = 443
    def units(self):
        result = {}
        for unit in ('apache2', 'caddy', 'ustar-console'):
            raw = command(['systemctl', 'show', unit + '.service', '--no-pager', '-p', 'ActiveState', '-p', 'MainPID']).decode()
            values = dict(line.split('=', 1) for line in raw.splitlines() if '=' in line)
            require(values.get('ActiveState') == 'active' and values.get('MainPID', '').isdecimal()
                    and int(values['MainPID']) > 0, 'required_services_not_active')
            result[unit] = int(values['MainPID'])
        return result
    def vhosts(self): return parse_vhosts(command(['apache2ctl', '-S'], combined=True).decode())
    def test_config(self):
        command(['apache2ctl', '-t'])
        return self.vhosts()
    def reload(self): command(['systemctl', 'reload', 'apache2.service'])
    def http_status(self):
        connection = http.client.HTTPConnection('127.0.0.1', self.HTTP_PORT, timeout=10)
        try:
            connection.request('HEAD', '/login/index.php', headers={'Host': 'ustar.local', 'Connection': 'close'})
            return connection.getresponse().status
        finally: connection.close()
    def first_tls_ready(self):
        # A listening port without a vhost may be serving the global plain HTTP site.
        # Refuse existing TLS, connection errors and unknown protocol responses.
        require(b'ssl_module' in command(['apache2ctl', '-M'], combined=True), 'SSL_module_required')
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        try:
            with socket.create_connection(('127.0.0.1', self.HTTPS_PORT), timeout=5) as raw:
                with context.wrap_socket(raw, server_hostname='ustar.local'):
                    return False
        except ssl.SSLError as error:
            if error.reason != 'WRONG_VERSION_NUMBER':
                return False
        connection = http.client.HTTPConnection('127.0.0.1', self.HTTPS_PORT, timeout=5)
        try:
            connection.request('HEAD', '/', headers={'Host': 'ustar.local', 'Connection': 'close'})
            return connection.getresponse().status == 200
        finally:
            connection.close()

    def authority(self, ca, key):
        command(['openssl', 'x509', '-in', str(ca), '-noout', '-checkend', str(31 * 86400)])
        text = command(['openssl', 'x509', '-in', str(ca), '-noout', '-text'])
        require(b'CA:TRUE' in text and b'Certificate Sign' in text, 'invalid_CA_constraints')
        cert_public = command(['openssl', 'x509', '-in', str(ca), '-pubkey', '-noout']).strip()
        key_public = command(['openssl', 'pkey', '-in', str(key), '-passin', 'pass:', '-pubout']).strip()
        require(cert_public == key_public, 'CA_key_mismatch')
        command(['openssl', 'verify', '-CAfile', str(ca), str(ca)])
        return digest(command(['openssl', 'x509', '-in', str(ca), '-outform', 'DER']))
    def certificate(self, ca, key, folder):
        private, request, cert, extension = [folder / n for n in ('server.key', 'server.csr', 'server.crt', 'server.ext')]
        atomic(extension, b'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:ustar.local\n')
        command(['openssl', 'req', '-new', '-newkey', 'rsa:3072', '-nodes', '-sha256', '-subj', '/CN=ustar.local',
                 '-keyout', str(private), '-out', str(request)], timeout=60)
        private.chmod(0o600)
        command(['openssl', 'x509', '-req', '-in', str(request), '-CA', str(ca), '-CAkey', str(key),
                 '-passin', 'pass:', '-set_serial', str(secrets.randbits(128) or 1), '-out', str(cert),
                 '-days', '30', '-sha256', '-extfile', str(extension)])
        cert.chmod(0o600)
        command(['openssl', 'verify', '-CAfile', str(ca), '-purpose', 'sslserver', '-verify_hostname', 'ustar.local', str(cert)])
        return {'fingerprint': digest(command(['openssl', 'x509', '-in', str(cert), '-outform', 'DER'])),
                'not_after': command(['openssl', 'x509', '-in', str(cert), '-noout', '-enddate']).decode().strip()}
    def tls_check(self, ca, body):
        context = ssl.create_default_context(cafile=str(ca))
        with socket.create_connection(('127.0.0.1', self.HTTPS_PORT), timeout=10) as connection:
            with context.wrap_socket(connection, server_hostname='ustar.local') as stream:
                peer = digest(stream.getpeercert(binary_form=True))
                stream.sendall(('GET ' + PROBE_PATH + ' HTTP/1.1\r\nHost: ustar.local\r\nConnection: close\r\n\r\n').encode())
                response = http.client.HTTPResponse(stream); response.begin()
                require(response.status == 200 and response.read(8193) == body, 'tls_probe_failed')
                return peer


class Manager:
    def __init__(self, paths=None, host=None, *, uid=0, expected_config=EXPECTED_CONFIG, first_tls=False):
        self.p, self.host = paths or Paths(), host or Host()
        self.uid, self.expected_config = uid, expected_config
        self.first_tls = first_tls

    def config(self):
        require(digest(read_file(self.p.config)) == self.expected_config, 'Academy_config_changed')

    def load(self):
        value = json.loads(read_file(self.p.state, self.uid, True))
        require(isinstance(value, dict) and value.get('schema') == 1, 'invalid_state')
        return value

    def save(self, state):
        atomic(self.p.state, (json.dumps(state, sort_keys=True) + '\n').encode())

    @contextmanager
    def locked(self):
        directory(self.p.base, 0o700, self.uid, self.p.root)
        path = self.p.base / 'lock'
        fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(fd)
            require(stat.S_ISREG(info.st_mode) and info.st_uid == self.uid and info.st_nlink == 1
                    and stat.S_IMODE(info.st_mode) == 0o600, 'unsafe_lock')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            yield
        finally: os.close(fd)

    def check(self):
        self.config()
        read_file(self.p.ca, self.uid); read_file(self.p.ca_key, self.uid, True)
        require(not os.path.lexists(self.p.enabled) and not os.path.lexists(self.p.site), 'existing_managed_site_requires_review')
        for path in (self.p.enabled.parent, self.p.site.parent):
            require(not any(p.is_symlink() for p in (path, *path.parents)), 'unsafe_Apache_directory')
            require(path.is_dir() and path.stat().st_uid == self.uid and not path.stat().st_mode & 0o022,
                    'unsafe_Apache_directory')
        units = self.host.units()
        before = self.host.test_config()
        assert_name_available(before, self.host.HTTPS_PORT, first_tls=self.first_tls)
        if self.first_tls:
            require(self.host.first_tls_ready(), 'first_TLS_plain_HTTP_prerequisite_failed')
        require(any(row['name'] == 'ustar.local' for binding, rows in before.items()
                    if binding.rsplit(':', 1)[1] == str(self.host.HTTP_PORT) for row in rows), 'Academy_HTTP_vhost_missing')
        status = self.host.http_status()
        require(status in (200, 301, 302, 303), 'Academy_HTTP_unavailable')
        ca = self.host.authority(self.p.ca, self.p.ca_key)
        return {'units': units, 'before': before, 'http_status': status, 'CA_fingerprint': ca, 'first_tls': self.first_tls}

    def public(self, state):
        return {'phase': state['phase'], 'first_TLS_vhost': state.get('first_tls', False), 'probe_url': 'https://ustar.local' + PROBE_PATH,
                'Academy_origin_changed': False, 'HTTP_redirect_enabled': False,
                'network_or_other_services_changed': False,
                **{key: state[key] for key in ('CA_fingerprint', 'leaf') if key in state}}

    def owned(self, state):
        if os.path.lexists(self.p.enabled):
            require(self.p.enabled.is_symlink() and os.readlink(self.p.enabled) == str(self.p.site), 'owned_link_changed')
        if os.path.lexists(self.p.site):
            require(digest(read_file(self.p.site, self.uid)) == state['site_sha256'], 'owned_site_changed')

    def verify(self, state):
        self.owned(state)
        require(self.p.enabled.is_symlink() and self.p.site.is_file(), 'probe_not_enabled')
        body = read_file(self.p.health, self.uid)
        require(digest(body) == state['health_sha256'], 'owned_health_changed')
        self.config()
        assert_additive(state['before'], self.host.vhosts(), str(self.p.enabled), self.host.HTTPS_PORT,
                        first_tls=state.get('first_tls', False))
        require(self.host.units() == state['units'] and self.host.http_status() == state['http_status'], 'shared_services_or_HTTP_changed')
        deadline = time.monotonic() + 8
        while True:
            try:
                require(self.host.tls_check(self.p.ca, body) == state['leaf']['fingerprint'], 'unexpected_live_leaf')
                break
            except (Refusal, OSError, http.client.HTTPException):
                if time.monotonic() >= deadline:
                    raise Refusal('tls_probe_failed') from None
                time.sleep(0.2)

    def prepare(self):
        with self.locked():
            if self.p.state.exists():
                prior = self.load()
                if prior['phase'] == 'tls_probe_ready':
                    self.verify(prior); return self.public(prior)
                require(prior['phase'] == 'rolled_back', 'unfinished_stage_requires_rollback')
            baseline = self.check()
            folder = self.p.base / ('run-' + uuid.uuid4().hex)
            folder.mkdir(mode=0o700)
            state = {'schema': 1, 'phase': 'preparing', 'reload_attempted': False, **baseline}
            self.save(state)
            try:
                leaf = self.host.certificate(self.p.ca, self.p.ca_key, folder)
                require(self.host.authority(self.p.ca, self.p.ca_key) == baseline['CA_fingerprint'], 'CA_changed')
                directory(self.p.web, 0o755, self.uid, self.p.root)
                directory(self.p.health.parent, 0o755, self.uid, self.p.root)
                require(not os.path.lexists(self.p.health), 'existing_health_file_requires_review')
                body = ('<!doctype html><meta charset="utf-8"><title>USTAR TLS</title>'
                    '<h1>USTAR: проверка HTTPS</h1><p>Сертификат установлен. '
                    'Академия пока работает по прежнему HTTP-адресу.</p><p>' + folder.name + '</p>').encode()
                text = render_vhost(self.p, folder / 'server.crt', folder / 'server.key', self.host.HTTPS_PORT).encode()
                state.update(leaf=leaf, site_sha256=digest(text), health_sha256=digest(body))
                self.save(state)
                atomic(self.p.health, body, 0o644)
                atomic(self.p.site, text, 0o644)
                os.symlink(str(self.p.site), self.p.enabled)
                sync_directory(self.p.enabled.parent)
                assert_additive(baseline['before'], self.host.test_config(), str(self.p.enabled), self.host.HTTPS_PORT,
                                first_tls=baseline['first_tls'])
                self.config()
                state['reload_attempted'] = True; self.save(state)
                self.host.reload()
                self.verify(state)
                state['phase'] = 'tls_probe_ready'; self.save(state)
                return self.public(state)
            except BaseException:
                if self.p.state.exists():
                    self._rollback(self.load())
                raise

    def _rollback(self, state):
        self.owned(state)
        if os.path.lexists(self.p.enabled):
            self.p.enabled.unlink(); sync_directory(self.p.enabled.parent)
        if state['reload_attempted']:
            self.host.test_config(); self.host.reload()
        if os.path.lexists(self.p.site): self.p.site.unlink()
        if ('health_sha256' in state and self.p.health.exists()
                and digest(read_file(self.p.health, self.uid)) == state['health_sha256']):
            self.p.health.unlink()
        state['phase'] = 'rolled_back'; self.save(state)
        return self.public(state)

    def rollback(self):
        with self.locked(): return self._rollback(self.load())

    def status(self):
        if not self.p.state.exists(): return {'phase': 'not_prepared'}
        return self.public(self.load())


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    for mode in ('check', 'prepare', 'status', 'rollback'): modes.add_argument('--' + mode, action='store_true')
    parser.add_argument('--first-tls-vhost', action='store_true',
                        help='Explicitly replace plain HTTP on 443 with the first TLS vhost; requires no existing 443 vhosts')
    args = parser.parse_args(argv)
    if not any((args.check, args.prepare, args.status, args.rollback)):
        print('{"phase":"plan_only","host_access":false,"next":"--check then --prepare creates only a TLS probe"}')
        return 0
    try:
        require(os.geteuid() == 0 and sys.version_info >= (3, 12), 'root_python312_required')
        os.umask(0o077)
        def stop(_signal, _frame): raise KeyboardInterrupt()
        for sig in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT): signal.signal(sig, stop)
        manager = Manager(first_tls=args.first_tls_vhost)
        if args.check:
            report = manager.check()
            result = {'phase': 'ready_for_TLS_probe', 'CA_fingerprint': report['CA_fingerprint'], 'host_configuration_changed': False, 'first_TLS_vhost': report['first_tls']}
        elif args.prepare: result = manager.prepare()
        elif args.rollback: result = manager.rollback()
        else: result = manager.status()
        print(json.dumps(result, ensure_ascii=False, indent=2)); return 0
    except (Exception, KeyboardInterrupt) as error:
        reason = str(error) if isinstance(error, Refusal) and re.fullmatch('[A-Za-z_]{1,80}', str(error)) else 'TLS_probe_refused'
        print(json.dumps({'phase': 'refused', 'reason': reason})); return 2


if __name__ == '__main__': raise SystemExit(main())
