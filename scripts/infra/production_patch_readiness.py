#!/usr/bin/env python3
"""Read production metadata and the latest backup before preparing patch code.

Default plan only. --check reads pinned local inputs, hashes files, inspects
existing production containers and runs bounded BEGIN READ ONLY SQL via psql.
No PHP/Moodle bootstrap, filesystem outputs, backup, service/container lifecycle,
configuration edits, application activation, upgrade or archive decryption.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
from decimal import Decimal
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

PROFILE = Path('/usr/local/lib/ustar-recovery/lab-patch-profile-20261005.json')
PROFILE_SHA = '772541013b8f4d5f5d6f49969557f52f89c4032b8e10a18d5552b88be5cf4972'
CODE = Path('/opt/ustar/data/moodle/public')
DATA = Path('/opt/ustar/data/moodle/moodledata')
PGDATA = Path('/opt/ustar/data/postgres')
HDD = Path('/srv/ustar-storage')
HDD_UUID = '359a2bae-4e79-461a-ab72-1597f605d801'
STATE = Path('/var/lib/ustar-backup')
LOCK = Path('/run/ustar-backup/lock')
PACKAGE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72/moodle-518-package-20261011-nnixp4se/moodle-5.1.8.tgz')
PACKAGE_SHA = '0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c'
IMAGES = {
    'ustar_moodle': 'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b',
    'ustar_postgres': 'sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94',
}
ARCHIVE_NAME = re.compile(r'ustar-recovery-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.tar\.gz\.age')


class Refusal(Exception):
    pass


def require(ok, message):
    if not ok:
        raise Refusal(message)


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def no_links(path):
    require(not any(p.is_symlink() for p in (path, *path.parents)), 'Symlink input refused')


def root_input(path):
    no_links(path)
    for parent in path.parents:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
                and not info.st_mode & 0o022, 'Untrusted input parent')
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
            and not info.st_mode & 0o022, 'Untrusted control input')


def read_file(path, maximum=1024 * 1024, digest_only=False):
    no_links(path)
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
            and before.st_size <= maximum, 'Input type, links or size refused')
    digest, chunks, total = hashlib.sha256(), [], 0
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), 'rb') as stream:
        require(signature(os.fstat(stream.fileno())) == signature(before), 'Input replaced before read')
        while chunk := stream.read(1024 * 1024):
            total += len(chunk)
            require(total <= maximum, 'Input grew beyond bound')
            digest.update(chunk)
            if not digest_only:
                chunks.append(chunk)
        require(signature(os.fstat(stream.fileno())) == signature(before)
                and signature(path.lstat()) == signature(before), 'Input changed during read')
    return digest.hexdigest() if digest_only else b''.join(chunks)


def read_json(path):
    root_input(path)
    value = json.loads(read_file(path))
    require(isinstance(value, dict), 'Control JSON must be an object')
    return value


def command(argv, input_text=None, timeout=30):
    result = subprocess.run(argv, input=input_text, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout, check=False)
    require(result.returncode == 0 and len(result.stdout) <= 1024 * 1024,
            'Bounded host command failed')
    return result.stdout


def containers():
    rows = json.loads(command(['docker', 'inspect', *IMAGES]))
    require(isinstance(rows, list) and len(rows) == 2, 'Production container set differs')
    result = {}
    expected_mounts = {
        'ustar_moodle': {str(CODE): '/var/www/html', str(DATA): '/var/www/moodledata'},
        'ustar_postgres': {str(PGDATA): '/var/lib/postgresql/data'},
    }
    for row in rows:
        name = row.get('Name', '').removeprefix('/')
        require(name in IMAGES and name not in result and row['Image'] == IMAGES[name]
                and row['State']['Running'] is True, 'Production container identity/state differs')
        binds = {m['Source']: m['Destination'] for m in row['Mounts'] if m['Type'] == 'bind'}
        require(binds == expected_mounts[name], 'Production bind roots differ')
        result[name] = {k: row[k] for k in ('Id', 'Image')}
        result[name].update(started_at=row['State']['StartedAt'], pid=row['State']['Pid'])
    return result


def parse_prefix(config):
    # Static literal only: no execution or interpolation of PHP configuration.
    values = re.findall(r"(?m)^[ \t]*\$CFG->prefix\s*=\s*(['\"])([A-Za-z][A-Za-z0-9_]{0,31})\1\s*;[ \t]*(?://[^\n]*)?$", config)
    require(len(values) == 1, 'One literal database prefix required')
    return values[0][1]


def home_policy(config):
    assignments = re.findall(r'(?m)^[ \t]*\$CFG->defaulthomepage\s*=([^;\n]+);', config)
    require(len(assignments) <= 1, 'Ambiguous homepage assignment')
    if not assignments:
        return {'declared': False, 'candidate_needs_reviewed_homepage_config_insertion': True}
    require(assignments[0].strip() in ("'/local/ustar/home.php'", '"/local/ustar/home.php"'),
            'Existing homepage expression needs separate review')
    return {'declared': True, 'ustar_relative_home': True}


def db_query(prefix, components):
    require(re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,31}', prefix) is not None,
            'Unsafe database prefix')
    require(len(components) == 57 and len(set(components)) == 57
            and all(re.fullmatch(r'[a-z][a-z0-9_]{0,63}', n) for n in components),
            'Unexpected addon component set')
    names = ','.join("'" + n + "'" for n in components)
    return ("BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; SET LOCAL lock_timeout='3s'; "
            "SELECT json_build_object('postgres_version_num',current_setting('server_version_num'),"
            "'core',(SELECT value FROM " + prefix + "config WHERE name='version'),"
            "'addons',(SELECT json_object_agg(plugin,value) FROM " + prefix +
            "config_plugins WHERE name='version' AND plugin IN (" + names + ")),"
            "'enabled_tasks',(SELECT count(*) FROM " + prefix + "task_scheduled WHERE disabled=0),"
            "'activity_counts',(SELECT json_object_agg(name,n) FROM (SELECT m.name,count(*) AS n FROM " +
            prefix + "course_modules c JOIN " + prefix + "modules m ON m.id=c.module GROUP BY m.name) a),"
            "'block_counts',(SELECT json_object_agg(blockname,n) FROM (SELECT blockname,count(*) AS n FROM " +
            prefix + "block_instances GROUP BY blockname) b)); COMMIT;")


def versions(prefix, profile):
    shell = 'exec psql -X -q -A -t -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
    data = json.loads(command(['docker', 'exec', '-i', 'ustar_postgres', 'sh', '-eu', '-c', shell],
                             db_query(prefix, [r['component'] for r in profile['additional_components']]), 25))
    require(Decimal(data['core']) == Decimal(profile['core_db_version']), 'Production core DB version differs')
    require(int(data['postgres_version_num']) >= 150000, 'Postgres platform below patch minimum')
    expected = {r['component']: str(r['disk_version']) for r in profile['additional_components']}
    require(data['addons'] == expected, 'Production addon DB versions differ from preserved profile')
    for field in ('activity_counts', 'block_counts'):
        data[field] = data[field] or {}
        require(isinstance(data[field], dict) and len(data[field]) <= 200
                and all(re.fullmatch(r'[a-z][a-z0-9_]{0,63}', k)
                        and type(v) is int and 0 <= v <= 10000000 for k, v in data[field].items()),
                'Aggregate usage output refused')
    require(type(data['enabled_tasks']) is int and 0 <= data['enabled_tasks'] <= 10000,
            'Task count output refused')
    return data


def mount():
    rows = json.loads(command(['findmnt', '--json', '--mountpoint', str(HDD),
                              '--output', 'TARGET,FSTYPE,UUID,OPTIONS']))['filesystems']
    require(len(rows) == 1 and rows[0]['target'] == str(HDD)
            and rows[0]['fstype'] == 'ext4' and rows[0]['uuid'] == HDD_UUID,
            'Reviewed HDD mount missing')
    require({'rw', 'nosuid', 'nodev', 'noexec'} <= set(rows[0]['options'].split(',')),
            'HDD mount options differ')
    return rows[0]


@contextmanager
def existing_backup_lock():
    root_input(LOCK)
    with os.fdopen(os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        before = signature(os.fstat(stream.fileno()))
        fcntl.flock(stream.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
        yield
        require(signature(LOCK.lstat()) == before, 'Shared backup lock changed')


def backup():
    with existing_backup_lock():
        before = mount()
        require(not os.path.lexists(STATE / 'moodle-was-running.json'), 'Unresolved backup recovery marker')
        scheduled = read_json(STATE / 'last-hdd-scheduled-run.json')
        success = read_json(STATE / 'last-hdd-success.json')
        manifest = read_json(STATE / 'last-hdd-export.json')
        require(scheduled.get('success') is True and scheduled.get('phase') == 'complete'
                and scheduled.get('recovery_marker_present') is False
                and scheduled.get('recovery_error_type') is None and success.get('success') is True,
                'Latest scheduled backup or resume did not succeed')
        require(success.get('manifest') == dict(manifest, archive=str(HDD / 'backups/managed' / manifest.get('file', ''))),
                'Success record and export manifest differ')
        name, digest, size = manifest.get('file'), manifest.get('sha256'), manifest.get('bytes')
        require(isinstance(name, str) and ARCHIVE_NAME.fullmatch(name)
                and isinstance(digest, str) and re.fullmatch(r'[0-9a-f]{64}', digest)
                and type(size) is int and 0 < size <= 100 * 1024 ** 3
                and manifest.get('readback_verified') is True and manifest.get('hdd_uuid') == HDD_UUID,
                'Archive identity fields refused')
        archive = HDD / 'backups/managed' / name
        root_input(archive)
        require(archive.stat().st_size == size and read_file(archive, size, True) == digest,
                'Latest HDD archive checksum differs')
        require(read_json(archive.with_name(name + '.json')) == manifest, 'Archive sidecar differs')
        require(read_json(STATE / 'last-hdd-export.json') == manifest and mount() == before,
                'Backup manifest or HDD mount changed')
        return {'file': name, 'sha256': digest, 'bytes': size,
                'finished_utc': success['finished_utc'], 'archive_readback_sha256_verified': True,
                'scheduled_success': True, 'recovery_marker_present': False,
                'decryption_or_restore_verified': False}


def tree_size(root):
    no_links(root)
    device, total, count = root.stat().st_dev, 0, 0
    started = time.monotonic()
    def failed(_error):
        raise Refusal('Directory inventory incomplete')
    for parent, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs + files:
            info = (Path(parent) / name).lstat()
            require(not stat.S_ISLNK(info.st_mode) and info.st_dev == device
                    and (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)),
                    'Directory inventory symlink/device/type refused')
            count += 1
            require(count <= 2000000 and time.monotonic() - started < 90,
                    'Directory inventory exceeds bounds')
            if stat.S_ISREG(info.st_mode):
                total += info.st_size
    return {'logical_file_bytes': total, 'entries': count, 'live_non_atomic_estimate': True}


def check():
    before = containers()
    root_input(PROFILE)
    require(read_file(PROFILE, digest_only=True) == PROFILE_SHA, 'Pinned profile checksum differs')
    profile = read_json(PROFILE)
    require(len(profile['application_files']) == 526 and len(profile['additional_components']) == 57,
            'Pinned profile shape differs')
    config = read_file(CODE / 'config.php', 128 * 1024)
    policy = home_policy(config.decode('utf-8'))
    for relative, digest in {**profile['application_files'], **profile['core_metadata_sha256'],
                             'public/index.php': profile['index_sha256']}.items():
        require(not Path(relative).is_absolute() and '..' not in Path(relative).parts,
                'Profile file path refused')
        require(read_file(CODE / relative, 16 * 1024 ** 2, True) == digest,
                'Production code fingerprint differs from tested baseline')
    for row in profile['additional_components']:
        text = read_file(CODE / 'public' / row['version_file'], 128 * 1024).decode('utf-8')
        found = re.findall(r'\$plugin->version\s*=\s*([0-9]+)\s*;', text)
        require(found == [str(row['disk_version'])], 'Addon disk version differs')
    root_input(PACKAGE)
    require(read_file(PACKAGE, 512 * 1024 ** 2, True) == PACKAGE_SHA,
            'Tested official package checksum differs')
    db = versions(parse_prefix(config.decode('utf-8')), profile)
    archive = backup()
    trees = {name: tree_size(root) for name, root in (('code', CODE), ('moodledata', DATA), ('postgres', PGDATA))}
    require(read_file(CODE / 'config.php', 128 * 1024) == config and containers() == before,
            'Production configuration or container identity changed')
    free = {str(root): {'available_bytes': os.statvfs(root).f_bavail * os.statvfs(root).f_frsize,
                       'available_inodes': os.statvfs(root).f_favail} for root in (CODE, HDD)}
    return {'kind': 'production_patch_readonly_fingerprint_and_backup_preflight',
            'measured_at': datetime.now(timezone.utc).isoformat(), 'result': 'PASS',
            'production_core_database_version': db['core'], 'postgres_version_num': db['postgres_version_num'],
            'production_enabled_tasks_unchanged': db['enabled_tasks'], 'addon_disk_and_database_versions_matching': 57,
            'ustar_matching_file_hashes': 526, 'original_core_metadata_and_index_hashes_matching': True,
            'official_package_sha256_verified': True, 'homepage_policy': policy,
            'existing_activity_counts': db['activity_counts'], 'existing_block_counts': db['block_counts'],
            'latest_backup': archive, 'trees': trees, 'free_space': free,
            'container_identities_and_config_unchanged': True, 'SQL_scope': 'bounded_BEGIN_READ_ONLY',
            'PHP_or_Moodle_bootstrap': False, 'containers_services_tasks_changed': False,
            'code_config_or_database_mutations': False, 'candidate_prepared_or_activated': False,
            'production_upgrade_executed': False, 'full_addon_runtime_compatibility_verified': False,
            'production_upgrade_space_budget_verified': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if not args.check:
        print('PLAN_ONLY; --check reads production versions, fingerprints, aggregate usage and backup checksum')
        return 0
    try:
        require(os.geteuid() == 0, 'Run production preflight as root')
        sys.dont_write_bytecode = True
        value = check()
        print('PRODUCTION_PATCH_READINESS=PASS; READ_ONLY; NO_UPGRADE')
        print(json.dumps(value, indent=2, sort_keys=True))
        return 0
    except Exception as error:
        print('PRODUCTION_PATCH_READINESS_ERROR=' + (str(error) if isinstance(error, Refusal)
              else type(error).__name__), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
