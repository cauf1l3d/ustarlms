#!/usr/bin/env python3
"""USTAR HDD backup adapter for the reviewed installed recovery engine.

Default/--check prepares private HDD directories and checks space/identities;
it never stops a container or captures a snapshot. --backup requires
--acknowledge-outage. --recover resumes Moodle using its SSD marker, even
without the HDD; it does not restore an archive. No timer, retention deletion,
source removal, partition changes, network upload or whole-host backup.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import types

ENGINE = Path('/usr/local/sbin/USTAR_BACKUP_SFTP_20261004.py')
ENGINE_SHA = '0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f'
MOUNT = Path('/srv/ustar-storage')
DISK = Path('/dev/disk/by-id/wwn-0x50014ee200c1ee59')
PART = Path(str(DISK) + '-part1')
UUID = '359a2bae-4e79-461a-ab72-1597f605d801'
SERIAL = 'WD-WCAS82914317'
WWN = '0x50014ee200c1ee59'
CAPACITY = 500106780160
SSD_STATE = Path('/var/lib/ustar-backup')
RUN = Path('/run/ustar-backup')
EXPORT = Path('backups/managed')
STAGING = Path('backups/.staging')
GIB = 1024 ** 3
EXPORT_LIMIT = 100 * GIB
TIMER = Path('/etc/systemd/system/ustar-hdd-backup.timer')
IMAGES = {
    'ustar_moodle': 'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b',
    'ustar_postgres': 'sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94',
}
SIGNALS = (signal.SIGINT, signal.SIGTERM, signal.SIGHUP)
ARCHIVE = re.compile(r'ustar-recovery-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.tar\.gz\.age')


class Refusal(RuntimeError):
    pass


def require(condition, message):
    if not condition:
        raise Refusal(message)


def utc():
    return datetime.now(timezone.utc).isoformat()


def trusted(path, directory=False, private=False):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and info.st_uid == 0 and not info.st_mode & (0o077 if private else 0o022)
            and (directory or info.st_nlink == 1), 'Untrusted path type/owner/mode/links')
    return info


def parents_trusted(path):
    for parent in path.parents:
        trusted(parent, directory=True)


def private_directory(path):
    parents_trusted(path)
    if not path.exists() and not path.is_symlink():
        path.mkdir(mode=0o700)
    return trusted(path, directory=True, private=True)


def command(argv, timeout=20):
    try:
        result = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=timeout, check=False,
                                env={**os.environ, 'LC_ALL': 'C'})
    except subprocess.TimeoutExpired:
        raise Refusal('Bounded host probe timed out') from None
    require(result.returncode == 0, 'Required host probe failed')
    return result.stdout.decode()


def mount_record():
    data = json.loads(command(['findmnt', '--json', '--mountpoint', str(MOUNT),
                               '--output', 'SOURCE,TARGET,FSTYPE,UUID,OPTIONS']))
    rows = data.get('filesystems', [])
    require(len(rows) == 1, 'Exact HDD mount missing or ambiguous')
    row = rows[0]
    require(row.get('target') == str(MOUNT) and row.get('fstype') == 'ext4'
            and row.get('uuid') == UUID, 'HDD mount UUID/type/target mismatch')
    options = set(row.get('options', '').split(','))
    require({'rw', 'nodev', 'nosuid', 'noexec'} <= options and 'ro' not in options,
            'HDD mount protection/read-write flags mismatch')
    return row


def block_device(path):
    info = path.stat()
    require(stat.S_ISBLK(info.st_mode), 'Expected HDD block device missing')
    return info.st_rdev


def validate_target(anchor=None):
    parents_trusted(MOUNT)
    root = trusted(MOUNT, directory=True, private=True)
    row = mount_record()
    part_number = block_device(PART)
    require(block_device(Path(row['source'])) == part_number
            and block_device(Path('/dev/disk/by-uuid') / UUID) == part_number
            and root.st_dev == part_number, 'HDD mount/block identity mismatch')
    require(os.stat('/').st_dev != part_number, 'System filesystem is not a backup target')
    if anchor is not None:
        held = os.fstat(anchor)
        require(held.st_dev == root.st_dev and held.st_ino == root.st_ino,
                'HDD mount changed after directory anchor was acquired')
    data = json.loads(command(['lsblk', '--json', '--bytes', '--paths', '--output',
                               'NAME,TYPE,SIZE,SERIAL,WWN,FSTYPE,UUID', str(DISK)]))
    disks = data.get('blockdevices', [])
    require(len(disks) == 1, 'HDD disk inventory ambiguous')
    disk = disks[0]
    require(disk.get('type') == 'disk' and disk.get('size') == CAPACITY
            and disk.get('serial') == SERIAL and disk.get('wwn') == WWN,
            'HDD WWN/serial/capacity mismatch')
    parts = disk.get('children', [])
    require(len(parts) == 1 and parts[0].get('type') == 'part'
            and parts[0].get('fstype') == 'ext4' and parts[0].get('uuid') == UUID
            and block_device(Path(parts[0]['name'])) == part_number,
            'HDD partition is not the reviewed disk child')
    return {'uuid': UUID, 'mount': str(MOUNT), 'serial': SERIAL}


@contextmanager
def anchored_hdd():
    validate_target()
    previous = os.open('.', os.O_RDONLY | os.O_DIRECTORY)
    anchor = os.open(MOUNT, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        validate_target(anchor)
        os.fchdir(anchor)
        # Every HDD write is relative to this held filesystem, including after
        # a lazy unmount. It cannot fall through to the SSD mountpoint directory.
        yield anchor
    finally:
        os.fchdir(previous)
        os.close(anchor)
        os.close(previous)


def load_engine():
    parents_trusted(ENGINE)
    trusted(ENGINE)
    data = ENGINE.read_bytes()
    require(hashlib.sha256(data).hexdigest() == ENGINE_SHA, 'Installed backup dependency SHA mismatch')
    engine = types.ModuleType('ustar_pinned_hdd_backup_engine')
    engine.__file__ = str(ENGINE)
    exec(compile(data, str(ENGINE), 'exec'), engine.__dict__)
    require(engine.STATE == SSD_STATE and engine.RUN == RUN
            and engine.MOODLE == 'ustar_moodle' and engine.POSTGRES == 'ustar_postgres'
            and engine.SITE == Path('/opt/ustar/data/moodle'), 'Installed backup dependency contract mismatch')
    return engine


@contextmanager
def backup_lock(wait=15):
    private_directory(RUN)
    fd = os.open(RUN / 'lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0
                and not info.st_mode & 0o077 and info.st_nlink == 1, 'Untrusted shared backup lock')
        deadline = time.monotonic() + wait
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                require(time.monotonic() < deadline, 'Backup/cron lock busy; no container was stopped')
                time.sleep(0.2)
        yield
    finally:
        os.close(fd)


def read_json(path, private=True):
    trusted(path, private=private)
    require(path.stat().st_size <= 65536, 'Control JSON exceeds size bound')
    value = json.loads(path.read_text())
    require(isinstance(value, dict), 'Control JSON is not an object')
    return value


def archive_usage():
    private_directory(EXPORT)
    total = 0
    for path in EXPORT.iterdir():
        info = trusted(path)
        require(ARCHIVE.fullmatch(path.name) or
                (path.name.endswith('.json') and ARCHIVE.fullmatch(path.name[:-5])),
                'Unexpected managed export entry; no cleanup was performed')
        total += info.st_size
    require(total < EXPORT_LIMIT, 'HDD managed export quota reached; archives preserved')
    return total


def validate_live(engine, expected=None):
    rows = (engine.inspect(engine.MOODLE), engine.inspect(engine.POSTGRES))
    for index, (name, row) in enumerate(zip((engine.MOODLE, engine.POSTGRES), rows)):
        require(row['State']['Running'] and row['Image'] == IMAGES[name],
                'Production running/image identity differs from reviewed baseline')
        if expected is not None:
            require(row['Id'] == expected[index]['Id'], 'Production container identity changed during preparation')
    return rows


def snapshot_budget(engine, images):
    image_bytes = sum(v['Size'] for v in json.loads(engine.execute(['docker', 'image', 'inspect', *images])))
    raw = command(['du', '--summarize', '--bytes', '--',
                   str(engine.SITE / 'public'), str(engine.SITE / 'moodledata')], timeout=60)
    rows = raw.splitlines()
    require(len(rows) == 2, 'Site size probe incomplete')
    site_bytes = sum(int(row.split()[0]) for row in rows)
    db_bytes = int(engine.db_sql("SELECT pg_database_size('moodle');"))
    require(image_bytes > 0 and site_bytes > 0 and db_bytes > 0, 'Invalid snapshot size probe')
    required = max(6 * GIB, 4 * (image_bytes + site_bytes + db_bytes) + 4 * GIB)
    free = shutil.disk_usage('.').free
    require(free >= required and os.statvfs('.').f_favail >= 10000,
            'Insufficient HDD space/inodes for snapshot and publication reserve')
    return {'required_free_bytes_estimate': required, 'available_free_bytes': free,
            'managed_export_quota_bytes': EXPORT_LIMIT, 'estimate_not_growth_guarantee': True}


def prepare(engine, anchor):
    validate_target(anchor)
    private_directory(SSD_STATE)
    require(not (SSD_STATE / 'moodle-was-running.json').exists()
            and not (SSD_STATE / 'moodle-was-running.json').is_symlink(),
            'Unresolved SSD recovery marker; run --recover before a new backup')
    for path in (Path('backups'), STAGING, EXPORT):
        private_directory(path)
    archive_usage()
    engine.EXPORT, engine.EXPORT_LIMIT = EXPORT, EXPORT_LIMIT
    engine.export_directory = lambda: (archive_usage(), 0)[1]
    # The original free-space check must inspect HDD staging, while its marker
    # and last-export state must continue using SSD during the actual capture.
    original_state = engine.STATE
    engine.STATE = STAGING
    try:
        moodle, pg, images = engine.preflight()
    finally:
        engine.STATE = original_state
    validate_live(engine, (moodle, pg))
    budget = snapshot_budget(engine, images)
    return (moodle, pg), images, budget


def resume_safely(engine):
    marker = SSD_STATE / 'moodle-was-running.json'
    if marker.exists() or marker.is_symlink():
        saved = read_json(marker)
        if saved.get('stop_phase') == 'inflight':
            current = engine.inspect(engine.MOODLE)
            require(current['Id'] == saved['container_id'], 'Recovery container identity changed')
            require(not current['State']['Running'],
                    'Docker stop completion unverified; keep SSD marker and inspect status before recovery')
    previous = {sig: signal.getsignal(sig) for sig in SIGNALS}
    try:
        for sig in SIGNALS:
            signal.signal(sig, signal.SIG_IGN)
        engine.recover()
    finally:
        for sig, handler in previous.items():
            signal.signal(sig, handler)


def http_ready():
    for attempt in range(15):
        try:
            code = command(['curl', '--silent', '--show-error', '--noproxy', '*',
                            '--connect-timeout', '2', '--max-time', '4', '--output', '/dev/null',
                            '--write-out', '%{http_code}', '--header', 'Host: ustar.local',
                            'http://127.0.0.1/login/index.php'], timeout=6).strip()
            if code == '200':
                return {'login_http': 200, 'authenticated_business_acceptance': False}
        except Refusal:
            pass
        if attempt < 14:
            time.sleep(1)
    raise Refusal('Moodle resumed but local Apache login readiness did not return HTTP200')


def install_adapter(engine, anchor, containers, progress):
    raw_execute, raw_recover, raw_json = engine.execute, engine.recover, engine.write_json

    def execute(argv, **kwargs):
        if argv[:2] == ['docker', 'stop']:
            require(argv[-1] == engine.MOODLE, 'Only Academy Moodle may be paused')
            validate_target(anchor)
            validate_live(engine, containers)
            progress('capture')
            marker_path = SSD_STATE / 'moodle-was-running.json'
            marker = read_json(marker_path)
            # Docker daemon stop may outlive an interrupted CLI client. Block
            # catchable signals for both parent and inherited child mask until
            # the stop reply and durable completion flag are recorded. A pending
            # signal is delivered on unmasking, then the engine finally resumes.
            previous_mask = signal.pthread_sigmask(signal.SIG_BLOCK, SIGNALS)
            try:
                raw_json(marker_path, {**marker, 'stop_phase': 'inflight'})
                result = raw_execute(argv, **kwargs)
                raw_json(marker_path, {**marker, 'stop_phase': 'complete'})
            finally:
                signal.pthread_sigmask(signal.SIG_SETMASK, previous_mask)
            return result
        elif argv[:2] == ['docker', 'save']:
            progress('images')
        elif argv[0] == 'tar' and '-C' in argv and argv[argv.index('-C') + 1] != str(engine.SITE):
            progress('package')
        elif argv[0] == 'age':
            progress('encrypt')
        return raw_execute(argv, **kwargs)

    def recover():
        # No HDD guard here: a lost HDD must never prevent the SSD-marker resume.
        held = engine.recover
        engine.recover = raw_recover
        try:
            resume_safely(engine)
        finally:
            engine.recover = held

    def write_json(path, value):
        if Path(path) == SSD_STATE / 'moodle-was-running.json':
            value = {**value, 'producer': 'ustar-hdd-backup-v1', 'stop_phase': 'before_stop'}
        if Path(path) == SSD_STATE / 'last-local-export.json':
            path = SSD_STATE / 'last-hdd-export.json'
        if Path(path).parent == EXPORT or Path(path) == SSD_STATE / 'last-hdd-export.json':
            value = {**value, 'scope': 'USTAR encrypted snapshot on same-host HDD',
                     'hdd_uuid': UUID, 'producer': 'ustar-hdd-backup-v1',
                     'dependency_sha256': ENGINE_SHA, 'external_copy_verified': False}
        raw_json(path, value)

    def engine_print(*args, **kwargs):
        text = ' '.join(str(arg) for arg in args)
        if text.startswith(('STOPPING_MOODLE_FOR_CONSISTENT_SNAPSHOT', 'MOODLE_RUNNING=YES')):
            print(text, flush=True)

    engine.execute, engine.recover, engine.write_json = execute, recover, write_json
    engine.__dict__['print'] = engine_print


def verified_manifest(engine):
    path = SSD_STATE / 'last-hdd-export.json'
    manifest = read_json(path)
    name = manifest.get('file', '')
    require(isinstance(name, str) and ARCHIVE.fullmatch(name)
            and re.fullmatch(r'[0-9a-f]{64}', manifest.get('sha256', ''))
            and manifest.get('readback_verified') is True and manifest.get('hdd_uuid') == UUID,
            'Completed HDD manifest invalid')
    final, sidecar = EXPORT / name, EXPORT / (name + '.json')
    trusted(final)
    trusted(sidecar)
    require(final.stat().st_size == manifest.get('bytes')
            and engine.digest(final) == manifest['sha256'], 'Completed HDD archive readback mismatch')
    require(read_json(sidecar, private=False) == manifest, 'Completed archive and SSD manifest disagree')
    for item in (final, sidecar):
        with item.open('rb') as stream:
            os.fsync(stream.fileno())
    fd = os.open(EXPORT, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    return {**manifest, 'archive': str(MOUNT / final)}


def run_backup(engine, anchor, containers, images, budget):
    started = utc()
    work = Path(tempfile.mkdtemp(prefix='work-', dir=STAGING))
    attempt = {'started_utc': started, 'hdd_uuid': UUID, 'success': False,
               'staging': str(MOUNT / work), 'budget': budget, 'phase': 'prepare'}

    def progress(phase):
        attempt['phase'] = phase
        engine.write_json(SSD_STATE / 'last-hdd-attempt.json', attempt)
        print('HDD_BACKUP_PHASE=' + phase, flush=True)

    install_adapter(engine, anchor, containers, progress)
    engine.write_json(SSD_STATE / 'last-hdd-attempt.json', attempt)
    try:
        engine.backup(work, containers, images)
        progress('verify')
        validate_target(anchor)
        manifest = verified_manifest(engine)
        progress('readiness')
        readiness = http_ready()
        result = {**attempt, 'phase': 'complete', 'success': True,
                  'finished_utc': utc(), 'manifest': manifest, 'readiness': readiness,
                  'staging_retained': False, 'timer_unit_present': TIMER.exists(), 'archive_deletion': False}
        shutil.rmtree(work)
        engine.write_json(SSD_STATE / 'last-hdd-success.json', result)
        engine.write_json(SSD_STATE / 'last-hdd-attempt.json', result)
        print('HDD_BACKUP=PASS', flush=True)
        print(json.dumps(result, ensure_ascii=False, indent=2), flush=True)
        return result
    except BaseException as exc:
        attempt.update(finished_utc=utc(), error_type=type(exc).__name__,
                       staging_retained=work.exists(),
                       recovery_required=(SSD_STATE / 'moodle-was-running.json').exists())
        engine.write_json(SSD_STATE / 'last-hdd-attempt.json', attempt)
        raise


def status():
    result = {'last_attempt': None, 'last_success': None,
              'recovery_marker_present': (SSD_STATE / 'moodle-was-running.json').exists(),
              'timer_unit_present': TIMER.exists()}
    for field, name in (('last_attempt', 'last-hdd-attempt.json'), ('last_success', 'last-hdd-success.json')):
        path = SSD_STATE / name
        if path.exists() or path.is_symlink():
            parents_trusted(path)
            result[field] = read_json(path)
    print(json.dumps(result, ensure_ascii=False, indent=2))


def interrupted(signum, frame):
    raise InterruptedError('Backup interrupted')


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    modes = ap.add_mutually_exclusive_group()
    for name in ('check', 'backup', 'recover', 'status'):
        modes.add_argument('--' + name, action='store_true')
    ap.add_argument('--acknowledge-outage', action='store_true')
    args = ap.parse_args()
    if os.geteuid() != 0:
        ap.error('Run with sudo')
    if args.backup and not args.acknowledge_outage:
        ap.error('--backup requires --acknowledge-outage in the chosen window')
    os.umask(0o077)
    for sig in SIGNALS:
        signal.signal(sig, interrupted)
    if args.status:
        status()
        return
    engine = load_engine()
    if args.recover:
        private_directory(SSD_STATE)
        with backup_lock():
            resume_safely(engine)
        return
    for tool in ('findmnt', 'lsblk', 'du', 'curl'):
        require(shutil.which(tool), 'Required host command missing')
    with anchored_hdd() as anchor, backup_lock():
        containers, images, budget = prepare(engine, anchor)
        print('HDD_PREFLIGHT=PASS; no container stopped during preflight', flush=True)
        print(json.dumps({'target': str(MOUNT / EXPORT), 'recovery_marker_on': str(SSD_STATE),
                          'budget': budget, 'timer_unit_present': TIMER.exists()}, indent=2), flush=True)
        if args.backup:
            run_backup(engine, anchor, containers, images, budget)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        reason = str(exc) if isinstance(exc, Refusal) else type(exc).__name__
        print('HDD_BACKUP_FAILED=' + reason, file=sys.stderr, flush=True)
        print('Resume only: sudo -n python3 /usr/local/sbin/USTAR_HDD_BACKUP_20261006.py --recover',
              file=sys.stderr, flush=True)
        sys.exit(1)
