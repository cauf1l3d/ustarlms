#!/usr/bin/env python3
"""Install/status the initial USTAR HDD timer, and handle its SSD recovery state.

Daily 03:30 Europe/Moscow, no missed-event catch-up or archive deletion.
Installation verifies a recent successful HDD trial and creates only these units.
Scheduled execution starts only within 03:30..03:40 Moscow. Recovery resumes the
exact container recorded by the pinned producer; it never restores an archive.
"""
import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import signal
import sys
import tempfile
import types
from zoneinfo import ZoneInfo

PRODUCER = Path('/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py')
PRODUCER_SHA = '75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75'
HELPER = '/usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py'
UNITS = Path('/etc/systemd/system')
SERVICE = 'ustar-hdd-backup.service'
RECOVERY = 'ustar-hdd-backup-recover.service'
TIMER = 'ustar-hdd-backup.timer'
REPORT = 'last-hdd-scheduled-run.json'
MOSCOW = ZoneInfo('Europe/Moscow')
CALENDAR = '*-*-* 03:30:00 Europe/Moscow'


def load_producer():
    import stat
    for path in PRODUCER.parents:
        info = path.lstat()
        if not (stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022):
            raise RuntimeError('Untrusted producer parent')
    info = PRODUCER.lstat()
    if not (stat.S_ISREG(info.st_mode) and info.st_uid == 0
            and info.st_nlink == 1 and not info.st_mode & 0o022):
        raise RuntimeError('Untrusted producer')
    data = PRODUCER.read_bytes()
    if hashlib.sha256(data).hexdigest() != PRODUCER_SHA:
        raise RuntimeError('Producer SHA mismatch')
    h = types.ModuleType('ustar_scheduled_backup_producer')
    h.__file__ = str(PRODUCER)
    exec(compile(data, str(PRODUCER), 'exec'), h.__dict__)
    return h


def now():
    return datetime.now(timezone.utc)


def parsed(value):
    result = datetime.fromisoformat(value)
    if result.tzinfo is None:
        raise ValueError('Timestamp lacks timezone')
    return result


def in_window(value):
    local = value.astimezone(MOSCOW)
    return local.hour == 3 and 30 <= local.minute < 40


def next_event(value):
    local = value.astimezone(MOSCOW)
    event = local.replace(hour=3, minute=30, second=0, microsecond=0)
    return event if event > local else event + timedelta(days=1)


def units():
    common = 'User=root\nGroup=root\nUMask=0077\nNoNewPrivileges=yes\nPrivateTmp=yes\n'
    return {
        SERVICE: (
            '[Unit]\nDescription=USTAR consistent encrypted HDD backup\n'
            f'Requires=docker.service {RECOVERY}\nAfter=docker.service {RECOVERY}\n\n'
            '[Service]\nType=oneshot\n' + common +
            f'ExecStart=/usr/bin/python3 -u {HELPER} --run\n'
            f'ExecStopPost=/usr/bin/python3 -u {HELPER} --after-service\n'
            'TimeoutStartSec=45min\nTimeoutStopSec=5min\nKillMode=control-group\n'
            'Restart=no\nNice=10\nIOSchedulingClass=best-effort\nIOSchedulingPriority=7\n'
            'StandardOutput=journal\nStandardError=journal\nSyslogIdentifier=ustar-hdd-backup\n'
        ),
        RECOVERY: (
            '[Unit]\nDescription=USTAR resume Moodle from SSD backup marker\n'
            'Requires=docker.service\nAfter=docker.service apache2.service\n'
            f'Before={SERVICE}\n\n[Service]\nType=oneshot\n' + common +
            f'ExecStart=/usr/bin/python3 -u {HELPER} --recover-if-needed\n'
            'TimeoutStartSec=5min\nTimeoutStopSec=5min\nKillMode=control-group\nRestart=no\n\n'
            '[Install]\nWantedBy=multi-user.target\n'
        ),
        TIMER: (
            '[Unit]\nDescription=USTAR HDD backup daily at 03:30 Moscow\n\n'
            f'[Timer]\nOnCalendar={CALENDAR}\nAccuracySec=1s\nRandomizedDelaySec=0\n'
            f'Persistent=false\nUnit={SERVICE}\n\n[Install]\nWantedBy=timers.target\n'
        ),
    }


def atomic_write(h, path, data, mode):
    h.parents_trusted(path)
    if path.exists() or path.is_symlink():
        h.trusted(path, private=mode == 0o600)
    fd, temporary = tempfile.mkstemp(prefix='.ustar-schedule-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fchmod(out.fileno(), mode)
            os.fsync(out.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)


def write_report(h, value):
    h.private_directory(h.SSD_STATE)
    atomic_write(h, h.SSD_STATE / REPORT, (json.dumps(value, indent=2) + '\n').encode(), 0o600)


def read_optional(h, name):
    path = h.SSD_STATE / name
    if path.exists() or path.is_symlink():
        h.parents_trusted(path)
        return h.read_json(path)
    return None


def producer_cli(h, mode):
    previous = sys.argv
    handlers = {sig: signal.getsignal(sig) for sig in h.SIGNALS}
    try:
        sys.argv = [str(PRODUCER), mode]
        if mode == '--backup':
            sys.argv.append('--acknowledge-outage')
        h.main()
    finally:
        sys.argv = previous
        for sig, handler in handlers.items():
            signal.signal(sig, handler)


def resume_if_needed(h):
    marker = read_optional(h, 'moodle-was-running.json')
    if marker is None:
        print('HDD_SCHEDULE_RECOVERY=NOT_NEEDED', flush=True)
        return False
    h.require(marker.get('producer') == 'ustar-hdd-backup-v1',
              'Foreign recovery marker retained; inspect its producer')
    producer_cli(h, '--recover')
    h.http_ready()
    print('HDD_SCHEDULE_RECOVERY=PASS', flush=True)
    return True


def run(h):
    started = now()
    write_report(h, {'started_utc': started.isoformat(), 'phase': 'starting',
                     'success': False, 'calendar': CALENDAR})
    h.require(in_window(started), 'Outside scheduled start window 03:30..03:40 Europe/Moscow')
    producer_cli(h, '--backup')


def after_service(h):
    # ExecStopPost runs even when startup, capture or a timeout failed. Recovery
    # needs the SSD marker and producer only; it does not require a mounted HDD.
    service_result = os.environ.get('SERVICE_RESULT', 'unknown')
    code, status = os.environ.get('EXIT_CODE', 'unknown'), os.environ.get('EXIT_STATUS', 'unknown')
    h.require(service_result != 'unknown', 'ExecStopPost service result missing')
    recovery_error = None
    resumed = False
    try:
        resumed = resume_if_needed(h)
    except Exception as exc:
        recovery_error = type(exc).__name__
        reason = str(exc) if isinstance(exc, h.Refusal) else type(exc).__name__
        print('HDD_SCHEDULE_RECOVERY_FAILED=' + reason, file=sys.stderr, flush=True)
    # A damaged reporting file must not block the marker-based resume above.
    record = read_optional(h, REPORT) or {'started_utc': None}
    success = read_optional(h, 'last-hdd-success.json')
    fresh = bool(record.get('started_utc') and success and success.get('success') is True
                 and parsed(success['started_utc']) >= parsed(record['started_utc'])
                 and success.get('manifest', {}).get('readback_verified') is True
                 and success.get('readiness', {}).get('login_http') == 200)
    marker = h.SSD_STATE / 'moodle-was-running.json'
    passed = (service_result == 'success' and code == 'exited' and status == '0'
              and fresh and recovery_error is None and not (marker.exists() or marker.is_symlink()))
    record.update(finished_utc=now().isoformat(), phase='complete', success=passed,
                  service_result=service_result, exit_code=code, exit_status=status,
                  resumed_from_marker=resumed, recovery_error_type=recovery_error,
                  recovery_marker_present=marker.exists() or marker.is_symlink())
    write_report(h, record)
    print('HDD_SCHEDULE_RESULT=' + ('PASS' if passed else 'FAILED'), flush=True)
    h.require(recovery_error is None, 'Recovery failed; inspect SSD marker and service journal')
    h.require(not (service_result == 'success' and not passed),
              'Service exited successfully without a fresh verified backup')


def trial_gate(h):
    result = read_optional(h, 'last-hdd-success.json')
    h.require(result is not None and result.get('success') is True
              and result.get('readiness', {}).get('login_http') == 200,
              'Successful HDD trial and login readiness required')
    age = (now() - parsed(result['finished_utc'])).total_seconds()
    h.require(0 <= age <= 48 * 3600, 'HDD trial older than 48h or server clock differs')
    engine = h.load_engine()
    with h.anchored_hdd() as anchor, h.backup_lock():
        h.prepare(engine, anchor)
        completed = h.verified_manifest(engine)
        h.require(completed == result.get('manifest'), 'Trial and latest HDD export differ')
    return result


def unit_conflicts(h, content):
    h.parents_trusted(UNITS)
    h.trusted(UNITS, directory=True)
    for name, data in content.items():
        path = UNITS / name
        if path.exists() or path.is_symlink():
            h.trusted(path)
            h.require(path.read_text() == data, 'Existing backup unit differs; no units replaced')
        for folder in (UNITS, Path('/run/systemd/system'), Path('/usr/lib/systemd/system')):
            h.require(not (folder / (name + '.d')).exists()
                      and not (folder / (name + '.d')).is_symlink(), 'Backup unit override present')
            if folder != UNITS:
                h.require(not (folder / name).exists() and not (folder / name).is_symlink(),
                          'Foreign backup unit present')
        loaded = h.command(['systemctl', 'list-units', '--all', '--plain', '--no-legend', '--no-pager', name])
        properties = (h.command(['systemctl', 'show', name, '--property=ActiveState,DropInPaths'])
                      if loaded.strip() else '')
        values = dict(line.split('=', 1) for line in properties.splitlines() if '=' in line)
        h.require(not values.get('DropInPaths'), 'Loaded backup unit overrides present')
        if name != TIMER:
            h.require(values.get('ActiveState') not in ('active', 'activating', 'deactivating'),
                      'Backup/recovery service active; installation deferred')


def install(h):
    h.require((next_event(now()) - now().astimezone(MOSCOW)).total_seconds() > 900,
              'Next backup less than 15min away; install outside this interval')
    trial_gate(h)
    content = units()
    unit_conflicts(h, content)
    with tempfile.TemporaryDirectory(prefix='schedule-verify-', dir=h.SSD_STATE) as temporary:
        paths = []
        for name, value in content.items():
            path = Path(temporary) / name
            path.write_text(value)
            paths.append(str(path))
        h.command(['systemd-analyze', 'verify', *paths], timeout=30)
    # Validate every existing unit before the first write. Identical reruns keep
    # files/inodes. Systemctl enables the timer only, never starts the backup now.
    for name, value in content.items():
        path = UNITS / name
        if not path.exists():
            atomic_write(h, path, value.encode(), 0o644)
    h.command(['systemctl', 'daemon-reload'])
    for name in content:
        properties = h.command(['systemctl', 'show', name, '--property=FragmentPath,DropInPaths'])
        values = dict(line.split('=', 1) for line in properties.splitlines() if '=' in line)
        h.require(values.get('FragmentPath') == str(UNITS / name)
                  and not values.get('DropInPaths'), 'Loaded unit differs from installed definition')
    h.command(['systemctl', 'enable', RECOVERY])
    h.command(['systemctl', 'enable', '--now', TIMER])
    h.require(h.command(['systemctl', 'is-active', TIMER]).strip() == 'active', 'Timer is not active')
    print('HDD_SCHEDULE_INSTALLED=PASS; DAILY=03:30_Europe/Moscow; CATCH_UP=NO; ARCHIVE_DELETION=NO')
    show_status(h)


def show_status(h):
    scheduled = read_optional(h, REPORT)
    success = read_optional(h, 'last-hdd-success.json')
    marker = h.SSD_STATE / 'moodle-was-running.json'
    props = h.command(['systemctl', 'show', TIMER, SERVICE, RECOVERY,
                       '--property=Id,LoadState,ActiveState,UnitFileState,Result,NextElapseUSecRealtime'])
    print(json.dumps({'calendar': CALENDAR, 'catch_up': False, 'archive_deletion': False,
                      'last_scheduled_run': scheduled, 'last_backup_success_utc':
                      success.get('finished_utc') if success else None,
                      'recovery_marker_present': marker.exists() or marker.is_symlink(),
                      'unit_properties': props.strip()}, indent=2))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    for name in ('install', 'status', 'run', 'after-service', 'recover-if-needed'):
        modes.add_argument('--' + name, action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run with sudo')
    os.umask(0o077)
    h = load_producer()
    if args.install:
        install(h)
    elif args.status:
        show_status(h)
    elif args.run:
        run(h)
    elif args.after_service:
        after_service(h)
    else:
        resume_if_needed(h)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('HDD_SCHEDULE_FAILED=' + type(exc).__name__ + ': ' + str(exc), file=sys.stderr, flush=True)
        sys.exit(1)
