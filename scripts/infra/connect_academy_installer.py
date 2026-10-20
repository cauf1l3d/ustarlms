#!/usr/bin/env python3
"""Guarded addon update of the fixed public Connect RC; default is plan only."""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
from decimal import Decimal
import fcntl
import hashlib
import importlib
import io
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys
import tempfile
import traceback
from types import SimpleNamespace
import uuid
import zipfile

SOURCE = '830d3703e2ffa2f9864900c0d0c58f60049cb0c2'
BASE = Path('/var/lib/ustar-connect-academy-update')
CORE = '2025100608.00'
ROOT_UID = 0
NEW_TASK = '\\local_ustar\\task\\prune_connect_identity'
SCOPES = ('local/ustar', 'theme/ustar')
SIGNALS = (signal.SIGTERM, signal.SIGINT, signal.SIGHUP)


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def notice(value):
    try:
        print(value, flush=True)
    except OSError:
        pass


def bundle(path):
    """Validate the complete embedded inventory, not just files used by activation."""
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        require(len(names) == len(set(names)) and len(names) <= 1000, 'bundle_inventory')
        require(sum(i.file_size for i in archive.infolist()) <= 512 * 1024 ** 2, 'bundle_size')
        manifest = json.loads(archive.read('INSTALL_MANIFEST.json'))
        require(manifest['payload_commit'] == SOURCE and manifest['schema'] == 1, 'bundle_source')
        require(set(names) == set(manifest['files']) | {'INSTALL_MANIFEST.json'}, 'bundle_inventory')
        for name, digest in manifest['files'].items():
            require(not Path(name).is_absolute() and '..' not in Path(name).parts
                    and '\\' not in name and not name.endswith('/'), 'bundle_path')
            require(hashlib.sha256(archive.read(name)).hexdigest() == digest, 'bundle_hash')
        reference = json.loads(archive.read('reference.json'))
        require(reference['source_commit'] == SOURCE, 'bundle_reference')
        payload = {name: archive.read('payload/' + name) for name in reference['candidate']['files']}
        verify_payload(reference, payload)
        return reference, payload, manifest


def verify_payload(reference, payload):
    expected = reference['candidate']['files']
    require(set(payload) == set(expected), 'payload_inventory')
    for name, value in payload.items():
        require(name.startswith(tuple(s + '/' for s in SCOPES)) and '..' not in Path(name).parts
                and not Path(name).is_absolute() and '\\' not in name, 'payload_path')
        require(hashlib.sha256(value).hexdigest() == expected[name], 'payload_hash')


def dependencies(path):
    modules = {name: importlib.import_module(module) for name, module in (
        ('p', 'production_patch_readiness'), ('cold', 'lab_cold_checkpoint'),
        ('u', 'lab_patch_upgrade'), ('c', 'lab_patch_candidate'),
        ('tx', 'production_patch_upgrade'), ('w', 'production_patch_switch_preflight'),
        ('probe', 'connect_readiness'))}
    d = SimpleNamespace(**modules)
    d.reference, d.payload, d.manifest = bundle(path)
    d.schema_fields = d.manifest['schema_fields']
    d.probe.validate_reference(d.reference)
    d.base, d.marker = BASE, BASE / 'active.json'
    d.web = d.p.CODE / 'public'
    d.trees = {'code': d.p.CODE, 'moodledata': d.p.DATA, 'postgres': d.p.PGDATA}
    d.cold.reserve = lambda: d.tx.reserve(d.p)
    d.c.reserve = lambda: d.tx.reserve(d.p)
    return d


class Host:
    """Delegate only container/CLI controls; do not invoke the old core installer."""
    def __init__(self, d, identities, log):
        self.d = d
        self.control = d.tx.Host(d, identities, log)

    def __getattr__(self, name):
        return getattr(self.control, name)

    def database(self, prefix, addons=None):
        value = self.control.sql(db_query(prefix))
        require(set(value) == {'core', 'addons', 'tasks', 'tables', 'schema', 'indexes', 'other_clients'}
                and isinstance(value['addons'], dict) and 2 <= len(value['addons']) <= 2000
                and isinstance(value['tasks'], dict) and len(value['tasks']) <= 10000
                and isinstance(value['tables'], list) and isinstance(value['schema'], list)
                and isinstance(value['indexes'], list)
                and type(value['other_clients']) is int, 'database_shape')
        return value


def db_query(prefix):
    require(re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}', prefix), 'database_prefix')
    # Full plugin registry and task flags; no credentials or personnel data.
    stem = prefix + 'local_ustar_'
    return ("BEGIN READ ONLY; SET LOCAL statement_timeout='25s'; SET LOCAL lock_timeout='3s'; "
             "SELECT json_build_object('core',(SELECT value FROM " + prefix + "config WHERE name='version'),"
             "'addons',(SELECT json_object_agg(plugin,value) FROM " + prefix + "config_plugins WHERE name='version'),"
             "'tasks',(SELECT json_object_agg(classname,json_build_array(disabled,customised)) FROM " + prefix + "task_scheduled),"
             "'tables',(SELECT json_agg(substr(tablename," + str(len(prefix) + 1) + ") ORDER BY tablename) "
             "FROM pg_catalog.pg_tables WHERE schemaname='public' AND left(tablename," + str(len(stem)) + ")='" + stem + "'),"
             "'schema',(SELECT json_agg(json_build_array(substr(table_name," + str(len(prefix) + 1) + "),column_name,data_type,udt_name,"
             "character_maximum_length,numeric_precision,numeric_scale,is_nullable,column_default) "
             "ORDER BY table_name,ordinal_position) FROM information_schema.columns "
             "WHERE table_schema='public' AND left(table_name," + str(len(stem)) + ")='" + stem + "'),"
             "'indexes',(SELECT json_agg(json_build_array(tablename,indexdef) ORDER BY tablename,indexdef) "
             "FROM pg_catalog.pg_indexes WHERE schemaname='public' AND left(tablename," + str(len(stem)) + ")='" + stem + "'),"
             "'other_clients',(SELECT count(*) FROM pg_stat_activity WHERE pid<>pg_backend_pid() "
             "AND backend_type='client backend')); COMMIT;")


def verify_versions(d, current, baseline, candidate):
    expected = dict(baseline['addons'])
    if candidate:
        expected.update({k: str(v) for k, v in d.reference['candidate']['versions'].items()})
    require(Decimal(current['core']) == Decimal(CORE) and current['addons'] == expected, 'database_versions')
    wanted = d.reference['candidate' if candidate else 'baseline']
    require(current['tables'] == wanted['tables'], 'database_table_inventory')
    tasks = dict(baseline['tasks'])
    if candidate:
        tasks[NEW_TASK] = [0, 0]
    require(current['tasks'] == tasks, 'scheduled_task_flags')
    if not candidate:
        require(current['schema'] == baseline['schema'] and current['indexes'] == baseline['indexes'], 'restored_schema')
    elif hasattr(d, 'schema_fields'):
        actual = {(row[0], row[1]): row for row in current['schema']}
        require(set(actual) == {(table, field) for table, fields in d.schema_fields.items() for field in fields},
                'schema_column_inventory')
        for table, fields in d.schema_fields.items():
            for field, spec in fields.items():
                row = actual[(table, field)]
                require((row[7] == 'NO') == spec['notnull'], 'schema_nullability')
                allowed = {'int': ('int2', 'int4', 'int8'), 'char': ('varchar',),
                           'text': ('text',), 'number': ('numeric',), 'binary': ('bytea',)}
                require(row[3] in allowed.get(spec['type'], ()), 'schema_column_type')
                if spec['type'] == 'char':
                    require(row[4] == int(spec['length']), 'schema_column_length')


def source(d, candidate=False):
    expected = d.reference['candidate' if candidate else 'baseline']['files']
    actual = {}
    for scope in SCOPES:
        tree = d.tx.inventory(d, d.web / scope)
        actual.update({scope + '/' + k: row['sha256'] for k, row in tree.items() if row['type'] == 'file'})
    require(actual == expected, 'source_drift')


def capacity(d, cold=False):
    estimates = [d.w.tree_estimate(d.p, root) for root in d.trees.values()]
    require(not cold or all(e['vanished_entries'] == 0 for e in estimates), 'cold_capacity_changed')
    total = sum(e['allocation_allowance_bytes'] for e in estimates)
    entries = sum(e['files'] + e['directories'] for e in estimates)
    fs = os.statvfs(d.p.CODE)
    # Pair + restore copy + retained changed files + DB growth, with room for recovery.
    required = 4 * 1024 ** 3 + 2 * 1024 ** 3 + 3 * total
    require(fs.f_bavail * fs.f_frsize >= required and fs.f_favail >= 3 * entries + 10000, 'recovery_capacity')
    return {'required_bytes': required, 'available_bytes': fs.f_bavail * fs.f_frsize,
            'required_inodes': 3 * entries + 10000, 'available_inodes': fs.f_favail}


def config_guard(raw):
    # New channels must be deliberately configured after this source/schema
    # update. Do not execute PHP to guess the value of a preparatory expression.
    require(not re.search(rb'\$CFG\s*->\s*ustar_connect_[A-Za-z_]+\s*=', raw),
            'connect_configuration_requires_separate_activation_review')


def checks(d):
    d.web = d.probe.webroot(d.p.CODE)
    require(d.web == d.p.CODE / 'public', 'production_layout')
    require(not os.path.lexists(d.p.DATA / 'climaintenance.html'), 'existing_maintenance')
    require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'backup_recovery_pending')
    d.p.mount()
    for path, digest in d.w.HELPERS.values():
        d.p.root_input(path)
        require(d.p.read_file(path, 1024 ** 2, True) == digest, 'installed_helper_changed')
    d.identities = d.p.containers()
    policy = d.w.docker_conditions(d.p, d.identities)
    roots = d.tx.roots(d)
    require(len({r[0] for r in roots.values()}) == 1, 'production_filesystems')
    source(d)
    config = d.p.read_file(d.p.CODE / 'config.php', 128 * 1024)
    config_guard(config)
    prefix = d.p.parse_prefix(config.decode())
    with io.BytesIO() as log:
        current = Host(d, d.identities, log).database(prefix)
    require({k: current['addons'].get(k) for k in d.reference['baseline']['versions']}
            == {k: str(v) for k, v in d.reference['baseline']['versions'].items()}, 'baseline_versions')
    verify_versions(d, current, current, False)
    require(NEW_TASK not in current['tasks'], 'unexpected_cleanup_task')
    return {'status': 'ready_for_maintenance_window', 'capacity': capacity(d),
            'source_files': len(d.reference['baseline']['files']),
            'candidate_files': len(d.payload), 'host_changes': False,
            'restart_policies': policy['restart_policies']}


def record(d, marker, **fields):
    marker.update(fields, updated_at=datetime.now(timezone.utc).isoformat())
    d.u.write_json(d.cold, Path(marker['run_directory']) / 'transaction.json', marker)
    d.u.write_json(d.cold, d.marker, marker)


def validate_marker(d):
    marker = d.p.read_json(d.marker)
    run = Path(marker.get('run_directory', ''))
    require(run.parent == d.base and re.fullmatch('update-[a-z0-9_]+', run.name)
            and marker.get('kind') == 'connect_academy_addon_transaction'
            and marker.get('payload_commit') == SOURCE, 'transaction_identity')
    d.p.no_links(run)
    info = run.lstat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == ROOT_UID and not info.st_mode & 0o077,
            'transaction_directory')
    require(d.tx.roots(d) == marker.get('roots') and marker.get('web_root') == str(d.web), 'transaction_roots')
    require(re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}', marker.get('prefix', '')), 'transaction_prefix')
    require(set(marker.get('identities', {})) == set(d.p.IMAGES), 'transaction_containers')
    for name, row in marker['identities'].items():
        require(row.get('Image') == d.p.IMAGES[name] and re.fullmatch('[a-f0-9]{64}', row.get('Id', '')),
                'transaction_container_identity')
    return marker


def fresh_hdd(d, marker, host):
    path, digest = d.w.HELPERS['hdd_backup']
    d.p.root_input(path)
    require(d.p.read_file(path, 1024 ** 2, True) == digest, 'hdd_helper_changed')
    started = datetime.now(timezone.utc)
    record(d, marker, phase='fresh_hdd_backup')
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, SIGNALS)
    try:
        host.action(['python3', '-I', '-B', '-u', str(path), '--backup', '--acknowledge-outage'], timeout=900)
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)
    success = d.p.read_json(d.p.STATE / 'last-hdd-success.json')
    exported = d.p.read_json(d.p.STATE / 'last-hdd-export.json')
    require(success.get('success') is True and success.get('phase') == 'complete'
            and datetime.fromisoformat(success['started_utc']) >= started
            and exported.get('readback_verified') is True
            and success['manifest']['sha256'] == exported['sha256']
            and not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'fresh_hdd_receipt')
    return {key: exported[key] for key in ('file', 'sha256', 'bytes')}


def references(d, host, prefix):
    return d.tx.references(d, host, prefix)


def activate(d, marker):
    # Snapshot is complete. Mark possible mutation BEFORE the first rename.
    record(d, marker, phase='activating_addons', live_trees_modified=True)
    retained = Path(marker['run_directory']) / 'retained-original-addons'
    retained.mkdir(mode=0o700)
    for scope in SCOPES:
        root = d.web / scope
        metadata = d.tx.inventory(d, root)
        rootinfo = root.lstat()
        d.u.move_children(d.cold, root, retained / scope.replace('/', '-'), [rootinfo.st_dev, rootinfo.st_ino])
        names = sorted(n for n in d.payload if n.startswith(scope + '/'))
        for name in names:
            relative = name[len(scope) + 1:]
            target = root / relative
            for parent in reversed(target.parents):
                if parent == root or root not in parent.parents:
                    continue
                if not parent.exists():
                    parent.mkdir(mode=0o755)
                    prior = metadata.get(parent.relative_to(root).as_posix(), {})
                    os.chown(parent, prior.get('uid', rootinfo.st_uid), prior.get('gid', rootinfo.st_gid))
                    os.chmod(parent, prior.get('mode', 0o755))
            raw = d.payload[name]
            prior = metadata.get(relative, {})
            d.c.write_file(target, io.BytesIO(raw), len(raw), prior.get('uid', rootinfo.st_uid),
                           prior.get('gid', rootinfo.st_gid), prior.get('mode', 0o644),
                           hashlib.sha256(raw).hexdigest())
        for base, dirs, _ in os.walk(root, topdown=False):
            d.cold.sync_directory(Path(base))
    source(d, True)


def recover(d, marker, host):
    host.policy('no')
    host.stop()
    require(not any(row['State']['Running'] for row in host.inspect().values()), 'stop_not_confirmed')
    require(d.tx.roots(d) == marker['roots'], 'recovery_roots')
    if marker.get('live_trees_modified'):
        pair = d.tx.read_pair(d, marker)
        total = sum(d.w.allocation(tree) for tree in pair['trees'].values())
        entries = sum(len(tree) for tree in pair['trees'].values())
        fs = os.statvfs(d.p.CODE)
        require(fs.f_bavail * fs.f_frsize >= 4 * 1024 ** 3 + 2 * total
                and fs.f_favail >= entries + 1000, 'restore_capacity')
        stash = Path(marker['run_directory']) / ('retained-before-restore-' + uuid.uuid4().hex)
        stash.mkdir(mode=0o700)
        for name, live in d.trees.items():
            d.u.move_children(d.cold, live, stash / name, marker['roots'][name][:2])
        for name, live in d.trees.items():
            d.u.copy_contents(d.cold, Path(marker['run_directory']) / 'baseline' / name, live, pair['trees'][name])
            d.tx.verify_tree(d, live, pair['trees'][name])
    # Baseline itself has no maintenance file. Keep writers fenced until the
    # restored pair has been checked and its release boundary is durable too.
    guard = d.tx.maintenance_path(d)
    if not os.path.lexists(guard):
        d.tx.add_maintenance(d)
    require(d.p.read_file(guard, 4096) == d.tx.MAINTENANCE, 'recovery_maintenance_changed')
    host.start()
    verify_versions(d, host.database(marker['prefix']), marker['baseline_versions'], False)
    source(d)
    references(d, host, marker['prefix'])
    record(d, marker, phase='baseline_release_committed', public_released=True)
    d.tx.remove_maintenance(d)
    require(host.login() == 200, 'restored_login')
    host.restore_policies(marker['restart_policies'])
    record(d, marker, phase='baseline_restored', live_trees_modified=False)


def upgrade(d):
    verify_payload(d.reference, d.payload)
    source(d)
    config_guard(d.p.read_file(d.p.CODE / 'config.php', 128 * 1024))
    if os.path.lexists(d.marker):
        old = validate_marker(d)
        require(old.get('phase') == 'baseline_restored', 'existing_transaction_use_restore')
        d.marker.rename(d.base / ('history-' + uuid.uuid4().hex + '.json'))
        d.cold.sync_directory(d.base)
    capacity(d)
    run = Path(tempfile.mkdtemp(prefix='update-', dir=d.base))
    with (run / 'commands-private.log').open('xb') as log:
        os.chmod(run / 'commands-private.log', 0o600)
        host = Host(d, d.identities, log)
        prefix = d.p.parse_prefix(d.p.read_file(d.p.CODE / 'config.php', 128 * 1024).decode())
        baseline = host.database(prefix)
        verify_versions(d, baseline, baseline, False)
        rows = host.inspect()
        require(all(row['State']['Running'] for row in rows.values()), 'production_not_running')
        policies = getattr(d, 'policies', {})
        marker = {'kind': 'connect_academy_addon_transaction', 'payload_commit': SOURCE,
            'run_directory': str(run), 'identities': d.identities, 'roots': d.tx.roots(d),
            'web_root': str(d.web), 'prefix': prefix, 'baseline_versions': baseline,
            'restart_policies': policies, 'live_trees_modified': False, 'switch_started': False,
            'public_released': False}
        record(d, marker, phase='prepared')
        try:
            notice('CONNECT_UPDATE_PHASE=FRESH_HDD_BACKUP')
            archive = fresh_hdd(d, marker, host)
            record(d, marker, phase='fresh_hdd_complete', fresh_hdd_backup=archive)
            with d.tx.exclusive_backup_lock(d.p):
                require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'backup_resume_marker')
                source(d)
                config_guard(d.p.read_file(d.p.CODE / 'config.php', 128 * 1024))
                record(d, marker, phase='stopping_production', switch_started=True)
                host.policy('no')
                host.stop_web(clean=True)
                current = host.database(prefix)
                verify_versions(d, current, baseline, False)
                require(current['other_clients'] == 0, 'other_database_clients')
                host.stop(clean=True)
                capacity(d, True)
                require(d.tx.roots(d) == marker['roots'], 'snapshot_roots')
                record(d, marker, phase='copying_paired_baseline')
                digest = d.tx.paired_copy(d, marker)
                record(d, marker, phase='paired_baseline_complete', baseline_manifest_sha256=digest)
                notice('CONNECT_FRESH_PAIRED_BASELINE=PASS')
                before = d.tx.inventory(d, d.p.CODE)
                activate(d, marker)
                expected = d.tx.inventory(d, d.p.CODE)
                # Everything outside the two addons, including config, must match.
                relative = [str((d.web / s).relative_to(d.p.CODE)) for s in SCOPES]
                outside = lambda key: not any(key == s or key.startswith(s + '/') for s in relative)
                require({k: v for k, v in before.items() if outside(k)}
                        == {k: v for k, v in expected.items() if outside(k)}, 'unrelated_code_changed')
                d.tx.add_maintenance(d)
                host.start()
                schema = None
                for number in (1, 2):
                    record(d, marker, phase='upgrade_' + str(number))
                    host.cli_upgrade()
                    current = host.database(prefix)
                    verify_versions(d, current, baseline, True)
                    current_schema = (current['schema'], current['indexes'])
                    if schema is not None:
                        require(current_schema == schema, 'repeat_schema_drift')
                    schema = current_schema
                    require(d.p.read_file(d.tx.maintenance_path(d), 4096) == d.tx.MAINTENANCE, 'maintenance_changed')
                d.tx.verify_tree(d, d.p.CODE, expected)
                count = references(d, host, prefix)
                require(d.tx.roots(d) == marker['roots'], 'release_roots')
                record(d, marker, phase='public_release_committed', public_released=True)
                d.tx.remove_maintenance(d)
                require(host.login() == 200, 'updated_login')
                host.restore_policies(policies)
                record(d, marker, phase='upgrade_complete', file_references_verified=count)
                notice('CONNECT_ACADEMY_UPDATE=PASS; UPGRADE_REPEAT=PASS; BOT_ACTIVATION=PENDING')
                return 0
        except BaseException as error:
            # Never print command output, exception messages or configuration.
            log.write(traceback.format_exc().encode())
            log.flush()
            for number in SIGNALS:
                signal.signal(number, signal.SIG_IGN)
            marker['failure_phase'] = marker.get('phase')
            marker['failure_type'] = type(error).__name__
            notice('CONNECT_UPDATE=STOPPED; PRIVATE_TRANSACTION=' + str(run))
            try:
                if marker.get('public_released'):
                    host.restore_policies(policies)
                    record(d, marker, phase='post_release_verification_required')
                    notice('CONNECT_POST_RELEASE_CHECK=REQUIRED; NO_AUTOMATIC_OLD_DATA_RESTORE')
                elif marker.get('switch_started'):
                    with d.tx.exclusive_backup_lock(d.p):
                        recover(d, marker, host)
                    notice('CONNECT_PAIRED_RECOVERY=PASS')
                else:
                    # Producer owns its resume marker; use its pinned recovery only.
                    if os.path.lexists(d.p.STATE / 'moodle-was-running.json'):
                        path, _ = d.w.HELPERS['hdd_backup']
                        host.action(['python3', '-I', '-B', '-u', str(path), '--recover'], timeout=120)
                    require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'hdd_recovery_pending')
                    require(all(row['State']['Running'] for row in host.inspect().values()), 'startup_incomplete')
                    verify_versions(d, host.database(prefix), baseline, False)
                    record(d, marker, phase='baseline_restored')
            except BaseException:
                if marker.get('switch_started') and not marker.get('public_released'):
                    try:
                        host.policy('no')
                        host.stop()
                    except BaseException:
                        notice('CONNECT_STOP_CONFIRMATION=INCOMPLETE')
                try:
                    record(d, marker, phase='recovery_required')
                except BaseException:
                    pass
                notice('CONNECT_RECOVERY=REQUIRED; USE_SAME_INSTALLER_RESTORE')
            return 1


def restore(d, discard=False):
    marker = validate_marker(d)
    require(not marker.get('public_released') or discard, 'late_restore_requires_discard_consent')
    require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'hdd_resume_marker')
    with (Path(marker['run_directory']) / 'commands-private.log').open('ab') as log:
        host = Host(d, marker['identities'], log)
        with d.tx.exclusive_backup_lock(d.p):
            try:
                recover(d, marker, host)
            except BaseException:
                try:
                    host.policy('no')
                    host.stop()
                finally:
                    record(d, marker, phase='recovery_required')
                raise
    notice('CONNECT_PAIRED_RECOVERY=PASS')
    return 0


@contextmanager
def transaction_lock(d):
    d.p.no_links(d.base)
    d.base.mkdir(mode=0o700, exist_ok=True)
    info = d.base.lstat()
    require(info.st_uid == ROOT_UID and stat.S_ISDIR(info.st_mode) and not info.st_mode & 0o077,
            'transaction_base')
    require(info.st_dev == d.p.CODE.stat().st_dev, 'transaction_filesystem')
    path = d.base / 'lock'
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        d.p.root_input(path)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--check', action='store_true')
    modes.add_argument('--upgrade', action='store_true')
    modes.add_argument('--restore', action='store_true')
    modes.add_argument('--status', action='store_true')
    parser.add_argument('--acknowledge-outage', action='store_true')
    parser.add_argument('--discard-writes-since-snapshot', action='store_true')
    args = parser.parse_args(argv)
    if not any((args.check, args.upgrade, args.restore, args.status)):
        notice('PLAN_ONLY; fixed RC830d3703; two addons; fresh HDD + code/config/data/physical-PG pair; no host access')
        return 0
    if (args.upgrade or args.restore) and not args.acknowledge_outage:
        parser.error('Choose a maintenance window and supply --acknowledge-outage')
    try:
        require(os.geteuid() == ROOT_UID and sys.version_info >= (3, 12), 'root_python312_required')
        d = dependencies(Path(sys.argv[0]))
        d.p.root_input(Path(sys.argv[0]).absolute())
        if args.check:
            with d.w.existing_shared_lock(d.p):
                report = checks(d)
            notice(json.dumps(report, indent=2))
            return 0
        if args.status:
            marker = validate_marker(d)
            notice(json.dumps({k: marker[k] for k in ('phase', 'public_released', 'live_trees_modified', 'run_directory')}))
            return 0
        os.umask(0o077)
        def interrupted(_signum, _frame):
            raise InterruptedError('operator_interrupted')
        for number in SIGNALS:
            signal.signal(number, interrupted)
        with transaction_lock(d):
            if args.upgrade:
                with d.w.existing_shared_lock(d.p):
                    report = checks(d)
                d.policies = report['restart_policies']
                return upgrade(d)
            return restore(d, args.discard_writes_since_snapshot)
    except Exception as error:
        reason = str(error) if isinstance(error, ValueError) and re.fullmatch('[a-z_]{1,80}', str(error)) else 'guard_refused'
        notice(json.dumps({'status': 'refused', 'reason': reason,
                           'private_transaction_if_present': str(BASE / 'active.json')}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
