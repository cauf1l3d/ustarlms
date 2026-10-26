#!/usr/bin/env python3
"""Read minimal HTTPS preparation facts. Never enables TLS, bridge or bot actions."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
from urllib.parse import urlsplit

CONFIG = Path('/opt/ustar/data/moodle/public/config.php')
ORIGIN_REASONS = {'configuration_link', 'configuration_shape', 'configuration_changed',
                  'origin_assignment', 'origin_expression', 'origin_value'}


class ReadinessDeadline(BaseException):
    """Global deadline must escape per-section metadata error handling."""


def command(argv):
    value = subprocess.run(argv, capture_output=True, text=True, timeout=10, check=False)
    if value.returncode or len(value.stdout) > 128 * 1024:
        raise ValueError('host_metadata_unavailable')
    return value.stdout


def origin(path):
    try:
        if any(p.is_symlink() for p in (path, *path.parents)):
            raise ValueError('configuration_link')
        before = path.lstat()
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > 128 * 1024:
            raise ValueError('configuration_shape')
        with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), 'rb') as stream:
            raw = stream.read(128 * 1024 + 1)
            after = os.fstat(stream.fileno())
        if (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (
                after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise ValueError('configuration_changed')
        assignments = re.findall(rb'(?m)^\s*\$CFG->wwwroot\s*=\s*([^;\n]+);', raw)
        if len(assignments) != 1:
            raise ValueError('origin_assignment')
        literal = re.fullmatch(rb"(['\"])([A-Za-z0-9:/._-]+)\1", assignments[0].strip())
        if not literal:
            raise ValueError('origin_expression')
        value = urlsplit(literal[2].decode('ascii'))
        if value.scheme not in ('http', 'https') or not value.hostname or value.username or value.password or value.query or value.fragment:
            raise ValueError('origin_value')
        return {'status': 'literal_configuration', 'scheme': value.scheme,
                'https': value.scheme == 'https', 'certificate_verified': False}
    except Exception as error:
        reason = str(error) if type(error) is ValueError and str(error) in ORIGIN_REASONS else 'configuration_unavailable'
        return {'status': 'unavailable', 'https': False, 'certificate_verified': False, 'reason_code': reason}


def protected_text(path, trusted_uid=0):
    """Only bounded root-owned config metadata; no PHP or webserver execution."""
    if any(p.is_symlink() for p in (path, *path.parents)):
        raise ValueError('config_link')
    before = path.lstat()
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != trusted_uid or before.st_nlink != 1
            or before.st_mode & 0o022 or before.st_size > 128 * 1024):
        raise ValueError('config_shape')
    signature = lambda value: (value.st_dev, value.st_ino, value.st_mode, value.st_uid,
                              value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), 'rb') as stream:
        if signature(before) != signature(os.fstat(stream.fileno())):
            raise ValueError('config_changed')
        raw = stream.read(128 * 1024 + 1)
        if (len(raw) > 128 * 1024 or signature(before) != signature(os.fstat(stream.fileno()))
                or signature(before) != signature(path.lstat())):
            raise ValueError('config_changed')
    return raw.decode('utf-8')


def web_configuration(etc=Path('/etc'), trusted_uid=0):
    result = {'apache': {'status': 'unavailable'}, 'caddy_default_file': {'status': 'unavailable'}}
    try:
        enabled, available = etc / 'apache2/sites-enabled', etc / 'apache2/sites-available'
        if any(p.is_symlink() for p in (enabled, *enabled.parents)):
            raise ValueError('enabled_link')
        paths = sorted(enabled.iterdir())
        if len(paths) > 128:
            raise ValueError('too_many_files')
        entries = []
        for path in paths:
            if path.is_dir():
                continue
            target = path.resolve(strict=True)
            if target.parent not in (enabled, available):
                raise ValueError('external_apache_include')
            raw = protected_text(target, trusted_uid)
            for address, body in re.findall(r'<VirtualHost\s+([^>]+)>(.*?)</VirtualHost\s*>', raw, re.I | re.S):
                names = re.findall(r'(?mi)^\s*(?:ServerName|ServerAlias)\s+([^\r\n#]+)', body)
                if not any(name.lower().split(':')[0] == 'ustar.local' for line in names for name in line.split()):
                    continue
                ports = sorted({int(p) for p in re.findall(r':([0-9]{1,5})(?:\s|$)', address)
                                if 1 <= int(p) <= 65535})
                entries.append({'ports': ports,
                    'TLS_directive': bool(re.search(r'(?mi)^\s*SSLEngine\s+on\s*(?:#.*)?$', body)),
                    'proxy_8082_directive': bool(re.search(r'(?mi)^\s*ProxyPass\s+/\s+http://127\.0\.0\.1:8082(?:/|\s|$)', body)),
                    'certificate_directive': bool(re.search(r'(?mi)^\s*SSLCertificateFile\s+', body)),
                    'includes_present': bool(re.search(r'(?mi)^\s*Include(?:Optional)?\s+', body))})
        result['apache'] = {'status': 'static_metadata_only', 'academy_vhosts': entries,
                            'complete_effective_configuration': False}
    except Exception:
        pass
    try:
        raw = protected_text(etc / 'caddy/Caddyfile', trusted_uid)
        # Lexical hints only: imports/env/placeholders are deliberately not evaluated.
        active_lines = '\n'.join(line for line in raw.splitlines() if not line.lstrip().startswith('#'))
        result['caddy_default_file'] = {'status': 'static_metadata_only',
            'academy_name_present': bool(re.search(r'(?<![\w.-])ustar\.local(?![\w.-])', active_lines)),
            'loopback_8082_present': bool(re.search(r'(?<![\w.-])127\.0\.0\.1:8082(?![0-9])', active_lines)),
            'import_present': bool(re.search(r'(?m)^\s*import\s+', active_lines)),
            'active_config_path_verified': False}
    except Exception:
        pass
    result['console_CA'] = {}
    for label, relative in (('public_certificate', 'ustar-console/authority/ca.crt'),
                            ('signing_key_metadata_only', 'ustar-console/authority/ca.key')):
        path = etc / relative
        try:
            if any(p.is_symlink() for p in (path, *path.parents)):
                raise ValueError('CA_link')
            info = path.lstat()
            safe = stat.S_ISREG(info.st_mode) and info.st_uid == trusted_uid and info.st_nlink == 1 and not info.st_mode & 0o022
            if label == 'signing_key_metadata_only':
                safe = safe and not info.st_mode & 0o077
            result['console_CA'][label] = {'protected_regular_file': bool(safe), 'contents_read': False}
        except Exception:
            result['console_CA'][label] = {'protected_regular_file': False, 'contents_read': False}
    return result


def listener_details(raw):
    result = []
    for line in raw.splitlines():
        fields = line.split()
        if len(fields) < 5:
            continue
        address, _, port = fields[3].rpartition(':')
        if port not in ('80', '443', '8443', '18443'):
            continue
        names = set(re.findall(r'\("([^"\r\n]{1,64})",pid=', line))
        result.append({'port': int(port),
            'binding': 'loopback' if address in ('127.0.0.1', '[::1]', '::1') else 'wildcard' if address in ('*', '0.0.0.0', '[::]', '::') else 'specific_address_redacted',
            'processes': sorted({n if n in ('apache2', 'caddy', 'nginx', 'docker-proxy', 'python3', 'gunicorn') else 'other' for n in names})})
    return result


def check(*, config=CONFIG, runner=command, details=False, etc=Path('/etc')):
    config = Path(config)
    academy = origin(config)
    moodle = {'status': 'unavailable'}
    try:
        template = '{"running":{{json .State.Running}},"ports":{{json .NetworkSettings.Ports}},"networks":{{json .NetworkSettings.Networks}}}'
        row = json.loads(runner(['docker', 'inspect', '--format', template, 'ustar_moodle']))
        if (not isinstance(row, dict) or set(row) != {'running', 'ports', 'networks'}
                or type(row['running']) is not bool or not isinstance(row['ports'], dict)
                or not isinstance(row['networks'], dict) or len(row['ports']) > 32 or len(row['networks']) > 8):
            raise ValueError('moodle_metadata')
        bindings, other = [], False
        for target, values in row['ports'].items():
            if not re.fullmatch('[0-9]{1,5}/tcp', target):
                continue
            for item in values or []:
                if not isinstance(item, dict) or not re.fullmatch('[0-9]{1,5}', str(item.get('HostPort', ''))):
                    raise ValueError('port_metadata')
                port = int(item['HostPort'])
                if not 1 <= port <= 65535:
                    raise ValueError('port_value')
                if item.get('HostIp') in ('127.0.0.1', '::1'):
                    bindings.append({'container_port': target, 'host_port': port})
                else:
                    other = True
        moodle = {'status': 'measured', 'running': row['running'],
            'loopback_bindings': sorted(bindings, key=lambda x: (x['container_port'], x['host_port'])),
            'other_bindings_present': other, 'network_count': len(row['networks'])}
    except Exception:
        pass
    services = {}
    for service in ('apache2', 'caddy'):
        try:
            raw = runner(['systemctl', 'show', service + '.service', '--no-pager', '-p', 'ActiveState', '-p', 'SubState'])
            fields = dict(line.split('=', 1) for line in raw.splitlines() if '=' in line)
            value = fields.get('ActiveState')
            if value not in ('active', 'inactive', 'failed', 'activating', 'deactivating', 'reloading'):
                raise ValueError('service_metadata')
            services[service] = {'status': 'measured', 'active_state': value}
        except Exception:
            services[service] = {'status': 'unavailable'}
    listeners = {'status': 'unavailable'}
    try:
        raw = runner(['ss', '-H', '-ltnp' if details else '-ltn'])
        ports = set()
        for line in raw.splitlines():
            fields = line.split()
            if len(fields) >= 5:
                token = fields[3].rsplit(':', 1)[-1]
                if token.isdecimal():
                    ports.add(int(token))
        listeners = {'status': 'measured', **{str(port): port in ports for port in (80, 443, 8443, 18443)}}
        if details:
            listeners['owners'] = listener_details(raw)
    except Exception:
        pass
    result = {'kind': 'connect_https_readiness', 'observed_at': datetime.now(timezone.utc).isoformat(),
        'status': 'https_origin_present_acceptance_pending' if academy['https'] else 'https_preparation_required',
        'academy_origin': academy, 'moodle': moodle, 'services': services, 'listeners': listeners,
        'activation_ready': False, 'read_only': True, 'PHP_or_Moodle_bootstrap': False,
        'host_route_DNS_proxy_or_service_changes': False, 'secrets_or_raw_configuration_emitted': False,
        'remaining': ['trusted_Academy_HTTPS_origin_acceptance', 'private_bridge_TLS_peer_and_link_only_key',
                      'worker_credentials_and_lifecycle', 'two_sided_account_confirmation', 'opt_in_notifications']}
    if details:
        result['web_configuration'] = web_configuration(Path(etc))
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--details', action='store_true', help='Filtered listener owners and static Academy vhost metadata')
    args = parser.parse_args(argv)
    if args.details and not args.check:
        parser.error('--details requires --check')
    if not args.check:
        print('{"status":"plan_only","host_access":false,"next":"--check reads HTTPS preparation metadata"}')
        return 0
    try:
        if os.geteuid() != 0 or sys.version_info < (3, 12):
            raise ValueError('root_python312_required')
        def deadline(_number, _frame):
            raise ReadinessDeadline()
        prior = signal.signal(signal.SIGALRM, deadline)
        signal.alarm(45)
        try:
            result = check(details=args.details)
        finally:
            signal.alarm(0)
            signal.signal(signal.SIGALRM, prior)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except (Exception, ReadinessDeadline):
        print('{"status":"refused","reason":"readiness_unavailable_no_changes"}')
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
