#!/usr/bin/env python3
"""One pinned Snap include repair; no package, Snap, Docker or Moodle writes."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile


PROFILE = Path('/etc/apparmor.d/usr.lib.snapd.snap-confine.real')
STATE_DIR = Path('/var/lib/ustar-host-apparmor-20261013')
INCLUDE = Path('/var/lib/snapd/apparmor/snap-confine')
ORIGINAL_SHA256 = 'a308e9311c95af4d8df14fd04491d27692dd530994c2297c276f3a76a40de86c'
ORIGINAL_IDENTITY = (2050, 3151055)
PARSER = '/usr/sbin/apparmor_parser'
SYSTEMCTL = '/usr/bin/systemctl'
DOCKER = '/usr/bin/docker'
KERNEL_PROFILES = Path('/sys/kernel/security/apparmor/profiles')
IMAGES = {
    '/ustar_moodle': 'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b',
    '/ustar_postgres': 'sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94',
}
SNAP_UNITS = (
    'snap-core18-1880.mount', 'snap-lxd-16099.mount', 'snap-snapd-8542.mount',
    'snap.lxd.daemon.service', 'snap.lxd.daemon.unix.socket',
    'snapd.service', 'snapd.socket', 'snapd.apparmor.service',
)
DOCKER_FORMAT = ('{"name":{{json .Name}},"id":{{json .Id}},"image":{{json .Image}},'
                 '"running":{{json .State.Running}},"profile":{{json .AppArmorProfile}},'
                 '"restart_count":{{json .RestartCount}}}')
DIRECTIVE = re.compile(
    rb'(?m)^([ \t]*#?include[ \t]+)("/var/lib/snapd/apparmor/snap-confine/?"[ \t]*(?:#[^\r\n]*)?)(\r?)$'
)


class Refusal(Exception):
    def __init__(self, code, return_code=None):
        self.code, self.return_code = code, return_code


def digest(data):
    return hashlib.sha256(data).hexdigest()


def fingerprint(s):
    # A read can advance atime; it is not a concurrent content/ownership change.
    return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink,
            s.st_size, s.st_mtime_ns, s.st_ctime_ns)


def conditional_include(data):
    if len(DIRECTIVE.findall(data)) != 1:
        raise Refusal('INCLUDE_DIRECTIVE_NOT_UNIQUE_OR_RECOGNIZED')
    return DIRECTIVE.sub(lambda m: m[1] + b'if exists ' + m[2] + m[3], data, count=1)


def safe_directory(path, private=False):
    s = path.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid != 0 or s.st_mode & 0o022:
        raise Refusal('UNSAFE_DIRECTORY')
    if private and stat.S_IMODE(s.st_mode) != 0o700:
        raise Refusal('PRIVATE_DIRECTORY_MODE')


def read_file(path, mode):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        s = os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or s.st_gid != 0
                or stat.S_IMODE(s.st_mode) != mode or s.st_nlink != 1 or s.st_size > 131072):
            raise Refusal('UNSAFE_FILE_METADATA')
        data = b''
        while True:
            part = os.read(fd, 16384)
            if not part:
                break
            data += part
            if len(data) > 131072:
                raise Refusal('FILE_SIZE_LIMIT')
        if fingerprint(os.fstat(fd)) != fingerprint(s):
            raise Refusal('FILE_CHANGED_DURING_READ')
        return data, s
    finally:
        os.close(fd)


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def atomic_write(path, data, mode):
    safe_directory(path.parent)
    fd, temporary = tempfile.mkstemp(prefix='.ustar-apparmor-', dir=path.parent)
    try:
        os.fchown(fd, 0, 0)
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        fd = None
        os.replace(temporary, path)
        temporary = None
        sync_directory(path.parent)
    finally:
        if fd is not None:
            try:
                os.close(fd)
            except OSError:
                pass
        if temporary is not None:
            os.unlink(temporary)


def command(argv, label, timeout=25, log_dir=None):
    p = subprocess.run(argv, capture_output=True, text=True, timeout=timeout, check=False)
    if log_dir is not None:
        atomic_write(log_dir / (label + '.stdout'), p.stdout.encode(), 0o600)
        atomic_write(log_dir / (label + '.stderr'), p.stderr.encode(), 0o600)
    if p.returncode:
        raise Refusal(label.upper() + '_FAILED', p.returncode)
    return p.stdout


def kernel_profiles(path):
    # securityfs files report size zero; bound the actual read instead.
    with path.open('rb') as stream:
        data = stream.read(1048577)
    if len(data) > 1048576:
        raise Refusal('KERNEL_PROFILE_SIZE_LIMIT')
    return set(data.decode().splitlines())


def snap_processes():
    count = 0
    for p in Path('/proc').glob('[0-9]*/exe'):
        try:
            name = os.readlink(p)
        except (FileNotFoundError, ProcessLookupError):
            continue
        except PermissionError:
            raise Refusal('PROCESS_INVENTORY_NOT_READABLE')
        name = name.removesuffix(' (deleted)')
        if (name.startswith(('/snap/', '/var/lib/snapd/snap/', '/usr/lib/snapd/'))
                or name in ('/usr/bin/snap', '/usr/bin/snapctl')):
            count += 1
    return count


def snapshot(run):
    data = run([DOCKER, 'inspect', '--format', DOCKER_FORMAT,
                'ustar_moodle', 'ustar_postgres'], 'docker_read')
    containers = sorted((json.loads(line) for line in data.splitlines()), key=lambda x: x['name'])
    if len(containers) != 2 or {x['name'] for x in containers} != set(IMAGES):
        raise Refusal('PRODUCTION_CONTAINER_SET')
    for c in containers:
        if (c['image'] != IMAGES[c['name']] or not c['running']
                or c['profile'] != 'docker-default' or not c['id']):
            raise Refusal('PRODUCTION_CONTAINER_DRIFT')
    units = run([SYSTEMCTL, 'show', *SNAP_UNITS, '-p', 'Id', '-p', 'LoadState',
                 '-p', 'ActiveState', '-p', 'SubState', '-p', 'UnitFileState', '--no-pager'], 'snap_units_read')
    if snap_processes() != 0:
        raise Refusal('SNAP_EXECUTABLE_ACTIVE')
    return {'containers': containers, 'snap_unit_properties': units}


def repair(profile=PROFILE, state_dir=STATE_DIR, include=INCLUDE,
           expected_sha=ORIGINAL_SHA256, expected_identity=ORIGINAL_IDENTITY,
           kernel_path=KERNEL_PROFILES, run=command):
    if os.geteuid() != 0:
        raise Refusal('ROOT_REQUIRED')
    safe_directory(profile.parent)
    safe_directory(state_dir.parent)
    source, source_stat = read_file(profile, 0o644)
    if include.exists() or include.is_symlink():
        raise Refusal('INCLUDE_DIRECTORY_STATE_CHANGED')
    for folder in ('disable', 'force-complain'):
        marker = profile.parent / folder / profile.name
        if marker.exists() or marker.is_symlink():
            raise Refusal('PROFILE_LOCAL_OVERRIDE_PRESENT')
    if digest(source) == expected_sha:
        if (source_stat.st_dev, source_stat.st_ino) != expected_identity:
            raise Refusal('ORIGINAL_FILE_IDENTITY_CHANGED')
        original = source
    else:
        if not state_dir.exists() or state_dir.is_symlink():
            raise Refusal('SOURCE_SHA256_CHANGED')
        safe_directory(state_dir, private=True)
        original, _ = read_file(state_dir / 'original.profile', 0o600)
        if digest(original) != expected_sha or source != conditional_include(original):
            raise Refusal('SOURCE_SHA256_CHANGED')
    corrected = conditional_include(original)
    before = snapshot(run)
    profiles_before = kernel_profiles(kernel_path)
    if 'docker-default (enforce)' not in profiles_before:
        raise Refusal('DOCKER_KERNEL_ENFORCEMENT_MISSING')
    if not state_dir.exists():
        state_dir.mkdir(mode=0o700)
        sync_directory(state_dir.parent)
    safe_directory(state_dir, private=True)
    lock_fd = os.open(state_dir / 'lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        lock_stat = os.fstat(lock_fd)
        if (not stat.S_ISREG(lock_stat.st_mode) or lock_stat.st_uid != 0 or lock_stat.st_gid != 0
                or stat.S_IMODE(lock_stat.st_mode) != 0o600 or lock_stat.st_nlink != 1):
            raise Refusal('UNSAFE_LOCK')
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Refusal('REPAIR_BUSY') from None
        backup = state_dir / 'original.profile'
        if backup.exists() or backup.is_symlink():
            saved, _ = read_file(backup, 0o600)
            if saved != original:
                raise Refusal('BACKUP_SHA256_CHANGED')
        else:
            atomic_write(backup, original, 0o600)
        saved, _ = read_file(backup, 0o600)
        if digest(saved) != expected_sha:
            raise Refusal('BACKUP_READBACK_FAILED')
        metadata_path = state_dir / 'original-metadata.json'
        if not metadata_path.exists() and not metadata_path.is_symlink():
            if source != original:
                raise Refusal('ORIGINAL_METADATA_MISSING')
            original_info = {'sha256': expected_sha, 'uid': source_stat.st_uid, 'gid': source_stat.st_gid,
                             'mode': stat.S_IMODE(source_stat.st_mode), 'device': source_stat.st_dev,
                             'inode': source_stat.st_ino, 'mtime_ns': source_stat.st_mtime_ns}
            atomic_write(metadata_path, (json.dumps(original_info) + '\n').encode(), 0o600)
        original_info = json.loads(read_file(metadata_path, 0o600)[0])
        if (original_info.get('sha256') != expected_sha or original_info.get('uid') != 0
                or original_info.get('gid') != 0 or original_info.get('mode') != 0o644
                or (original_info.get('device'), original_info.get('inode')) != expected_identity):
            raise Refusal('ORIGINAL_METADATA_CHANGED')
        candidate = state_dir / 'corrected.profile'
        atomic_write(candidate, corrected, 0o600)
        report = {'kind': 'one_optional_snap_include', 'phase': 'prepared',
                  'original_sha256': expected_sha, 'corrected_sha256': digest(corrected),
                  'original_file_metadata': original_info,
                  'source_file_metadata_before': {'uid': source_stat.st_uid, 'gid': source_stat.st_gid,
                                                'mode': stat.S_IMODE(source_stat.st_mode),
                                                'device': source_stat.st_dev, 'inode': source_stat.st_ino,
                                                'mtime_ns': source_stat.st_mtime_ns},
                  'live_file_replaced': False, 'AppArmor_action': None,
                  'original_profile': str(backup), 'full_Snap_LXD_runtime_verified': False}

        def save():
            atomic_write(state_dir / 'receipt.json', (json.dumps(report, indent=2) + '\n').encode(), 0o600)

        save()
        try:
            run([PARSER, '-Q', '-K', '-j', '1', '-b', str(profile.parent), str(candidate)],
                'candidate_compile', log_dir=state_dir)
            names = run([PARSER, '-Q', '-K', '-N', '-b', str(profile.parent), str(candidate)],
                        'candidate_names', log_dir=state_dir).splitlines()
            if not names or len(names) > 32 or any(not n.startswith('/usr/lib/snapd/snap-confine') for n in names):
                raise Refusal('PROFILE_NAMES_OUT_OF_SCOPE')
            properties = run([SYSTEMCTL, 'show', 'apparmor.service', '-p', 'LoadState',
                              '-p', 'ActiveState', '-p', 'Result', '--no-pager'], 'apparmor_state_before')
            props = dict(line.split('=', 1) for line in properties.splitlines() if '=' in line)
            if props.get('LoadState') != 'loaded' or props.get('ActiveState') not in ('active', 'failed', 'inactive'):
                raise Refusal('APPARMOR_UNIT_STATE')
            again, again_stat = read_file(profile, 0o644)
            if include.exists() or include.is_symlink():
                raise Refusal('INCLUDE_DIRECTORY_STATE_CHANGED')
            if (again != source or fingerprint(again_stat) != fingerprint(source_stat) or snapshot(run) != before
                    or kernel_profiles(kernel_path) != profiles_before):
                raise Refusal('BASELINE_CHANGED_BEFORE_REPLACEMENT')
            if source != corrected:
                atomic_write(profile, corrected, 0o644)
                report['live_file_replaced'] = True
            report['phase'] = 'profile_committed'
            save()
            needs_load = (source != corrected or props['ActiveState'] != 'active'
                          or props.get('Result') != 'success'
                          or any(n + ' (enforce)' not in profiles_before for n in names))
            action = ('reload' if props['ActiveState'] == 'active' else 'start') if needs_load else 'none'
            report['AppArmor_action'] = action
            report['phase'] = 'AppArmor_loading'
            save()
            if needs_load:
                run([SYSTEMCTL, action, 'apparmor.service'], 'apparmor_load', timeout=120, log_dir=state_dir)
            properties = run([SYSTEMCTL, 'show', 'apparmor.service', '-p', 'Result',
                              '-p', 'ActiveState', '--no-pager'], 'apparmor_state_after')
            props = dict(line.split('=', 1) for line in properties.splitlines() if '=' in line)
            if props.get('Result') != 'success' or props.get('ActiveState') != 'active':
                raise Refusal('APPARMOR_NOT_ACTIVE_SUCCESS')
            after = kernel_profiles(kernel_path)
            if (not profiles_before <= after or 'docker-default (enforce)' not in after
                    or any(n + ' (enforce)' not in after for n in names)):
                raise Refusal('KERNEL_CONFINEMENT_CHANGED_OR_SNAP_PROFILE_MISSING')
            final, _ = read_file(profile, 0o644)
            if final != corrected or snapshot(run) != before:
                raise Refusal('POST_REPAIR_FILE_OR_SERVICE_DRIFT')
            report.update(phase='complete', result='PASS', candidate_compile='PASS',
                          AppArmor_active_success=True, prior_kernel_profile_labels_preserved=True,
                          docker_default_enforce=True, container_identities_images_and_restart_counts_preserved=True,
                          selected_Snap_units_preserved=True, loaded_snap_confine_profile_names=names,
                          packages_Moodle_database_Snap_or_Docker_mutations=False)
            save()
            return report
        except Exception as error:
            report.update(result='ERROR', failure_phase=report['phase'],
                          failure_code=getattr(error, 'code', type(error).__name__),
                          return_code=getattr(error, 'return_code', None),
                          automatic_restore_or_unload_executed=False)
            # Restoring the known broken include after policy loading is not a healthy rollback.
            # Keep the backed-up original and durable phase; never unload other kernel profiles.
            save()
            raise
    finally:
        os.close(lock_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repair', action='store_true')
    args = parser.parse_args()
    if not args.repair:
        print(json.dumps({'mode': 'PLAN_ONLY', 'profile': str(PROFILE),
                          'original_sha256': ORIGINAL_SHA256, 'repair': 'one include gains if exists',
                          'apply': '--repair', 'backup_directory': str(STATE_DIR),
                          'AppArmor_only_start_or_reload': True}, indent=2))
        return 0
    os.umask(0o077)
    try:
        result = repair()
    except Exception as error:
        print('HOST_APPARMOR_REPAIR=ERROR; ' + json.dumps({
            'failure_code': getattr(error, 'code', type(error).__name__),
            'return_code': getattr(error, 'return_code', None),
            'private_receipt_directory': str(STATE_DIR)}))
        return 1
    print('HOST_APPARMOR_REPAIR=PASS; ONE_OPTIONAL_INCLUDE; NO_PACKAGE_OR_MOODLE_UPGRADE')
    print(json.dumps(result, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
