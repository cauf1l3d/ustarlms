#!/usr/bin/env python3
"""Explicit interactive NOVAVPS node refresh using the installed pinned importer."""
import fcntl
import getpass
import hashlib
import http.client
import json
import os
from pathlib import Path
import secrets
import signal
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import time


def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def command(args, check=True):
    return subprocess.run(args, check=check, timeout=40, stdin=subprocess.DEVNULL,
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def probe(port):
    c = http.client.HTTPSConnection('127.0.0.1', port, timeout=8,
                                    context=ssl.create_default_context())
    try:
        c.set_tunnel('api.telegram.org', 443)
        c.request('GET', '/', headers={'Host': 'api.telegram.org'})
        require(c.getresponse().status in (200, 301, 302, 404), 'telegram_TLS_unverified')
    finally:
        c.close()


def ready(port):
    for _ in range(50):
        with socket.socket() as s:
            s.settimeout(.1)
            if s.connect_ex(('127.0.0.1', port)) == 0:
                return
        time.sleep(.1)
    raise RuntimeError('proxy_not_ready')


def atomic(path, raw):
    fd, name = tempfile.mkstemp(prefix='.refresh-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(raw); f.flush(); os.fsync(f.fileno())
        os.replace(name, path)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    finally:
        Path(name).unlink(missing_ok=True)


def activate(path, before, after, unit):
    require(path.read_bytes() == before, 'configuration_changed_during_probe')
    backup = path.with_name('xray.before-' + secrets.token_hex(8) + '.json')
    atomic(backup, before)
    print('BACKUP=' + str(backup), flush=True)
    changed = False
    try:
        # Set before the atomic write: interruption after rename must also restore.
        changed = True
        atomic(path, after)
        command(['systemctl', 'restart', unit])
        ready(18081); probe(18081)
        command(['systemctl', 'is-active', '--quiet', unit])
    except BaseException:
        if changed:
            require(path.read_bytes() in (before, after), 'recovery_refused_configuration_drift')
            atomic(path, before)
            command(['systemctl', 'restart', unit])
            print('PREVIOUS_CONFIGURATION_RESTORED', flush=True)
        raise


def refresh(e):
    e.trusted_source(); e.binary()
    require(e.UNIT_PATH.read_text() == e.unit_text(), 'managed_unit_changed')
    st = e.CONFIG.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_gid == 0
            and stat.S_IMODE(st.st_mode) == 0o600 and st.st_nlink == 1,
            'unexpected_config_metadata')
    for p in (e.CONFIG.parent, *e.CONFIG.parent.parents):
        st = p.lstat()
        require(stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and not st.st_mode & 0o022,
                'unsafe_config_parent')
    require(not os.listxattr(e.CONFIG), 'config_attributes_require_review')
    before = e.CONFIG.read_bytes()
    current = json.loads(before)
    nodes = [o for o in current.get('outbounds', []) if o.get('protocol') == 'vless']
    require(len(nodes) == 1 and current == e.configuration(nodes[0]), 'managed_config_changed')
    command(['systemctl', 'is-active', '--quiet', e.UNIT])
    value = getpass.getpass('Subscription URL (hidden): ').strip()
    signal.alarm(30)
    try: choices, skipped = e.candidates(e.subscription.fetch_subscription(value))
    finally: signal.alarm(0)
    del value
    print(json.dumps({'candidates': len(choices), 'unsupported': skipped}), flush=True)
    # Prefer alternatives to the currently failing node; still test it last.
    choices.sort(key=lambda o: o == nodes[0])
    for i, node in enumerate(choices, 1):
        unit = 'ustar-egress-refresh-' + secrets.token_hex(6) + '.service'
        selected = None
        with tempfile.TemporaryDirectory(prefix='ustar-egress-refresh-', dir='/run') as td:
            source = Path(td) / 'xray.json'
            try:
                signal.alarm(12)
                try: node = e.pin_endpoint(node)
                finally: signal.alarm(0)
                with socket.socket() as s:
                    s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]
                config = e.configuration(node)
                config['inbounds'][0]['port'] = port
                source.write_text(json.dumps(config)); source.chmod(0o600)
                command(e.xray_command(source, test=True))
                command(e.transient_argv(source, unit))
                ready(port)
                command(['systemctl', 'is-active', '--quiet', unit])
                probe(port)
                command(['systemctl', 'is-active', '--quiet', unit])
                selected = json.dumps(e.configuration(node)).encode()
            except (Exception,):
                print('NODE_' + str(i) + '=UNAVAILABLE', flush=True)
            finally:
                signal.alarm(0)
                command(['systemctl', 'stop', unit], check=False)
            if selected is not None:
                print('NODE_' + str(i) + '=TLS_VERIFIED', flush=True)
                activate(e.CONFIG, before, selected, e.UNIT)
                print('{"phase":"egress_refreshed","telegram_https":"verified","subscription_URL_saved":false}', flush=True)
                return
    raise RuntimeError('no_working_node_original_config_preserved')


def main():
    try:
        require(os.geteuid() == 0, 'root_required')
        os.umask(0o077)
        def timeout(*_): raise TimeoutError()
        def interrupted(*_): raise KeyboardInterrupt()
        signal.signal(signal.SIGALRM, timeout)
        for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            signal.signal(sig, interrupted)
        folder = Path('/opt/ustar-console-egress/tools')
        hashes = {'telegram_egress.py':'9285f1d299122d301136eac662488916ad1c6ac76ef1532011caccfab0fc5816',
                  'subscription_probe.py':'e74d0e2674c6deb4adfb629784bf97e475cebdaa20219c76875ecf1a3fab8eb5'}
        for name, digest in hashes.items():
            p = folder/name
            for item in (p, *p.parents):
                st = item.lstat()
                require(st.st_uid == 0 and not st.st_mode & 0o022 and not item.is_symlink(), 'untrusted_helper')
            require(hashlib.sha256(p.read_bytes()).hexdigest() == digest, 'installed_helper_version_mismatch')
        sys.path.insert(0, str(folder))
        import telegram_egress as e
        fd = os.open('/run/lock/ustar-egress-refresh.lock',
                     os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        with os.fdopen(fd, 'r+') as lock:
            st = os.fstat(lock.fileno())
            require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1
                    and not st.st_mode & 0o077, 'unsafe_refresh_lock')
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            refresh(e)
        return 0
    except BaseException as error:
        reason = str(error) if type(error) is RuntimeError else type(error).__name__
        print(json.dumps({'phase':'refused','reason':reason}), flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
