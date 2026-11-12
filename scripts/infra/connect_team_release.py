#!/usr/bin/env python3
"""Install the qualified Academy and Connect announcement/team release on server1."""

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import types
import zipfile


CORE_SOURCE = None
WORKER_INSTALLER = None
CORE_COMMIT = '86d5fbb8acc24157da4d47317bd22bc3d94e9498'
CORE_SHA256 = 'e1f903d315f1c33b46eecbfb5e26b7e2325e01d710255adaff74e8a848397e51'
WORKER_INSTALLER_SHA256 = 'e12038549f29355a8730692205c4b911fc4362a4b24ff813dadc9685d1cf1cb7'
BASES = ('a7a34b4927ffd42db28a8d76e410d20a37d7a434', 'fc6c8ca02d940cb346d8000783c4416ff7347784')
WORKER_BEFORE = '267e55874eb8f6c36259d748f28a2c636adc9810'
TARGET = '814a99d9b54196e34c5a2b86529f30e58027da2a'
BEFORE, AFTER, THEME = 2026111100, 2026111200, 2026110200
STATE = Path('/var/lib/ustar-connect-team-20261112')
DATA = Path('/opt/ustar/data/moodle/moodledata')
CRON_SCRIPT = Path('/usr/local/sbin/USTAR_CRON_SETUP_20261005.py')
CRON_INFO = Path('/var/lib/ustar-cron/installation.json')
CRON_SCHEDULE = Path('/etc/cron.d/ustar_moodle')
CRON_SCHEDULE_SHA256 = '21568ee9bd2afd09109ba47a3c15f571e9b1e61c4df3dd9f527c720755ae00b8'
CRON_ORIGINAL_SHA256 = 'ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751'
CRON_ORIGINAL_LINE = b"VERSIONS = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}"
CRON_PATTERN = rb"^VERSIONS = \{'local_ustar': '(20[0-9]{8})', 'theme_ustar': '(20[0-9]{8})'\}$"
SERVICES = ('ustar-connect-notify.service', 'ustar-connect-receiver.service')
RUNNABLE_STEPS = ('prepared', 'migration', 'app_ready')


class Refused(Exception):
    pass


def require(condition, reason):
    if not condition:
        raise Refused(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(data):
    return (json.dumps(data, sort_keys=True, indent=2) + '\n').encode()


def embedded(source, expected, name):
    require(source is not None and sha(source) == expected, 'embedded_source_mismatch')
    module = types.ModuleType(name)
    exec(compile(source, name, 'exec'), module.__dict__)
    return module


def path_name(name):
    return bool(re.fullmatch(r'[a-zA-Z0-9_./-]+', name)
                and not name.startswith('/') and PurePosixPath(name).as_posix() == name
                and name != '.' and '..' not in PurePosixPath(name).parts)


def digest_index(index):
    return isinstance(index, dict) and bool(index) and all(
        path_name(name) and isinstance(value, str) and re.fullmatch('[a-f0-9]{64}', value)
        for name, value in index.items())


def changes(before, after):
    require(set(before) <= set(after), 'source_removals_unsupported')
    return sorted(name for name in after if before.get(name) != after[name])


def load_package(folder, core, worker):
    checksums = {}
    for line in core.safe_path(folder, 'SHA256SUMS').read_text().splitlines():
        value, delimiter, name = line.partition('  ')
        require(delimiter and path_name(name) and name not in checksums and re.fullmatch('[a-f0-9]{64}', value),
                'invalid_checksum_list')
        path = core.safe_path(folder, name)
        require(path.is_file() and core.digest(path) == value, 'package_digest_mismatch')
        checksums[name] = value
    manifest = json.loads(core.safe_path(folder, 'INSTALL.json').read_bytes())
    require(manifest['schema'] == 1 and manifest['target'] == TARGET and manifest['worker_before'] == WORKER_BEFORE
            and set(manifest['before']) == set(BASES) and manifest['local_ustar'] == AFTER
            and manifest['theme_ustar'] == THEME, 'unexpected_manifest_identity')
    after = manifest['after']
    require(all(digest_index(index) for index in (after, manifest['theme'], *manifest['before'].values())),
            'invalid_source_index')
    delta = set().union(*(changes(before, after) for before in manifest['before'].values()))
    require(set(checksums) == {'update.py', 'INSTALL.json', 'worker.tar.gz'} | {'payload/' + name for name in delta},
            'package_inventory_mismatch')
    require(all(checksums['payload/' + name] == after[name] for name in delta), 'payload_source_mismatch')
    commit, values = worker.validate_archive(folder / 'worker.tar.gz', checksums['worker.tar.gz'])
    require(commit == TARGET and set(manifest['worker_identity']) == worker.FILES, 'unexpected_worker_identity')
    worker_identity(values)
    return manifest, values, checksums['INSTALL.json']


def worker_identity(values):
    """Compare the installed predecessor even if its ZIP compression library differed."""
    result = {}
    for name, value in values.items():
        if not name.endswith('.pyz'):
            result[name] = sha(value)
            continue
        with zipfile.ZipFile(io.BytesIO(value)) as archive:
            entries = archive.infolist()
            require(entries and len(entries) <= 128 and sum(entry.file_size for entry in entries) <= 16 * 1024**2
                    and len({entry.filename for entry in entries}) == len(entries)
                    and all(path_name(entry.filename) and entry.filename.endswith('.py') and not entry.is_dir()
                            for entry in entries), 'invalid_worker_zip')
            result[name] = {entry.filename: sha(archive.read(entry)) for entry in entries}
    return result


def cron_line(version, theme):
    return f"VERSIONS = {{'local_ustar': '{version}', 'theme_ustar': '{theme}'}}".encode()


def cron_update(script, info, *, expected=None):
    # Keep the published wrapper's checks, task runner and historical logs intact.
    lines = list(re.finditer(CRON_PATTERN, script, flags=re.MULTILINE))
    require(len(lines) == 1, 'cron_wrapper_version_line')
    match = lines[0]
    normalized = script[:match.start()] + CRON_ORIGINAL_LINE + script[match.end():]
    require(sha(normalized) == CRON_ORIGINAL_SHA256, 'cron_wrapper_source_drift')
    script_versions = [int(match[1]), int(match[2])]
    data = json.loads(info)
    versions = data['versions']
    info_versions = [int(versions['local_ustar']), int(versions['theme_ustar'])]
    require(data['schema'] == 1 and set(versions) == {'local_ustar', 'theme_ustar'}
            and data['script_sha256'] == sha(normalized.replace(CRON_ORIGINAL_LINE, cron_line(*info_versions))),
            'cron_installation_drift')
    target = [AFTER, THEME]
    binding = sha(canonical({key: value for key, value in data.items() if key not in ('versions', 'script_sha256')}))
    if expected is None:
        require(script_versions == info_versions, 'cron_contract_incomplete')
        record = {'before': script_versions, 'binding': binding}
    else:
        require(binding == expected['binding'] and script_versions in (expected['before'], target)
                and info_versions in (expected['before'], target), 'cron_contract_drift')
        record = expected
    desired = normalized.replace(CRON_ORIGINAL_LINE, cron_line(*target))
    compile(desired, '<qualified-ustar-cron>', 'exec')
    if info_versions == target:
        desired_info = info
    else:
        desired_info = canonical({**data, 'versions': {'local_ustar': str(AFTER), 'theme_ustar': str(THEME)},
                                  'script_sha256': sha(desired)})
    return record, desired, desired_info


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_atomic(path, raw, *, staging=None, metadata=None):
    existing = path if os.path.lexists(path) else None
    if existing:
        status = existing.lstat()
        require(stat.S_ISREG(status.st_mode) and status.st_nlink == 1, 'unsafe_write_target')
    if metadata is None:
        status = (existing or path.parent).stat()
        metadata = (status.st_uid, status.st_gid, stat.S_IMODE(status.st_mode) if existing else 0o644)
    fd, name = tempfile.mkstemp(prefix='.ustar-connect-team-', dir=staging or path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            os.fchown(stream.fileno(), metadata[0], metadata[1])
            os.fchmod(stream.fileno(), metadata[2])
            if existing:
                for key in os.listxattr(existing, follow_symlinks=False):
                    os.setxattr(stream.fileno(), key, os.getxattr(existing, key, follow_symlinks=False))
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
        sync_directory(path.parent)
    finally:
        if os.path.lexists(name):
            os.unlink(name)


class Host:
    def __init__(self, folder, manifest, values, core, worker):
        self.folder, self.manifest, self.values = folder, manifest, values
        self.core, self.worker = core, worker
        self.app = core.HOST_APP / 'local/ustar'
        self.installation = worker.Installation()
        self.target_worker = self.installation.prefix / 'releases' / TARGET
        self.container_id = None

    def run(self, argv, reason, *, timeout=30):
        try:
            result = self.core.run(argv, timeout=timeout, check=False)
        except (OSError, subprocess.TimeoutExpired):
            raise Refused(reason) from None
        require(result.returncode == 0, reason)
        return result.stdout

    def source(self, root):
        require(root.is_dir() and root.resolve() == root, 'unsafe_moodle_source')
        return self.core.scan(root)

    def inspect_source(self):
        require(self.source(self.core.HOST_APP / 'theme/ustar') == self.manifest['theme'], 'theme_source_drift')
        live = self.source(self.app)
        if live == self.manifest['after']:
            return TARGET
        return next((base for base in BASES if live == self.manifest['before'][base]), None)

    def worker_commit(self):
        self.worker.safe(self.installation.prefix)
        current = self.installation.current()
        require(current is not None and current.name in (WORKER_BEFORE, TARGET), 'unexpected_worker')
        return current.name

    def worker_files(self, commit):
        release = self.worker.safe(self.installation.prefix / 'releases' / commit)
        self.worker.safe(release / 'manifest.json')
        values = self.installation.release_files(release)
        require({path.relative_to(release).as_posix() for path in release.rglob('*')}
                == self.worker.FILES | {'units', 'manifest.json'}, 'worker_inventory_drift')
        require((values == self.values if commit == TARGET else
                 worker_identity(values) == self.manifest['worker_identity']), 'worker_source_drift')
        return values

    def verify_units(self, values):
        for unit, executable in zip(SERVICES, ('notifications.pyz', 'receiver.pyz')):
            path = self.worker.safe(self.installation.units / unit)
            require(path.read_bytes() == values['units/' + unit] == self.values['units/' + unit], 'worker_unit_drift')
            result = self.run(['systemctl', 'show', '-p', 'LoadState', '-p', 'FragmentPath',
                               '-p', 'ExecStart', '-p', 'NeedDaemonReload', unit], 'worker_unit_unavailable')
            properties = dict(line.split('=', 1) for line in result.decode().splitlines())
            require(properties.get('LoadState') == 'loaded' and properties.get('FragmentPath') == str(path)
                    and properties.get('NeedDaemonReload') == 'no'
                    and f'/opt/ustar-connect/current/{executable} ' in properties.get('ExecStart', ''),
                    'worker_effective_unit_drift')
        config = self.worker.safe(self.installation.config / 'notifications.json')
        require(json.loads(config.read_bytes()).get('notification_contract') == 3, 'notification_contract_v3_required')

    def verify(self, *, worker, active, interrupted=False):
        current = self.worker_commit()
        require(current == worker, 'unexpected_worker')
        self.verify_units(self.worker_files(current))
        result = self.run(['docker', 'inspect', '--format',
                           '{"id":{{json .Id}},"running":{{json .State.Running}},"mounts":{{json .Mounts}}}',
                           'ustar_moodle'], 'moodle_container_unavailable')
        info = json.loads(result)
        binds = {(mount['Source'], mount['Destination']) for mount in info['mounts']}
        require(info['running'] and (str(self.core.HOST_APP.parent), '/var/www/html') in binds
                and (str(DATA), '/var/www/moodledata') in binds, 'moodle_bind_drift')
        require(self.container_id is None or self.container_id == info['id'], 'moodle_container_replaced')
        self.container_id = info['id']
        self.core.cli_preflight()
        php = '''
define("CLI_SCRIPT", true);
require "/var/www/html/config.php";
require_once($CFG->libdir . "/upgradelib.php");
$tables = [];
foreach (["local_ustar_reward_shop_offer", "local_ustar_reward_shop_order", "local_ustar_conn_links"] as $name) {
    $tables[$name] = $DB->get_manager()->table_exists(new xmldb_table($name));
}
echo json_encode([
    "core" => (int)$CFG->version,
    "local_ustar" => (int)get_config("local_ustar", "version"),
    "theme_ustar" => (int)get_config("theme_ustar", "version"),
    "data" => $CFG->dataroot,
    "tables_ready" => !in_array(false, $tables, true),
    "maintenance" => !empty($CFG->maintenance_enabled) || is_file($CFG->dataroot . "/climaintenance.html"),
    "maintenance_scheduled" => !empty($CFG->maintenance_later),
    "needs_upgrade" => moodle_needs_upgrading(),
    "announcements_enabled" => ($CFG->ustar_connect_announcements_enabled ?? false) === true
], JSON_THROW_ON_ERROR);
'''
        status = json.loads(self.php(['-r', php], 'moodle_preflight_failed'))
        require(status['core'] == 2025100608 and status['theme_ustar'] == THEME and status['tables_ready']
                and status['data'] == '/var/www/moodledata', 'unexpected_moodle_profile')
        require(not status['maintenance_scheduled'], 'maintenance_scheduled')
        require(active or not status['announcements_enabled'], 'announcements_enabled')
        require(interrupted or not status['needs_upgrade'], 'moodle_upgrade_already_pending')
        return status

    def php(self, args, reason, *, timeout=30):
        return self.run(['docker', 'exec', '-u', 'www-data', 'ustar_moodle', 'php', *args], reason, timeout=timeout)

    def maintenance(self, enable):
        mode = '--enable' if enable else '--disable'
        self.php([self.core.CONTAINER_CLI + '/maintenance.php', mode], 'maintenance_switch_failed', timeout=60)
        require((DATA / 'climaintenance.html').is_file() == enable, 'maintenance_not_effective')

    def service_states(self):
        states = {}
        for unit in SERVICES:
            raw = self.run(['systemctl', 'show', '-p', 'ActiveState', '--value', unit], 'worker_state_unavailable').strip()
            require(raw in (b'active', b'inactive', b'failed'), 'worker_state_changing')
            states[unit] = raw == b'active'
        return states

    def set_services(self, states):
        current = self.service_states()
        for unit in SERVICES:
            if current[unit] and not states[unit]:
                self.run(['systemctl', 'stop', unit], 'worker_stop_failed', timeout=60)
        for unit in reversed(SERVICES):
            if states[unit] and not current[unit]:
                self.run(['systemctl', 'start', unit], 'worker_start_failed', timeout=60)
        require(self.service_states() == states, 'worker_state_not_effective')

    def stage_worker(self):
        parent = self.worker.safe(self.target_worker.parent)
        if not os.path.lexists(self.target_worker):
            staging = Path(tempfile.mkdtemp(prefix='.connect-team-release-', dir=parent))
            try:
                (staging / 'units').mkdir(mode=0o755)
                (staging / 'units').chmod(0o755)
                manifest = canonical({'schema': 1, 'source_commit': TARGET,
                                      'files': {name: sha(raw) for name, raw in self.values.items()}})
                for name, raw in {**self.values, 'manifest.json': manifest}.items():
                    self.worker.write_file(staging / name, raw)
                staging.chmod(0o755)
                sync_directory(staging)
                os.rename(staging, self.target_worker)
                sync_directory(parent)
            finally:
                if staging.exists():
                    shutil.rmtree(staging)
        self.worker_files(TARGET)

    def switch_worker(self):
        self.worker_files(TARGET)
        prefix = self.installation.prefix
        with tempfile.TemporaryDirectory(prefix='.connect-team-pointer-', dir=prefix) as folder:
            pending = Path(folder) / 'current'
            pending.symlink_to(self.target_worker)
            os.replace(pending, prefix / 'current')
            sync_directory(prefix)
        require(self.worker_commit() == TARGET, 'worker_pointer_not_effective')

    def cron_plan(self, expected=None):
        require(sha(self.worker.safe(CRON_SCHEDULE).read_bytes()) == CRON_SCHEDULE_SHA256, 'cron_schedule_drift')
        self.cron_values = {path: self.worker.safe(path).read_bytes() for path in (CRON_SCRIPT, CRON_INFO)}
        record, script, info = cron_update(self.cron_values[CRON_SCRIPT], self.cron_values[CRON_INFO], expected=expected)
        self.cron_target = {CRON_SCRIPT: script, CRON_INFO: info}
        return record

    def commit_cron(self, plan):
        self.cron_plan(plan)
        for path, raw in self.cron_target.items():
            if raw != self.cron_values[path]:
                write_atomic(path, raw)
        require(self.cron_plan()['before'] == [AFTER, THEME], 'cron_contract_not_effective')

    def backup(self, baseline):
        plan = {**self.manifest, 'baseline_commit': baseline}
        path = Path(self.core.backup(plan))
        archive = path / 'local-ustar-before.tar.gz'
        for item in (archive, path / 'postgres-before.dump'):
            with item.open('rb') as stream:
                os.fsync(stream.fileno())
        sync_directory(path)
        sync_directory(path.parent)
        return {'path': str(path), 'sha256': self.core.digest(archive)}

    def install_source(self, state):
        before = self.manifest['before'][state['baseline']]
        for name in changes(before, self.manifest['after']):
            path = self.core.safe_path(self.app, name)
            # The qualified source adds files only to directories present in both baselines.
            require(path.parent.is_dir(), 'application_parent_missing')
            write_atomic(path, (self.folder / 'payload' / name).read_bytes(), staging=self.core.HOST_APP.parent)
            if name.endswith('.php'):
                self.php(['-l', self.core.CONTAINER_APP + '/local/ustar/' + name], 'candidate_php_lint_failed')
        require(self.inspect_source() == TARGET, 'candidate_application_mismatch')

    def restore_source(self, state):
        before = self.manifest['before'][state['baseline']]
        after = self.manifest['after']
        delta = changes(before, after)
        live = self.source(self.app)
        require(set(live) <= set(after) and set(before) <= set(live)
                and all(value in (before.get(name), after[name]) for name, value in live.items()),
                'transitional_application_drift')
        backup = state['backup']
        path = Path(backup['path'])
        require(path.parent == self.core.BACKUP_DIR and path.name.startswith('ustar-reward-shop-')
                and path.is_dir() and not path.is_symlink(), 'invalid_backup_path')
        archive = self.core.safe_path(path, 'local-ustar-before.tar.gz')
        require(self.core.digest(archive) == backup['sha256'], 'source_backup_mismatch')
        with tarfile.open(archive, 'r:gz') as tar:
            for name in delta:
                dest = self.core.safe_path(self.app, name)
                if name in before:
                    member = tar.getmember('local/ustar/' + name)
                    require(member.isfile(), 'invalid_source_backup_entry')
                    raw = tar.extractfile(member).read()
                    require(sha(raw) == before[name], 'source_backup_digest_mismatch')
                    if live[name] != before[name]:
                        write_atomic(dest, raw, staging=self.core.HOST_APP.parent,
                                     metadata=(member.uid, member.gid, member.mode))
                elif dest.exists():
                    dest.unlink()
                    sync_directory(dest.parent)
        require(self.inspect_source() == state['baseline'], 'application_restore_not_effective')

    def upgrade_database(self):
        self.php([self.core.CONTAINER_CLI + '/upgrade.php', '--non-interactive'], 'moodle_upgrade_failed', timeout=600)

    def purge(self):
        self.php([self.core.CONTAINER_CLI + '/purge_caches.php'], 'moodle_cache_purge_failed', timeout=120)

    def smoke(self):
        args = ['curl', '--silent', '--show-error', '--noproxy', '*', '--connect-timeout', '3', '--max-time', '6',
                '--output', '/dev/null', '--write-out', '%{http_code}', '--header', 'Host: ustar.local',
                'http://127.0.0.1:8082/login/index.php']
        for _ in range(5):
            result = self.core.run(args, timeout=8, check=False)
            if result.returncode == 0 and result.stdout == b'200':
                return
            time.sleep(1)
        raise Refused('moodle_backend_login_failed')


class Transaction:
    def __init__(self, host, package):
        self.host, self.package = host, package

    def read(self):
        if not os.path.lexists(STATE):
            return None
        directory = STATE.lstat()
        require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == os.geteuid()
                and stat.S_IMODE(directory.st_mode) == 0o700, 'unsafe_journal_directory')
        path = STATE / 'state.json'
        if not os.path.lexists(path):
            return None
        status = path.lstat()
        require(stat.S_ISREG(status.st_mode) and status.st_uid == os.geteuid() and status.st_nlink == 1
                and stat.S_IMODE(status.st_mode) == 0o600 and status.st_size < 65536, 'unsafe_journal_file')
        state = json.loads(path.read_bytes())
        require(state['package'] == self.package and state['baseline'] in BASES
                and state['step'] in (*RUNNABLE_STEPS, 'active', 'rolled_back')
                and set(state['services']) == set(SERVICES)
                and all(type(value) is bool for value in state['services'].values()), 'journal_identity_mismatch')
        return state

    def save(self, state, step):
        if not os.path.lexists(STATE):
            STATE.mkdir(mode=0o700)
            sync_directory(STATE.parent)
        self.read()
        state['step'] = step
        write_atomic(STATE / 'state.json', canonical(state), metadata=(os.geteuid(), os.getegid(), 0o600))

    def check(self):
        state = self.read()
        if state and state['step'] in RUNNABLE_STEPS:
            return {'phase': 'recovery_required', 'step': state['step']}
        active = bool(state and state['step'] == 'active')
        source = self.host.inspect_source()
        require(source == TARGET if active else source in BASES, 'application_source_drift')
        worker = TARGET if active else WORKER_BEFORE
        status = self.host.verify(worker=worker, active=active)
        require(not status['maintenance'], 'existing_maintenance')
        require(status['local_ustar'] == (AFTER if active else BEFORE), 'unexpected_database_version')
        cron = self.host.cron_plan()
        require(not active or cron['before'] == [AFTER, THEME], 'cron_contract_not_effective')
        services = self.host.service_states()
        return {'phase': 'active' if active else 'ready', 'application_commit': source, 'worker_commit': worker,
                'local_ustar': status['local_ustar'], 'theme_ustar': THEME,
                'announcements_enabled': status['announcements_enabled'], 'services_running': services}

    def upgrade(self):
        current = self.check()
        if current['phase'] == 'active':
            return current
        require(current['phase'] == 'ready', 'recovery_required')
        baseline = current['application_commit']
        self.host.stage_worker()
        state = {'package': self.package, 'baseline': baseline, 'services': self.host.service_states(),
                 'cron': self.host.cron_plan(), 'backup': self.host.backup(baseline)}
        # Preflight was repeated under the shared deployment locks before making the backup.
        recheck = self.check()
        require(recheck['application_commit'] == baseline and recheck['services_running'] == state['services']
                and self.host.cron_plan() == state['cron'], 'baseline_changed_after_backup')
        self.save(state, 'prepared')
        try:
            self.host.maintenance(True)
            self.host.set_services(dict.fromkeys(SERVICES, False))
            self.host.install_source(state)
            self.save(state, 'migration')
            return self.complete(state)
        except Exception:
            if state['step'] == 'prepared':
                self.rollback(state)
            raise

    def rollback(self, state):
        status = self.host.verify(worker=WORKER_BEFORE, active=False, interrupted=True)
        require(status['local_ustar'] == BEFORE, 'database_rollback_refused')
        if not status['maintenance']:
            self.host.maintenance(True)
        self.host.set_services(dict.fromkeys(SERVICES, False))
        self.host.restore_source(state)
        self.host.purge()
        self.host.maintenance(False)
        self.host.set_services(state['services'])
        self.host.smoke()
        self.save(state, 'rolled_back')
        return {'phase': 'rolled_back', 'local_ustar': BEFORE, 'worker_commit': WORKER_BEFORE}

    def complete(self, state):
        require(self.host.inspect_source() == TARGET, 'candidate_application_mismatch')
        current = self.host.worker_commit()
        status = self.host.verify(worker=current, active=False, interrupted=True)
        if state['step'] == 'migration':
            require(status['local_ustar'] in (BEFORE, AFTER), 'unexpected_database_version')
            if not status['maintenance']:
                self.host.maintenance(True)
            self.host.set_services(dict.fromkeys(SERVICES, False))
            if status['local_ustar'] == BEFORE:
                require(current == WORKER_BEFORE, 'worker_switched_before_savepoint')
                self.host.upgrade_database()
            self.host.purge()
            status = self.host.verify(worker=current, active=False)
            require(status['local_ustar'] == AFTER and status['maintenance'], 'moodle_savepoint_not_effective')
            self.host.commit_cron(state['cron'])
            if current != TARGET:
                self.host.switch_worker()
            self.save(state, 'app_ready')
        require(self.host.worker_commit() == TARGET and status['local_ustar'] == AFTER
                and self.host.cron_plan()['before'] == [AFTER, THEME], 'candidate_runtime_mismatch')
        if status['maintenance']:
            self.host.maintenance(False)
        self.host.smoke()
        self.host.set_services(state['services'])
        final = self.host.verify(worker=TARGET, active=False)
        require(final['local_ustar'] == AFTER and not final['maintenance'], 'candidate_not_released')
        self.save(state, 'active')
        return {'phase': 'active', 'application_commit': TARGET, 'worker_commit': TARGET, 'local_ustar': AFTER,
                'theme_ustar': THEME, 'announcements_enabled': final['announcements_enabled'],
                'services_running': state['services'], 'backup': state['backup']['path'],
                'database_changed': True, 'worker_changed': True}

    def recover(self):
        state = self.read()
        require(state is not None and state['step'] in RUNNABLE_STEPS, 'no_matching_recovery')
        if state['step'] == 'prepared':
            return self.rollback(state)
        return self.complete(state)


def handle_signal(_signal, _frame):
    raise Refused('operator_interrupted')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    for mode in ('verify', 'check', 'upgrade', 'recover'):
        modes.add_argument('--' + mode, dest='mode', action='store_const', const=mode)
    parser.add_argument('--acknowledge-outage', action='store_true')
    args = parser.parse_args(argv)
    os.umask(0o077)
    for signum in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT):
        signal.signal(signum, handle_signal)
    core = tx = None
    try:
        core = embedded(CORE_SOURCE, CORE_SHA256, '_ustar_reward_shop_v3')
        worker = embedded(WORKER_INSTALLER, WORKER_INSTALLER_SHA256, '_ustar_connect_worker_installer')
        folder = Path(__file__).absolute().parent
        if args.mode != 'verify':
            require(os.geteuid() == 0, 'root_required')
            worker.safe(folder)
            worker.safe(folder / 'update.py')
        manifest, values, package = load_package(folder, core, worker)
        if args.mode == 'verify':
            result = {'phase': 'package_verified', 'application_commit': TARGET, 'worker_commit': TARGET}
        else:
            tx = Transaction(Host(folder, manifest, values, core, worker), package)
            require(args.mode == 'check' or args.acknowledge_outage, 'outage_acknowledgement_required')
            with core.exclusive_operator_guards():
                result = getattr(tx, args.mode)()
        result.update(schema_changed=False, business_data_changed=False)
        print(json.dumps(result, sort_keys=True))
        return 2 if result['phase'] == 'recovery_required' else 0
    except Exception as error:
        reason = str(error)
        if (not isinstance(error, (Refused, core.Refused if core else Refused))
                or not re.fullmatch(r'[a-z0-9_:/.-]{1,160}', reason)):
            reason = 'operation_failed'
        result = {'phase': 'refused', 'reason': reason}
        if tx:
            try:
                state = tx.read()
                if state and state['step'] in (*RUNNABLE_STEPS, 'rolled_back'):
                    result.update(phase='rolled_back' if state['step'] == 'rolled_back' else 'recovery_required',
                                  step=state['step'])
            except Exception:
                pass
        print(json.dumps(result, sort_keys=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())
