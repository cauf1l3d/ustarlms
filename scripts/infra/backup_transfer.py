#!/usr/bin/env python3
"""Package a verified Moodle recovery kit, or start an isolated restored copy.

--pack: root-private HDD tar + SHA sidecar, including the decryption identity.
--start-lab --duration 2h: server1, latest completed HDD backup, chosen lifetime.
--start --duration 0: extracted kit on an otherwise empty Docker host, no timer.
Optional --install-dependencies bootstraps missing tools on clean Ubuntu24.04.
Production migration is explicit: --pack-production freezes the source;
--start-production restores a permanent Academy on an empty target.
"""
import argparse
from contextlib import contextmanager
import fcntl
from datetime import datetime, timezone
import hashlib
import types
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
from types import SimpleNamespace

CONTROLLER = Path('/usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py')
CONTROLLER_SHA = '4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022'
ENGINE = Path('/usr/local/sbin/USTAR_RESTORE_LAB_20261004.py')
ENGINE_SHA = '71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac'
CHECKER = Path('/usr/local/lib/ustar-recovery/USTAR_RESTORE_PREFLIGHT_20261004.py')
CHECKER_SHA = 'e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da'
PRODUCTION = Path('/usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py')
PRODUCTION_SHA = '47ab2ced436897d8c659bf49ded93b6ecbeeb1a77c99b880bea7be5bad24f278'
SELF = Path(__file__).absolute()
TRANSFER_PARENT = Path('/srv/ustar-storage/transfers')
UNIT = 'ustar-backup-recovery-20261015.service'
UNIT_PATH = Path('/etc/systemd/system') / UNIT
BOOTSTRAP_LOG_ROOT = Path('/var/lib/ustar-recovery-bootstrap')
MANAGED = '# USTAR_BACKUP_TRANSFER_V1\n'
GIB = 1024 ** 3


class TransferError(Exception):
    pass


def require(ok, code):
    if not ok:
        raise TransferError(code)


def trusted(path, directory=False, private=False):
    path = Path(path)
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for item in (*reversed(path.parents), path):
        info = item.lstat()
        is_dir = item != path or directory
        require((stat.S_ISDIR(info.st_mode) if is_dir else stat.S_ISREG(info.st_mode))
                and info.st_uid == 0
                and not info.st_mode & (0o077 if private and item == path else 0o022)
                and (is_dir or info.st_nlink == 1), 'UNTRUSTED_PATH')
    return path


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def checked(path, expected):
    trusted(path)
    require(digest(path) == expected, 'SCRIPT_SHA_MISMATCH')
    return path


def controller(path=CONTROLLER):
    checked(path, CONTROLLER_SHA)
    obj = types.ModuleType('ustar_transfer_controller')
    obj.__file__ = str(path)
    exec(compile(path.read_bytes(), str(path), 'exec'), obj.__dict__)
    return obj


def command(args, timeout=30):
    result = subprocess.run(args, capture_output=True, timeout=timeout)
    require(result.returncode == 0, 'COMMAND_FAILED_' + Path(args[0]).name.upper())
    return result.stdout


def write_new(path, data, mode=0o600):
    with path.open('xb') as stream:
        os.fchmod(stream.fileno(), mode)
        stream.write(data.encode() if isinstance(data, str) else data)
        stream.flush()
        os.fsync(stream.fileno())


def add_checked(tar, path, name, expected=None):
    """Hash precisely the bytes written into the tar; reject changing sources."""
    trusted(path)
    require('/' not in name and name not in ('', '.', '..'), 'INVALID_MEMBER_NAME')
    h = hashlib.sha256()
    class Reader:
        def __init__(self, raw):
            self.raw = raw
        def read(self, count):
            data = self.raw.read(count)
            h.update(data)
            return data
    with path.open('rb') as raw:
        before = os.fstat(raw.fileno())
        entry = tarfile.TarInfo(name)
        entry.size, entry.mode, entry.uid, entry.gid = before.st_size, 0o600, 0, 0
        tar.addfile(entry, Reader(raw))
        after = os.fstat(raw.fileno())
    require((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
            == (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns),
            'SOURCE_CHANGED_DURING_PACK')
    checksum = h.hexdigest()
    require(expected is None or checksum == expected, 'PACK_MEMBER_HASH_MISMATCH')
    return {'bytes': before.st_size, 'sha256': checksum}


def pack(source_receipt=None):
    r = controller()
    checked(ENGINE, ENGINE_SHA)
    checked(CHECKER, CHECKER_SHA)
    trusted(SELF)
    checked(PRODUCTION, PRODUCTION_SHA)
    args = SimpleNamespace(manifest=None, input_directory=None, identity=str(r.IDENTITY))
    selected = r.selection(args)  # Exact last-success, sidecar, HDD mount/UUID.
    print('TRANSFER_PHASE=checking_archive_and_key', flush=True)
    r.verify(r.engine(selected))  # Streaming age/auth + nested checks; no plaintext restore.
    if source_receipt is not None:
        require(source_receipt['archive_sha256'] == selected['sha256'], 'FROZEN_SOURCE_BACKUP_CHANGED')
    archive = Path(selected['archive'])
    files = {
        'restore.py': (SELF, digest(SELF)),
        PRODUCTION.name: (PRODUCTION, PRODUCTION_SHA),
        CONTROLLER.name: (CONTROLLER, CONTROLLER_SHA),
        ENGINE.name: (ENGINE, ENGINE_SHA),
        CHECKER.name: (CHECKER, CHECKER_SHA),
        'recovery.key': (Path(selected['identity']), None),
        archive.name: (archive, selected['sha256']),
        archive.name + '.json': (Path(str(archive) + '.json'), None),
    }
    require(shutil.disk_usage(r.HDD).free > sum(p.stat().st_size for p, _ in files.values()) + 64 * 1024**2,
            'INSUFFICIENT_HDD_SPACE')
    trusted(TRANSFER_PARENT.parent, directory=True)
    if not TRANSFER_PARENT.exists():
        TRANSFER_PARENT.mkdir(mode=0o700)
    trusted(TRANSFER_PARENT, directory=True, private=True)
    folder = Path(tempfile.mkdtemp(prefix=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-'),
                                   dir=TRANSFER_PARENT))
    target = folder / 'ustar-transfer.tar'
    partial = folder / 'ustar-transfer.tar.partial'
    with partial.open('xb') as output:
        os.fchmod(output.fileno(), 0o600)
        with tarfile.open(fileobj=output, mode='w', format=tarfile.PAX_FORMAT) as tar:
            records = {name: add_checked(tar, path, name, expected)
                       for name, (path, expected) in files.items()}
            metadata = {'format': 'ustar-portable-v2', 'contains_private_recovery_key': True,
                        'archive': archive.name, 'files': records,
                        'source_frozen_for_migration': source_receipt is not None,
                        'source_frozen_utc': source_receipt.get('frozen_utc') if source_receipt else None}
            import io
            body = (json.dumps(metadata, indent=2) + '\n').encode()
            entry = tarfile.TarInfo('transfer.json')
            entry.size, entry.mode = len(body), 0o600
            tar.addfile(entry, io.BytesIO(body))
        output.flush()
        os.fsync(output.fileno())
    os.replace(partial, target)
    checksum = digest(target)
    write_new(folder / 'ustar-transfer.tar.sha256', checksum + '  ustar-transfer.tar\n')
    print('TRANSFER_PACK=PASS; CONTAINS_DECRYPTION_KEY; KEEP_PRIVATE', flush=True)
    print(json.dumps({'directory': str(folder), 'download': ['ustar-transfer.tar', 'ustar-transfer.tar.sha256'],
                      'sha256': checksum, 'bytes': target.stat().st_size,
                      'source_archive': archive.name, 'source_archive_unchanged': True,
                      'production_stopped': source_receipt is not None}, indent=2))


def validate_kit(folder):
    trusted(folder, directory=True, private=True)
    metadata_path = trusted(folder / 'transfer.json', private=True)
    require(metadata_path.stat().st_size < 65536, 'TRANSFER_METADATA_TOO_LARGE')
    def unique(items):
        result = {}
        for name, value in items:
            require(name not in result, 'DUPLICATE_TRANSFER_KEY')
            result[name] = value
        return result
    data = json.loads(metadata_path.read_bytes(), object_pairs_hook=unique)
    require(data.get('format') == 'ustar-portable-v2' and data.get('contains_private_recovery_key') is True,
            'INVALID_TRANSFER_FORMAT')
    name = data.get('archive')
    require(isinstance(name, str) and re.fullmatch(r'ustar-recovery-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.tar\.gz\.age', name),
            'INVALID_ARCHIVE_NAME')
    expected = {'restore.py', PRODUCTION.name, CONTROLLER.name, ENGINE.name, CHECKER.name, 'recovery.key', name, name + '.json'}
    records = data.get('files')
    require(isinstance(records, dict) and set(records) == expected, 'TRANSFER_MEMBER_SET_MISMATCH')
    require({p.name for p in folder.iterdir()} == expected | {'transfer.json'}, 'EXTRACTED_MEMBER_SET_MISMATCH')
    for filename, record in records.items():
        path = trusted(folder / filename, private=True)
        require(type(record.get('bytes')) is int and path.stat().st_size == record['bytes']
                and isinstance(record.get('sha256'), str)
                and digest(path) == record['sha256'], 'TRANSFER_MEMBER_HASH_MISMATCH')
    for filename, sha in ((PRODUCTION.name, PRODUCTION_SHA), (CONTROLLER.name, CONTROLLER_SHA), (ENGINE.name, ENGINE_SHA), (CHECKER.name, CHECKER_SHA)):
        checked(folder / filename, sha)
    return data


def install_exact(src, dst, sha):
    checked(src, sha)
    trusted(dst.parent, directory=True)
    if dst.exists() or dst.is_symlink():
        checked(dst, sha)  # Never replace a different installed helper here.
        return
    write_new(dst, src.read_bytes())


def unit_text(duration, input_directory=None):
    require(type(duration) is int and 0 <= duration <= 30 * 86400, 'INVALID_DURATION')
    args = ['--restore-lab', '--duration', '0' if duration == 0 else str(duration // 60) + 'm']
    if input_directory is not None:
        require(re.fullmatch(r'/[A-Za-z0-9_./-]+', str(input_directory)) and '..' not in input_directory.parts,
                'TRANSFER_PATH_NEEDS_SIMPLE_ABSOLUTE_NAME')
        args += ['--input-directory', str(input_directory), '--identity', str(input_directory / 'recovery.key'), '--load-images']
    return (MANAGED + '[Unit]\nDescription=USTAR backup recovery with chosen lifetime\nRequires=docker.service\nAfter=docker.service\n\n'
            '[Service]\nType=exec\nUMask=0077\nNice=10\nKillMode=control-group\nTimeoutStopSec=180\n'
            'RuntimeMaxSec=' + str(duration + 3900 if duration else 0) + '\n'
            'ExecStart=/usr/bin/python3 -I -B -u ' + str(CONTROLLER) + ' ' + ' '.join(args) + '\n'
            'ExecStopPost=/usr/bin/python3 -I -B -u ' + str(CONTROLLER) + ' --stop-lab\n')


@contextmanager
def launch_lock():
    path = Path('/run/ustar-transfer-launch.lock')
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077
                and info.st_nlink == 1, 'UNTRUSTED_LAUNCH_LOCK')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise TransferError('ANOTHER_LAUNCH_IN_PROGRESS') from None
        yield
    finally:
        os.close(fd)


def install_dependencies():
    """Explicit clean-host bootstrap using signed Ubuntu apt repositories."""
    values = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines()
                  if '=' in line and not line.startswith('#'))
    require(values.get('ID', '').strip('"') == 'ubuntu'
            and values.get('VERSION_ID', '').strip('"') == '24.04', 'BOOTSTRAP_REQUIRES_UBUNTU_24_04')
    if shutil.which('docker'):
        require(not command(['docker', 'ps', '-aq']).strip(), 'BOOTSTRAP_REQUIRES_EMPTY_DOCKER')
    else:
        for path in (Path('/var/lib/docker'), Path('/var/lib/containerd')):
            require(not path.exists() or not any(path.iterdir()), 'EXISTING_CONTAINER_STORAGE_NO_BOOTSTRAP')
    packages = []
    if not shutil.which('docker'):
        packages.append('docker.io')
    if not shutil.which('age'):
        packages.append('age')
    if not all(shutil.which(x) for x in ('nsenter', 'findmnt')):
        packages.append('util-linux')
    if packages:
        trusted(BOOTSTRAP_LOG_ROOT.parent, directory=True)
        if not BOOTSTRAP_LOG_ROOT.exists():
            BOOTSTRAP_LOG_ROOT.mkdir(mode=0o700)
        trusted(BOOTSTRAP_LOG_ROOT, directory=True, private=True)
        fd, name = tempfile.mkstemp(prefix='install-', suffix='.log', dir=BOOTSTRAP_LOG_ROOT)
        with os.fdopen(fd, 'wb') as log:
            for args in (['apt-get', '-o', 'Dpkg::Lock::Timeout=60', 'update'],
                         ['apt-get', '-o', 'Dpkg::Lock::Timeout=60', 'install', '-y', '--no-install-recommends', *packages]):
                p = subprocess.run(args, stdout=log, stderr=subprocess.STDOUT, timeout=1800,
                                   env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive'})
                require(p.returncode == 0, 'BOOTSTRAP_FAILED_PRIVATE_LOG_' + name)
        print('TRANSFER_DEPENDENCIES=INSTALLED; PRIVATE_LOG=' + name, flush=True)
    command(['systemctl', 'start', 'docker.service'])


def launch(duration, portable=False, bootstrap=False):
    folder = SELF.parent if portable else None
    if portable:
        validate_kit(folder)
        r = controller(folder / CONTROLLER.name)
        require(not r.ROOT.exists() and not r.ROOT.is_symlink(), 'EXISTING_RESTORE_DATA_NO_OVERWRITE')
        for dst, sha in ((CONTROLLER, CONTROLLER_SHA), (ENGINE, ENGINE_SHA), (CHECKER, CHECKER_SHA)):
            if dst.exists() or dst.is_symlink():
                checked(dst, sha)
        if bootstrap:
            install_dependencies()
    else:
        r = controller()
    for name in ('docker', 'age', 'systemctl', 'systemd-run', 'nsenter', 'findmnt'):
        require(shutil.which(name), 'INSTALL_REQUIRED_TOOL_' + name.upper().replace('-', '_'))
    command(['docker', 'info', '--format', '{{.ServerVersion}}'])
    if portable:
        require(not command(['docker', 'ps', '-aq']).strip(), 'PORTABLE_START_REQUIRES_EMPTY_DOCKER')
    else:
        checked(ENGINE, ENGINE_SHA)
        checked(CHECKER, CHECKER_SHA)
        r.selection(SimpleNamespace(manifest=None, input_directory=None, identity=str(r.IDENTITY)))
    require(not r.ROOT.exists() and not r.ROOT.is_symlink(), 'EXISTING_RESTORE_DATA_NO_OVERWRITE')
    for name in (UNIT, 'ustar-restore-test-20261014.service'):
        require(command(['systemctl', 'show', name, '-p', 'MainPID', '--value']).strip() in (b'', b'0'),
                'RESTORE_ALREADY_RUNNING')
    text = unit_text(duration, folder)
    trusted(UNIT_PATH.parent, directory=True)
    if UNIT_PATH.exists() or UNIT_PATH.is_symlink():
        trusted(UNIT_PATH)
        require(UNIT_PATH.read_text().startswith(MANAGED), 'UNOWNED_UNIT_COLLISION')
    if portable:
        if not CHECKER.parent.exists():
            trusted(CHECKER.parent.parent, directory=True)
            CHECKER.parent.mkdir(mode=0o700)
        for dst, sha in ((CONTROLLER, CONTROLLER_SHA), (ENGINE, ENGINE_SHA), (CHECKER, CHECKER_SHA)):
            install_exact(folder / dst.name, dst, sha)
    with tempfile.NamedTemporaryFile(prefix='.ustar-recovery-', dir=UNIT_PATH.parent, delete=False) as temp:
        os.fchmod(temp.fileno(), 0o644)
        temp.write(text.encode())
        temp.flush()
        os.fsync(temp.fileno())
        tmp = Path(temp.name)
    os.replace(tmp, UNIT_PATH)
    command(['systemctl', 'daemon-reload'])
    command(['systemctl', 'start', UNIT])
    print('RESTORE_START=SUBMITTED; NOT_YET_READY')
    print(json.dumps({'unit': UNIT, 'duration_seconds': duration,
                      'unlimited': duration == 0, 'countdown_starts': 'after_Moodle_ready',
                      'status_command': 'sudo python3 -I -B ' + str(CONTROLLER) + ' --status',
                      'automatic_start_after_reboot': False}, indent=2))


def production(path=PRODUCTION):
    checked(path, PRODUCTION_SHA)
    module = types.ModuleType('ustar_production_transfer_worker')
    module.__file__ = str(path)
    exec(compile(path.read_bytes(), str(path), 'exec'), module.__dict__)
    return module


def production_unit(ep, folder):
    require(re.fullmatch(r'/[A-Za-z0-9_./-]+', str(folder)) and '..' not in folder.parts,
            'TRANSFER_PATH_NEEDS_SIMPLE_ABSOLUTE_NAME')
    args = ['--restore-production', '--kit', str(folder), '--url', ep['url'],
            '--bind', ep['bind'], '--http-port', str(ep['port']), '--source-stopped']
    if ep['behind_proxy']: args.append('--behind-proxy')
    return (MANAGED + '[Unit]\nDescription=USTAR production archive import\nRequires=docker.service\nAfter=docker.service\n\n'
            '[Service]\nType=exec\nUMask=0077\nRuntimeMaxSec=1h\nTimeoutStopSec=180\n'
            'ExecStart=/usr/bin/python3 -I -B -u ' + str(PRODUCTION) + ' ' + ' '.join(args) + '\n')


def launch_production(args):
    folder = SELF.parent
    validate_kit(folder)
    p = production(folder / PRODUCTION.name)
    ep = p.endpoint(args.url, args.bind, args.http_port, args.behind_proxy)
    require(args.source_stopped, 'DECLARE_SOURCE_STOPPED_BEFORE_PRODUCTION_ACTIVATION')
    require(not p.ROOT.exists() and not p.ROOT.is_symlink() and not p.TARGET.exists() and not p.TARGET.is_symlink(),
            'EXISTING_PRODUCTION_DATA_NO_OVERWRITE')
    for dst, sha in ((PRODUCTION, PRODUCTION_SHA), (CONTROLLER, CONTROLLER_SHA), (ENGINE, ENGINE_SHA), (CHECKER, CHECKER_SHA)):
        if dst.exists() or dst.is_symlink(): checked(dst, sha)
    unit = 'ustar-production-import-20261015.service'
    unit_path = UNIT_PATH.parent / unit
    require(not unit_path.exists() and not unit_path.is_symlink(), 'PRODUCTION_IMPORT_UNIT_COLLISION')
    for name in p.units():
        require(not (UNIT_PATH.parent / name).exists() and not (UNIT_PATH.parent / name).is_symlink(), 'PRODUCTION_RUNTIME_UNIT_COLLISION')
    text = production_unit(ep, folder)
    trusted(UNIT_PATH.parent, directory=True)
    if args.install_dependencies: install_dependencies()
    require(not command(['docker', 'ps', '-aq']).strip(), 'PRODUCTION_START_REQUIRES_EMPTY_DOCKER')
    for name in ('docker', 'age', 'systemctl', 'systemd-run', 'nsenter', 'findmnt'):
        require(shutil.which(name), 'INSTALL_REQUIRED_TOOL_' + name.upper().replace('-', '_'))
    if not CHECKER.parent.exists():
        trusted(CHECKER.parent.parent, directory=True); CHECKER.parent.mkdir(mode=0o700)
    for dst, sha in ((PRODUCTION, PRODUCTION_SHA), (CONTROLLER, CONTROLLER_SHA), (ENGINE, ENGINE_SHA), (CHECKER, CHECKER_SHA)):
        install_exact(folder / dst.name, dst, sha)
    write_new(unit_path, text, 0o644)
    command(['systemctl', 'daemon-reload'])
    command(['systemctl', 'start', unit])
    print('PRODUCTION_START=SUBMITTED; NOT_YET_READY')
    print(json.dumps({'unit': unit, 'url': ep['url'], 'status': 'sudo python3 -I -B ' + str(PRODUCTION) + ' --production-status'}))


def parse_duration(value):
    if value == '0':
        return 0
    match = re.fullmatch(r'([1-9][0-9]{0,5})([mhd])', value)
    require(match is not None, 'DURATION_USE_30m_2h_1d_OR_0')
    seconds = int(match[1]) * {'m': 60, 'h': 3600, 'd': 86400}[match[2]]
    require(seconds <= 30 * 86400, 'DURATION_MAX_30d_OR_0')
    return seconds


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    modes = ap.add_mutually_exclusive_group(required=True)
    modes.add_argument('--pack', action='store_true')
    modes.add_argument('--pack-production', action='store_true', help='Fresh final backup and leave source web stopped for migration')
    modes.add_argument('--start-production', action='store_true', help='Permanent Academy on a clean target')
    modes.add_argument('--resume-source', action='store_true', help='Abort migration only after the target is stopped')
    modes.add_argument('--start-lab', action='store_true')
    modes.add_argument('--start', action='store_true', help='ISOLATED TEST COPY only; use --start-production for a working Academy')
    ap.add_argument('--install-dependencies', action='store_true',
                    help='Only with --start: install missing Docker/age/util-linux on clean Ubuntu24.04')
    ap.add_argument('--acknowledge-outage', action='store_true')
    ap.add_argument('--source-stopped', action='store_true')
    ap.add_argument('--target-stopped', action='store_true')
    ap.add_argument('--url')
    ap.add_argument('--bind', default='0.0.0.0')
    ap.add_argument('--http-port', type=int, default=80)
    ap.add_argument('--behind-proxy', action='store_true')
    ap.add_argument('--duration', help='30m, 2h, 1d; 0 means no timer. Lab default 1h, portable default 0.')
    args = ap.parse_args()
    require(os.geteuid() == 0, 'RUN_WITH_SUDO')
    os.umask(0o077)
    require(not (args.pack or args.pack_production or args.start_production or args.resume_source) or args.duration is None, 'PRODUCTION_OR_PACK_HAS_NO_DURATION')
    require(not args.install_dependencies or args.start or args.start_production, 'BOOTSTRAP_ONLY_WITH_PORTABLE_START')
    if args.pack_production:
        def interrupted(*_): raise KeyboardInterrupt()
        for sig in (signal.SIGTERM, signal.SIGHUP): signal.signal(sig, interrupted)
        require(args.acknowledge_outage, 'PACK_PRODUCTION_REQUIRES_ACKNOWLEDGE_OUTAGE')
        p = production()
        with p.lock('ustar-source-transfer.lock'):
            pack(p.freeze_source())
    elif args.resume_source:
        p = production()
        with p.lock('ustar-source-transfer.lock'): p.resume_source(args.target_stopped)
    elif args.start_production:
        require(args.url, 'PRODUCTION_URL_REQUIRED')
        with launch_lock(): launch_production(args)
    elif args.pack:
        pack()
    else:
        duration = parse_duration(args.duration) if args.duration is not None else (0 if args.start else 3600)
        # A separate launcher lock must NOT block the worker's own restore lock.
        with launch_lock():
            launch(duration, portable=args.start, bootstrap=args.install_dependencies)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        message = str(exc) if isinstance(exc, TransferError) else type(exc).__name__
        print('TRANSFER_ERROR=' + message, file=sys.stderr)
        sys.exit(1)
