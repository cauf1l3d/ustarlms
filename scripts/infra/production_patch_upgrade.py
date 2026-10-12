#!/usr/bin/env python3
"""Upgrade the accepted production candidate with fresh HDD and paired recovery.

Default is plan only. --upgrade-production --acknowledge-outage creates a fresh
encrypted HDD backup, blocks managed cron/backup with their existing EX lock,
stops the same web/PG containers, verifies a fresh code/config/data/physical-PG
pair, copies the candidate into the existing code root and runs official CLI
upgrade twice behind a CLI-maintenance file. Failure before public release
restores all three live trees together. No images, Composer, roles, retentions,
shared-host services, schedules, lab lifecycle or source-candidate modifications.
--restore-baseline is explicit; after release it additionally requires consent
to discard writes made since the snapshot. Interrupted pairs/journals retained.
--diagnose-transaction reads only restored transaction metadata, fixed container
control receipts and current read-only DB comparisons; no shutdown or backup.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
from decimal import Decimal
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import tempfile
import time
from types import ModuleType, SimpleNamespace
import uuid

PREFLIGHT = Path('/usr/local/sbin/USTAR_PRODUCTION_SWITCH_PREFLIGHT_20261012.py')
PREFLIGHT_SHA = 'fd65eda0c18c758b6ed1dda33a6ec5fdbc3f9b23f3bee829d9854f06fe15e649'
UPGRADE_COPY = Path('/usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py')
UPGRADE_COPY_SHA = '452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4'
BASE = Path('/var/lib/ustar-production-patch-20261012')
MARKER = BASE / 'switch-active.json'
SCRIPT = Path('/usr/local/sbin/USTAR_PRODUCTION_PATCH_UPGRADE_20261012.py')
TARGET = '2025100608.00'
OLD_CORE = '2025100601.03'
SIGNALS = (signal.SIGINT, signal.SIGTERM, signal.SIGHUP)
MAINTENANCE = b'<!doctype html><meta charset="utf-8"><title>USTAR</title><p>USTAR: technical maintenance. Please return shortly.</p>\n'
MAX_FILE = 1024 ** 3
ROOT_UID, ROOT_GID = 0, 0
FAILURE_CODES = frozenset(('GUARD_REFUSED', 'COMMAND_FAILED', 'STOP_NOT_CONFIRMED',
    'WEB_STOP_UNCLEAN', 'POSTGRES_STOP_UNCLEAN', 'POSTGRES_PID_REMAINS',
    'CORE_OR_ADDON_CHANGED', 'TASK_FLAGS_CHANGED', 'OTHER_DATABASE_CLIENTS',
    'HOST_OS_ERROR', 'HOST_COMMAND_TIMEOUT', 'UNCLASSIFIED_EXCEPTION', 'OPERATOR_INTERRUPTED'))
PHASES = frozenset(('prepared', 'fresh_hdd_backup', 'fresh_hdd_backup_complete',
    'stopping_production', 'setting_restart_guard', 'stopping_web', 'checking_stopped_web_database',
    'checking_database_quiescence', 'stopping_postgres', 'copying_paired_baseline',
    'paired_baseline_complete', 'activating_candidate', 'official_upgrade', 'repeat_upgrade',
    'verified_before_release', 'public_release_committed', 'upgrade_complete',
    'baseline_restored', 'hdd_recovery_required', 'post_release_verification_required', 'recovery_required'))


class ProductionUpgradeError(Exception):
    def __init__(self, message, code='GUARD_REFUSED', details=None):
        super().__init__(message)
        self.code, self.details = code, details or {}


def require(ok, message, code='GUARD_REFUSED', details=None):
    if not ok:
        raise ProductionUpgradeError(message, code, details)


def public_failure_details(value):
    # Never serialize exception messages/argv/stderr, query text, or arbitrary
    # journal fields. Only fixed container labels and bounded scalar evidence.
    if not isinstance(value, dict):
        return {}
    result = {}
    for key in ('exit_code', 'return_code', 'other_clients', 'changed_task_count', 'errno'):
        number = value.get(key)
        if type(number) is int and -255 <= number <= 100000:
            result[key] = number
    for key in ('running', 'oom_killed'):
        if type(value.get(key)) is bool:
            result[key] = value[key]
    if value.get('container') in ('ustar_moodle', 'ustar_postgres'):
        result['container'] = value['container']
    return result


def failure_evidence(error, phase):
    code = 'UNCLASSIFIED_EXCEPTION'
    details = {}
    if isinstance(error, ProductionUpgradeError):
        code = error.code if isinstance(error.code, str) and error.code in FAILURE_CODES else 'GUARD_REFUSED'
        details = public_failure_details(error.details)
    elif isinstance(error, subprocess.TimeoutExpired):
        code = 'HOST_COMMAND_TIMEOUT'
    elif isinstance(error, OSError):
        code, details = 'HOST_OS_ERROR', public_failure_details({'errno': error.errno})
    return {'failure_phase': phase if isinstance(phase, str) and phase in PHASES else 'UNRECORDED',
            'failure_code': code, 'failure_details': details}


def now():
    return datetime.now(timezone.utc).isoformat()


def notice(message):
    # Loss of the SSH output stream must not interrupt snapshot/restore cleanup.
    try:
        print(message, flush=True)
    except OSError:
        pass


def load_preflight():
    for parent in PREFLIGHT.parents:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                'Untrusted switch dependency parent')
    before = PREFLIGHT.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
            and not before.st_mode & 0o022 and before.st_size <= 1024 ** 2,
            'Untrusted switch preflight dependency')
    with os.fdopen(os.open(PREFLIGHT, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        require(os.fstat(stream.fileno()).st_ino == before.st_ino, 'Preflight changed before read')
        raw = stream.read(1024 ** 2 + 1)
        after = os.fstat(stream.fileno())
    require((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
            == (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
            and after == PREFLIGHT.lstat() and hashlib.sha256(raw).hexdigest() == PREFLIGHT_SHA,
            'Switch preflight dependency checksum/identity differs')
    module = ModuleType('production_upgrade_pinned_switch')
    exec(compile(raw, str(PREFLIGHT), 'exec'), module.__dict__)
    return module


def dependencies():
    w = load_preflight()
    s = w.load_builder()
    p, c = s.dependencies()
    cold = s.load_module(c.COLD, c.COLD_SHA, 'production_pure_copy_primitives')
    u = s.load_module(UPGRADE_COPY, UPGRADE_COPY_SHA, 'production_pure_transaction_copy')
    require(s.BASE == BASE and MARKER.parent == BASE, 'Production transaction namespace differs')
    # Reuse ONLY pure write_json/copy_contents/move_children and the tested cold
    # copier. No stage readiness/upgrade/recovery/engine function is called.
    cold.reserve = lambda: reserve(p)
    c.reserve = lambda: reserve(p)
    return SimpleNamespace(w=w, s=s, p=p, c=c, cold=cold, u=u,
                           trees={'code': p.CODE, 'moodledata': p.DATA, 'postgres': p.PGDATA})


def reserve(p):
    fs = os.statvfs(p.CODE)
    require(fs.f_bavail * fs.f_frsize >= 4 * 1024 ** 3 and fs.f_favail >= 100,
            'Production recovery reserve reached')


def root_id(path):
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode), 'Production bind root changed type')
    return [info.st_dev, info.st_ino, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)]


def roots(d):
    return {name: root_id(path) for name, path in d.trees.items()}


def inventory(d, root):
    d.p.no_links(root)
    device, entries, started = root.stat().st_dev, {}, time.monotonic()
    def item(path):
        info = path.lstat()
        directory = stat.S_ISDIR(info.st_mode)
        require(info.st_dev == device and (directory or stat.S_ISREG(info.st_mode))
                and (directory or info.st_nlink == 1), 'Recovery tree link/type/device differs')
        key = path.relative_to(root).as_posix()
        key = '' if key == '.' else key
        digest = d.p.read_file(path, MAX_FILE, True) if not directory else None
        require(d.p.signature(info) == d.p.signature(path.lstat()), 'Offline recovery input changed')
        entries[key] = d.cold.metadata(info, directory=directory, digest=digest)
        require(len(entries) <= 100000 and time.monotonic() - started < 900,
                'Recovery inventory exceeded bound')
    item(root)
    def failed(_):
        raise ProductionUpgradeError('Recovery inventory incomplete')
    for base, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs + files:
            item(Path(base) / name)
    return entries


def verify_tree(d, root, expected):
    require(inventory(d, root) == expected, 'Paired recovery tree metadata/hash readback differs')


@contextmanager
def exclusive_backup_lock(p):
    p.root_input(p.LOCK)
    before = p.LOCK.lstat()
    fd = os.open(p.LOCK, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        require(p.signature(os.fstat(fd)) == p.signature(before), 'Backup lock identity differs')
        deadline = time.monotonic() + 15
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                require(time.monotonic() < deadline, 'Managed cron/backup remains busy; no stop attempted')
                time.sleep(0.25)
        require(p.signature(p.LOCK.lstat()) == p.signature(before), 'Backup lock was replaced')
        yield
        require(p.signature(p.LOCK.lstat()) == p.signature(before), 'Backup lock changed during transaction')
    finally:
        os.close(fd)


def record(d, marker, **fields):
    marker.update(fields, updated_at=now())
    d.u.write_json(d.cold, MARKER, marker)
    d.u.write_json(d.cold, Path(marker['run_directory']) / 'transaction.json', marker)


def recovery_record(d, marker, **fields):
    marker.update(fields, updated_at=now())
    try:
        record(d, marker)
    except Exception:
        notice('PRODUCTION_JOURNAL_WRITE=FAILED; recovery remains necessary')


class Host:
    def __init__(self, d, identities, log):
        self.d, self.identities, self.log = d, identities, log

    def inspect(self):
        rows = json.loads(self.d.p.command(['docker', 'inspect', *self.identities]))
        require(isinstance(rows, list) and len(rows) == 2, 'Production containers missing')
        result = {}
        for row in rows:
            name = row['Name'].removeprefix('/')
            expected = self.identities.get(name, {})
            require(row.get('Id') == expected.get('Id') and row.get('Image') == expected.get('Image'),
                    'Production container ownership changed')
            binds = {m['Source']: m['Destination'] for m in row['Mounts'] if m['Type'] == 'bind'}
            paths = {str(self.d.p.CODE): '/var/www/html', str(self.d.p.DATA): '/var/www/moodledata'} \
                if name == 'ustar_moodle' else {str(self.d.p.PGDATA): '/var/lib/postgresql/data'}
            require(binds == paths, 'Production bind configuration changed')
            result[name] = row
        require(set(result) == set(self.identities), 'Production container set changed')
        return result

    def action(self, argv, timeout=90):
        result = subprocess.run(argv, stdout=self.log, stderr=self.log, timeout=timeout, check=False)
        self.log.flush()
        require(result.returncode == 0, 'Production command failed; private log retained',
                'COMMAND_FAILED', {'return_code': result.returncode})

    def policy(self, name):
        for container in ('ustar_moodle', 'ustar_postgres'):
            self.action(['docker', 'update', '--restart=' + name, self.identities[container]['Id']])

    def restore_policies(self, policies):
        for name, policy in policies.items():
            value = policy['name']
            if value == 'on-failure' and policy['maximum_retry_count']:
                value += ':' + str(policy['maximum_retry_count'])
            self.action(['docker', 'update', '--restart=' + value, self.identities[name]['Id']])

    def stop(self, clean=False):
        previous = signal.pthread_sigmask(signal.SIG_BLOCK, SIGNALS)
        try:
            self._stop(clean)
        finally:
            signal.pthread_sigmask(signal.SIG_SETMASK, previous)

    def stop_web(self, clean=False):
        previous = signal.pthread_sigmask(signal.SIG_BLOCK, SIGNALS)
        try:
            self._stop_one('ustar_moodle', clean)
        finally:
            signal.pthread_sigmask(signal.SIG_SETMASK, previous)

    def _stop_one(self, name, clean):
        row = self.inspect()[name]
        if row['State']['Running']:
            self.action(['docker', 'stop', '--time', '60', row['Id']])
        state = self.inspect()[name]['State']
        details = {'container': name, 'running': state['Running'],
                   'oom_killed': state.get('OOMKilled', False), 'exit_code': state.get('ExitCode')}
        require(not state['Running'], 'Production stop not confirmed; no recovery writes',
                'STOP_NOT_CONFIRMED', details)
        if clean:
            require(not state.get('OOMKilled') and state.get('ExitCode') == 0,
                    'Production did not stop cleanly; no physical baseline',
                    'WEB_STOP_UNCLEAN' if name == 'ustar_moodle' else 'POSTGRES_STOP_UNCLEAN', details)

    def _stop(self, clean):
        for name in ('ustar_moodle', 'ustar_postgres'):
            self._stop_one(name, clean)
        if clean:
            require(not (self.d.p.PGDATA / 'postmaster.pid').exists(),
                    'PostgreSQL PID remains after stop', 'POSTGRES_PID_REMAINS')

    def start(self):
        for name in ('ustar_postgres', 'ustar_moodle'):
            row = self.inspect()[name]
            if not row['State']['Running']:
                self.action(['docker', 'start', row['Id']])
            if name == 'ustar_postgres':
                deadline = time.monotonic() + 60
                while True:
                    argv = ['docker', 'exec', row['Id'], 'sh', '-eu', '-c',
                            'exec pg_isready -q -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"']
                    try:
                        self.d.p.command(argv, timeout=5)
                        break
                    except Exception:
                        require(time.monotonic() < deadline, 'PostgreSQL did not become ready')
                        time.sleep(1)
        require(all(row['State']['Running'] for row in self.inspect().values()), 'Production startup incomplete')

    def sql(self, query):
        require(query.startswith('BEGIN READ ONLY;'), 'Non-read-only custom SQL refused')
        shell = 'exec psql -X -q -A -t -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
        return json.loads(self.d.p.command(['docker', 'exec', '-i', self.identities['ustar_postgres']['Id'],
                                           'sh', '-eu', '-c', shell], query, 35))

    def database(self, prefix, addons):
        require(re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}', prefix)
                and len(addons) == 57 and all(re.fullmatch('[a-z][a-z0-9_]{0,63}', name) for name in addons),
                'Database query identifiers refused')
        names = ','.join("'" + name + "'" for name in sorted(addons))
        query = ("BEGIN READ ONLY; SET LOCAL statement_timeout='25s'; SET LOCAL lock_timeout='3s'; "
                 "SELECT json_build_object('core',(SELECT value FROM " + prefix + "config WHERE name='version'),"
                 "'addons',(SELECT json_object_agg(plugin,value) FROM " + prefix +
                 "config_plugins WHERE name='version' AND plugin IN (" + names + ")),"
                 "'tasks',(SELECT json_object_agg(classname,json_build_array(disabled,customised)) FROM " + prefix + "task_scheduled),"
                 "'other_clients',(SELECT count(*) FROM pg_stat_activity WHERE pid<>pg_backend_pid() "
                 "AND backend_type='client backend')); COMMIT;")
        return self.sql(query)

    def cli_upgrade(self):
        argv = ['docker', 'exec', '--user', '33:33', self.identities['ustar_moodle']['Id'], 'php']
        for setting in ('memory_limit=512M', 'sendmail_path=/bin/false', 'auto_prepend_file=',
                        'auto_append_file=', 'opcache.enable_cli=0', 'display_errors=0', 'log_errors=1'):
            argv += ['-d', setting]
        self.action(argv + ['/var/www/html/admin/cli/upgrade.php', '--non-interactive'], timeout=1800)

    def login(self):
        for attempt in range(15):
            try:
                status = self.d.p.command(['curl', '--silent', '--show-error', '--noproxy', '*',
                    '--connect-timeout', '2', '--max-time', '4', '--output', '/dev/null',
                    '--write-out', '%{http_code}', '--header', 'Host: ustar.local',
                    'http://127.0.0.1/login/index.php'], timeout=6).strip()
                if status == '200':
                    return 200
            except Exception:
                pass
            if attempt < 14:
                time.sleep(1)
        raise ProductionUpgradeError('Production login did not return HTTP200')


def verify_versions(after, baseline, target):
    require(Decimal(after['core']) == Decimal(target) and after['addons'] == baseline['addons'],
            'Production core or preserved addon DB versions differ', 'CORE_OR_ADDON_CHANGED')
    prefixes = tuple('\\' + name + '\\' for name in baseline['addons'])
    changed = sum(after['tasks'].get(name) != value for name, value in baseline['tasks'].items()
                  if Decimal(target) == Decimal(OLD_CORE) or name.startswith(prefixes) or value[1] == 1)
    require(changed == 0,
            'A customised or preserved addon task changed; restored original tasks must match entirely',
            'TASK_FLAGS_CHANGED', {'changed_task_count': changed})


def references(d, host, prefix):
    rows = host.sql("BEGIN READ ONLY; SET LOCAL statement_timeout='25s'; SET LOCAL lock_timeout='3s'; "
                    "SELECT COALESCE(json_agg(json_build_array(contenthash,filesize)),'[]'::json) FROM "
                    "(SELECT DISTINCT contenthash,filesize FROM " + prefix +
                    "files WHERE filesize>0 AND filename<>'.') f; COMMIT;")
    require(isinstance(rows, list) and len(rows) <= 50000, 'File reference bound exceeded')
    for digest, size in rows:
        require(isinstance(digest, str) and re.fullmatch('[0-9a-f]{40}', digest)
                and type(size) is int and 0 < size <= MAX_FILE, 'File reference shape differs')
        path = d.p.DATA / 'filedir' / digest[:2] / digest[2:4] / digest
        d.p.no_links(path)
        info = path.lstat()
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size == size,
                'Referenced file is missing/linked or changed size')
        with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
            require(d.p.signature(os.fstat(stream.fileno())) == d.p.signature(info), 'File reference replaced')
            actual = hashlib.sha1()
            for chunk in iter(lambda: stream.read(1024 ** 2), b''):
                actual.update(chunk)
            require(actual.hexdigest() == digest and d.p.signature(os.fstat(stream.fileno())) == d.p.signature(info)
                    and d.p.signature(path.lstat()) == d.p.signature(info), 'Referenced file content changed')
    return len(rows)


def fresh_hdd(d, marker, host):
    path, digest = d.w.HELPERS['hdd_backup']
    d.p.root_input(path)
    require(d.p.read_file(path, 1024 ** 2, True) == digest, 'Installed HDD producer changed')
    started = datetime.now(timezone.utc)
    record(d, marker, phase='fresh_hdd_backup')
    notice('PRODUCTION_PATCH_PHASE=FRESH_HDD_BACKUP; SHORT_WEB_PAUSE')
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, SIGNALS)
    try:
        host.action(['python3', '-I', '-B', '-u', str(path), '--backup', '--acknowledge-outage'], timeout=900)
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)
    success = d.p.read_json(d.p.STATE / 'last-hdd-success.json')
    exported = d.p.read_json(d.p.STATE / 'last-hdd-export.json')
    require(success.get('success') is True and success.get('phase') == 'complete'
            and datetime.fromisoformat(success['started_utc']) >= started
            and exported.get('readback_verified') is True and success['manifest']['sha256'] == exported['sha256']
            and not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'Fresh HDD capture/readiness not complete')
    return {key: exported[key] for key in ('file', 'sha256', 'bytes')}


def paired_copy(d, marker):
    run = Path(marker['run_directory'])
    pair = run / 'baseline'
    pair.mkdir(mode=0o700)
    trees = {}
    for name, live in d.trees.items():
        expected = inventory(d, live)
        (pair / name).mkdir(mode=0o700)
        d.u.copy_contents(d.cold, live, pair / name, expected)
        verify_tree(d, pair / name, expected)
        require(root_id(live) == marker['roots'][name], 'Live bind root changed during snapshot')
        trees[name] = expected
    manifest = {'kind': 'fresh_production_code_config_data_physical_pg_pair',
                'complete': True, 'created_at': now(), 'trees': trees}
    digest = d.u.write_json(d.cold, run / 'baseline-manifest.json', manifest)
    d.cold.sync_directory(pair)
    return digest


def read_pair(d, marker):
    run = Path(marker['run_directory'])
    manifest = d.w.protected_json(d.p, run / 'baseline-manifest.json', marker['baseline_manifest_sha256'])
    require(manifest.get('complete') is True
            and manifest.get('kind') == 'fresh_production_code_config_data_physical_pg_pair'
            and set(manifest.get('trees', {})) == set(d.trees), 'Incomplete production paired baseline')
    for name in d.trees:
        verify_tree(d, run / 'baseline' / name, manifest['trees'][name])
    return manifest


def maintenance_path(d):
    return d.p.DATA / 'climaintenance.html'


def add_maintenance(d):
    path = maintenance_path(d)
    require(not os.path.lexists(path), 'Existing CLI maintenance file needs separate handling')
    with io.BytesIO(MAINTENANCE) as stream:
        d.c.write_file(path, stream, len(MAINTENANCE), 33, 33, 0o644, hashlib.sha256(MAINTENANCE).hexdigest())
    d.cold.sync_directory(d.p.DATA)


def remove_maintenance(d):
    path = maintenance_path(d)
    require(d.p.read_file(path, 4096) == MAINTENANCE, 'Owned maintenance guard changed; site not released')
    path.unlink()
    d.cold.sync_directory(d.p.DATA)


def activate(d, marker, accepted):
    run = Path(marker['run_directory'])
    record(d, marker, phase='activating_candidate', live_trees_modified=True)
    d.u.move_children(d.cold, d.p.CODE, run / 'retained-original-code', marker['roots']['code'][:2])
    expected = dict(accepted['entries'])
    expected[''] = d.cold.metadata(d.p.CODE.lstat(), directory=True)  # Keep LIVE root owner/mode/inode.
    d.u.copy_contents(d.cold, d.w.CANDIDATE / 'code', d.p.CODE, expected)
    verify_tree(d, d.p.CODE, expected)
    require(roots(d) == marker['roots'], 'Live bind roots changed during activation')
    add_maintenance(d)


def restore_pair(d, marker, host):
    restored_pair = bool(marker.get('live_trees_modified'))
    host.policy('no')
    host.stop()
    require(roots(d) == marker['roots'], 'Live bind roots changed; restore refused')
    if marker.get('live_trees_modified'):
        pair = read_pair(d, marker)
        needed = 4 * 1024 ** 3 + 2 * sum(d.w.allocation(tree) for tree in pair['trees'].values())
        fs = os.statvfs(d.p.CODE)
        require(fs.f_bavail * fs.f_frsize >= needed
                and fs.f_favail >= sum(map(len, pair['trees'].values())) + 1000,
                'Insufficient fresh restore capacity; containers stay stopped')
        stash = Path(marker['run_directory']) / ('retained-before-restore-' + uuid.uuid4().hex)
        stash.mkdir(mode=0o700)
        for name, live in d.trees.items():
            d.u.move_children(d.cold, live, stash / name, marker['roots'][name][:2])
        for name, live in d.trees.items():
            d.u.copy_contents(d.cold, Path(marker['run_directory']) / 'baseline' / name, live, pair['trees'][name])
            verify_tree(d, live, pair['trees'][name])
    require(roots(d) == marker['roots'], 'Restored root metadata differs')
    host.start()
    baseline = marker['baseline_versions']
    verify_versions(host.database(marker['prefix'], baseline['addons']), baseline, OLD_CORE)
    count = references(d, host, marker['prefix'])
    require(host.login() == 200, 'Restored login readiness differs')
    host.restore_policies(marker['restart_policies'])
    record(d, marker, phase='baseline_restored', live_trees_modified=False,
           paired_restored=restored_pair, restored_file_references=count)
    return {'core_db_version': OLD_CORE, 'paired_trees_restored': restored_pair,
            'login_http': 200, 'file_references_verified': count}


def validate_marker(d):
    marker = d.p.read_json(MARKER)
    run = Path(marker.get('run_directory', ''))
    require(run.parent == BASE and re.fullmatch('switch-518-[a-z0-9_]+', run.name)
            and marker.get('kind') == 'production_patch_518_transaction'
            and marker.get('candidate_manifest_sha256') == d.w.CANDIDATE_SHA
            and marker.get('source_manifest_sha256') == d.w.SOURCE_SHA
            and isinstance(marker.get('prefix'), str)
            and re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}', marker['prefix'])
            and set(marker.get('identities', {})) == set(d.p.IMAGES)
            and all(row.get('Image') == d.p.IMAGES[name]
                    and re.fullmatch('[0-9a-f]{64}', row.get('Id', ''))
                    for name, row in marker['identities'].items()),
            'Foreign/unrecognized production transaction')
    d.p.no_links(run)
    info = run.lstat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == ROOT_UID and not info.st_mode & 0o077,
            'Production transaction run is not private/root-owned')
    require(roots(d) == marker.get('roots'), 'Transaction bind roots changed')
    return marker


def diagnose_transaction(d):
    # The old helper did not record the failing guard. Current DB observations
    # and control-ID receipts are evidence, not a reconstruction of an old exit.
    before = d.p.signature(MARKER.lstat())
    marker = validate_marker(d)
    require(marker.get('phase') == 'baseline_restored'
            and marker.get('live_trees_modified') is False
            and marker.get('public_released') is False,
            'Read-only diagnostic requires a restored unreleased baseline')
    host = Host(d, marker['identities'], io.BytesIO())
    rows = host.inspect()
    require(all(row['State']['Running'] for row in rows.values()),
            'Restored production is not running; use recovery workflow')
    baseline = marker['baseline_versions']
    current = host.database(marker['prefix'], baseline['addons'])
    require(type(current.get('other_clients')) is int and 0 <= current['other_clients'] <= 100000,
            'Database client count shape differs')
    run = Path(marker['run_directory'])
    logpath = run / 'commands-private.log'
    d.p.root_input(logpath)
    raw = d.p.read_file(logpath, 32 * 1024 ** 2)
    labels = {row['Id'].encode(): name for name, row in marker['identities'].items()}
    controls = [labels[line.rstrip(b'\r')] for line in raw.splitlines()
                if line.rstrip(b'\r') in labels]
    signals = {}
    for name, row in rows.items():
        value = row.get('Config', {}).get('StopSignal', '')
        signals[name] = value if value in ('SIGINT', 'SIGTERM', 'SIGWINCH', '2', '15', '28') \
            else 'DEFAULT_SIGTERM' if value in ('', None) else 'OTHER_SIGNAL'
    phase, code = marker.get('failure_phase'), marker.get('failure_code')
    report = {'kind': 'restored_production_transaction_readonly_diagnostic',
              'read_only': True, 'PHP_or_Moodle_bootstrap': False,
              'SQL_scope': 'bounded_BEGIN_READ_ONLY_current_sample',
              'backup_or_container_operations': False, 'journal_or_log_written': False,
              'transaction_phase': 'baseline_restored',
              'failure_phase': phase if isinstance(phase, str) and phase in PHASES else 'UNRECORDED',
              'failure_code': code if isinstance(code, str) and code in FAILURE_CODES else 'UNRECORDED',
              'failure_details': public_failure_details(marker.get('failure_details')),
              'pair_directories': {name: (run / 'baseline' / name).is_dir() for name in d.trees},
              'pair_manifest_exists': (run / 'baseline-manifest.json').is_file(),
              'current_core_matches_old_version': Decimal(current['core']) == Decimal(OLD_CORE),
              'current_addon_versions_match_baseline': current['addons'] == baseline['addons'],
              'current_task_flags_match_baseline': current['tasks'] == baseline['tasks'],
              'current_other_database_clients': current['other_clients'],
              'current_sample_is_not_past_shutdown_evidence': True,
              'container_stop_signals': signals,
              'container_control_receipt_count': len(controls),
              'container_control_receipt_sequence': controls[-32:],
              'control_receipts_are_ids_only_not_command_or_exit_details': True,
              'config_query_personnel_and_private_log_text_emitted': False,
              'measured_at': now()}
    require(d.p.signature(MARKER.lstat()) == before, 'Transaction changed during diagnostic')
    return report


def upgrade(d):
    if os.path.lexists(MARKER):
        old = validate_marker(d)
        require(old.get('phase') == 'baseline_restored', 'Existing production transaction; do not run a second upgrade')
        MARKER.rename(BASE / ('switch-history-' + uuid.uuid4().hex + '.json'))
        d.cold.sync_directory(BASE)
    with d.w.existing_shared_lock(d.p):
        plan = d.w.check(d.s, d.p, d.c)
    require(not os.path.lexists(maintenance_path(d)), 'Existing production CLI maintenance file')
    accepted = d.w.protected_json(d.p, d.w.CANDIDATE / 'candidate-manifest.json', d.w.CANDIDATE_SHA)
    source = d.w.protected_json(d.p, d.w.CANDIDATE / 'source-manifest.json', d.w.SOURCE_SHA)
    require(d.s.scan_code(d.p, d.w.CANDIDATE / 'code') == accepted['entries']
            and d.s.scan_code(d.p, d.p.CODE) == source['code_entries'], 'Accepted candidate/live source code drifted')
    prefix = d.p.parse_prefix(d.p.read_file(d.p.CODE / 'config.php', 128 * 1024).decode())
    identities = d.p.containers()
    run = Path(tempfile.mkdtemp(prefix='switch-518-', dir=BASE))
    logpath = run / 'commands-private.log'
    d.c.write_file(logpath, io.BytesIO(b''), 0, ROOT_UID, ROOT_GID, 0o600)
    with logpath.open('ab') as log:
        host = Host(d, identities, log)
        baseline = host.database(prefix, accepted['source_addon_versions'])
        require(Decimal(baseline['core']) == Decimal(OLD_CORE)
                and baseline['addons'] == accepted['source_addon_versions'], 'Source DB changed before transaction')
        marker = {'kind': 'production_patch_518_transaction', 'run_directory': str(run),
                  'candidate_manifest_sha256': d.w.CANDIDATE_SHA, 'source_manifest_sha256': d.w.SOURCE_SHA,
                  'identities': identities, 'roots': roots(d), 'prefix': prefix,
                  'baseline_versions': baseline, 'restart_policies': plan['docker_conditions']['restart_policies'],
                  'live_trees_modified': False, 'switch_started': False,
                  'public_released': False, 'started_at': now(), 'storage_plan': plan['storage_plan']}
        record(d, marker, phase='prepared')
        try:
            archive = fresh_hdd(d, marker, host)
            record(d, marker, phase='fresh_hdd_backup_complete', fresh_hdd_backup=archive)
            notice('FRESH_PRODUCTION_HDD_BACKUP=PASS; PREPARING_PAIRED_COLD_RECOVERY')
            with exclusive_backup_lock(d.p):
                require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'Backup resume marker remains')
                require(d.s.scan_code(d.p, d.p.CODE) == source['code_entries']
                        and roots(d) == marker['roots'], 'Production source/root drifted during HDD backup')
                record(d, marker, phase='setting_restart_guard', switch_started=True)
                host.policy('no')  # Durable daemon policy: power loss must not boot a partial pair.
                record(d, marker, phase='stopping_web')
                host.stop_web(clean=True)
                record(d, marker, phase='checking_stopped_web_database')
                current = host.database(prefix, baseline['addons'])
                verify_versions(current, baseline, OLD_CORE)
                record(d, marker, phase='checking_database_quiescence')
                require(current['other_clients'] == 0, 'Other database writer/client remains; no snapshot',
                        'OTHER_DATABASE_CLIENTS', {'other_clients': current['other_clients']})
                record(d, marker, phase='stopping_postgres')
                host.stop(clean=True)
                record(d, marker, phase='copying_paired_baseline')
                pair_sha = paired_copy(d, marker)
                record(d, marker, phase='paired_baseline_complete', baseline_manifest_sha256=pair_sha)
                notice('FRESH_PRODUCTION_PAIRED_BASELINE=PASS; ACTIVATING_ACCEPTED_CODE')
                activate(d, marker, accepted)
                host.start()
                for attempt in (1, 2):
                    record(d, marker, phase='official_upgrade' if attempt == 1 else 'repeat_upgrade')
                    notice('OFFICIAL_PRODUCTION_CLI_UPGRADE=' + str(attempt))
                    host.cli_upgrade()
                    verify_versions(host.database(prefix, baseline['addons']), baseline, TARGET)
                    require(d.p.read_file(maintenance_path(d), 4096) == MAINTENANCE, 'Maintenance guard changed')
                require(d.s.scan_code(d.p, d.p.CODE) == accepted['entries'], 'Activated candidate code changed')
                count = references(d, host, prefix)
                require(roots(d) == marker['roots'], 'Production root ownership/inodes changed')
                record(d, marker, phase='verified_before_release', verified_file_references=count)
                # This durable phase is the boundary: after it automatic old-data
                # restoration must not silently discard new public writes.
                record(d, marker, phase='public_release_committed', public_released=True)
                remove_maintenance(d)
                login = host.login()
                host.restore_policies(marker['restart_policies'])
                after = host.database(prefix, baseline['addons'])
                report = {'kind': 'production_patch_upgrade_and_repeat', 'result': 'PASS',
                          'core_db_version': TARGET, 'addon_versions_preserved': 57, 'upgrade_repeat': 'PASS',
                          'fresh_hdd_backup': archive, 'paired_baseline_manifest_sha256': pair_sha,
                          'run_directory': str(run), 'candidate_manifest_sha256': d.w.CANDIDATE_SHA,
                          'file_references_verified': count, 'login_http': login,
                          'scheduled_task_customizations_and_addon_flags_preserved': True,
                          'core_default_task_registry_changes': sum(after['tasks'].get(name) != value
                              for name, value in baseline['tasks'].items()),
                          'enabled_scheduled_tasks': sum(value[0] == 0 for value in after['tasks'].values()),
                          'container_ids_images_bind_root_inodes_and_root_metadata_preserved': True,
                          'restart_policies_restored': True, 'cli_builtin_sendmail_path': '/bin/false',
                          'production_paired_restore_executed': False, 'manual_role_learning_QA': 'PENDING',
                          'post_switch_cron_observation': 'PENDING', 'full_addon_runtime_compatibility_verified': False,
                          'finished_at': now()}
                d.u.write_json(d.cold, run / 'report.json', report)
                record(d, marker, phase='upgrade_complete')
                notice('PRODUCTION_PATCH_UPGRADE=PASS; CORE_518; REPEAT=PASS; POST_SWITCH_QA_PENDING')
                notice(json.dumps(report, indent=2, sort_keys=True))
                return 0
        except BaseException as error:
            for number in SIGNALS:
                signal.signal(number, signal.SIG_IGN)
            evidence = failure_evidence(error, marker.get('phase'))
            recovery_record(d, marker, failure_type=type(error).__name__, **evidence)
            notice('PRODUCTION_PATCH_STOPPED; ' + json.dumps(evidence, sort_keys=True))
            if not marker.get('switch_started'):
                try:
                    if os.path.lexists(d.p.STATE / 'moodle-was-running.json'):
                        path, _ = d.w.HELPERS['hdd_backup']
                        host.action(['python3', '-I', '-B', '-u', str(path), '--recover'], timeout=120)
                    require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'), 'HDD resume marker remains')
                    require(all(row['State']['Running'] for row in host.inspect().values()), 'Original startup incomplete')
                    verify_versions(host.database(prefix, baseline['addons']), baseline, OLD_CORE)
                    host.login()
                    record(d, marker, phase='baseline_restored', paired_restored=False)
                    notice('PRODUCTION_PRE_SWITCH_FAILURE; ORIGINAL_RUNNING; NO_CORE_OR_PG_STOP')
                except BaseException as recovery_error:
                    recovery_record(d, marker, phase='hdd_recovery_required', recovery_error_type=type(recovery_error).__name__)
                    notice('PRODUCTION_HDD_RECOVERY_REQUIRED; NO_CORE_SWITCH_OR_PG_STOP; USE_EXISTING_HDD_RECOVER')
                return 1
            if marker.get('public_released'):
                # The committed release may have accepted new writes. Keep the
                # pair intact; explicit rollback needs the extra acknowledgement.
                recovery_record(d, marker, phase='post_release_verification_required')
                try:
                    host.restore_policies(marker['restart_policies'])
                except Exception:
                    pass
                notice('PRODUCTION_POST_RELEASE_CHECK_REQUIRED; NO_AUTOMATIC_OLD_DATA_RESTORE; PRIVATE_LOG=' + str(logpath))
                return 1
            try:
                with exclusive_backup_lock(d.p):
                    require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'),
                            'HDD resume marker needs existing producer recovery before patch recovery')
                    result = restore_pair(d, marker, host)
                notice('PRODUCTION_BASELINE_RECOVERY=PASS; ' + json.dumps(result, sort_keys=True))
            except BaseException as recovery_error:
                recovery_record(d, marker, phase='recovery_required', recovery_error_type=type(recovery_error).__name__)
                try:
                    host.policy('no')
                    host.stop()
                except BaseException:
                    notice('PRODUCTION_STOP_CONFIRMATION=INCOMPLETE')
                notice('PRODUCTION_RECOVERY_REQUIRED; USE_SAME_HELPER_RESTORE_BASELINE; PRIVATE_LOG=' + str(logpath))
            return 1


def interrupted(_number, _frame):
    raise ProductionUpgradeError('Operator signal interrupted production transaction', 'OPERATOR_INTERRUPTED')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--upgrade-production', action='store_true')
    modes.add_argument('--restore-baseline', action='store_true')
    modes.add_argument('--diagnose-transaction', action='store_true')
    parser.add_argument('--acknowledge-outage', action='store_true')
    parser.add_argument('--discard-writes-since-snapshot', action='store_true')
    args = parser.parse_args()
    if not args.upgrade_production and not args.restore_baseline and not args.diagnose_transaction:
        print('PLAN_ONLY; production upgrade captures fresh recovery and changes the working site')
        return 0
    if not args.diagnose_transaction and not args.acknowledge_outage:
        parser.error('Production operation requires --acknowledge-outage in the chosen maintenance window')
    try:
        require(os.geteuid() == 0, 'Run production transaction as root')
        os.umask(0o077)
        sys.dont_write_bytecode = True
        d = dependencies()
        d.p.root_input(SCRIPT)
        if args.diagnose_transaction:
            with d.w.existing_shared_lock(d.p):
                report = diagnose_transaction(d)
            notice('PRODUCTION_TRANSACTION_DIAGNOSTIC=COMPLETE; READ_ONLY; NO_STOP_OR_BACKUP')
            notice(json.dumps(report, indent=2, sort_keys=True))
            return 0
        for number in SIGNALS:
            signal.signal(number, interrupted)
        with d.s.own_lock(d.p, d.c, False):
            if args.upgrade_production:
                return upgrade(d)
            marker = validate_marker(d)
            require(not os.path.lexists(d.p.STATE / 'moodle-was-running.json'),
                    'Existing HDD capture needs its reviewed --recover before patch restoration')
            require(not marker.get('public_released') or args.discard_writes_since_snapshot,
                    'Post-release restore discards newer writes; explicit --discard-writes-since-snapshot required')
            with (Path(marker['run_directory']) / 'commands-private.log').open('ab') as log:
                host = Host(d, marker['identities'], log)
                try:
                    with exclusive_backup_lock(d.p):
                        result = restore_pair(d, marker, host)
                    notice('PRODUCTION_BASELINE_RECOVERY=PASS; ' + json.dumps(result, sort_keys=True))
                    return 0
                except BaseException:
                    for number in SIGNALS:
                        signal.signal(number, signal.SIG_IGN)
                    host.policy('no')
                    host.stop()
                    raise
    except Exception as error:
        print('PRODUCTION_PATCH_ERROR=' + (str(error) if isinstance(error, ProductionUpgradeError)
              else type(error).__name__), file=sys.stderr, flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
