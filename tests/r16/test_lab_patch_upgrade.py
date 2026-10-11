"""Real paired filesystem copies/recovery with portable owners; Docker/SQL/PHP mocked.

Failure injection tests prove three-tree recovery, retained bind-root inodes,
transaction markers and process shutdown ordering. They do not claim live Moodle
upgrade, PostgreSQL startup or production acceptance.
"""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import signal
from pathlib import Path
import stat
import subprocess
from types import SimpleNamespace
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, REPO / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


u = load('tested_patch_upgrade', 'scripts/infra/lab_patch_upgrade.py')
cold = load('upgrade_cold_primitives', 'scripts/infra/lab_cold_checkpoint.py')
r = load('upgrade_policy', 'scripts/infra/lab_patch_readiness.py')


class Stage:
    def __init__(self):
        self.running = {'pg': True, 'web': True}
        self.state = {'token': 'f' * 32, 'containers': {'pg': 'pg-owned', 'web': 'web-owned'}}
        self.actions = []
        self._LOG = None
        self.dirty = False

    def load_state(self):
        return self.state

    def owned(self, kind, state):
        self.actions.append(('owned', kind))
        return {'Id': self.state['containers'][kind], 'Image': 'image-' + kind,
                'State': {'Running': self.running[kind], 'ExitCode': 137 if self.dirty else 0,
                          'OOMKilled': False, 'StartedAt': 'fixed'}}

    def command(self, argv, **kwargs):
        self.actions.append(('command', argv))
        if argv[:2] in (['docker', 'stop'], ['docker', 'start']):
            kind = next(k for k, value in self.state['containers'].items() if value == argv[-1])
            self.running[kind] = argv[1] == 'start'
        return SimpleNamespace(stdout=b'')

    def stop_relay(self, state):
        self.actions.append(('stop_relay',))

    def assert_offline(self, state):
        self.actions.append(('offline',))

    def start_relay(self, state):
        self.actions.append(('start_relay',))

    def production_state(self):
        return {'production': 'unchanged'}

    def write_private(self, path, raw):
        path.write_bytes(raw if isinstance(raw, bytes) else raw.encode())
        path.chmod(0o600)

    def save_state(self, state):
        self.actions.append(('save_state',))


class UpgradeTests(unittest.TestCase):
    def setUp(self):
        for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            self.addCleanup(signal.signal, number, signal.getsignal(number))
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.source = Path(temp.name)
        self.work = self.source / 'patch-stage-20261005'
        self.work.mkdir(mode=0o700)
        (self.work / 'site').mkdir(mode=0o700)
        self.run = self.source / 'patch-upgrade-518-20261012-fixture'
        self.run.mkdir(mode=0o700)
        self.stage = Stage()
        self.c = SimpleNamespace(trusted=self.trusted, trusted_parents=lambda _: None,
                                 read_checked=self.read_checked,
                                 verify_candidate=lambda root, entries: None)
        self.resume = SimpleNamespace(wait_database=lambda *args: self.stage.actions.append(('db_ready',)))
        for module, field, value in [(u, 'SOURCE', self.source), (u, 'WORK', self.work),
                                     (u, 'MARKER', self.work / 'patch-upgrade-active.json'),
                                     (u, 'CODE_UID', os.getuid()), (u, 'DATA_UID', os.getuid()),
                                     (u, 'DATA_GID', os.getgid()), (cold, 'SOURCE', self.source),
                                     (cold, 'RESERVE', 0)]:
            patch = mock.patch.object(module, field, value)
            patch.start()
            self.addCleanup(patch.stop)
        for name, kind in u.TREE_KINDS.items():
            root = self.work / name
            root.mkdir(mode=0o700)
            (root / 'nested').mkdir(mode=0o700)
            self.file(root / 'nested/payload', (kind + '-original').encode())
        for name in u.CONTROLS:
            self.file(self.work / name, (name + '-original').encode())
        self.marker = {'schema_version': 1, 'stage_root': str(self.work),
                       'run_directory': str(self.run), 'stage_token': self.stage.state['token'],
                       'stage_containers': dict(self.stage.state['containers']),
                       'roots': u.roots_identity(), 'live_trees_modified': True}

    def file(self, path, raw):
        path.write_bytes(raw)
        path.chmod(0o600)

    def trusted(self, path, directory=False, private=False):
        info = path.lstat()
        u.require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                  and not info.st_mode & (0o077 if private else 0o022)
                  and (directory or info.st_nlink == 1), 'Fixture metadata refused')
        return info

    def read_checked(self, path, expected_sha=None, maximum=32 * 1024 ** 2):
        self.trusted(path)
        raw = path.read_bytes()
        u.require(len(raw) <= maximum and (expected_sha is None or
                  hashlib.sha256(raw).hexdigest() == expected_sha), 'Fixture input hash differs')
        return raw

    def pair(self):
        u.stop_stage(self.stage, self.stage.state)
        manifest, sha = u.paired_copy(cold, self.c, self.run)
        self.marker['baseline_manifest_sha256'] = sha
        return manifest

    def mutate(self):
        for name in u.TREE_KINDS:
            self.file(self.work / name / 'nested/payload', b'upgraded-' + name.encode())
            self.file(self.work / name / 'added', b'added-in-upgrade')

    def test_real_paired_copy_and_metadata_readback(self):
        manifest = self.pair()
        self.assertEqual(set(manifest['trees']), set(u.TREE_KINDS))
        self.assertEqual(set(manifest['controls']), set(u.CONTROLS))
        self.assertEqual(u.read_pair(cold, self.c, self.run, self.marker['baseline_manifest_sha256']), manifest)
        for name in u.TREE_KINDS:
            a, b = self.work / name / 'nested/payload', self.run / 'baseline' / name / 'nested/payload'
            self.assertNotEqual(a.stat().st_ino, b.stat().st_ino)

    def test_paired_restore_restores_all_trees_retains_upgraded_files_and_root_inodes(self):
        manifest = self.pair()
        self.mutate()
        self.stage.running = {'pg': True, 'web': True}
        result = u.restore_pair(cold, self.c, self.stage, self.resume, self.marker)
        self.assertIs(result, self.stage.state)
        self.assertEqual(u.roots_identity(), self.marker['roots'])
        self.assertTrue(all(self.stage.running.values()))
        for name, kind in u.TREE_KINDS.items():
            u.verify_tree(cold, self.work / name, manifest['trees'][name], kind)
        stash = next(self.run.glob('retained-before-restore-*'))
        self.assertEqual(len(list(stash.glob('*/added'))), 3)
        commands = [row[1] for row in self.stage.actions if row[0] == 'command']
        self.assertEqual([argv[1] for argv in commands[-4:]], ['stop', 'stop', 'start', 'start'])

    def test_tampered_pair_stops_stage_but_does_not_move_live_files(self):
        self.pair()
        self.mutate()
        self.file(self.run / 'baseline/postgres/nested/payload', b'tamper')
        self.stage.running = {'pg': True, 'web': True}
        with self.assertRaises(u.UpgradeError):
            u.restore_pair(cold, self.c, self.stage, self.resume, self.marker)
        self.assertFalse(any(self.stage.running.values()))
        self.assertFalse(list(self.run.glob('retained-before-restore-*')))
        self.assertEqual((self.work / 'postgres/nested/payload').read_bytes(), b'upgraded-postgres')

    def test_partial_restore_can_be_retried_from_complete_pair(self):
        manifest = self.pair()
        self.mutate()
        original = u.copy_contents
        calls = []
        def fail_second(*args):
            calls.append(args[2])
            if len(calls) == 2:
                raise OSError('injected disk failure')
            return original(*args)
        with mock.patch.object(u, 'copy_contents', side_effect=fail_second):
            with self.assertRaises(OSError):
                u.restore_pair(cold, self.c, self.stage, self.resume, self.marker)
        self.assertFalse(any(self.stage.running.values()))
        u.restore_pair(cold, self.c, self.stage, self.resume, self.marker)
        for name, kind in u.TREE_KINDS.items():
            u.verify_tree(cold, self.work / name, manifest['trees'][name], kind)
        self.assertEqual(len(list(self.run.glob('retained-before-restore-*'))), 2)

    def test_replaced_live_root_is_refused_before_move(self):
        self.pair()
        root = self.work / 'postgres'
        root.rename(self.work / 'postgres-retained')
        root.mkdir(mode=0o700)
        with self.assertRaises(u.UpgradeError):
            u.restore_pair(cold, self.c, self.stage, self.resume, self.marker)
        self.assertFalse(list(self.run.glob('retained-before-restore-*')))

    def test_stage_ownership_token_mismatch_refuses_before_stop(self):
        self.marker['stage_token'] = 'different'
        with self.assertRaises(u.UpgradeError):
            u.restore_pair(cold, self.c, self.stage, self.resume, self.marker)
        self.assertFalse(self.stage.actions)

    def test_dirty_shutdown_refuses_snapshot_but_recovery_can_stop_processes(self):
        self.stage.dirty = True
        with self.assertRaises(u.UpgradeError):
            u.stop_stage(self.stage, self.stage.state)
        u.stop_for_recovery(self.stage, self.stage.state)
        self.assertFalse(any(self.stage.running.values()))

    def test_snapshot_refuses_running_postgres_pid(self):
        self.file(self.work / 'postgres/postmaster.pid', b'123')
        with self.assertRaises(u.UpgradeError):
            u.stop_stage(self.stage, self.stage.state)

    def test_inventory_refuses_symlink_and_hardlink(self):
        root = self.work / 'postgres'
        link = root / 'link'
        link.symlink_to(root / 'nested/payload')
        with self.assertRaises(u.UpgradeError):
            u.inventory(cold, root, 'postgres')
        link.unlink()
        os.link(root / 'nested/payload', link)
        with self.assertRaises(u.UpgradeError):
            u.inventory(cold, root, 'postgres')

    def test_copy_refuses_nonempty_destination_and_hash_change(self):
        root = self.work / 'postgres'
        entries = u.inventory(cold, root, 'postgres')
        destination = self.run / 'copy'
        destination.mkdir(mode=0o700)
        self.file(destination / 'existing', b'existing')
        with self.assertRaises(u.UpgradeError):
            u.copy_contents(cold, root, destination, entries)
        destination = self.run / 'empty-copy'
        destination.mkdir(mode=0o700)
        self.file(root / 'nested/payload', b'changed-content')
        with self.assertRaises(u.UpgradeError):
            u.copy_contents(cold, root, destination, entries)

    def test_candidate_activation_keeps_bind_root_and_baseline_stash(self):
        self.pair()
        candidate = self.run / 'candidate'
        candidate.mkdir(mode=0o755)
        self.file(candidate / 'config.php', b'candidate-config')
        entries = u.inventory(cold, candidate, 'code')
        r_fixture = SimpleNamespace(CANDIDATE=self.run)
        candidate.rename(self.run / 'code')
        u.activate(cold, self.c, r_fixture, self.marker, {'entries': {k: v for k, v in entries.items() if k}})
        self.assertEqual(u.roots_identity(), self.marker['roots'])
        self.assertEqual((self.work / 'site/public/config.php').read_bytes(), b'candidate-config')
        self.assertTrue((self.run / 'retained-baseline-code/nested/payload').exists())
        marker = json.loads(u.MARKER.read_bytes())
        self.assertTrue(marker['live_trees_modified'])
        self.assertEqual(marker['phase'], 'activating_candidate')

    def test_marker_private_atomic_and_directory_scope(self):
        u.record(cold, self.marker, phase='test')
        self.assertEqual(stat.S_IMODE(u.MARKER.stat().st_mode), 0o600)
        self.assertEqual(u.validate_marker(self.c, self.stage)['phase'], 'test')
        self.marker['run_directory'] = str(self.source.parent)
        u.record(cold, self.marker, phase='test')
        with self.assertRaises(u.UpgradeError):
            u.validate_marker(self.c, self.stage)

    def test_cli_policy_is_explicit_for_every_php_program(self):
        argv = u.php_argv(r, '/var/www/html/admin/cli/upgrade.php', '--non-interactive')
        self.assertIn('sendmail_path=/bin/false', argv)
        self.assertIn('memory_limit=512M', argv)
        self.assertIn('auto_prepend_file=', argv)
        self.assertEqual(argv[-2:], ['/var/www/html/admin/cli/upgrade.php', '--non-interactive'])

    def test_core_version_numeric_zero_equivalence_and_addon_scope(self):
        baseline = {'core': 'old', 'addons': {'local_ustar': '123'}, 'enabled_tasks': 0}
        actual = {'core': '2025100608', 'addons': {'local_ustar': '123', 'mod_stock': 'changed'}, 'enabled_tasks': 0}
        with mock.patch.object(u, 'database_versions', return_value=actual):
            u.verify_versions(self.stage, self.stage.state, baseline, u.TARGET_VERSION)
            actual['addons']['local_ustar'] = '124'
            with self.assertRaises(u.UpgradeError):
                u.verify_versions(self.stage, self.stage.state, baseline, u.TARGET_VERSION)

    def test_addon_baseline_excludes_stock_core_components(self):
        profile = {'additional_components': [{'component': 'local_ustar'}]}
        baseline = u.addon_baseline({'addons': {'local_ustar': '1', 'mod_stock': '2'}}, profile)
        self.assertEqual(baseline['addons'], {'local_ustar': '1'})

    def test_reference_queries_bounded_readonly_and_adapter_restored(self):
        calls = []
        def sql(state, query, db):
            calls.append(query)
            return 'reference'
        self.stage.sql = sql
        self.stage.check_file_references = lambda state: self.stage.sql(state, 'SELECT files;')
        self.assertEqual(u.check_references(self.stage, self.stage.state), 'reference')
        self.assertIs(self.stage.sql, sql)
        self.assertIn('BEGIN READ ONLY;', calls[0])
        self.assertIn("statement_timeout='30s'", calls[0])

    def test_capacity_refusal_includes_growth_and_paired_restore(self):
        candidate = self.run / 'code'
        candidate.mkdir(mode=0o755)
        self.file(candidate / 'version.php', b'version')
        with mock.patch.object(u.shutil, 'disk_usage', return_value=SimpleNamespace(free=u.RESERVE)):
            with self.assertRaises(u.UpgradeError):
                u.budget(cold, candidate)

    def test_default_plan_does_not_load_dependencies(self):
        with mock.patch.object(u.sys, 'argv', ['helper']), mock.patch.object(u, 'load_readiness') as dependency:
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(u.main(), 0)
            dependency.assert_not_called()

    def flow(self, fail_attempt=None):
        candidate = self.source / 'accepted'
        candidate.mkdir(mode=0o700)
        (candidate / 'code').mkdir(mode=0o755)
        self.file(candidate / 'code/config.php', b'candidate')
        entries = u.inventory(cold, candidate / 'code', 'code')
        raw = json.dumps({'entries': {k: v for k, v in entries.items() if k}}).encode()
        self.file(candidate / 'manifest.json', raw)
        checkpoint = self.source / 'original-checkpoint'
        checkpoint.mkdir(mode=0o700)
        self.file(checkpoint / 'manifest.json', b'frozen-original-manifest')
        self.c.CHECKPOINT = checkpoint
        self.c.MANIFEST_SHA = hashlib.sha256((checkpoint / 'manifest.json').read_bytes()).hexdigest()
        readonly = SimpleNamespace(check=lambda _: {}, CANDIDATE=candidate,
                                   CANDIDATE_SHA=hashlib.sha256(raw).hexdigest(), CLI_POLICY=r.CLI_POLICY)
        pg_file = self.work / 'postgres/nested/payload'
        self.file(pg_file, b'2025100601.03')
        calls = []
        enabled = [0]
        def sql(state, query, db='ustar_recovery_lab'):
            calls.append(('sql', query))
            if 'UPDATE mdl_task_scheduled' in query:
                enabled[0] = 0
                return ''
            return json.dumps({'core': pg_file.read_text(), 'addons': {'local_ustar': '123',
                               'mod_stock': 'changed-core-plugin'}, 'enabled_tasks': enabled[0]})
        self.stage.sql = sql
        self.stage.check_file_references = lambda state: 4
        attempt = [0]
        def php(kind, state, argv, **kwargs):
            self.assertTrue(all(self.stage.running.values()))
            self.assertIn('sendmail_path=/bin/false', argv)
            calls.append(('php', argv))
            if argv[-1] == '--non-interactive':
                attempt[0] += 1
                self.file(pg_file, b'2025100608')
                self.file(self.work / 'site/moodledata/nested/payload', b'data-written-by-upgrade')
                enabled[0] = 1
                if attempt[0] == fail_attempt:
                    # Mimic a docker exec timeout leaving the child running until container stop.
                    enabled[0] = 0
                    raise subprocess.TimeoutExpired(['docker', 'exec'], 1800)
                return SimpleNamespace(stdout=b'upgrade output')
            return SimpleNamespace(stdout=json.dumps({'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab',
                'wwwroot': 'http://127.0.0.1:18085', 'noemail': True, 'cron': 0, 'adhoc': 0,
                'mail': '/bin/false', 'memory': '512M', 'needs_upgrade':
                (self.work / 'site/public/config.php').exists() and pg_file.read_text() == '2025100601.03'}))
        self.stage.lab_exec = php
        profile = {'core_db_version': '2025100601.03',
                   'additional_components': [{'component': 'local_ustar'}]}
        deps = (cold, SimpleNamespace(readonly_database=lambda *args: None), self.resume, self.stage,
                self.stage, None, profile)
        return readonly, deps, calls, attempt

    def test_complete_upgrade_repeat_then_explicit_paired_recovery(self):
        readonly, deps, calls, attempt = self.flow()
        original = {name: u.inventory(cold, self.work / name, kind) for name, kind in u.TREE_KINDS.items()}
        with mock.patch.object(cold, 'http_check', return_value=200), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(u.upgrade(readonly, self.c, deps), 0)
            marker = u.validate_marker(self.c, self.stage)
            self.assertEqual(marker['phase'], 'upgrade_complete')
            self.assertEqual(attempt[0], 2)
            self.assertEqual(json.loads((self.work / 'report.json').read_bytes())['patch_upgrade_rollback_verified'], False)
            u.recover(readonly, self.c, deps, marker)
        for name, kind in u.TREE_KINDS.items():
            u.verify_tree(cold, self.work / name, original[name], kind)
        self.assertEqual(u.validate_marker(self.c, self.stage)['phase'], 'baseline_restored')
        self.assertEqual(u.roots_identity(), marker['roots'])

    def test_timeout_in_first_or_repeat_upgrade_automatically_restores_whole_pair(self):
        readonly, deps, calls, attempt = self.flow(fail_attempt=2)
        original = {name: u.inventory(cold, self.work / name, kind) for name, kind in u.TREE_KINDS.items()}
        with mock.patch.object(cold, 'http_check', return_value=200), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(u.upgrade(readonly, self.c, deps), 1)
        self.assertEqual(attempt[0], 2)
        self.assertTrue(all(self.stage.running.values()))
        marker = u.validate_marker(self.c, self.stage)
        self.assertEqual(marker['phase'], 'baseline_restored')
        self.assertEqual(marker['failure_type'], 'TimeoutExpired')
        for name, kind in u.TREE_KINDS.items():
            u.verify_tree(cold, self.work / name, original[name], kind)

    def test_existing_success_transaction_refuses_duplicate_upgrade(self):
        readonly, deps, calls, attempt = self.flow()
        with mock.patch.object(cold, 'http_check', return_value=200), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(u.upgrade(readonly, self.c, deps), 0)
            with self.assertRaises(u.UpgradeError):
                u.upgrade(readonly, self.c, deps)
        self.assertEqual(attempt[0], 2)

    def test_failed_recovery_preserves_marker_and_confirms_stage_stopped(self):
        readonly, deps, calls, attempt = self.flow(fail_attempt=1)
        with mock.patch.object(u, 'restore_pair', side_effect=OSError('injected recovery failure')):
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(u.upgrade(readonly, self.c, deps), 1)
        marker = u.validate_marker(self.c, self.stage)
        self.assertEqual(marker['phase'], 'recovery_required')
        self.assertFalse(any(self.stage.running.values()))
        self.assertTrue((Path(marker['run_directory']) / 'baseline/postgres/nested/payload').exists())

    def test_failed_journal_write_does_not_prevent_recovery_attempt_or_stage_stop(self):
        readonly, deps, calls, attempt = self.flow(fail_attempt=1)
        original = u.record
        recovering = []
        def record(*args, **kwargs):
            if kwargs.get('failure_type') or args[1].get('failure_type'):
                recovering.append(True)
                raise OSError('injected full disk')
            return original(*args, **kwargs)
        with mock.patch.object(u, 'record', side_effect=record), mock.patch.object(u, 'restore_pair',
                side_effect=OSError('injected insufficient restore capacity')) as restore:
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(u.upgrade(readonly, self.c, deps), 1)
        restore.assert_called_once()
        self.assertTrue(recovering)
        self.assertFalse(any(self.stage.running.values()))


if __name__ == '__main__':
    unittest.main()
