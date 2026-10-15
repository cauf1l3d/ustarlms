#!/usr/bin/env python3
"""Upgrade the accepted offline stage, retaining a fresh paired cold baseline.

Default is plan only. --upgrade-stage stops only the owned patch stage, copies
code/config/Moodledata/physical PostgreSQL, activates the accepted code into the
existing bind root, and runs the official CLI upgrade twice. Failures restore the
whole pair. --restore-baseline also works after success or an interrupted run;
it preserves failed/upgraded trees and never shrinks/reverts production storage.
No production exec/SQL/stop, Composer, pulls, cron, deletion or frozen-copy writes.
"""
import argparse
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
import sys
import tempfile
from types import ModuleType
import uuid

READINESS = Path('/usr/local/sbin/USTAR_LAB_PATCH_READINESS_CLI_20261012.py')
READINESS_SHA = '2942e58967980d1d8adcc63c04d996d8798d642724bfead2c66380aee32bc9f9'
SCRIPT = Path('/usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py')
SOURCE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
WORK = SOURCE / 'patch-stage-20261005'
LOCK = Path('/run/ustar-restore-lab-20261004.lock')
MARKER = WORK / 'patch-upgrade-active.json'
TREE_KINDS = {'site/public': 'code', 'site/moodledata': 'moodledata', 'postgres': 'postgres'}
CONTROLS = ('state.json', 'report.json', 'pg.env', 'lab.ini', 'apache.conf')
RESERVE = 4 * 1024 ** 3
DB_GROWTH = 2 * 1024 ** 3
TARGET_VERSION = '2025100608.00'
MAX_ENTRIES = 100000
CODE_UID, DATA_UID, DATA_GID = 0, 33, 33


class UpgradeError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise UpgradeError(message)


def now():
    return datetime.now(timezone.utc).isoformat()


def load_readiness():
    # The transitive builder/cold/profile/engine pins are enforced by this input.
    for path in (READINESS, *READINESS.parents):
        info = path.lstat()
        require((stat.S_ISREG(info.st_mode) if path == READINESS else stat.S_ISDIR(info.st_mode))
                and info.st_uid == 0 and not info.st_mode & 0o022
                and (path != READINESS or info.st_nlink == 1), 'Untrusted readiness dependency')
    fd = os.open(READINESS, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        data = stream.read(1024 ** 2 + 1)
    require(hashlib.sha256(data).hexdigest() == READINESS_SHA, 'Readiness dependency checksum differs')
    module = ModuleType('ustar_upgrade_pinned_readiness')
    exec(compile(data, str(READINESS), 'exec'), module.__dict__)
    require(module.SOURCE == SOURCE and module.LOCK == LOCK, 'Readiness namespace differs')
    return module


def write_json(cold, path, value):
    temporary = path.parent / ('.transaction-' + uuid.uuid4().hex + '.tmp')
    raw = (json.dumps(value, sort_keys=True, indent=2) + '\n').encode()
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)
    cold.sync_directory(path.parent)
    return hashlib.sha256(raw).hexdigest()


def file_hash(cold, path):
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1, 'Nonregular or linked file')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        require(cold.signature(os.fstat(stream.fileno())) == cold.signature(before), 'File identity changed')
        digest = hashlib.sha256()
        for block in iter(lambda: stream.read(1024 ** 2), b''):
            digest.update(block)
        require(cold.signature(os.fstat(stream.fileno())) == cold.signature(before), 'File changed during hash')
    return digest.hexdigest()


def inventory(cold, root, kind, hashes=True):
    first = root.lstat()
    require(stat.S_ISDIR(first.st_mode), 'Tree root is not a directory')
    entries = {}
    def checked(path, directory):
        info = path.lstat()
        mode = stat.S_IMODE(info.st_mode)
        require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                and info.st_dev == first.st_dev and (directory or info.st_nlink == 1),
                'Unsupported tree entry, link or nested filesystem')
        if kind == 'moodledata':
            require(info.st_uid == DATA_UID and info.st_gid == DATA_GID and
                    ((mode == 0o700 if path == root else not mode & 0o7022) if directory
                     else not mode & ~0o666), 'Moodledata ownership or permissions differ')
        else:
            require((kind != 'code' or info.st_uid == CODE_UID) and not mode & 0o7022,
                    'Code/PostgreSQL ownership or permissions differ')
        key = path.relative_to(root).as_posix()
        key = '' if key == '.' else key
        entries[key] = cold.metadata(info, directory=directory,
                                    digest=file_hash(cold, path) if hashes and not directory else None)
        require(len(entries) <= MAX_ENTRIES, 'Tree exceeds inventory limit')
    checked(root, True)
    def failed(_):
        raise UpgradeError('Tree scan incomplete')
    for base, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs:
            checked(Path(base) / name, True)
        for name in files:
            checked(Path(base) / name, False)
    return entries


def verify_tree(cold, root, expected, kind):
    require(inventory(cold, root, kind) == expected, 'Paired tree metadata or hashes differ')


def copy_contents(cold, source, destination, entries):
    # Only immutable, pre-verified trees or an offline source are supplied here.
    require(not any(destination.iterdir()), 'Destination is not empty; no overwrite')
    for key in sorted(entries, key=lambda value: (value.count('/'), value)):
        if not key:
            continue
        info = (source / key).lstat()
        expected = entries[key]
        require(cold.metadata(info, directory=expected['type'] == 'directory',
                              digest=expected.get('sha256')) == expected,
                'Copy source metadata changed')
        target = destination / key
        if expected['type'] == 'directory':
            cold.create_directory(target, info)
        else:
            require(cold.copy_file(source / key, target, info) == expected, 'Copied file hash differs')
    root = entries['']
    os.chown(destination, root['uid'], root['gid'])
    os.chmod(destination, root['mode'])
    for key in sorted(entries, key=lambda value: len(Path(value).parts), reverse=True):
        if entries[key]['type'] == 'directory':
            cold.sync_directory(destination / key)


def budget(cold, candidate):
    entries = [inventory(cold, WORK / name, kind, hashes=False) for name, kind in TREE_KINDS.items()]
    total = sum(sum((v.get('size', 0) + 4095) // 4096 * 4096 + 4096 for v in tree.values()) for tree in entries)
    count = sum(len(tree) for tree in entries)
    code = inventory(cold, candidate, 'code', hashes=False)
    code_bytes = sum((v.get('size', 0) + 4095) // 4096 * 4096 + 4096 for v in code.values())
    # Fresh cold pair, candidate activation, retained upgraded trees and a fresh
    # restore copy, with conservative growth and allocation allowances.
    needed = RESERVE + DB_GROWTH + 4 * total + 2 * code_bytes
    needed_inodes = 4 * count + 2 * len(code) + 10000
    free, inodes = shutil.disk_usage(WORK).free, os.statvfs(WORK).f_favail
    require(free >= needed and inodes >= needed_inodes, 'Insufficient paired restore space or inodes')
    return {'required_free_bytes': needed, 'available_free_bytes': free,
            'required_free_inodes': needed_inodes, 'available_free_inodes': inodes,
            'minimum_free_after_bytes': RESERVE, 'database_growth_allowance_bytes': DB_GROWTH,
            'planning_estimate_not_capacity_guarantee': True}


def roots_identity():
    result = {}
    for name in TREE_KINDS:
        info = (WORK / name).lstat()
        require(stat.S_ISDIR(info.st_mode), 'Live bind root is not a directory')
        result[name] = [info.st_dev, info.st_ino]
    return result


def outside_identity(source):
    state = source.load_state()
    rows = {}
    for kind in ('pg', 'web'):
        item = source.owned(kind, state)
        require(item['State']['Running'], 'Retained source lab is not running')
        rows[kind] = [item['Id'], item['Image'], item['State']['StartedAt']]
    return {'production': source.production_state(), 'source': rows}


def stop_stage(stage, state):
    stage.stop_relay(state)
    for kind in ('web', 'pg'):
        item = stage.owned(kind, state)
        if item['State']['Running']:
            stage.command(['docker', 'stop', '--time', '60', item['Id']], timeout=90)
        item = stage.owned(kind, state)
        require(not item['State']['Running'] and not item['State'].get('OOMKilled')
                and item['State'].get('ExitCode') == 0, 'Stage did not stop cleanly; no cold snapshot')
    require(not (WORK / 'postgres/postmaster.pid').exists(), 'PostgreSQL PID remains after stop')


def stop_for_recovery(stage, state):
    # A killed docker-exec client can leave PHP executing in the container.
    # Stop/confirm both containers before restoring any byte, including on timeout.
    stage.stop_relay(state)
    for kind in ('web', 'pg'):
        item = stage.owned(kind, state)
        if item['State']['Running']:
            stage.command(['docker', 'stop', '--time', '60', item['Id']], timeout=90)
        require(not stage.owned(kind, state)['State']['Running'], 'Stage still running; restore refused')


def start_stage(stage, resume, state):
    for kind in ('pg', 'web'):
        item = stage.owned(kind, state)
        require(not item['State']['Running'], 'Unexpected running stage during start')
        stage.command(['docker', 'start', item['Id']])
        if kind == 'pg':
            resume.wait_database(stage, state)
    stage.assert_offline(state)


def php_argv(r, *program):
    args = ['php']
    for setting in (*r.CLI_POLICY, 'auto_prepend_file=', 'auto_append_file=', 'opcache.enable_cli=0'):
        args += ['-d', setting]
    return args + list(program)


def bootstrap(r, stage, state, expected_upgrade):
    program = "define('CLI_SCRIPT',true); define('NO_UPGRADE_CHECK',true); define('CACHE_DISABLE_ALL',true); " \
              "require '/var/www/html/config.php'; require_once($CFG->libdir.'/upgradelib.php'); " \
              "echo json_encode(['dbhost'=>$CFG->dbhost,'dbname'=>$CFG->dbname,'wwwroot'=>$CFG->wwwroot," \
              "'noemail'=>(bool)$CFG->noemailever,'cron'=>(int)$CFG->cron_enabled," \
              "'adhoc'=>(int)$CFG->task_adhoc_concurrency_limit,'mail'=>ini_get('sendmail_path')," \
              "'memory'=>ini_get('memory_limit'),'needs_upgrade'=>moodle_needs_upgrading()]);"
    output = stage.lab_exec('web', state, php_argv(r, '-r', program), user='33:33').stdout
    require(json.loads(output) == {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab',
            'wwwroot': 'http://127.0.0.1:18085', 'noemail': True, 'cron': 0, 'adhoc': 0,
            'mail': '/bin/false', 'memory': '512M', 'needs_upgrade': expected_upgrade},
            'Stage Moodle bootstrap or process isolation differs')


def database_versions(stage, state):
    query = "BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; " \
            "SET LOCAL lock_timeout='5s'; SELECT json_build_object(" \
            "'core',(SELECT value FROM mdl_config WHERE name='version')," \
            "'addons',(SELECT json_object_agg(plugin,value) FROM mdl_config_plugins WHERE name='version')," \
            "'enabled_tasks',(SELECT count(*) FROM mdl_task_scheduled WHERE disabled=0)); COMMIT;"
    return json.loads(stage.sql(state, query))


def verify_versions(stage, state, baseline, target):
    from decimal import Decimal
    after = database_versions(stage, state)
    require(Decimal(after['core']) == Decimal(target), 'Stage core DB version differs')
    require(all(after['addons'].get(name) == version for name, version in baseline['addons'].items()),
            'An existing addon version changed unexpectedly')
    require(after['enabled_tasks'] == 0, 'Stage scheduled task enabled')


def addon_baseline(versions, profile):
    names = {item['component'] for item in profile['additional_components']}
    require(names <= set(versions['addons']), 'Existing additional addon version is missing')
    # Stock core plugins may legitimately change in a patch. Retained addons
    # have exactly the frozen version.php metadata and must not be upgraded.
    return dict(versions, addons={name: versions['addons'][name] for name in sorted(names)})


def check_references(stage, state):
    original = stage.sql
    def bounded(query_state, text, db='ustar_recovery_lab'):
        require(text.lstrip().upper().startswith('SELECT '), 'File reference checker issued non-SELECT SQL')
        return original(query_state, "BEGIN READ ONLY; SET LOCAL statement_timeout='30s'; "
                        "SET LOCAL lock_timeout='5s'; " + text + ' COMMIT;', db=db)
    stage.sql = bounded
    try:
        return stage.check_file_references(state)
    finally:
        stage.sql = original


def disable_stage_tasks(stage, state):
    stage.sql(state, "BEGIN; SET LOCAL statement_timeout='15s'; SET LOCAL lock_timeout='5s'; "
              "UPDATE mdl_task_scheduled SET disabled=1 WHERE disabled=0; COMMIT;")


def paired_copy(cold, c, run):
    pair = run / 'baseline'
    pair.mkdir(mode=0o700)
    (pair / 'site').mkdir(mode=0o700)
    trees = {}
    for name, kind in TREE_KINDS.items():
        expected = inventory(cold, WORK / name, kind)
        (pair / name).mkdir(mode=0o700)
        copy_contents(cold, WORK / name, pair / name, expected)
        verify_tree(cold, pair / name, expected, kind)
        trees[name] = expected
    controls = {}
    for name in CONTROLS:
        info = c.trusted(WORK / name, private=True)
        controls[name] = cold.copy_file(WORK / name, pair / name, info)
    manifest = {'schema_version': 1, 'kind': 'current_stage_paired_cold_baseline',
                'complete': True, 'created_at': now(), 'trees': trees, 'controls': controls}
    sha = write_json(cold, run / 'baseline-manifest.json', manifest)
    cold.sync_directory(pair / 'site')
    cold.sync_directory(pair)
    return manifest, sha


def read_pair(cold, c, run, expected_sha):
    raw = c.read_checked(run / 'baseline-manifest.json', expected_sha, maximum=64 * 1024 ** 2)
    pair = json.loads(raw)
    require(pair.get('complete') is True and pair.get('kind') == 'current_stage_paired_cold_baseline'
            and set(pair.get('trees', {})) == set(TREE_KINDS)
            and set(pair.get('controls', {})) == set(CONTROLS), 'Incomplete paired baseline')
    for name, kind in TREE_KINDS.items():
        verify_tree(cold, run / 'baseline' / name, pair['trees'][name], kind)
    for name, expected in pair['controls'].items():
        info = c.trusted(run / 'baseline' / name, private=True)
        require(cold.metadata(info, digest=file_hash(cold, run / 'baseline' / name)) == expected,
                'Paired baseline control changed')
    return pair


def move_children(cold, root, destination, expected_identity):
    require([root.lstat().st_dev, root.lstat().st_ino] == expected_identity,
            'Live bind root identity changed; refused move')
    destination.mkdir(mode=0o700)
    src = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    dst = os.open(destination, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        require([os.fstat(src).st_dev, os.fstat(src).st_ino] == expected_identity,
                'Bind root changed while opening')
        for name in os.listdir(src):
            os.rename(name, name, src_dir_fd=src, dst_dir_fd=dst)
        os.fsync(src)
        os.fsync(dst)
    finally:
        os.close(src)
        os.close(dst)
    cold.sync_directory(destination.parent)


def activate(cold, c, r, marker, manifest):
    run = Path(marker['run_directory'])
    root = WORK / 'site/public'
    marker.update(live_trees_modified=True, phase='activating_candidate')
    write_json(cold, MARKER, marker)  # Durable before the first rename.
    move_children(cold, root, run / 'retained-baseline-code', marker['roots']['site/public'])
    entries = dict(manifest['entries'])
    entries[''] = cold.metadata((r.CANDIDATE / 'code').lstat(), directory=True)
    copy_contents(cold, r.CANDIDATE / 'code', root, entries)
    verify_tree(cold, root, entries, 'code')
    c.verify_candidate(root, manifest['entries'])


def restore_pair(cold, c, stage, resume, marker):
    run = Path(marker['run_directory'])
    state = stage.load_state()
    require(state['token'] == marker['stage_token'] and state['containers'] == marker['stage_containers'],
            'Stage state ownership differs from transaction')
    stop_for_recovery(stage, state)
    require(roots_identity() == marker['roots'], 'Live bind roots changed; restore refused')
    if marker.get('live_trees_modified'):
        pair = read_pair(cold, c, run, marker['baseline_manifest_sha256'])
        size = sum(v.get('size', 0) + 4096 for tree in pair['trees'].values() for v in tree.values())
        require(shutil.disk_usage(WORK).free >= RESERVE + 2 * size
                and os.statvfs(WORK).f_favail >= sum(map(len, pair['trees'].values())) + 1000,
                'Insufficient fresh paired restore capacity; stage remains stopped')
        stash = run / ('retained-before-restore-' + uuid.uuid4().hex)
        stash.mkdir(mode=0o700)
        for name in TREE_KINDS:
            move_children(cold, WORK / name, stash / name.replace('/', '-'), marker['roots'][name])
        # All old live trees have been retained; now restore all three before start.
        for name, kind in TREE_KINDS.items():
            copy_contents(cold, run / 'baseline' / name, WORK / name, pair['trees'][name])
            verify_tree(cold, WORK / name, pair['trees'][name], kind)
        for name in ('pg.env', 'lab.ini', 'apache.conf'):
            require(cold.metadata(c.trusted(WORK / name, private=True),
                    digest=file_hash(cold, WORK / name)) == pair['controls'][name],
                    'A bound control changed; no unsafe file-bind replacement')
        for name in ('state.json', 'report.json'):
            raw = c.read_checked(run / 'baseline' / name, pair['controls'][name]['sha256'])
            temporary = WORK / ('.restored-' + uuid.uuid4().hex)
            stage.write_private(temporary, raw)
            os.replace(temporary, WORK / name)
        cold.sync_directory(WORK)
        state = stage.load_state()
    start_stage(stage, resume, state)
    return state


def finish_http(cold, stage, state):
    stage.start_relay(state)
    return cold.http_check()


def validate_marker(c, stage):
    c.trusted_parents(MARKER)
    marker = json.loads(c.read_checked(MARKER, maximum=1024 ** 2))
    run = Path(marker.get('run_directory', ''))
    require(run.parent == SOURCE and re.fullmatch(r'patch-upgrade-518-20261012-[a-z0-9_]+', run.name),
            'Unrecognized transaction directory')
    c.trusted_parents(run)
    c.trusted(run, directory=True, private=True)
    require(marker.get('schema_version') == 1 and marker.get('stage_root') == str(WORK),
            'Unrecognized stage transaction')
    state = stage.load_state()
    require(marker.get('stage_token') == state['token'] and marker.get('stage_containers') == state['containers']
            and marker.get('roots') == roots_identity(), 'Transaction ownership differs')
    return marker


def record(cold, marker, **fields):
    marker.update(fields, updated_at=now())
    write_json(cold, MARKER, marker)
    write_json(cold, Path(marker['run_directory']) / 'transaction.json', marker)


def recovery_record(cold, marker, **fields):
    # A full disk or interrupted journal write must never prevent stage shutdown.
    marker.update(fields, updated_at=now())
    try:
        record(cold, marker)
    except Exception:
        print('TRANSACTION_JOURNAL_WRITE=FAILED; continuing stage recovery', file=sys.stderr, flush=True)


def recover(r, c, dependencies, marker):
    cold, p, resume, source, stage, _, profile = dependencies
    paired_restored = bool(marker.get('live_trees_modified'))
    recovery_record(cold, marker, phase='restoring_baseline')
    state = restore_pair(cold, c, stage, resume, marker)
    bootstrap(r, stage, state, False)
    p.readonly_database(stage, state, profile)
    verify_versions(stage, state, marker['baseline_versions'], profile['core_db_version'])
    references = check_references(stage, state)
    login = finish_http(cold, stage, state)
    record(cold, marker, phase='baseline_restored', rollback_restored=paired_restored,
           restored_file_contenthashes=references, restored_http_login=login,
           live_trees_modified=False)
    return {'baseline_restored': True, 'paired_trees_restored': paired_restored,
            'restored_core_db_version': profile['core_db_version'],
            'verified_file_contenthashes': references, 'http_login': login}


def upgrade(r, c, dependencies):
    cold, _, resume, source, stage, _, profile = dependencies
    if MARKER.exists() or MARKER.is_symlink():
        old = validate_marker(c, stage)
        require(old.get('phase') == 'baseline_restored',
                'Existing transaction: use --restore-baseline; no duplicate upgrade')
        retained = WORK / ('patch-upgrade-history-' + uuid.uuid4().hex + '.json')
        os.rename(MARKER, retained)
        cold.sync_directory(WORK)
    r.check(c)
    outside = outside_identity(source)
    planned = budget(cold, r.CANDIDATE / 'code')
    state = stage.load_state()
    baseline_versions = addon_baseline(database_versions(stage, state), profile)
    require(baseline_versions['core'] == profile['core_db_version']
            and baseline_versions['enabled_tasks'] == 0, 'Stage is not the accepted original baseline')
    run = Path(tempfile.mkdtemp(prefix='patch-upgrade-518-20261012-', dir=SOURCE))
    marker = {'schema_version': 1, 'stage_root': str(WORK), 'run_directory': str(run),
              'stage_token': state['token'], 'stage_containers': state['containers'],
              'roots': roots_identity(), 'baseline_versions': baseline_versions,
              'outside_before': outside, 'storage_plan': planned,
              'candidate_manifest_sha256': r.CANDIDATE_SHA, 'live_trees_modified': False,
              'started_at': now()}
    record(cold, marker, phase='stopping_stage')
    print('STAGE_UPGRADE_START; DIRECTORY=' + str(run), flush=True)
    log = run / 'commands-private.log'
    stage.write_private(log, b'')
    stage._LOG = log.open('ab')
    try:
        stop_stage(stage, state)
        _, sha = paired_copy(cold, c, run)
        record(cold, marker, phase='paired_baseline_complete', baseline_manifest_sha256=sha)
        print('FRESH_STAGE_PAIRED_BASELINE=PASS; NO_CORE_UPGRADE_YET', flush=True)
        accepted = json.loads(c.read_checked(r.CANDIDATE / 'manifest.json', r.CANDIDATE_SHA))
        activate(cold, c, r, marker, accepted)
        start_stage(stage, resume, state)
        bootstrap(r, stage, state, True)
        for attempt in (1, 2):
            record(cold, marker, phase='core_upgrade' if attempt == 1 else 'repeat_upgrade')
            stage.assert_offline(state)
            print('OFFICIAL_STAGE_CLI_UPGRADE=' + str(attempt), flush=True)
            stage.lab_exec('web', state, php_argv(r, '/var/www/html/admin/cli/upgrade.php',
                           '--non-interactive'), user='33:33', timeout=1800)
            disable_stage_tasks(stage, state)
            verify_versions(stage, state, baseline_versions, TARGET_VERSION)
        bootstrap(r, stage, state, False)
        references = check_references(stage, state)
        c.verify_candidate(WORK / 'site/public', accepted['entries'])
        require(roots_identity() == marker['roots'], 'Stage bind roots changed during activation')
        c.read_checked(r.CANDIDATE / 'manifest.json', r.CANDIDATE_SHA)
        c.read_checked(c.CHECKPOINT / 'manifest.json', c.MANIFEST_SHA)
        require(outside_identity(source) == outside, 'Source/production identity or start time changed')
        login = finish_http(cold, stage, state)
        require(outside_identity(source) == outside, 'Source/production changed during final HTTP check')
        report = {'kind': 'isolated_stage_patch_upgrade_and_repeat', 'core_db_version': TARGET_VERSION,
                  'result': 'PASS', 'candidate_activated': True, 'core_upgrade_executed': True,
                  'upgrade_repeat': 'PASS', 'paired_baseline': 'PASS',
                  'paired_baseline_manifest_sha256': sha, 'run_directory': str(run),
                  'candidate_manifest_sha256': r.CANDIDATE_SHA, 'http_login': login,
                  'verified_file_contenthashes': references, 'lab_network': 'loopback_only',
                  'scheduled_tasks_enabled': 0, 'cli_mail_disabled': True,
                  'source_and_production_containers_unchanged': True,
                  'stage_container_ids_and_bind_root_inodes_unchanged': True,
                  'patch_upgrade_rollback_verified': False, 'apache_php_settings_verified': False,
                  'manual_login_roles_and_scorm': 'NOT_TESTED', 'custom_home_runtime_verified': False,
                  'full_addon_compatibility_verified': False, 'composer_executed': False,
                  'finished_at': now(), 'storage_plan': planned}
        write_json(cold, run / 'report.json', report)
        write_json(cold, WORK / 'report.json', report)
        state.update(ready=True, stopped=False, patch_upgrade_run=str(run))
        stage.save_state(state)
        record(cold, marker, phase='upgrade_complete')
        print('LAB_PATCH_UPGRADE=PASS; STAGE_ONLY; REPEAT=PASS; PAIRED_ROLLBACK_TEST_PENDING', flush=True)
        print(json.dumps(report, sort_keys=True, indent=2), flush=True)
        return 0
    except BaseException as error:
        # A second terminal signal must not interrupt the restore midway.
        for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            signal.signal(number, signal.SIG_IGN)
        recovery_record(cold, marker, failure_type=type(error).__name__)
        print('STAGE_UPGRADE_FAILED; restoring only the owned stage; private log=' + str(log), flush=True)
        try:
            result = recover(r, c, dependencies, marker)
            result['source_and_production_containers_unchanged'] = outside_identity(source) == outside
            print('STAGE_BASELINE_RECOVERY=PASS; ' + json.dumps(result, sort_keys=True), flush=True)
        except BaseException as recovery_error:
            recovery_record(cold, marker, phase='recovery_required', recovery_error_type=type(recovery_error).__name__)
            try:
                stop_for_recovery(stage, stage.load_state())
            except BaseException:
                print('STAGE_STOP_CONFIRMATION=INCOMPLETE', file=sys.stderr, flush=True)
            print('STAGE_RECOVERY_REQUIRED; preserved files; run same helper --restore-baseline',
                  file=sys.stderr, flush=True)
        return 1
    finally:
        stage._LOG.close()
        stage._LOG = None


def interrupted(_number, _frame):
    raise UpgradeError('Operator signal interrupted stage transaction')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--upgrade-stage', action='store_true')
    mode.add_argument('--restore-baseline', action='store_true')
    args = parser.parse_args()
    if not args.upgrade_stage and not args.restore_baseline:
        print('PLAN_ONLY; --upgrade-stage changes only the accepted offline stage; --restore-baseline restores its pair')
        return 0
    try:
        require(os.geteuid() == 0, 'Run the explicit stage operation as root')
        r = load_readiness()
        r.trusted_parents(SCRIPT)
        r.trusted(SCRIPT)
        r.trusted_parents(LOCK)
        info = r.trusted(LOCK)
        fd = os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            require(r.signature(os.fstat(stream.fileno())) == r.signature(info), 'Existing lock changed')
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
                signal.signal(number, interrupted)
            c = r.load_builder()
            dependencies = c.load_dependencies()
            cold, _, _, source, stage, _, _ = dependencies
            if args.restore_baseline:
                marker = validate_marker(c, stage)
                before = outside_identity(source)
                had_live_changes, old_phase = bool(marker.get('live_trees_modified')), marker.get('phase')
                try:
                    result = recover(r, c, dependencies, marker)
                except BaseException:
                    for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
                        signal.signal(number, signal.SIG_IGN)
                    recovery_record(cold, marker, phase='recovery_required')
                    stop_for_recovery(stage, stage.load_state())
                    raise
                require(outside_identity(source) == before, 'Source/production changed during restore')
                record(cold, marker, paired_restore_test_passed=had_live_changes,
                       patch_upgrade_rollback_verified=had_live_changes and old_phase == 'upgrade_complete')
                print(('LAB_PATCH_PAIRED_RESTORE=PASS' if had_live_changes else 'LAB_PATCH_BASELINE_RESUME=PASS')
                      + '; ORIGINAL_STAGE_BASELINE_RUNNING', flush=True)
                print(json.dumps(result, sort_keys=True, indent=2), flush=True)
                result_code = 0
            else:
                result_code = upgrade(r, c, dependencies)
            require(r.signature(LOCK.lstat()) == r.signature(info), 'Coordination lock replaced')
            return result_code
    except Exception as error:
        print('LAB_PATCH_UPGRADE_ERROR=' + (str(error) if isinstance(error, UpgradeError)
              else type(error).__name__), file=sys.stderr, flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
