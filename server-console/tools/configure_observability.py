#!/usr/bin/env python3
"""Opt-in, root-local numeric Apache logging. No listener/proxy/Academy changes.

Without --install this only checks the existing vhost and reports the plan.
This helper is intentionally not exposed through the web/operations broker.
"""
import argparse
from datetime import datetime, timezone
import fcntl
import grp
import json
import os
from pathlib import Path
import re
import secrets
import selectors
import shlex
import signal
import stat
import subprocess
import tempfile
import time

APACHE = Path('/etc/apache2')
ENABLED = APACHE/'sites-enabled'
AVAILABLE = APACHE/'sites-available'
INCLUDE = APACHE/'ustar-console-observability.conf'
LOG = Path('/var/log/apache2/ustar-console-access.log')
ROTATE = Path('/etc/logrotate.d/ustar-console-observability')
LOCK = Path('/run/ustar-console-observability.lock')
ROOT_UID = 0
ADM_GID = grp.getgrnam('adm').gr_gid
DUMP = ('/usr/sbin/apache2ctl', '-t', '-D', 'DUMP_VHOSTS')
CONFIGTEST = ('/usr/sbin/apache2ctl', 'configtest')
RELOAD = ('/usr/bin/systemctl', 'reload', 'apache2.service')
BEGIN = '# BEGIN USTAR CONSOLE OBSERVABILITY'
END = '# END USTAR CONSOLE OBSERVABILITY'
LIMIT = 1024*1024


class ObservabilityError(Exception):
    """Safe fixed diagnostic: never include raw configuration or command output."""


def _require(condition, message):
    if not condition:
        raise ObservabilityError(message)


def _trusted(path, directory=False):
    info = path.lstat()
    _require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
             and info.st_uid == ROOT_UID and not info.st_mode & 0o022
             and (directory or info.st_nlink == 1), 'Untrusted root-local path')
    return info


def _parents(path):
    for parent in path.parents:
        _trusted(parent, directory=True)


def _read(path):
    _parents(path)
    before = _trusted(path)
    _require(before.st_size <= LIMIT, 'Configuration exceeds limit')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        actual = os.fstat(stream.fileno())
        _require((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns)
                 == (actual.st_dev, actual.st_ino, actual.st_size, actual.st_mtime_ns),
                 'Configuration changed during read')
        raw = stream.read(LIMIT+1)
        after = os.fstat(stream.fileno())
        _require(len(raw) <= LIMIT and after.st_mtime_ns == actual.st_mtime_ns
                 and after.st_size == actual.st_size, 'Configuration changed during read')
    return raw, before


def _run(argv):
    _require(tuple(argv) in (DUMP, CONFIGTEST, RELOAD), 'Unsupported fixed operation')
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                               env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C'},
                               start_new_session=True)
    output = bytearray()
    deadline = time.monotonic()+8
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                remaining = deadline-time.monotonic()
                _require(remaining > 0 and selector.select(remaining), 'Fixed operation timed out')
                chunk = os.read(process.stdout.fileno(), 8192)
                if not chunk:
                    break
                output.extend(chunk)
                _require(len(output) <= LIMIT, 'Fixed operation output exceeds limit')
        _require(process.wait(timeout=max(.01, deadline-time.monotonic())) == 0,
                 'Fixed Apache operation failed')
        return output.decode('utf-8')
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        process.stdout.close()


def _managed():
    return (f'    {BEGIN}\n    Include {INCLUDE}\n    {END}\n')


def _blocks(text):
    """Conservative local grammar: refuse indirect/conditional target vhosts."""
    lines = text.splitlines(keepends=True)
    stack = []
    current = None
    targets = []
    for number, line in enumerate(lines):
        # Apache tag lines can contain spaces inside quoted arguments.
        stripped = line.strip()
        if not stripped or stripped.startswith('#'):
            continue
        if stripped.startswith('<'):
            match = re.fullmatch(r'<(/?)([A-Za-z]+)(?:\s+[^<>]*)?>\s*(?:#.*)?', stripped)
            _require(match is not None, 'Unsupported Apache block syntax')
            close, name = match.group(1), match.group(2).lower()
            if close:
                _require(stack and stack[-1] == name, 'Unbalanced Apache blocks')
                stack.pop()
                if name == 'virtualhost':
                    _require(current is not None, 'Unbalanced VirtualHost')
                    if 'ustar.local' in current['names']:
                        _require(current['names'] == ['ustar.local'] and not current['indirect'],
                                 'Ambiguous or indirect Academy VirtualHost')
                        _require(current['access_log'],
                                 'Refusing to suppress inherited access logging; direct CustomLog is required')
                        targets.append((current['start'], number))
                    current = None
            else:
                if name == 'virtualhost':
                    _require(not stack and current is None, 'Conditional VirtualHost is unsupported')
                    current = dict(start=number, names=[], indirect=False, access_log=False)
                stack.append(name)
            continue
        try:
            words = shlex.split(stripped, comments=True, posix=True)
        except ValueError as error:
            raise ObservabilityError('Unsupported Apache directive syntax') from error
        if not words or current is None:
            continue
        directive = words[0].lower()
        if directive == 'servername':
            _require(stack == ['virtualhost'] and len(words) == 2, 'Indirect ServerName is unsupported')
            current['names'].append(words[1].lower())
        if directive == 'customlog' and stack == ['virtualhost']:
            current['access_log'] = True
        if directive in ('include', 'includeoptional'):
            if words != ['Include', str(INCLUDE)] or stack != ['virtualhost']:
                current['indirect'] = True
    _require(not stack and current is None, 'Unclosed Apache block')
    return lines, targets


def _plan():
    _parents(ENABLED/'placeholder'); _trusted(ENABLED, directory=True)
    _parents(AVAILABLE/'placeholder'); _trusted(AVAILABLE, directory=True)
    paths = list(ENABLED.glob('*.conf'))
    _require(0 < len(paths) <= 64, 'Enabled Apache inventory is empty or exceeds limit')
    targets = []
    seen = set()
    for enabled in paths:
        # Debian's enabled sites are intentional symlinks, only to the trusted fixed directory.
        canonical = enabled.resolve(strict=True)
        _require(canonical.parent == AVAILABLE and canonical.suffix == '.conf',
                 'Enabled site is outside the trusted sites-available directory')
        if canonical in seen:
            continue
        seen.add(canonical)
        raw, info = _read(canonical)
        try:
            text = raw.decode('utf-8')
        except UnicodeError as error:
            raise ObservabilityError('Apache source is not UTF-8') from error
        lines, blocks = _blocks(text)
        targets += [(canonical, raw, info, lines, start, end) for start, end in blocks]
    _require(len(targets) == 1, 'Expected exactly one explicit ServerName ustar.local')
    target, raw, info, lines, start, end = targets[0]
    # Confirm Apache's effective inventory agrees with the exact source block.
    dump = _run(DUMP)
    matches = [line for line in dump.splitlines() if re.search(r'(?<![A-Za-z0-9_.-])ustar\.local(?![A-Za-z0-9_.-])', line)]
    _require(len(matches) == 1, 'Effective Academy VirtualHost is missing or ambiguous')
    match = re.search(r'\((/[^():]+):([0-9]+)\)\s*$', matches[0])
    _require(match is not None and Path(match.group(1)).resolve(strict=True) == target
             and int(match.group(2)) == start+1 and 'alias' not in matches[0].lower(),
             'Effective Academy VirtualHost differs from checked source')
    block = ''.join(lines[start:end+1])
    marker_count = raw.count(BEGIN.encode())
    _require(marker_count <= 1 and raw.count(END.encode()) == marker_count,
             'Managed include markers are ambiguous')
    if marker_count:
        _require(_managed() in block and block.count(str(INCLUDE)) == 1,
                 'Existing managed include differs')
        updated = raw
    else:
        _require(str(INCLUDE) not in raw.decode('utf-8'), 'Unmanaged observability include exists')
        updated = (''.join(lines[:end])+_managed()+''.join(lines[end:])).encode('utf-8')
    return target, raw, info, updated


def _include_content():
    return ('# Managed by USTAR console configure_observability.py\n'
            'SetEnvIfNoCase User-Agent "^USTAR-Console-Probe/1$" ustar_console_probe\n'
            'LogFormat "%{end:sec}t %>s %D" ustar_console_numeric\n'
            f'CustomLog {LOG} ustar_console_numeric env=!ustar_console_probe\n').encode()


def _rotate_content():
    return (f'{LOG} {{\n    weekly\n    maxsize 10M\n    rotate 2\n    missingok\n'
            '    notifempty\n    compress\n    delaycompress\n    create 0640 root adm\n'
            '    sharedscripts\n    postrotate\n'
            '        /usr/sbin/invoke-rc.d apache2 reload > /dev/null 2>&1\n'
            '    endscript\n}\n').encode()


def _optional(path, expected):
    _parents(path)
    if os.path.lexists(path):
        raw, _ = _read(path)
        _require(raw == expected, 'Existing managed configuration differs; refusing overwrite')
        return True
    return False


def _write(path, raw, mode=0o644, uid=None, gid=0):
    _parents(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.ustar-observability-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), mode)
            os.fchown(stream.fileno(), ROOT_UID if uid is None else uid, gid)
            stream.write(raw); stream.flush(); os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def _preflight():
    _require(os.geteuid() == 0, 'Run this fixed helper locally as root')
    plan = _plan()
    include_exists = _optional(INCLUDE, _include_content())
    rotate_exists = _optional(ROTATE, _rotate_content())
    _parents(LOG)
    if os.path.lexists(LOG):
        info = _trusted(LOG)
        _require(info.st_gid == ADM_GID and stat.S_IMODE(info.st_mode) in (0o600, 0o640),
                 'Existing numeric log ownership or mode differs')
    return plan, include_exists, rotate_exists


def check():
    (target, raw, info, updated), include_exists, rotate_exists = _preflight()
    _run(CONFIGTEST)
    return dict(changed=False, configured=raw == updated and include_exists and rotate_exists,
                vhost=str(target), log=str(LOG), window_seconds=300)


def _install():
    (target, raw, info, updated), include_exists, rotate_exists = _preflight()
    if raw == updated and include_exists and rotate_exists and LOG.exists():
        _run(CONFIGTEST)
        return dict(changed=False, configured=True, vhost=str(target), log=str(LOG))
    # Preserve the exact original before any mutation; never overwrite an earlier backup.
    suffix = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+secrets.token_hex(4)
    backup = target.with_name(target.name+'.ustar-observability-backup-'+suffix)
    descriptor = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(raw); stream.flush(); os.fsync(stream.fileno())
    created = []
    log_created = None
    reload_attempted = False
    target_write_attempted = False
    try:
        if not os.path.lexists(LOG):
            descriptor = os.open(LOG, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
            try:
                os.fchmod(descriptor, 0o640); os.fchown(descriptor, ROOT_UID, ADM_GID)
                log_created = os.fstat(descriptor)
            finally:
                os.close(descriptor)
        for path, content, exists in ((INCLUDE, _include_content(), include_exists),
                                      (ROTATE, _rotate_content(), rotate_exists)):
            if not exists:
                _write(path, content); created.append(path)
        current, _ = _read(target)
        _require(current == raw, 'Academy source changed before installation')
        target_write_attempted = True
        _write(target, updated, stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
        _run(CONFIGTEST)
        reload_attempted = True
        _run(RELOAD)
    except BaseException as error:
        # A configtest failure never reloads Apache. A failed reload restores and
        # tests the original, then reloads that original to restore runtime state.
        try:
            if target_write_attempted:
                current, _ = _read(target)
                _require(current in (raw, updated), 'Academy source changed independently; refusing overwrite')
                if current != raw:
                    _write(target, raw, stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
            for path in reversed(created):
                path.unlink()
            if log_created is not None and os.path.lexists(LOG):
                current = LOG.lstat()
                if ((current.st_dev, current.st_ino) == (log_created.st_dev, log_created.st_ino)
                        and current.st_size == 0):
                    LOG.unlink()
            _run(CONFIGTEST)
            if reload_attempted:
                _run(RELOAD)
        except BaseException as rollback_error:
            raise ObservabilityError('Installation failed; rollback requires local review of the preserved backup') from rollback_error
        raise ObservabilityError('Installation failed; managed changes rolled back and configuration checked') from error
    return dict(changed=True, configured=True, backup=str(backup), vhost=str(target), log=str(LOG))


def install():
    _require(os.geteuid() == 0, 'Run this fixed helper locally as root')
    _parents(LOCK)
    descriptor = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    try:
        info = os.fstat(descriptor)
        _require(stat.S_ISREG(info.st_mode) and info.st_uid == ROOT_UID
                 and info.st_nlink == 1 and stat.S_IMODE(info.st_mode) == 0o600,
                 'Untrusted installer lock')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise ObservabilityError('Another local observability installation is running') from error
        return _install()
    finally:
        os.close(descriptor)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true', help='Read-only preflight (default)')
    mode.add_argument('--install', action='store_true', help='Opt in to numeric log configuration and graceful reload')
    args = parser.parse_args(argv)
    try:
        print(json.dumps(install() if args.install else check(), sort_keys=True))
    except (ObservabilityError, OSError, ValueError, subprocess.SubprocessError) as error:
        message = str(error) if isinstance(error, ObservabilityError) else 'Root-local preflight or operation failed'
        parser.exit(1, message+'\n')


if __name__ == '__main__':
    main()
