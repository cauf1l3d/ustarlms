"""Addon-only production transaction: real copies, modeled Docker/SQL."""
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]


def load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts/infra' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


x = load('connect_academy_installer')


class Host:
    def __init__(self, d, identities, log):
        self.d, self.log = d, log

    def inspect(self):
        return {name: {'State': {'Running': state}} for name, state in self.d.running.items()}

    def policy(self, value):
        self.d.actions.append(('policy', value))

    def restore_policies(self, policies):
        self.d.actions.append(('restore_policies', policies))

    def stop_web(self, clean=False):
        self.d.running['ustar_moodle'] = False
        if self.d.bad_stop:
            raise RuntimeError('unclean stop')

    def stop(self, clean=False):
        if self.d.cannot_stop:
            raise RuntimeError('stop failed')
        self.d.running = dict.fromkeys(self.d.running, False)

    def start(self):
        self.d.running = dict.fromkeys(self.d.running, True)

    def database(self, prefix, addons=None):
        return json.loads((self.d.p.PGDATA / 'db.json').read_bytes())

    def cli_upgrade(self):
        self.d.attempt += 1
        data = self.database('mdl_')
        data['addons'].update({k: str(v) for k, v in self.d.reference['candidate']['versions'].items()})
        data['tables'] = self.d.reference['candidate']['tables']
        data['schema'] = ['candidate']
        data['tasks'][x.NEW_TASK] = [0, 0]
        (self.d.p.PGDATA / 'db.json').write_text(json.dumps(data))
        (self.d.p.DATA / 'migration').write_bytes(b'new cache')
        self.log.write(b'PRIVATE_SENTINEL')
        if self.d.attempt == self.d.fail_at:
            raise RuntimeError('PRIVATE_SENTINEL')

    def login(self):
        if self.d.fail_login:
            raise RuntimeError('login failed after release')
        return 200


class Transaction(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.base = self.root / 'transactions'
        self.base.mkdir(mode=0o700)
        trees = {name: self.root / name for name in ('code', 'moodledata', 'postgres')}
        for root in trees.values():
            root.mkdir(mode=0o700)
        p = load('production_patch_readiness')
        cold = load('lab_cold_checkpoint')
        u = load('lab_patch_upgrade')
        tx = load('production_patch_upgrade')
        w = load('production_patch_switch_preflight')
        c = load('lab_patch_candidate')
        p.CODE, p.DATA, p.PGDATA = trees.values()
        p.STATE = self.root / 'backup-state'
        p.STATE.mkdir()
        p.LOCK = self.root / 'backup.lock'
        p.LOCK.touch(mode=0o600)
        # Test owner may be nonroot; real adapter retains strict root guards.
        p.root_input = lambda path: None
        cold.reserve = lambda: None
        c.reserve = lambda: None
        self.d = SimpleNamespace(p=p, c=c, cold=cold, u=u, tx=tx, w=w,
            trees=trees, base=self.base, marker=self.base / 'active.json',
            reference={'baseline': {'versions': {'local_ustar': 2026100204, 'theme_ustar': 2026100202},
                'tables': ['local_ustar_old']},
                'candidate': {'versions': {'local_ustar': 2026101905, 'theme_ustar': 2026100202},
                'tables': ['local_ustar_old', 'local_ustar_new']}},
            identities={name: {'Id': str(i) * 64, 'Image': image}
                for i, (name, image) in enumerate(p.IMAGES.items(), 1)},
            running={'ustar_moodle': True, 'ustar_postgres': True},
            actions=[], attempt=0, fail_at=0, bad_stop=False, cannot_stop=False, fail_login=False)
        self.d.web = p.CODE / 'public'
        self.payload = {}
        baseline_files = {}
        for scope in ('local/ustar', 'theme/ustar'):
            target = self.d.web / scope
            target.mkdir(parents=True, mode=0o755)
            (target / 'version.php').write_bytes(b'old')
            baseline_files[scope + '/version.php'] = hashlib.sha256(b'old').hexdigest()
            self.payload[scope + '/version.php'] = b'new'
        self.d.reference['baseline']['files'] = baseline_files
        self.d.reference['candidate']['files'] = {k: hashlib.sha256(v).hexdigest() for k, v in self.payload.items()}
        self.d.payload = self.payload
        self.config = b"<?php\n$CFG->prefix = 'mdl_';\n// PRIVATE_CONFIG\n"
        (p.CODE / 'config.php').write_bytes(self.config)
        (p.CODE / 'core.txt').write_bytes(b'core unchanged')
        (p.DATA / 'learning').write_bytes(b'PRIVATE_LEARNING')
        db = {'core': '2025100608.00', 'addons': {'local_ustar': '2026100204',
            'theme_ustar': '2026100202', 'other_plugin': '123'},
            'tasks': {'existing': [1, 1]}, 'tables': ['local_ustar_old'],
            'schema': ['baseline'], 'indexes': ['baseline_index'], 'other_clients': 0}
        (p.PGDATA / 'db.json').write_text(json.dumps(db))
        self.baseline = copy.deepcopy(db)
        self.before = {n: tx.inventory(self.d, t) for n, t in trees.items()}
        import signal
        for number in x.SIGNALS:
            self.addCleanup(signal.signal, number, signal.getsignal(number))
        for target, name, value in ((x, 'Host', Host), (x, 'ROOT_UID', os.getuid()),
                (x, 'fresh_hdd', lambda d, marker, host: {'sha256': 'b' * 64}),
                (x, 'references', lambda *args: 1), (x, 'capacity', lambda *args: {})):
            patch = mock.patch.object(target, name, value)
            patch.start()
            self.addCleanup(patch.stop)
        # UID33 chown is covered by the existing root-only production fixture.
        maintenance = mock.patch.object(tx, 'add_maintenance', side_effect=lambda d:
            (d.p.DATA / 'climaintenance.html').write_bytes(tx.MAINTENANCE))
        maintenance.start()
        self.addCleanup(maintenance.stop)

    def run_update(self):
        with mock.patch.object(x.sys, 'stdout', new_callable=io.StringIO) as output:
            result = x.upgrade(self.d)
        self.assertNotIn('PRIVATE_SENTINEL', output.getvalue())
        return result

    def assert_pair_restored(self):
        self.assertEqual(self.before, {n: self.d.tx.inventory(self.d, t) for n, t in self.d.trees.items()})
        self.assertTrue(all(self.d.running.values()))

    def test_success_changes_only_addons_and_migrates_twice(self):
        self.assertEqual(self.run_update(), 0)
        self.assertEqual(self.d.attempt, 2)
        self.assertEqual((self.d.p.CODE / 'config.php').read_bytes(), self.config)
        self.assertEqual((self.d.p.CODE / 'core.txt').read_bytes(), b'core unchanged')
        self.assertFalse((self.d.p.DATA / 'climaintenance.html').exists())
        self.assertTrue(json.loads(self.d.marker.read_text())['public_released'])

    def test_first_and_repeat_upgrade_failure_restore_full_pair(self):
        self.d.fail_at = 1
        self.assertEqual(self.run_update(), 1)
        self.assert_pair_restored()
        self.d.fail_at = self.d.attempt + 2
        self.assertEqual(self.run_update(), 1)
        self.assert_pair_restored()

    def test_other_clients_refuse_before_snapshot_or_activation(self):
        db = dict(self.baseline, other_clients=1)
        (self.d.p.PGDATA / 'db.json').write_text(json.dumps(db))
        self.assertEqual(self.run_update(), 1)
        self.assertEqual(self.d.attempt, 0)
        self.assertEqual((self.d.web / 'local/ustar/version.php').read_bytes(), b'old')

    def test_candidate_or_baseline_drift_refused_before_stop(self):
        self.d.payload['local/ustar/version.php'] = b'bad'
        with self.assertRaises(Exception):
            x.upgrade(self.d)
        self.assertEqual(self.d.actions, [])

    def test_release_failure_does_not_restore_old_user_data(self):
        self.d.fail_login = True
        self.assertEqual(self.run_update(), 1)
        self.assertEqual((self.d.web / 'local/ustar/version.php').read_bytes(), b'new')
        with self.assertRaises(Exception):
            x.restore(self.d, discard=False)

    def test_explicit_late_restore_requires_consent_and_restores_pair(self):
        self.assertEqual(self.run_update(), 0)
        with self.assertRaises(Exception):
            x.restore(self.d, discard=False)
        x.restore(self.d, discard=True)
        self.assert_pair_restored()

    def test_tampered_pair_refuses_before_recovery_tree_moves(self):
        self.assertEqual(self.run_update(), 0)
        marker = json.loads(self.d.marker.read_text())
        pair = Path(marker['run_directory']) / 'baseline/code/config.php'
        pair.write_bytes(b'tampered')
        with self.assertRaises(Exception):
            x.restore(self.d, discard=True)
        self.assertEqual((self.d.web / 'local/ustar/version.php').read_bytes(), b'new')
        self.assertFalse(any(self.d.running.values()))

    def test_failed_stop_prevents_recovery_writes(self):
        self.assertEqual(self.run_update(), 0)
        self.d.cannot_stop = True
        with self.assertRaises(Exception):
            x.restore(self.d, discard=True)
        self.assertEqual((self.d.web / 'local/ustar/version.php').read_bytes(), b'new')

    def test_unrecognized_marker_path_refused(self):
        self.assertEqual(self.run_update(), 0)
        marker = json.loads(self.d.marker.read_text())
        marker['run_directory'] = str(self.root)
        self.d.marker.write_text(json.dumps(marker))
        with self.assertRaises(Exception):
            x.restore(self.d, discard=True)

    def test_interrupted_addon_activation_can_restore_without_version_file(self):
        self.assertEqual(self.run_update(), 0)
        (self.d.web / 'local/ustar/version.php').unlink()
        x.restore(self.d, discard=True)
        self.assert_pair_restored()

    def test_baseline_source_drift_refuses_without_container_actions(self):
        (self.d.web / 'local/ustar/version.php').write_bytes(b'drift')
        with self.assertRaisesRegex(ValueError, 'source_drift'):
            x.upgrade(self.d)
        self.assertEqual(self.d.actions, [])

    def test_hdd_failure_does_not_activate_or_stop_postgres(self):
        with mock.patch.object(x, 'fresh_hdd', side_effect=RuntimeError('PRIVATE_SENTINEL')):
            self.assertEqual(self.run_update(), 1)
        self.assert_pair_restored()
        self.assertEqual(self.d.attempt, 0)

    def test_insufficient_capacity_refuses_before_stop(self):
        with mock.patch.object(x, 'capacity', side_effect=ValueError('recovery_capacity')):
            with self.assertRaisesRegex(ValueError, 'recovery_capacity'):
                x.upgrade(self.d)
        self.assertEqual(self.d.actions, [])

    def test_interrupted_upgrade_restores_pair(self):
        with mock.patch.object(Host, 'cli_upgrade', side_effect=InterruptedError('PRIVATE_SENTINEL')):
            self.assertEqual(self.run_update(), 1)
        self.assert_pair_restored()

    def test_failed_clean_stop_does_not_capture_or_activate(self):
        self.d.bad_stop = True
        self.assertEqual(self.run_update(), 1)
        self.assert_pair_restored()
        self.assertFalse(list(self.base.glob('update-*/baseline')))

    def test_recovery_release_failure_cannot_silently_restore_again(self):
        self.d.fail_at = 1
        self.d.fail_login = True
        self.assertEqual(self.run_update(), 1)
        marker = json.loads(self.d.marker.read_text())
        self.assertTrue(marker['public_released'])
        with self.assertRaisesRegex(ValueError, 'late_restore'):
            x.restore(self.d, discard=False)

    def test_real_uid33_and_root_gid_activation_and_restore(self):
        if os.geteuid() != 0:
            self.skipTest('Actual UID33 preservation requires privileged CI')
        try:
            os.chown(self.d.web / 'local/ustar/version.php', 33, 0)
        except OSError:
            if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE') == '1':
                raise
            self.skipTest('Local namespace does not map UID33; mandatory privileged CI covers it')
        self.before = {n: self.d.tx.inventory(self.d, t) for n, t in self.d.trees.items()}
        self.assertEqual(self.run_update(), 0)
        info = (self.d.web / 'local/ustar/version.php').stat()
        self.assertEqual((33, 0), (info.st_uid, info.st_gid))
        x.restore(self.d, discard=True)
        self.assert_pair_restored()


class AdapterGuards(unittest.TestCase):
    def test_preconfigured_connect_expression_refused_without_php_evaluation(self):
        with self.assertRaisesRegex(ValueError, 'connect_configuration'):
            x.config_guard(b'<?php $CFG->ustar_connect_notifications_enabled = getenv("ENABLE");')
        x.config_guard(b'<?php $CFG->prefix = "mdl_";')

    def test_default_plan_does_not_load_dependencies(self):
        with mock.patch.object(x, 'dependencies', side_effect=AssertionError('host access')):
            self.assertEqual(x.main([]), 0)

    def test_upgrade_requires_explicit_window(self):
        with self.assertRaises(SystemExit):
            x.main(['--upgrade'])

    def test_all_existing_versions_and_custom_tasks_preserved(self):
        d = SimpleNamespace(reference={'candidate': {'versions': {'local_ustar': 2026101905}, 'tables': ['local_ustar_new']}})
        baseline = {'addons': {'local_ustar': '2026100204', 'other': '5'}, 'tasks': {'custom': [1, 1]}}
        current = {'core': x.CORE, 'addons': {'local_ustar': '2026101905', 'other': '6'},
            'tasks': {'custom': [1, 1], x.NEW_TASK: [0, 0]}, 'tables': ['local_ustar_new']}
        with self.assertRaisesRegex(ValueError, 'database_versions'):
            x.verify_versions(d, current, baseline, True)
        current['addons']['other'] = '5'
        current['tasks']['custom'] = [0, 0]
        with self.assertRaisesRegex(ValueError, 'scheduled_task_flags'):
            x.verify_versions(d, current, baseline, True)

    def test_pinned_hdd_helper_refused_without_command_execution(self):
        d = SimpleNamespace(w=SimpleNamespace(HELPERS={'hdd_backup': (Path('/fixed'), 'a' * 64)}),
            p=SimpleNamespace(root_input=lambda p: None, read_file=lambda *args: 'b' * 64))
        host = mock.Mock()
        with self.assertRaisesRegex(ValueError, 'hdd_helper_changed'):
            x.fresh_hdd(d, {}, host)
        host.action.assert_not_called()

    def test_candidate_columns_are_checked_against_frozen_xmldb(self):
        d = SimpleNamespace(reference={'candidate': {'versions': {'local_ustar': 2026101905},
            'tables': ['local_ustar_new']}}, schema_fields={'local_ustar_new': {
                'id': {'type': 'int', 'length': '10', 'notnull': True}}})
        baseline = {'addons': {'local_ustar': '2026100204'}, 'tasks': {}}
        current = {'core': x.CORE, 'addons': {'local_ustar': '2026101905'},
            'tasks': {x.NEW_TASK: [0, 0]}, 'tables': ['local_ustar_new'],
            'schema': [['local_ustar_new', 'id', 'bigint', 'int8', None, 64, 0, 'NO', 'sequence']]}
        x.verify_versions(d, current, baseline, True)
        current['schema'][0][7] = 'YES'
        with self.assertRaisesRegex(ValueError, 'schema_nullability'):
            x.verify_versions(d, current, baseline, True)
        current['schema'] = []
        with self.assertRaisesRegex(ValueError, 'schema_column_inventory'):
            x.verify_versions(d, current, baseline, True)


if __name__ == '__main__':
    unittest.main()
