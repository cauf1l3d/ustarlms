#!/usr/bin/env python3
"""Read-only planning check for the already resumed recovery lab.

Default is plan only. --check reads retained code/config/metadata and storage,
inspects container ownership/network, runs bounded READ ONLY SQL in the lab,
and reads PHP CLI settings without Moodle bootstrap. No backup, upgrade,
container creation/stop, relay change, production exec/SQL or output files.
"""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
from datetime import datetime, timezone

ROOT = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
LOCK = Path('/run/ustar-restore-lab-20261004.lock')
RESUME = Path('/usr/local/sbin/USTAR_LAB_RESUME_20261005.py')
RESUME_SHA = '59dec234262dd98fac0aa09a81cc3af9649ab8518580f4d977f5659a18042282'
PROFILE = Path('/usr/local/lib/ustar-recovery/lab-patch-profile-20261005.json')
PROFILE_SHA = '772541013b8f4d5f5d6f49969557f52f89c4032b8e10a18d5552b88be5cf4972'
GIB = 1024 ** 3
MIB = 1024 ** 2


class PreflightError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise PreflightError(message)


def guarded(call, message):
    """Borrowed helpers may handle private inputs; publish only a fixed failure category."""
    try:
        return call()
    except Exception:
        raise PreflightError(message) from None


def trusted(path, directory=False, root_owned=True, private=False):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and (not root_owned or info.st_uid == 0)
            and not info.st_mode & (0o077 if private else 0o022)
            and (directory or info.st_nlink == 1), 'Untrusted retained input')
    return info


def trust_parents(path):
    for parent in path.parents:
        trusted(parent, directory=True)


def load_resume():
    trusted(RESUME)
    trust_parents(RESUME)
    data = RESUME.read_bytes()
    require(hashlib.sha256(data).hexdigest() == RESUME_SHA, 'Resume dependency checksum mismatch')
    spec = importlib.util.spec_from_file_location('ustar_patch_checked_resume', RESUME)
    resume = importlib.util.module_from_spec(spec)
    exec(compile(data, str(RESUME), 'exec'), resume.__dict__)
    require(resume.ROOT == ROOT and resume.LOCK == LOCK, 'Dependency namespace mismatch')
    lab, template = guarded(resume.load_engine, 'Pinned engine/checker validation failed')
    require(lab._LOG is None, 'Unexpected private log in read-only mode')
    return resume, lab, template


def relative_path(value):
    require(isinstance(value, str), 'Invalid reference path')
    p = PurePosixPath(value)
    require(value and not p.is_absolute()
            and p.as_posix() == value and '\\' not in value
            and not set(p.parts) & {'.', '..'} and '\0' not in value,
            'Invalid reference path')
    return value


def load_profile():
    trusted(PROFILE, private=True)
    trust_parents(PROFILE)
    data = PROFILE.read_bytes()
    require(hashlib.sha256(data).hexdigest() == PROFILE_SHA, 'Planning profile checksum mismatch')
    p = json.loads(data)
    require(p['schema_version'] == 1 and len(p['application_files']) == 526
            and len(p['additional_components']) == 57 and len(p['additional_roots']) == 19,
            'Unsupported planning reference')
    for path, digest in p['application_files'].items():
        relative_path(path)
        require(path.startswith(('public/local/ustar/', 'public/theme/ustar/'))
                and re.fullmatch(r'[0-9a-f]{64}', digest), 'Invalid USTAR file reference')
    for path in [*p['additional_roots'], *p['core_metadata_sha256'],
                 *('public/' + c['version_file'] for c in p['additional_components'])]:
        relative_path(path)
    require(all(re.fullmatch(r'[a-z][a-z0-9_]*', c['component'])
                and isinstance(c['disk_version'], int) for c in p['additional_components']),
            'Invalid component metadata reference')
    return p


def scan_tree(path, block_size, root_owned=False):
    """Measure metadata only; no links, sockets, devices, hardlinks or crossing filesystems."""
    first = trusted(path, directory=True, root_owned=root_owned)
    total = {'files': 0, 'directories': 1, 'logical_bytes': 0,
             'allocated_bytes': first.st_blocks * 512, 'copy_budget_bytes': block_size}
    def onerror(_):
        raise PreflightError('Directory scan incomplete; live estimate must be repeated')
    for base, dirs, files in os.walk(path, followlinks=False, onerror=onerror):
        for name in dirs + files:
            try:
                info = trusted(Path(base) / name, directory=name in dirs, root_owned=root_owned)
            except FileNotFoundError:
                raise PreflightError('Live tree changed during scan; repeat read-only check') from None
            require(info.st_dev == first.st_dev, 'Nested filesystem in retained tree')
            total['allocated_bytes'] += info.st_blocks * 512
            if name in dirs:
                total['directories'] += 1
                total['copy_budget_bytes'] += block_size
            else:
                total['files'] += 1
                total['logical_bytes'] += info.st_size
                total['copy_budget_bytes'] += ((info.st_size + block_size - 1) // block_size) * block_size
    return total


def file_hash(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(MIB), b''):
            h.update(chunk)
    return h.hexdigest()


def check_sources(lab, profile):
    code = ROOT / 'site/public'
    for path, digest in profile['core_metadata_sha256'].items():
        require(file_hash(code / path) == digest, 'Baseline core metadata differs')
    require(file_hash(code / 'public/index.php') == profile['index_sha256'],
            'Retained USTAR entry point differs')
    expected = profile['application_files']
    actual = set()
    for path in ('public/local/ustar', 'public/theme/ustar'):
        actual.update(p.relative_to(code).as_posix() for p in (code / path).rglob('*') if p.is_file())
    require(actual == set(expected), 'Retained USTAR file inventory differs from PR76')
    require(all(file_hash(code / path) == digest for path, digest in expected.items()),
            'Retained USTAR file hash differs from PR76')
    for path in profile['additional_roots']:
        trusted(code / path, directory=True)
    for c in profile['additional_components']:
        text = lab.strip_php_comments((code / 'public' / c['version_file']).read_text())
        matches = re.findall(r'\$plugin\s*->\s*version\s*=\s*(\d+)(?:\.0+)?\s*;', text)
        require(matches == [str(c['disk_version'])], 'Retained addon version metadata differs or is nonliteral')
        if c['requires_literal'] is not None:
            needs = re.findall(r'\$plugin\s*->\s*requires\s*=\s*(\d+)(?:\.0+)?\s*;', text)
            require(needs == [str(c['requires_literal'])], 'Retained addon minimum core metadata differs or is nonliteral')


def budget(trees, free, free_inodes):
    """Conservative cold-copy allowance, separate candidate/work space and a 4 GiB floor."""
    baseline = sum(v['copy_budget_bytes'] for v in trees.values())
    rollback = (baseline * 5 + 3) // 4 + 64 * MIB
    candidate = max(GIB, trees['code']['copy_budget_bytes'] * 2)
    growth = max(512 * MIB, trees['postgres']['copy_budget_bytes'] // 4)
    need = rollback + 2 * candidate + growth + 4 * GIB
    entries = sum(v['files'] + v['directories'] for v in trees.values())
    inodes = entries + 2 * (trees['code']['files'] + trees['code']['directories']) + 10000
    require(free >= need, 'Insufficient space for cold rollback + candidate/work + 4 GiB reserve')
    require(free_inodes >= inodes, 'Insufficient free inodes for rollback and candidate')
    return {'rollback_copy_allowance_bytes': rollback, 'candidate_code_allowance_bytes': candidate,
            'candidate_work_allowance_bytes': candidate, 'database_growth_allowance_bytes': growth,
            'minimum_free_after_bytes': 4 * GIB, 'required_free_bytes': need, 'available_free_bytes': free,
            'estimated_free_after_work_bytes': free - need + 4 * GIB, 'required_free_inodes': inodes,
            'available_free_inodes': free_inodes, 'planning_estimate_not_capacity_guarantee': True}


def readonly_database(lab, state, profile):
    require(all(re.fullmatch(r'[a-z][a-z0-9_]*', c['component'])
                for c in profile['additional_components']), 'Invalid component name for read-only query')
    names = ','.join("'" + c['component'] + "'" for c in profile['additional_components'])
    query = ("BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; SET LOCAL lock_timeout='3s'; "
             "SELECT json_build_object('core',(SELECT value FROM mdl_config WHERE name='version'),"
             "'enabled_tasks',(SELECT count(*) FROM mdl_task_scheduled WHERE disabled=0),"
             "'versions',(SELECT json_object_agg(plugin,value) FROM mdl_config_plugins "
             "WHERE name='version' AND plugin IN (" + names + "))); COMMIT;")
    row = json.loads(guarded(lambda: lab.sql(state, query), 'Bounded read-only clone SQL failed'))
    require(row['core'] == profile['core_db_version'] and row['enabled_tasks'] == 0,
            'Clone core DB version differs or scheduled tasks are enabled')
    require(row['versions'] == {c['component']: str(c['disk_version']) for c in profile['additional_components']},
            'Clone addon disk/DB versions differ')


def check(resume, lab, template, profile):
    trust_parents(ROOT)
    trusted(ROOT, directory=True, private=True)
    for name in ('state.json', 'pg.env', 'lab.ini', 'apache.conf', 'report.json'):
        trusted(ROOT / name, private=True)
    state = lab.load_state()
    require(state.get('ready') is True and state.get('stopped') is False,
            'Expected the successfully resumed running lab')
    require(set(state.get('images', {})) == {'web', 'pg'} and set(state['containers']) == {'web', 'pg'},
            'Incomplete retained container/image identities')
    require(set(lab.NAMES.values()).isdisjoint(lab.PRODUCTION), 'Lab namespace overlaps production')
    before = lab.production_state()  # Docker inspect only; no production exec.
    for kind in ('pg', 'web'):
        owned = guarded(lambda: lab.owned(kind, state), 'Retained container ownership/image/mount/network guard failed')
        require(owned['State']['Running'], 'A retained lab container is stopped')
        name = 'ustar_postgres' if kind == 'pg' else 'ustar_moodle'
        require(state['images'][kind] == state['preflight']['images'][name]['id'],
                'Retained image differs from verified archive')
    guarded(lambda: lab.assert_offline(state), 'Retained loopback namespace validation failed')
    trusted(ROOT / 'site', directory=True)
    trusted(ROOT / 'site/public', directory=True)
    trusted(ROOT / 'site/moodledata', directory=True, root_owned=False, private=True)
    report = json.loads((ROOT / 'report.json').read_bytes())
    require(report.get('snapshot_sha256') == state['archive_sha256']
            and report.get('database_restore') == 'PASS' and report.get('moodle_bootstrap') == 'PASS',
            'Retained restore acceptance is missing')
    fs = os.statvfs(ROOT)
    trees = {name: scan_tree(ROOT / path, fs.f_frsize, root_owned=name == 'code')
             for name, path in {'code': 'site/public', 'moodledata': 'site/moodledata', 'postgres': 'postgres'}.items()}
    require((ROOT / 'postgres/PG_VERSION').read_text().strip() == '16', 'Existing PostgreSQL cluster is not 16')
    guarded(lambda: resume.check_config(lab, template), 'Retained offline config validation failed')
    check_sources(lab, profile)
    planned = budget(trees, shutil.disk_usage(ROOT).free, fs.f_favail)
    readonly_database(lab, state, profile)
    php = json.loads(guarded(lambda: lab.lab_exec('web', state, ['php', '-r',
        'echo json_encode(["version"=>PHP_VERSION,"sapi"=>PHP_SAPI,"max_input_vars"=>ini_get("max_input_vars"),'
        '"soap"=>extension_loaded("soap"),"exif"=>extension_loaded("exif")]);'], user='33:33'),
        'Clone PHP CLI settings read failed').stdout)
    require(php['version'] == profile['patch_runtime']['php_version'] and php['sapi'] == 'cli',
            'Retained PHP CLI version differs from tested candidate runtime')
    require(lab.production_state() == before, 'Production containers changed during preflight')
    return {'kind': 'retained_lab_patch_planning_preflight', 'measured_at': datetime.now(timezone.utc).isoformat(),
            'planning_check': 'PASS', 'read_only': True, 'moodle_bootstrap_executed': False,
            'production_containers_unchanged': True, 'lab_network': 'loopback_only',
            'ustar_matching_files': len(profile['application_files']),
            'addon_metadata_matching': len(profile['additional_components']),
            'addon_requires_nonliteral': [c['component'] for c in profile['additional_components']
                                         if c['requires_literal'] is None],
            'php_cli': php, 'sizes_live_non_atomic': trees, 'storage_plan': planned,
            'candidate_tag': profile['patch_runtime']['moodle_tag'], 'core_upgrade_executed': False,
            'rollback_created_or_verified': False, 'full_addon_compatibility_verified': False,
            'coverage': profile['coverage']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if not args.check:
        print('PLAN_ONLY; --check reads the existing running lab and estimates paired rollback space')
        return
    require(os.geteuid() == 0, 'Run with sudo')
    sys.dont_write_bytecode = True
    profile = load_profile()
    resume, lab, template = load_resume()
    trusted(LOCK, private=True)
    # Open the existing coordination lock read-only; do not create a new file.
    fd = os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077
                and info.st_nlink == 1, 'Untrusted coordination lock')
        fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        result = check(resume, lab, template, profile)
        print('LAB_PATCH_PREFLIGHT=PASS; read-only planning; no upgrade or backup created')
        print(json.dumps(result, indent=2, sort_keys=True))
    finally:
        os.close(fd)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error) if isinstance(error, PreflightError) else type(error).__name__
        print('LAB_PATCH_PREFLIGHT_ERROR: ' + message, file=sys.stderr)
        sys.exit(1)
