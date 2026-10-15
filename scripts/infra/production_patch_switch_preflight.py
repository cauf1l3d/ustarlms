#!/usr/bin/env python3
"""Read the missing production switch/paired-restore conditions; never switch.

Default is plan only. --check reads the accepted candidate manifests/config,
live tree metadata, Docker mount/restart metadata, exact installed recovery/cron
helpers and an existing shared backup lock. No PHP, SQL, backup, filesystem
outputs, lock creation, lifecycle, config/task changes, activation or upgrade.
The budget is a live non-atomic estimate; a cold pair is not captured or restored.
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
import sys
import time
from types import ModuleType

BUILDER = Path('/usr/local/sbin/USTAR_PRODUCTION_PATCH_CANDIDATE_20261012.py')
BUILDER_SHA = '7b8ee79a1d7ac9768a72cffc9f308b01109452047ee2990a5196856d8368ed88'
CANDIDATE = Path('/var/lib/ustar-production-patch-20261012/candidate-518-wnd475x8')
CANDIDATE_SHA = 'eb6d025d458daf6b5bf9f2367b48811fa6f8c20cac95df28370d9c58a8a6b92d'
SOURCE_SHA = 'e7318ecca9d49663f81ed76b75f51e416a3b33dd70860a2c9fe0378cd7b99a01'
HELPERS = {
    'cron': (Path('/usr/local/sbin/USTAR_CRON_SETUP_20261005.py'),
             '66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852'),
    'hdd_backup': (Path('/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py'),
                   '75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75'),
    'backup_engine': (Path('/usr/local/sbin/USTAR_BACKUP_SFTP_20261004.py'),
                      '0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f'),
}
RESERVE = 4 * 1024 ** 3
GROWTH = 2 * 1024 ** 3
VOLATILITY = 128 * 1024 ** 2
MAX_ENTRIES = 100000
MAX_SECONDS = 180
ROOT_UID = 0
CANDIDATE_ENTRIES = 44174
SOURCE_ENTRIES = 44221


class SwitchRefusal(Exception):
    pass


def require(ok, message):
    if not ok:
        raise SwitchRefusal(message)


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def load_builder():
    for path in BUILDER.parents:
        info = path.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == ROOT_UID and not info.st_mode & 0o022,
                'Untrusted builder parent')
    before = BUILDER.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_uid == ROOT_UID and before.st_nlink == 1
            and not before.st_mode & 0o022 and before.st_size <= 1024 ** 2,
            'Untrusted installed candidate builder')
    with os.fdopen(os.open(BUILDER, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        require(signature(os.fstat(stream.fileno())) == signature(before), 'Builder changed before read')
        raw = stream.read(1024 ** 2 + 1)
        require(signature(os.fstat(stream.fileno())) == signature(before)
                and signature(BUILDER.lstat()) == signature(before), 'Builder changed during read')
    require(hashlib.sha256(raw).hexdigest() == BUILDER_SHA, 'Installed candidate builder SHA differs')
    module = ModuleType('production_switch_pinned_candidate')
    exec(compile(raw, str(BUILDER), 'exec'), module.__dict__)
    return module


def protected_json(p, path, digest):
    p.root_input(path)
    raw = p.read_file(path, 32 * 1024 ** 2)
    require(hashlib.sha256(raw).hexdigest() == digest, 'Accepted manifest checksum differs')
    value = json.loads(raw)
    require(isinstance(value, dict), 'Manifest is not an object')
    return value


def root_metadata(p, path):
    p.no_links(path)
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode), 'Live/candidate root is not a directory')
    return {'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
            'device': info.st_dev, 'inode': info.st_ino}


@contextmanager
def existing_shared_lock(p):
    p.root_input(p.LOCK)
    before = p.LOCK.lstat()
    fd = os.open(p.LOCK, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        require(signature(os.fstat(fd)) == signature(before), 'Backup lock replaced before acquisition')
        fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        require(signature(p.LOCK.lstat()) == signature(before), 'Backup lock replaced during acquisition')
        yield
        require(signature(p.LOCK.lstat()) == signature(before), 'Backup lock changed during preflight')
    finally:
        os.close(fd)


def allocation(entries):
    return sum(((row.get('size', 0) + 4095) // 4096 + 1) * 4096 for row in entries.values())


def tree_estimate(p, root):
    """Metadata only; live disappearing files are counted, never silently called cold."""
    first = root_metadata(p, root)
    started = time.monotonic()
    report = {'files': 0, 'directories': 1, 'logical_file_bytes': 0,
              'allocation_allowance_bytes': 4096, 'vanished_entries': 0,
              'file_owner_counts': {}, 'directory_owner_counts': {},
              'group_or_other_writable_files': 0, 'group_or_other_writable_directories': 0,
              'executable_regular_files': 0, 'live_non_atomic_estimate': True}
    info = root.lstat()
    report['directory_owner_counts'][str(info.st_uid) + ':' + str(info.st_gid)] = 1
    def failed(_):
        raise SwitchRefusal('Live metadata walk failed')
    for base, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs + files:
            require(time.monotonic() - started < MAX_SECONDS
                    and report['files'] + report['directories'] < MAX_ENTRIES,
                    'Live metadata walk exceeded bound')
            try:
                info = (Path(base) / name).lstat()
            except FileNotFoundError:
                report['vanished_entries'] += 1
                require(report['vanished_entries'] <= 32, 'Live metadata changed too much to estimate')
                continue
            directory = stat.S_ISDIR(info.st_mode)
            require(info.st_dev == first['device'] and (directory or stat.S_ISREG(info.st_mode))
                    and (directory or info.st_nlink == 1),
                    'Live tree link/type/nested-filesystem needs separate recovery review')
            kind = 'directory' if directory else 'file'
            owners = report[kind + '_owner_counts']
            owner = str(info.st_uid) + ':' + str(info.st_gid)
            owners[owner] = owners.get(owner, 0) + 1
            report['directories' if directory else 'files'] += 1
            report['allocation_allowance_bytes'] += 4096
            if info.st_mode & 0o022:
                report['group_or_other_writable_directories' if directory
                       else 'group_or_other_writable_files'] += 1
            if not directory:
                report['logical_file_bytes'] += info.st_size
                report['allocation_allowance_bytes'] += (info.st_size + 4095) // 4096 * 4096
                report['executable_regular_files'] += bool(info.st_mode & 0o111)
    require(root_metadata(p, root) == first, 'Live tree root identity or permissions changed')
    return report


def docker_conditions(p, identities):
    ids = p.command(['docker', 'ps', '-aq', '--no-trunc'], timeout=20).splitlines()
    require(2 <= len(ids) <= 128 and len(set(ids)) == len(ids)
            and all(re.fullmatch('[0-9a-f]{64}', value) for value in ids), 'Container inventory bound/IDs differ')
    rows = json.loads(p.command(['docker', 'inspect', *ids], timeout=30))
    require(isinstance(rows, list) and len(rows) == len(ids)
            and {row.get('Id') for row in rows} == set(ids), 'Container mount inventory incomplete')
    own = {row['Id']: name for name, row in identities.items()}
    roots = (p.CODE, p.DATA, p.PGDATA)
    policies, overlaps = {}, 0
    for row in rows:
        require(row.get('Id') in ids, 'Container inventory identity differs')
        if row['Id'] in own:
            require(row['State']['Running'] and row['Image'] == identities[own[row['Id']]]['Image'],
                    'Production lifecycle changed during inspection')
            policy = row['HostConfig']['RestartPolicy']
            require(policy.get('Name') in ('no', 'always', 'unless-stopped', 'on-failure')
                    and isinstance(policy.get('MaximumRetryCount'), int), 'Restart policy requires review')
            policies[own[row['Id']]] = {'name': policy['Name'],
                                      'maximum_retry_count': policy['MaximumRetryCount']}
            continue
        for mount in row.get('Mounts', []):
            if mount.get('Type') == 'bind':
                source = Path(mount['Source'])
                if any(source == root or source.is_relative_to(root) or root.is_relative_to(source) for root in roots):
                    overlaps += 1
    require(len(policies) == 2 and overlaps == 0, 'Another container binds a production tree; recovery scope needs review')
    return {'restart_policies': policies, 'other_container_production_bind_overlaps': overlaps,
            'restart_policy_preservation_required_for_switch': True,
            'container_environment_or_inspect_text_emitted': False}


def check(s, p, c):
    require(CANDIDATE.parent == s.BASE, 'Candidate namespace differs')
    before = p.containers()
    candidate_root = root_metadata(p, CANDIDATE)
    require(candidate_root['uid'] == ROOT_UID and not candidate_root['mode'] & 0o077,
            'Accepted candidate directory is not private/root-owned')
    candidate = protected_json(p, CANDIDATE / 'candidate-manifest.json', CANDIDATE_SHA)
    source = protected_json(p, CANDIDATE / 'source-manifest.json', SOURCE_SHA)
    require(candidate.get('kind') == 'separate_unactivated_production_patch_candidate'
            and candidate.get('production_activated') is False
            and candidate.get('source_manifest_sha256') == SOURCE_SHA
            and candidate.get('official_archive_sha256') == c.ARCHIVE_SHA
            and Decimal(candidate['source_core_db_version']) == Decimal('2025100601.03')
            and len(candidate['source_addon_versions']) == 57
            and len(candidate['entries']) == CANDIDATE_ENTRIES
            and len(source['code_entries']) == SOURCE_ENTRIES,
            'Accepted candidate provenance or inventory shape differs')
    original = p.read_file(p.CODE / 'config.php', 128 * 1024)
    require(hashlib.sha256(original).hexdigest() == source['original_config_sha256']
            and p.read_file(CANDIDATE / 'code/config.php', 128 * 1024) == s.configuration(original),
            'Live/candidate config changed since accepted preparation')
    for path, digest in HELPERS.values():
        p.root_input(path)
        require(p.read_file(path, 1024 ** 2, True) == digest, 'Installed backup/cron helper SHA differs')
    require(not (p.STATE / 'moodle-was-running.json').exists()
            and not (p.STATE / 'moodle-was-running.json').is_symlink(), 'Unresolved backup resume marker')
    roots = {name: root_metadata(p, path) for name, path in
             (('code', p.CODE), ('moodledata', p.DATA), ('postgres', p.PGDATA))}
    code_root = root_metadata(p, CANDIDATE / 'code')
    require(len({row['device'] for row in roots.values()} | {code_root['device']}) == 1,
            'Paired rollback roots/candidate do not share the planned filesystem')
    require(p.read_file(p.PGDATA / 'PG_VERSION', 32) == b'16\n', 'Physical PostgreSQL major version differs')
    data = tree_estimate(p, p.DATA)
    pg = tree_estimate(p, p.PGDATA)
    total = allocation(source['code_entries']) + data['allocation_allowance_bytes'] + pg['allocation_allowance_bytes']
    entries = len(source['code_entries']) + data['files'] + data['directories'] + pg['files'] + pg['directories']
    needed = RESERVE + GROWTH + VOLATILITY + 3 * total + 2 * allocation(candidate['entries'])
    inodes = 3 * entries + 2 * len(candidate['entries']) + 10000
    fs = os.statvfs(p.CODE)
    free = fs.f_bavail * fs.f_frsize
    require(free >= needed and fs.f_favail >= inodes, 'Paired switch/restore space or inode estimate does not fit')
    docker = docker_conditions(p, before)
    require(p.containers() == before, 'Production identities/start times changed during preflight')
    return {'kind': 'production_switch_paired_recovery_readonly_preflight', 'result': 'PASS',
            'measured_at': datetime.now(timezone.utc).isoformat(),
            'candidate_manifest_sha256': CANDIDATE_SHA, 'source_manifest_sha256': SOURCE_SHA,
            'accepted_candidate_config_matches_unchanged_source': True,
            'full_candidate_code_rehash_required_immediately_before_switch': True,
            'live_bind_roots': roots, 'private_candidate_code_root': code_root,
            'live_root_metadata_must_be_preserved_not_replaced_by_private_candidate_root': True,
            'live_metadata_estimates': {'moodledata': data, 'postgres': pg},
            'storage_plan': {'required_free_bytes': needed, 'available_free_bytes': free,
                'required_free_inodes': inodes, 'available_free_inodes': fs.f_favail,
                'minimum_free_after_bytes': RESERVE, 'database_growth_allowance_bytes': GROWTH,
                'live_volatility_allowance_bytes': VOLATILITY, 'planning_estimate_not_capacity_guarantee': True},
            'docker_conditions': docker, 'installed_backup_and_cron_helper_hashes_matching': True,
            'existing_backup_lock_shared_acquisition': 'PASS',
            'switch_requires_existing_backup_lock_exclusive_for_managed_cron_and_backup': True,
            'paired_cold_snapshot_created': False, 'paired_production_restore_executed': False,
            'PHP_or_Moodle_bootstrap': False, 'SQL_executed': False, 'filesystem_outputs': False,
            'production_candidate_data_database_services_tasks_changed': False,
            'production_upgrade_executed': False, 'full_addon_runtime_compatibility_verified': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if not args.check:
        print('PLAN_ONLY; --check reads switch/paired-restore conditions; no capture or activation')
        return 0
    try:
        require(os.geteuid() == 0, 'Run switch preflight as root')
        os.umask(0o077)
        sys.dont_write_bytecode = True
        s = load_builder()
        p, c = s.dependencies()
        with existing_shared_lock(p), s.own_lock(p, c, False):
            report = check(s, p, c)
        print('PRODUCTION_SWITCH_PREFLIGHT=PASS; READ_ONLY; NO_OUTAGE; NO_UPGRADE')
        print(json.dumps(report, indent=2, sort_keys=True))
        return 0
    except Exception as error:
        print('PRODUCTION_SWITCH_PREFLIGHT_ERROR=' + (str(error) if isinstance(error, SwitchRefusal)
              else type(error).__name__), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
