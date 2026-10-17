#!/usr/bin/env python3
"""Local Telegram-only Xray setup. No TUN, host routes, public listener, or secrets in output."""
import argparse
import copy
import getpass
import hashlib
import http.client
import io
import ipaddress
import json
import os
from pathlib import Path
import platform
import secrets
import signal
import socket
import stat
import subprocess
import tempfile
import time
import urllib.request
import zipfile

import subscription_probe as subscription

VERSION = 'v26.3.27'
ARCHIVE_URL = 'https://github.com/XTLS/Xray-core/releases/download/'+VERSION+'/Xray-linux-64.zip'
ARCHIVE_SHA256 = '23cd9af937744d97776ee35ecad4972cf4b2109d1e0fe6be9930467608f7c8ae'
BINARY_SHA256 = '8255dd939c34cf966cc91517b6324dd3c8d0bcf49ffac8beca049a38c46845ed'
BINARY = Path('/opt/ustar-console-egress')/VERSION/'xray'
SCRIPTS = Path('/opt/ustar-console-egress/tools')
CONFIG = Path('/etc/ustar-console-egress/xray.json')
UNIT = 'ustar-telegram-egress.service'
UNIT_PATH = Path('/etc/systemd/system')/UNIT
PROPERTIES = [
    'DynamicUser=yes', 'UMask=0077', 'NoNewPrivileges=yes', 'CapabilityBoundingSet=',
    'ProtectSystem=strict', 'ProtectHome=yes', 'PrivateTmp=yes', 'PrivateDevices=yes',
    'ProtectKernelTunables=yes', 'ProtectKernelModules=yes', 'ProtectControlGroups=yes',
    'RestrictNamespaces=yes', 'RestrictSUIDSGID=yes', 'LockPersonality=yes',
    'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6', 'MemoryMax=128M', 'CPUQuota=10%',
    'TasksMax=32', 'LimitNOFILE=256', 'LimitCORE=0', 'StandardOutput=null', 'StandardError=null',
]


class SetupError(Exception):
    pass


def require(condition, code='unsupported_node'):
    if not condition:
        raise SetupError(code)


def text(value, maximum=512, empty=False):
    require(isinstance(value, str) and (empty or bool(value)) and len(value) <= maximum
            and not any(ord(char) < 32 or ord(char) == 127 for char in value))
    return value


def hostname(value):
    value = text(value, 253)
    try:
        address = ipaddress.ip_address(value)
    except ValueError:
        import re
        require(value.isascii() and '.' in value and all(
            re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label)
            for label in value.rstrip('.').split('.')))
    else:
        require(address.is_global)
    return value


def candidate(outbound):
    """Rebuild a narrow client profile; never import provider listeners/rules/files."""
    require(isinstance(outbound, dict) and outbound.get('protocol') == 'vless')
    require(not outbound.get('proxySettings') and outbound.get('sendThrough') in (None, '', '0.0.0.0', '::'))
    settings = outbound.get('settings', {})
    require(isinstance(settings, dict) and not settings.get('reverse'))
    if 'vnext' in settings:
        servers = settings['vnext']
        require(isinstance(servers, list) and len(servers) == 1 and isinstance(servers[0], dict))
        server = servers[0]
        users = server.get('users')
        require(isinstance(users, list) and len(users) == 1 and isinstance(users[0], dict))
        user = users[0]
    else:
        server = user = settings
    address, port = hostname(server.get('address')), server.get('port')
    require(type(port) is int and 1 <= port <= 65535)
    require(user.get('encryption', 'none') == 'none' and not user.get('reverse'))
    account = dict(id=text(user.get('id'), 128), encryption='none')
    flow = user.get('flow', '')
    require(flow in ('', 'xtls-rprx-vision', 'xtls-rprx-vision-udp443'))
    if flow:
        account['flow'] = flow
    stream = outbound.get('streamSettings', {})
    require(isinstance(stream, dict))
    sockopt = stream.get('sockopt') or {}
    require(isinstance(sockopt, dict) and not sockopt.get('dialerProxy'))
    network = stream.get('network', stream.get('method', 'tcp'))
    require(network in ('tcp', 'raw', 'grpc') and not (network == 'grpc' and flow))
    security = stream.get('security')
    require(security in ('tls', 'reality'))
    transport = dict(network=network, security=security)
    mode = stream.get(security+'Settings', {})
    require(isinstance(mode, dict) and mode.get('allowInsecure', False) is False
            and not mode.get('disableSystemRoot') and not mode.get('certificates')
            and not mode.get('privateKey'))
    fields = ('serverName', 'fingerprint', 'publicKey', 'password', 'shortId', 'spiderX', 'mldsa65Verify') if security == 'reality' else (
        'serverName', 'fingerprint', 'minVersion', 'maxVersion', 'cipherSuites')
    cleaned = {key: text(mode[key], 1024, empty=key in ('shortId', 'spiderX')) for key in fields if key in mode}
    if security == 'reality':
        require(cleaned.get('serverName') and (cleaned.get('publicKey') or cleaned.get('password')))
    else:
        cleaned.setdefault('serverName', address)
        cleaned['allowInsecure'] = False
    if 'alpn' in mode:
        alpn = mode['alpn']
        require(isinstance(alpn, list) and 1 <= len(alpn) <= 4)
        cleaned['alpn'] = [text(item, 32) for item in alpn]
    transport[security+'Settings'] = cleaned
    if network == 'grpc':
        grpc = stream.get('grpcSettings', {})
        require(isinstance(grpc, dict))
        cleaned_grpc = {key: text(grpc[key], 512, empty=True)
                        for key in ('serviceName', 'authority', 'user_agent') if key in grpc}
        if 'multiMode' in grpc:
            require(type(grpc['multiMode']) is bool)
            cleaned_grpc['multiMode'] = grpc['multiMode']
        transport['grpcSettings'] = cleaned_grpc
    else:
        tcp = stream.get('tcpSettings', stream.get('rawSettings', {})) or {}
        require(isinstance(tcp, dict) and isinstance(tcp.get('header', {}), dict)
                and tcp.get('header', {}).get('type', 'none') == 'none')
    return dict(tag='telegram_vless', protocol='vless', settings=dict(vnext=[dict(
        address=address, port=port, users=[account])]), streamSettings=transport)


def candidates(body):
    require(len(body) <= subscription.LIMIT, 'response_too_large')
    value = json.loads(body)
    profiles = value if isinstance(value, list) else [value]
    result, seen, skipped = [], set(), 0
    for profile in profiles[:subscription.NODE_LIMIT]:
        if not isinstance(profile, dict) or not isinstance(profile.get('outbounds'), list):
            continue
        for outbound in profile['outbounds'][:subscription.NODE_LIMIT]:
            if not isinstance(outbound, dict) or outbound.get('protocol') != 'vless':
                continue
            try:
                item = candidate(outbound)
            except SetupError:
                skipped += 1
                continue
            key = json.dumps(item, sort_keys=True)
            if key not in seen:
                seen.add(key)
                result.append(item)
            require(len(result) <= 32, 'too_many_nodes')
    result.sort(key=lambda item: item['streamSettings']['network'] == 'grpc')
    require(bool(result), 'no_supported_secure_VLESS_nodes')
    return result, skipped


def pin_endpoint(outbound):
    item = copy.deepcopy(outbound)
    server = item['settings']['vnext'][0]
    # Pin the resolved public IPv4. Provider DNS cannot later redirect the client into LAN.
    addresses = {entry[4][0] for entry in socket.getaddrinfo(
        server['address'], server['port'], family=socket.AF_INET, type=socket.SOCK_STREAM)}
    require(bool(addresses) and all(ipaddress.ip_address(a).is_global for a in addresses), 'node_not_public_IPv4')
    server['address'] = sorted(addresses)[0]
    return item


def configuration(outbound):
    return dict(log=dict(access='none', error='none', loglevel='none', dnsLog=False),
        inbounds=[dict(tag='telegram_http', listen='127.0.0.1', port=18081,
                       protocol='http', settings=dict(allowTransparent=False))],
        outbounds=[dict(tag='blocked', protocol='blackhole'), outbound],
        routing=dict(domainStrategy='AsIs', rules=[dict(type='field', inboundTag=['telegram_http'],
            domain=['full:api.telegram.org'], port='443', network='tcp', outboundTag='telegram_vless')]))


def unit_text():
    return ('[Unit]\nDescription=USTAR Telegram-only loopback egress\nAfter=network-online.target\n'
            'Wants=network-online.target\n\n[Service]\nType=simple\n'+
            '\n'.join(PROPERTIES)+'\nLoadCredential=xray:'+str(CONFIG)+'\nExecStart='+str(BINARY)+
            ' run -config %d/xray\nRestart=on-failure\nRestartSec=15\n\n[Install]\nWantedBy=multi-user.target\n')


def run(argv, check=True):
    return subprocess.run(argv, check=check, timeout=35, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def transient_argv(source, name):
    argv = ['systemd-run', '--quiet', '--collect', '--expand-environment=yes', '--unit='+name]
    for prop in PROPERTIES+['LoadCredential=xray:'+str(source), 'RuntimeMaxSec=25s']:
        argv.append('--property='+prop)
    return argv+[str(BINARY), 'run', '-config', '${CREDENTIALS_DIRECTORY}/xray']


def trusted_source():
    for path in (Path(__file__).absolute(), Path(subscription.__file__).absolute()):
        for entry in (path, *path.parents):
            info = entry.lstat()
            require(info.st_uid == 0 and not info.st_mode & 0o022 and not stat.S_ISLNK(info.st_mode), 'untrusted_source')


def binary():
    require(platform.machine() == 'x86_64', 'requires_x86_64')
    if BINARY.exists():
        for entry in (BINARY, *BINARY.parents):
            info = entry.lstat()
            require(info.st_uid == 0 and not info.st_mode & 0o022 and not stat.S_ISLNK(info.st_mode), 'untrusted_binary_directory')
        require(hashlib.sha256(BINARY.read_bytes()).hexdigest() == BINARY_SHA256, 'binary_mismatch')
        return
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    print('Downloading pinned Xray binary...', flush=True)
    def deadline(*_): raise SetupError('binary_download_deadline')
    previous = signal.signal(signal.SIGALRM, deadline)
    signal.alarm(120)
    try:
        with opener.open(ARCHIVE_URL, timeout=15) as response:
            data = response.read(32*1024*1024+1)
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous)
    require(hashlib.sha256(data).hexdigest() == ARCHIVE_SHA256, 'archive_checksum_mismatch')
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        info = archive.getinfo('xray')
        require(info.file_size <= 64*1024*1024, 'binary_too_large')
        content = archive.read(info)
    require(hashlib.sha256(content).hexdigest() == BINARY_SHA256, 'binary_checksum_mismatch')
    for directory in (BINARY.parent.parent, BINARY.parent):
        directory.mkdir(mode=0o755, exist_ok=True)
        info = directory.lstat()
        require(info.st_uid == 0 and not info.st_mode & 0o022 and not directory.is_symlink(), 'untrusted_binary_directory')
        directory.chmod(0o755)
    for directory in (BINARY.parent, *BINARY.parent.parents):
        info = directory.lstat()
        require(info.st_uid == 0 and not info.st_mode & 0o022 and not stat.S_ISLNK(info.st_mode), 'untrusted_binary_directory')
    with BINARY.open('xb') as target:
        target.write(content)
    BINARY.chmod(0o755)
    print('XRAY_BINARY_SHA256=OK', flush=True)


def probe():
    from ustar_console.telegram_transport import exchange, PROXY
    status, _ = exchange('/', proxy=PROXY)
    require(status in (200, 301, 302, 404), 'unexpected_Telegram_HTTP_status')
    return dict(telegram_https='verified', HTTP_status=status, proxy='loopback_CONNECT',
                token_used=False, host_routes_changed=False)


def ready():
    for _ in range(20):
        with socket.socket() as sock:
            sock.settimeout(.1)
            if sock.connect_ex(('127.0.0.1', 18081)) == 0:
                return
        time.sleep(.1)
    raise SetupError('proxy_not_listening')


def write_new(path, content, mode):
    with path.open('x') as stream:
        os.chmod(path, mode)
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())


def public_tools():
    SCRIPTS.mkdir(mode=0o755, exist_ok=True)
    for directory in (SCRIPTS, *SCRIPTS.parents):
        info = directory.lstat()
        require(info.st_uid == 0 and not info.st_mode & 0o022 and not directory.is_symlink(), 'untrusted_helper_directory')
    SCRIPTS.chmod(0o755)
    for source in (Path(__file__).absolute(), Path(subscription.__file__).absolute()):
        target = SCRIPTS/source.name
        if target.exists():
            info = target.lstat()
            require(info.st_uid == 0 and not info.st_mode & 0o022 and not target.is_symlink()
                    and target.read_bytes() == source.read_bytes(), 'existing_helper_mismatch')
        else:
            write_new(target, source.read_text(), 0o644)


def install():
    require(os.geteuid() == 0 and os.isatty(0), 'root_interactive_TTY_required')
    trusted_source()
    from ustar_console.telegram_transport import PROXY
    require(not CONFIG.exists() and not UNIT_PATH.exists(), 'existing_egress_preserved_use_check')
    with socket.socket() as sock:
        try:
            sock.bind(('127.0.0.1', 18081))
        except OSError:
            raise SetupError('loopback_port_in_use') from None
    binary()
    value = getpass.getpass('Subscription URL (hidden): ').strip()
    def deadline(*_): raise SetupError('subscription_deadline')
    previous = signal.signal(signal.SIGALRM, deadline)
    signal.alarm(30)
    try:
        choices, skipped = candidates(subscription.fetch_subscription(value))
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous)
    del value
    print(json.dumps(dict(secure_candidates=len(choices), unsupported_skipped=skipped)), flush=True)
    selected = None
    with tempfile.TemporaryDirectory(prefix='ustar-egress-', dir='/run') as directory:
        source = Path(directory)/'xray.json'
        source.touch(mode=0o600)
        for index, item in enumerate(choices[:15], 1):
            transient = 'ustar-egress-probe-'+secrets.token_hex(6)
            try:
                previous = signal.signal(signal.SIGALRM, deadline)
                signal.alarm(12)
                try:
                    item = pin_endpoint(item)
                finally:
                    signal.alarm(0)
                    signal.signal(signal.SIGALRM, previous)
                source.write_text(json.dumps(configuration(item)))
                run(transient_argv(source, transient))
                ready()
                probe()
                selected = item
                print('NODE_'+str(index)+'=TLS_VERIFIED', flush=True)
                break
            except (SetupError, OSError, ValueError, http.client.HTTPException, subprocess.SubprocessError):
                print('NODE_'+str(index)+'=UNAVAILABLE', flush=True)
            finally:
                signal.alarm(0)
                run(['systemctl', 'stop', transient], check=False)
    require(selected is not None, 'no_working_node_selected')
    config_created = unit_created = False
    try:
        CONFIG.parent.mkdir(mode=0o700, exist_ok=True)
        info = CONFIG.parent.lstat()
        require(info.st_uid == 0 and not info.st_mode & 0o077 and not CONFIG.parent.is_symlink(), 'untrusted_config_directory')
        write_new(CONFIG, json.dumps(configuration(selected)), 0o600)
        config_created = True
        write_new(UNIT_PATH, unit_text(), 0o644)
        unit_created = True
        run(['systemd-analyze', 'verify', str(UNIT_PATH)])
        run(['systemctl', 'daemon-reload'])
        run(['systemctl', 'enable', '--now', UNIT])
        ready()
        result = probe()
        require(run(['systemctl', 'is-active', '--quiet', UNIT], check=False).returncode == 0, 'service_not_active')
        public_tools()
    except BaseException:
        if unit_created:
            run(['systemctl', 'disable', '--now', UNIT], check=False)
            UNIT_PATH.unlink(missing_ok=True)
            run(['systemctl', 'daemon-reload'], check=False)
        if config_created:
            CONFIG.unlink(missing_ok=True)
        raise
    result.update(service='active', configuration_installed=True, subscription_URL_saved=False)
    print(json.dumps(result, indent=2))


def telegram_id():
    require(os.isatty(0), 'interactive_TTY_required')
    from ustar_console.alerts import Telegram
    from ustar_console.telegram_transport import PROXY
    api = Telegram(getpass.getpass('Bot token (hidden): ').strip(), proxy=PROXY)
    api('getMe', {})
    require(not api('getWebhookInfo', {}).get('url'), 'existing_webhook_preserved')
    proof = 'ustar-'+secrets.token_hex(8)
    print('Send this text to your bot in a private chat: '+proof, flush=True)
    input('After sending, press Enter here: ')
    ids = set()
    for item in api('getUpdates', dict(limit=100, timeout=0)):
        message = item.get('message', {})
        actor, chat = message.get('from', {}), message.get('chat', {})
        if (message.get('text') == proof and chat.get('type') == 'private'
                and type(actor.get('id')) is int and actor['id'] > 0
                and actor.get('id') == chat.get('id') and not actor.get('is_bot')
                and not message.get('forward_origin') and not message.get('forward_date')):
            ids.add(actor['id'])
    require(len(ids) == 1, 'proof_message_not_found_repeat_command')
    print('TELEGRAM_ID='+str(ids.pop()))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('install', 'check', 'telegram-id'))
    args = parser.parse_args()
    os.umask(0o077)
    try:
        if args.action == 'install':
            install()
        elif args.action == 'check':
            print(json.dumps(probe(), indent=2))
        else:
            telegram_id()
    except (KeyboardInterrupt, EOFError):
        print(json.dumps(dict(result='unavailable', reason='cancelled')))
        return 1
    except (SetupError, subscription.ProbeError) as error:
        print(json.dumps(dict(result='unavailable', reason=str(error))))
        return 1
    except Exception:
        # Remote JSON, URLs, token-bearing paths and binary output stay private.
        print(json.dumps(dict(result='unavailable', reason='setup_or_transport_failed')))
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
