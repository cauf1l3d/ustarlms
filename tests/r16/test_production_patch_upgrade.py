"""Real paired file copies/restores and transaction journals; Docker/SQL/HDD fake."""
import ast
from contextlib import nullcontext
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import stat
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, REPO / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


x = load('production_upgrade_tested', 'scripts/infra/production_patch_upgrade.py')
REAL_HOST = x.Host
s = load('production_upgrade_candidate', 'scripts/infra/production_patch_candidate.py')
p = load('production_upgrade_reads', 'scripts/infra/production_patch_readiness.py')
c = load('production_upgrade_writes', 'scripts/infra/lab_patch_candidate.py')
cold = load('production_upgrade_cold', 'scripts/infra/lab_cold_checkpoint.py')
u = load('production_upgrade_pure_transaction', 'scripts/infra/lab_patch_upgrade.py')
w = load('production_upgrade_switch', 'scripts/infra/production_patch_switch_preflight.py')


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


class FakeHost:
    def __init__(self, d, identities, log):
        self.d, self.identities, self.log = d, identities, log

    def inspect(self):
        return {name: {'State': {'Running': value}} for name, value in self.d.running.items()}

    def action(self, argv, timeout=90):
        self.d.actions.append(('action', argv))

    def policy(self, value):
        self.d.actions.append(('policy', value))
        self.d.policy = value

    def restore_policies(self, policies):
        self.d.actions.append(('policies_restored', policies))
        self.d.policy = 'unless-stopped'

    def stop_web(self, clean=False):
        self.d.actions.append(('stop_web', clean))
        self.d.running['ustar_moodle'] = False

    def stop(self, clean=False):
        self.d.actions.append(('stop_all', clean))
        self.d.running = {'ustar_moodle': False, 'ustar_postgres': False}

    def start(self):
        # Starts are allowed only after both coherent roots have been copied.
        self.d.actions.append(('start_all', None))
        self.d.running = {'ustar_moodle': True, 'ustar_postgres': True}

    def database(self, prefix, addons):
        if not self.d.running['ustar_postgres']:
            raise AssertionError('database query while PostgreSQL is stopped')
        data = json.loads((self.d.p.PGDATA / 'database.json').read_bytes())
        data['other_clients'] = self.d.other_clients
        return data

    def cli_upgrade(self):
        self.d.attempt += 1
        self.d.actions.append(('upgrade', self.d.attempt))
        data = json.loads((self.d.p.PGDATA / 'database.json').read_bytes())
        data['core'] = x.TARGET
        data['tasks'].pop('\\core\\task\\removed_default', None)
        data['tasks']['\\core\\task\\new_default'] = [0, 0]
        (self.d.p.PGDATA / 'database.json').write_text(json.dumps(data))
        (self.d.p.DATA / 'new-migration-cache').write_bytes(b'PRIVATE_MIGRATION_SENTINEL')
        self.log.write(b'PRIVATE_RUNTIME_LOG_SENTINEL\n')
        if self.d.fail_at == self.d.attempt:
            raise x.ProductionUpgradeError('Injected official upgrade failure')

    def login(self):
        if self.d.fail_login and self.d.attempt:
            raise x.ProductionUpgradeError('Injected public readiness failure')
        if not all(self.d.running.values()):
            raise AssertionError('HTTP while production stopped')
        return 200


class ProductionPairTransactionGuards(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.base = self.root / 'transactions'
        self.base.mkdir(mode=0o700)
        self.live = self.root / 'live'
        self.code, self.data, self.pg = (self.live / name for name in ('code', 'moodledata', 'postgres'))
        self.candidate = self.base / 'candidate-518-test'
        self.candidate_code = self.candidate / 'code'
        self.state = self.root / 'state'
        for path in (self.code, self.data, self.pg, self.candidate_code, self.state):
            path.mkdir(parents=True, mode=0o700)
        os.chmod(self.code, 0o755)
        os.chmod(self.data, 0o770)
        os.chmod(self.candidate_code, 0o755)
        self.config = ("<?php\n$CFG->prefix='mdl_';\n$CFG->dbpass='PRIVATE_PASSWORD_SENTINEL';\n"
                       + s.SETUP + ' // original tail\n').encode()
        self.file(self.code / 'config.php', self.config)
        self.file(self.code / 'public/index.php', b'old custom index')
        self.file(self.candidate_code / 'config.php', s.configuration(self.config))
        self.file(self.candidate_code / 'public/index.php', b'new stock index')
        self.file(self.data / 'course/learning-file', b'PRIVATE_LEARNING_SENTINEL', 0o777)
        self.file(self.data / 'root-owned-cache', b'PRIVATE_CACHE_SENTINEL', 0o666)
        self.file(self.pg / 'PG_VERSION', b'16\n')
        self.db = {'core': x.OLD_CORE, 'addons': {'addon_' + str(n): '1' for n in range(57)},
                   'tasks': {'\\addon_1\\task\\work': [0, 0],
                             '\\core\\task\\custom': [1, 1],
                             '\\core\\task\\removed_default': [1, 0]}}
        self.file(self.pg / 'database.json', json.dumps(self.db).encode())
        self.lock = self.root / 'backup.lock'
        self.file(self.lock, b'')
        self.images = {'ustar_moodle': 'sha256:' + '1' * 64, 'ustar_postgres': 'sha256:' + '2' * 64}
        self.identities = {name: {'Id': str(n) * 64, 'Image': image, 'started_at': 'initial', 'pid': n}
                           for n, (name, image) in enumerate(self.images.items(), 1)}
        primitives = SimpleNamespace(CODE=self.code, DATA=self.data, PGDATA=self.pg,
            STATE=self.state, LOCK=self.lock, IMAGES=self.images, no_links=p.no_links,
            signature=p.signature, read_file=p.read_file, root_input=self.trusted,
            read_json=lambda path: json.loads(p.read_file(path)), parse_prefix=p.parse_prefix,
            containers=lambda: copy.deepcopy(self.identities))
        self.d = SimpleNamespace(p=primitives, c=c, cold=cold, u=u,
            trees={'code': self.code, 'moodledata': self.data, 'postgres': self.pg},
            running={'ustar_moodle': True, 'ustar_postgres': True}, policy='unless-stopped',
            actions=[], attempt=0, fail_at=0, fail_login=False, other_clients=0)
        self.d.s = SimpleNamespace(BASE=self.base, configuration=s.configuration,
            scan_code=s.scan_code, own_lock=lambda *args: nullcontext())
        source = {'code_entries': s.scan_code(primitives, self.code), 'original_config_sha256': sha(self.config)}
        source_sha = self.json(self.candidate / 'source-manifest.json', source)
        accepted = {'entries': s.scan_code(primitives, self.candidate_code), 'source_addon_versions': self.db['addons']}
        accepted_sha = self.json(self.candidate / 'candidate-manifest.json', accepted)
        self.plan = {'storage_plan': {'required_free_bytes': 1}, 'docker_conditions': {'restart_policies':
                     {name: {'name': 'unless-stopped', 'maximum_retry_count': 0} for name in self.images}}}
        self.d.w = SimpleNamespace(CANDIDATE=self.candidate, CANDIDATE_SHA=accepted_sha,
            SOURCE_SHA=source_sha, protected_json=w.protected_json,
            existing_shared_lock=w.existing_shared_lock, check=lambda *args: self.plan,
            allocation=w.allocation, HELPERS={'hdd_backup': (self.root / 'never-executed-backup.py', 'a' * 64)})
        for target, name, value in ((x, 'BASE', self.base), (x, 'MARKER', self.base / 'switch-active.json'),
                                   (x, 'ROOT_UID', os.getuid()), (x, 'ROOT_GID', os.getgid()),
                                   (x, 'Host', FakeHost), (cold, 'reserve', lambda: None),
                                   (c, 'reserve', lambda: None)):
            patch = mock.patch.object(target, name, value)
            patch.start()
            self.addCleanup(patch.stop)
        for number in x.SIGNALS:
            prior = signal.getsignal(number)
            self.addCleanup(signal.signal, number, prior)
        self.initial = {name: x.inventory(self.d, path) for name, path in self.d.trees.items()}
        self.initial_roots = x.roots(self.d)

    def trusted(self, path):
        p.no_links(path)
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o022:
            raise x.ProductionUpgradeError('Temporary control is untrusted')

    def file(self, path, data, mode=0o600):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        os.chmod(path, mode)

    def json(self, path, value):
        raw = json.dumps(value, sort_keys=True).encode()
        self.file(path, raw)
        return sha(raw)

    def run_upgrade(self, backup_error=None):
        def backup(*args):
            self.d.actions.append(('fresh_hdd', None))
            if backup_error:
                raise backup_error
            return {'file': 'encrypted.age', 'sha256': 'b' * 64, 'bytes': 1}
        with mock.patch.object(x, 'fresh_hdd', side_effect=backup), \
                mock.patch.object(x, 'references', return_value=1), \
                mock.patch.object(x, 'add_maintenance', side_effect=lambda d:
                    self.file(x.maintenance_path(d), x.MAINTENANCE, 0o644)), \
                mock.patch.object(x.sys, 'stdout', new_callable=io.StringIO) as output:
            result = x.upgrade(self.d)
        return result, output.getvalue()

    def assert_original(self):
        for name, path in self.d.trees.items():
            self.assertEqual(x.inventory(self.d, path), self.initial[name])
        self.assertEqual(x.roots(self.d), self.initial_roots)

    def marker(self):
        return json.loads(x.MARKER.read_bytes())

    def test_upgrade_real_code_pair_two_CLI_passes_and_preserved_live_roots(self):
        result, output = self.run_upgrade()
        self.assertEqual(result, 0)
        self.assertEqual(self.d.attempt, 2)
        self.assertEqual(x.roots(self.d), self.initial_roots)
        self.assertEqual(s.scan_code(self.d.p, self.code), s.scan_code(self.d.p, self.candidate_code))
        self.assertTrue(all(self.d.running.values()))
        self.assertEqual(self.d.policy, 'unless-stopped')
        self.assertFalse(x.maintenance_path(self.d).exists())
        marker = self.marker()
        self.assertEqual(marker['phase'], 'upgrade_complete')
        self.assertTrue(marker['public_released'])
        for name in self.d.trees:
            self.assertEqual(x.inventory(self.d, Path(marker['run_directory']) / 'baseline' / name), self.initial[name])
        self.assertNotIn('PRIVATE_', output)
        report = json.loads((Path(marker['run_directory']) / 'report.json').read_bytes())
        self.assertEqual(report['core_default_task_registry_changes'], 1)
        self.assertFalse(report['production_paired_restore_executed'])

    def test_first_and_repeat_upgrade_failure_restore_all_three_trees(self):
        for attempt in (1, 2):
            self.d.fail_at = attempt
            result, output = self.run_upgrade()
            self.assertEqual(result, 1)
            self.assert_original()
            self.assertTrue(all(self.d.running.values()))
            self.assertEqual(self.d.policy, 'unless-stopped')
            self.assertEqual(self.marker()['phase'], 'baseline_restored')
            self.assertTrue(self.marker()['paired_restored'])
            self.assertIn('PRODUCTION_BASELINE_RECOVERY=PASS', output)
            self.d.attempt = 0

    def test_HDD_failure_never_stops_postgres_or_activates_code(self):
        result, output = self.run_upgrade(x.ProductionUpgradeError('Injected fresh HDD error'))
        self.assertEqual(result, 1)
        self.assert_original()
        self.assertEqual([action[0] for action in self.d.actions], ['fresh_hdd'])
        self.assertEqual(self.d.attempt, 0)
        self.assertIn('NO_CORE_OR_PG_STOP', output)

    def test_partial_baseline_copy_failure_only_resumes_original_source(self):
        original = u.copy_contents
        calls = []
        def fail_second(*args):
            calls.append(args[2])
            if len(calls) == 2:
                raise OSError('Injected cold-copy failure')
            return original(*args)
        with mock.patch.object(u, 'copy_contents', side_effect=fail_second):
            result, output = self.run_upgrade()
        self.assertEqual(result, 1)
        self.assert_original()
        self.assertFalse(self.marker()['paired_restored'])
        self.assertEqual(self.d.attempt, 0)
        self.assertIn('PRODUCTION_BASELINE_RECOVERY=PASS', output)

    def test_candidate_drift_refuses_before_HDD_or_stop(self):
        (self.candidate_code / 'public/index.php').write_bytes(b'tampered')
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'code drifted'):
            self.run_upgrade()
        self.assertFalse(self.d.actions)
        self.assertFalse(x.MARKER.exists())

    def test_other_database_client_blocks_pg_stop_and_no_pair_or_CLI_runs(self):
        self.d.other_clients = 1
        result, _ = self.run_upgrade()
        self.assertEqual(result, 1)
        self.assert_original()
        self.assertEqual(self.d.attempt, 0)
        self.assertFalse(self.marker().get('baseline_manifest_sha256'))

    def test_public_readiness_failure_keeps_target_data_and_needs_explicit_rollback(self):
        self.d.fail_login = True
        result, output = self.run_upgrade()
        self.assertEqual(result, 1)
        self.assertEqual(json.loads((self.pg / 'database.json').read_bytes())['core'], x.TARGET)
        self.assertEqual(self.marker()['phase'], 'post_release_verification_required')
        self.assertTrue(self.marker()['public_released'])
        self.assertTrue((self.data / 'new-migration-cache').exists())
        self.assertIn('NO_AUTOMATIC_OLD_DATA_RESTORE', output)
        self.assertFalse(any(Path(self.marker()['run_directory']).glob('retained-before-restore-*')))

    def test_real_restore_retain_failed_trees_and_preserve_root_metadata(self):
        self.assertEqual(self.run_upgrade()[0], 0)
        marker = self.marker()
        host = FakeHost(self.d, self.identities, io.BytesIO())
        with mock.patch.object(x, 'references', return_value=1):
            result = x.restore_pair(self.d, marker, host)
        self.assert_original()
        self.assertTrue(result['paired_trees_restored'])
        self.assertTrue(list(Path(marker['run_directory']).glob('retained-before-restore-*')))

    def test_tampered_pair_stops_containers_without_moving_live_trees(self):
        self.assertEqual(self.run_upgrade()[0], 0)
        marker = self.marker()
        (Path(marker['run_directory']) / 'baseline/postgres/database.json').write_bytes(b'tampered')
        before = {name: x.inventory(self.d, root) for name, root in self.d.trees.items()}
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'readback differs'):
            x.restore_pair(self.d, marker, FakeHost(self.d, self.identities, io.BytesIO()))
        self.assertFalse(any(self.d.running.values()))
        self.assertEqual({name: x.inventory(self.d, root) for name, root in self.d.trees.items()}, before)

    def test_interrupted_restore_is_retryable_from_immutable_pair(self):
        self.assertEqual(self.run_upgrade()[0], 0)
        marker = self.marker()
        host = FakeHost(self.d, self.identities, io.BytesIO())
        original = u.copy_contents
        count = []
        def fail_once(*args):
            count.append(args[2])
            if len(count) == 2:
                raise OSError('Injected restore copy failure')
            return original(*args)
        with mock.patch.object(u, 'copy_contents', side_effect=fail_once):
            with self.assertRaises(OSError):
                x.restore_pair(self.d, marker, host)
        self.assertFalse(any(self.d.running.values()))
        with mock.patch.object(x, 'references', return_value=1):
            x.restore_pair(self.d, marker, host)
        self.assert_original()

    def test_second_upgrade_on_completed_transaction_is_refused(self):
        self.assertEqual(self.run_upgrade()[0], 0)
        before = len(self.d.actions)
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'second upgrade'):
            self.run_upgrade()
        self.assertEqual(len(self.d.actions), before)

    def test_symlink_and_hardlink_snapshot_inputs_refuse(self):
        link = self.data / 'link'
        link.symlink_to(self.data / 'root-owned-cache')
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'link/type'):
            x.inventory(self.d, self.data)
        link.unlink()
        os.link(self.data / 'root-owned-cache', link)
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'link/type'):
            x.inventory(self.d, self.data)

    def test_owned_maintenance_file_only_can_be_removed(self):
        path = x.maintenance_path(self.d)
        self.file(path, b'preexisting maintenance')
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'guard changed'):
            x.remove_maintenance(self.d)
        self.assertEqual(path.read_bytes(), b'preexisting maintenance')

    def test_missing_outage_ack_has_no_helper_load_or_writes(self):
        with mock.patch.object(x.sys, 'argv', ['upgrade', '--upgrade-production']), \
                mock.patch.object(x, 'dependencies', side_effect=AssertionError('no dependencies')), \
                mock.patch.object(x.sys, 'stderr', new_callable=io.StringIO):
            with self.assertRaises(SystemExit) as result:
                x.main()
        self.assertEqual(result.exception.code, 2)
        self.assertFalse(x.MARKER.exists())

    def test_default_plan_has_no_helper_load(self):
        with mock.patch.object(x.sys, 'argv', ['upgrade']), \
                mock.patch.object(x, 'dependencies', side_effect=AssertionError('no dependencies')), \
                mock.patch.object(x.sys, 'stdout', new_callable=io.StringIO):
            self.assertEqual(x.main(), 0)

    def test_post_release_manual_restore_requires_discard_ack_before_stopping_anything(self):
        self.assertEqual(self.run_upgrade()[0], 0)
        installed = self.root / 'installed.py'
        self.file(installed, b'test installed')
        before = len(self.d.actions)
        with mock.patch.object(x.sys, 'argv', ['upgrade', '--restore-baseline', '--acknowledge-outage']), \
                mock.patch.object(x, 'dependencies', return_value=self.d), \
                mock.patch.object(x, 'SCRIPT', installed), mock.patch.object(x.os, 'geteuid', return_value=0), \
                mock.patch.object(x.sys, 'stderr', new_callable=io.StringIO):
            self.assertEqual(x.main(), 1)
        self.assertEqual(len(self.d.actions), before)

    def test_customized_and_addon_tasks_preserved_while_core_defaults_can_evolve(self):
        after = copy.deepcopy(self.db)
        after['core'] = x.TARGET
        after['tasks'].pop('\\core\\task\\removed_default')
        x.verify_versions(after, self.db, x.TARGET)
        after['tasks']['\\addon_1\\task\\work'] = [1, 0]
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'preserved addon task'):
            x.verify_versions(after, self.db, x.TARGET)
        after['tasks'] = copy.deepcopy(self.db['tasks'])
        after['tasks']['\\core\\task\\custom'] = [0, 1]
        with self.assertRaises(x.ProductionUpgradeError):
            x.verify_versions(after, self.db, x.TARGET)

    def test_broken_SSH_output_does_not_raise_during_cleanup_notice(self):
        output = mock.Mock()
        output.write.side_effect = BrokenPipeError()
        with mock.patch.object(x.sys, 'stdout', output):
            x.notice('recovery notice')

    def test_real_exclusive_backup_lock_blocks_managed_shared_cron_lock(self):
        before = p.signature(self.lock.stat())
        with x.exclusive_backup_lock(self.d.p):
            with self.lock.open('rb') as stream:
                import fcntl
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(stream.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
        self.assertEqual(p.signature(self.lock.stat()), before)

    @unittest.skipUnless(os.geteuid() == 0, 'Exact33/999 ownership fixture requires root; portable mode tests run in CI')
    def test_exact_reported_mixed_ownership_and_root_gid_survive_copy_and_restore(self):
        try:
            os.chown(self.code, 33, 33)
        except OSError:
            if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE') == '1':
                raise
            self.skipTest('Local root user namespace does not map UID33; exact fixture is mandatory in privileged source CI')
        os.chown(self.data, 33, 33)
        os.chown(self.pg, 999, 1000)
        os.chown(self.code / 'config.php', 33, 33)
        os.chown(self.candidate_code / 'config.php', 33, 33)
        os.chown(self.code / 'public', 33, 33)
        os.chown(self.code / 'public/index.php', 33, 33)
        os.chmod(self.code / 'public/index.php', 0o644)
        os.chmod(self.candidate_code / 'public/index.php', 0o644)
        os.chown(self.data / 'course', 33, 33)
        os.chmod(self.data / 'course', 0o777)
        os.chown(self.data / 'course/learning-file', 33, 33)
        os.chown(self.pg / 'database.json', 999, 999)
        self.initial = {name: x.inventory(self.d, path) for name, path in self.d.trees.items()}
        self.initial_roots = x.roots(self.d)
        self.d.w.SOURCE_SHA = self.json(self.candidate / 'source-manifest.json',
            {'code_entries': s.scan_code(self.d.p, self.code), 'original_config_sha256': sha(self.config)})
        self.d.w.CANDIDATE_SHA = self.json(self.candidate / 'candidate-manifest.json',
            {'entries': s.scan_code(self.d.p, self.candidate_code), 'source_addon_versions': self.db['addons']})
        self.assertEqual(self.run_upgrade()[0], 0)
        with mock.patch.object(x, 'references', return_value=1):
            x.restore_pair(self.d, self.marker(), FakeHost(self.d, self.identities, io.BytesIO()))
        self.assert_original()
        self.assertEqual(self.pg.stat().st_gid, 1000)
        self.assertEqual((self.pg / 'database.json').stat().st_gid, 999)

    def test_stage_module_usage_is_limited_to_three_pure_copy_journal_functions(self):
        tree = ast.parse((REPO / 'scripts/infra/production_patch_upgrade.py').read_text())
        calls = {node.func.attr for node in ast.walk(tree) if isinstance(node, ast.Call)
                 and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Attribute)
                 and node.func.value.attr == 'u'}
        self.assertEqual(calls, {'write_json', 'copy_contents', 'move_children'})

    def test_custom_SQL_interface_refuses_mutation_before_exec(self):
        real = REAL_HOST(self.d, self.identities, io.BytesIO())
        self.d.p.command = mock.Mock(side_effect=AssertionError('no commands'))
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'Non-read-only'):
            real.sql('UPDATE mdl_config SET value=1;')
        self.d.p.command.assert_not_called()

    def test_real_stop_API_blocks_signals_until_both_stops_are_confirmed(self):
        rows = {}
        for name, saved in self.identities.items():
            binds = {str(self.code): '/var/www/html', str(self.data): '/var/www/moodledata'} \
                if name == 'ustar_moodle' else {str(self.pg): '/var/lib/postgresql/data'}
            rows[name] = {'Name': '/' + name, **saved,
                'State': {'Running': True, 'ExitCode': 0, 'OOMKilled': False},
                'Mounts': [{'Type': 'bind', 'Source': source, 'Destination': destination}
                           for source, destination in binds.items()]}
        self.d.p.command = lambda argv: json.dumps(list(rows.values()))
        real = REAL_HOST(self.d, self.identities, io.BytesIO())
        calls = []
        def action(argv, timeout=90):
            self.assertTrue(set(x.SIGNALS) <= signal.pthread_sigmask(signal.SIG_BLOCK, []))
            self.assertEqual(argv[:4], ['docker', 'stop', '--time', '60'])
            name = next(name for name, row in rows.items() if row['Id'] == argv[4])
            rows[name]['State']['Running'] = False
            calls.append(name)
        real.action = action
        previous = signal.pthread_sigmask(signal.SIG_BLOCK, [])
        real.stop(clean=True)
        self.assertEqual(calls, ['ustar_moodle', 'ustar_postgres'])
        self.assertEqual(signal.pthread_sigmask(signal.SIG_BLOCK, []), previous)

    def test_real_SQL_builds_readonly_query_and_checks_all_cluster_clients(self):
        self.d.p.command = mock.Mock(return_value='{}')
        real = REAL_HOST(self.d, self.identities, io.BytesIO())
        real.database('mdl_', self.db['addons'])
        args = self.d.p.command.call_args.args
        self.assertEqual(args[0][:3], ['docker', 'exec', '-i'])
        self.assertTrue(args[1].startswith('BEGIN READ ONLY;'))
        self.assertIn('json_build_array(disabled,customised)', args[1])
        self.assertNotIn('datname=current_database()', args[1])
        with self.assertRaisesRegex(x.ProductionUpgradeError, 'identifiers refused'):
            real.database('mdl_; DELETE FROM mdl_user;', self.db['addons'])


if __name__ == '__main__':
    unittest.main()
