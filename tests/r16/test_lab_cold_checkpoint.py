"""Real filesystem copies with portable fixture owners; Docker/PHP/systemd mocked."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
from types import SimpleNamespace
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('cold_checkpoint_tested', REPO / 'scripts/infra/lab_cold_checkpoint.py')
cold = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(cold)


class FilePolicy:
    """Real type/mode/link checks; fixture UID portability only. Production uses pinned preflight."""
    def __init__(self, source):
        self.ROOT = source

    def trusted(self, path, directory=False, root_owned=True, private=False):
        info = path.lstat()
        cold.require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                     and not info.st_mode & (0o077 if private else 0o022)
                     and (directory or info.st_nlink == 1), 'Fixture type/mode/link guard')
        return info

    def moodledata_entry(self, path, directory=False):
        info = path.lstat()
        mode = stat.S_IMODE(info.st_mode)
        cold.require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                     and (not mode & 0o7022 if directory else not mode & ~0o666)
                     and (directory or info.st_nlink == 1), 'Fixture data guard')
        return info

    @staticmethod
    def file_hash(path):
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def readonly_database(self, lab, state, profile):
        lab.sql(state, 'BEGIN READ ONLY; SELECT fixture; COMMIT;')


class FakeLab:
    PRODUCTION = ('ustar_moodle', 'ustar_postgres')
    def __init__(self, state, stage=False):
        self.state = state
        self.stage = stage
        self.NAMES = dict(cold.NAMES) if stage else {'pg': 'source_lab_pg', 'web': 'source_lab_web'}
        self.ROOT = cold.WORK if stage else cold.SOURCE
        self.running = {'pg': True, 'web': True}
        self.actions = []
        self.dirty_stop = False
        self.bootstrap_failure = False
        self.production_drift = False
        self._LOG = None

    def owned(self, kind, state):
        self.actions.append(('owned', kind))
        return {'Id': state['containers'][kind], 'State': {'Running': self.running[kind],
            'ExitCode': 137 if self.dirty_stop else 0, 'OOMKilled': False, 'Error': ''}}

    def command(self, argv, **kw):
        self.actions.append(('command', argv))
        if argv[:2] in (['docker', 'stop'], ['docker', 'start']):
            kind = next(k for k, value in self.state['containers'].items() if value == argv[-1])
            self.running[kind] = argv[1] == 'start'
        return SimpleNamespace(stdout=b'', returncode=0)

    def production_state(self):
        return {'production': 'changed' if self.production_drift else 'unchanged'}

    def assert_offline(self, state):
        self.actions.append(('offline',))

    def load_state(self):
        return self.state

    def write_private(self, path, data):
        if isinstance(data, str):
            data = data.encode()
        with path.open('xb') as out:
            out.write(data)
        path.chmod(0o600)

    def save_state(self, state):
        self.state = state
        path = self.ROOT / 'state.json'
        path.write_text(json.dumps(state))
        path.chmod(0o600)

    def create_container(self, kind, state):
        self.actions.append(('create', kind))
        state['containers'][kind] = 'stage-' + kind
        self.state = state

    def sql(self, state, query):
        if not query.startswith('BEGIN READ ONLY;'):
            raise AssertionError('Unexpected SQL write')
        self.actions.append(('readonly_sql',))
        return '0'

    def check_file_references(self, state):
        self.actions.append(('file_references',))
        return 1

    def lab_exec(self, kind, state, argv, user=None):
        self.actions.append(('lab_exec', kind, argv, user))
        if kind != 'web' or user != '33:33' or argv[:2] not in (['php', '-l'], ['php', '-r']):
            raise AssertionError('Unexpected exec')
        data = {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab', 'wwwroot': 'http://127.0.0.1:18085',
                'noemail': True, 'cron': 0, 'needs_upgrade': self.bootstrap_failure}
        return SimpleNamespace(stdout=json.dumps(data).encode())

    def start_relay(self, state):
        self.actions.append(('relay',))

    def stop_lab(self, state):
        self.actions.append(('cleanup_stage_only',))
        self.running = {'pg': False, 'web': False}


class CheckpointTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.source = Path(self.tmp.name) / 'source'
        self.source.mkdir(mode=0o700)
        patches = [mock.patch.object(cold, 'SOURCE', self.source),
                   mock.patch.object(cold, 'CHECKPOINT', self.source / 'rollback'),
                   mock.patch.object(cold, 'WORK', self.source / 'workspace'),
                   mock.patch.object(cold, 'reserve')]
        for patch in patches:
            patch.start()
            self.addCleanup(patch.stop)
        for name in ('site', 'site/public', 'site/public/public', 'site/moodledata',
                     'site/moodledata/filedir', 'postgres', 'postgres/global'):
            (self.source / name).mkdir(mode=0o700)
        for name, data in {'site/public/config.php': "$CFG->wwwroot = 'http://127.0.0.1:18084';\n"
                "$CFG->sessioncookie = 'USTAR_RECOVERY_LAB_20261004';\nprivate-secret\n",
                'site/public/public/index.php': 'USTAR redirect',
                'site/moodledata/filedir/private-content': 'private content',
                'postgres/global/pg_control': 'control fixture', 'postgres/PG_VERSION': '16\n',
                **{name: 'private input' for name in cold.CONTROLS}}.items():
            path = self.source / name
            path.write_text(data)
            path.chmod(0o600)
        (self.source / 'site/moodledata/filedir/private-content').chmod(0o666)
        self.p = FilePolicy(self.source)
        self.state = {'ready': True, 'stopped': False, 'containers': {'pg': 'source-pg', 'web': 'source-web'},
                      'token': 'old-private-token', 'images': {'pg': 'pg-image', 'web': 'web-image'}}
        self.source_lab = FakeLab(self.state)
        self.resume = SimpleNamespace(wait_database=lambda *_: None, check_database=lambda *_: None)
        self.profile = {'core_db_version': '2025100601.03', 'application_files': {'file': 'hash'},
                        'additional_components': [{'component': 'fixture'}]}

    def checkpoint(self):
        return cold.cold_checkpoint(self.p, self.resume, self.source_lab, self.state,
                                    self.source_lab.production_state())

    def test_cold_pair_verified_and_same_source_containers_restarted(self):
        before = {p.relative_to(self.source): p.read_bytes() for p in self.source.rglob('*') if p.is_file()}
        manifest = self.checkpoint()
        self.assertTrue(manifest['complete'])
        self.assertTrue(all(self.source_lab.running.values()))
        commands = [a[1] for a in self.source_lab.actions if a[0] == 'command']
        self.assertEqual([c[1] for c in commands], ['stop', 'stop', 'start', 'start'])
        self.assertEqual([c[-1] for c in commands], ['source-web', 'source-pg', 'source-pg', 'source-web'])
        for path, content in before.items():
            self.assertEqual((self.source / path).read_bytes(), content)
        self.assertEqual(cold.verify_checkpoint(self.p), manifest)
        self.assertNotIn('private-secret', json.dumps(manifest))

    def test_cold_copy_failure_still_restarts_source_and_accepts_no_manifest(self):
        with mock.patch.object(cold, 'copy_source', side_effect=cold.CheckpointError('copy failed')):
            with self.assertRaisesRegex(cold.CheckpointError, 'copy failed'):
                self.checkpoint()
        self.assertTrue(all(self.source_lab.running.values()))
        self.assertFalse((cold.CHECKPOINT / 'manifest.json').exists())

    def test_unclean_stop_or_pid_file_refuses_copy_and_restarts_source(self):
        self.source_lab.dirty_stop = True
        with self.assertRaisesRegex(cold.CheckpointError, 'stop cleanly'):
            self.checkpoint()
        self.source_lab.dirty_stop = False
        (self.source / 'postgres/postmaster.pid').write_text('live')
        with self.assertRaisesRegex(cold.CheckpointError, 'shutdown marker'):
            self.checkpoint()
        self.assertTrue(all(self.source_lab.running.values()))
        self.assertFalse(cold.CHECKPOINT.exists())

    def test_source_restart_failure_reports_recovery_mode(self):
        self.source_lab.running = {'pg': False, 'web': False}
        with mock.patch.object(self.source_lab, 'command', side_effect=RuntimeError('fixture')):
            with self.assertRaisesRegex(cold.CheckpointError, 'resume-source'):
                cold.restart_source(self.resume, self.source_lab, self.state)

    def test_modified_missing_added_symlink_hardlink_copy_entries_refused(self):
        self.checkpoint()
        path = cold.CHECKPOINT / 'site/moodledata/filedir/private-content'
        old = path.read_bytes()
        for action in ('modify', 'remove', 'symlink', 'hardlink', 'add'):
            with self.subTest(action=action):
                if action == 'modify':
                    path.write_text('changed')
                elif action == 'add':
                    (cold.CHECKPOINT / 'unexpected').write_text('extra')
                else:
                    path.unlink()
                    if action == 'symlink':
                        path.symlink_to(self.source / 'site/moodledata/filedir/private-content')
                    elif action == 'hardlink':
                        os.link(self.source / 'site/moodledata/filedir/private-content', path)
                with self.assertRaises(cold.CheckpointError):
                    cold.verify_checkpoint(self.p)
                if action == 'add':
                    (cold.CHECKPOINT / 'unexpected').unlink()
                else:
                    if path.exists() or path.is_symlink():
                        path.unlink()
                    path.write_bytes(old)
                    path.chmod(0o666)

    def test_workspace_copy_has_no_shared_inodes_and_checkpoint_survives_mutation(self):
        manifest = self.checkpoint()
        cold.copy_workspace(self.p, manifest)
        for key, value in manifest['entries'].items():
            if value['type'] == 'file':
                self.assertNotEqual((cold.CHECKPOINT / key).stat().st_ino, (cold.WORK / key).stat().st_ino)
        (cold.WORK / 'postgres/global/pg_control').write_text('workspace modified')
        cold.verify_checkpoint(self.p)

    def test_source_links_special_files_and_writable_code_are_not_copied(self):
        path = self.source / 'site/public/public/unsafe'
        for action in ('symlink', 'fifo', 'writable'):
            with self.subTest(action=action):
                if action == 'symlink':
                    path.symlink_to(self.source.parent)
                elif action == 'fifo':
                    os.mkfifo(path, 0o600)
                else:
                    path.write_text('data')
                    path.chmod(0o666)
                with self.assertRaises(cold.CheckpointError):
                    self.checkpoint()
                path.unlink()
                if cold.CHECKPOINT.exists():
                    shutil.rmtree(cold.CHECKPOINT)  # Test-owned fixture cleanup only.
        self.assertTrue(all(self.source_lab.running.values()))

    def test_copy_does_not_overwrite_existing_target_or_follow_source_symlink(self):
        src = self.source / 'site/public/public/index.php'
        target = self.source / 'target'
        target.write_text('keep')
        with self.assertRaises(FileExistsError):
            cold.copy_file(src, target, src.lstat())
        self.assertEqual(target.read_text(), 'keep')
        target.unlink()
        fake = src.lstat()
        src.unlink()
        src.symlink_to(self.source / 'postgres/PG_VERSION')
        with self.assertRaises(OSError):
            cold.copy_file(src, target, fake)
        self.assertFalse(target.exists())

    def test_copy_detects_source_size_or_identity_change(self):
        src = self.source / 'site/public/public/index.php'
        info = src.lstat()
        src.write_text('a different size')
        with self.assertRaisesRegex(cold.CheckpointError, 'before copy'):
            cold.copy_file(src, self.source / 'target', info)

    def test_budget_accounts_for_second_full_copy_and_keeps_reserve(self):
        plan = {'storage_plan': {'required_free_bytes': 8 * cold.GIB, 'rollback_copy_allowance_bytes': 2 * cold.GIB,
                                'required_free_inodes': 100},
                'sizes_live_non_atomic': {'code': {'files': 10, 'directories': 2}}}
        with mock.patch.object(cold.shutil, 'disk_usage', return_value=SimpleNamespace(free=9 * cold.GIB)):
            with self.assertRaisesRegex(cold.CheckpointError, 'Insufficient space'):
                cold.workspace_budget(plan)
        with mock.patch.object(cold.shutil, 'disk_usage', return_value=SimpleNamespace(free=11 * cold.GIB)):
            result = cold.workspace_budget(plan)
        self.assertEqual(result['required_free_bytes'], 10 * cold.GIB)
        self.assertEqual(result['minimum_free_after_bytes'], 4 * cold.GIB)

    def test_manifest_path_injection_is_refused(self):
        for name in ('../config.php', '/etc/passwd', 'site/../private', 'site//public', 'site\\public'):
            with self.subTest(name=name), self.assertRaises(cold.CheckpointError):
                cold.relative(name)

    def test_stage_config_changes_only_workspace_url_and_cookie(self):
        manifest = self.checkpoint()
        cold.copy_workspace(self.p, manifest)
        original = (cold.CHECKPOINT / 'site/public/config.php').read_text()
        lab = FakeLab(self.state, stage=True)
        with mock.patch.object(cold.os, 'chown') as chown:
            cold.stage_config(self.p, lab)
        self.assertEqual((cold.CHECKPOINT / 'site/public/config.php').read_text(), original)
        text = (cold.WORK / 'site/public/config.php').read_text()
        self.assertIn('http://127.0.0.1:18085', text)
        self.assertIn('USTAR_PATCH_STAGE_20261005', text)
        self.assertIn('private-secret', text)
        chown.assert_called_once_with(cold.WORK / 'stage-config.tmp', 0, 33)

    def test_stage_success_is_separate_and_redacted_and_leaves_checkpoint_intact(self):
        manifest = self.checkpoint()
        cold.copy_workspace(self.p, manifest)
        stage = FakeLab(self.state, stage=True)
        with mock.patch.object(cold, 'http_check', return_value=200):
            report = cold.start_workspace(self.p, self.resume, stage, self.state, self.profile,
                                          self.source_lab.production_state(), manifest, {})
        self.assertTrue(stage.state['ready'])
        self.assertNotEqual(stage.state['token'], self.state['token'])
        self.assertEqual(self.state['containers'], {'pg': 'source-pg', 'web': 'source-web'})
        self.assertFalse(report['core_upgrade_executed'])
        self.assertFalse(report['patch_upgrade_rollback_verified'])
        self.assertNotIn('private', json.dumps(report))
        cold.verify_checkpoint(self.p)

    def test_stage_bootstrap_http_or_production_failure_cleans_up_only_stage(self):
        manifest = self.checkpoint()
        cold.copy_workspace(self.p, manifest)
        for failure in ('bootstrap', 'http', 'production'):
            stage = FakeLab(self.state, stage=True)
            stage.bootstrap_failure = failure == 'bootstrap'
            stage.production_drift = failure == 'production'
            with self.subTest(failure=failure), mock.patch.object(cold, 'http_check',
                    side_effect=RuntimeError('HTTP fixture') if failure == 'http' else None, return_value=200):
                with self.assertRaises((cold.CheckpointError, RuntimeError)):
                    cold.start_workspace(self.p, self.resume, stage, self.state, self.profile,
                                         self.source_lab.production_state(), manifest, {})
            self.assertIn(('cleanup_stage_only',), stage.actions)
            self.assertTrue(all(self.source_lab.running.values()))
            cold.verify_checkpoint(self.p)
            (cold.WORK / 'prepare-private.log').unlink()

    def test_default_plan_does_not_load_private_dependencies(self):
        with mock.patch.object(cold.sys, 'argv', ['helper']), mock.patch.object(cold, 'load_preflight') as loader, \
                contextlib.redirect_stdout(io.StringIO()):
            cold.main()
        loader.assert_not_called()

    def test_late_report_failure_stops_only_owned_stage_and_preserves_checkpoint(self):
        manifest = self.checkpoint()
        cold.copy_workspace(self.p, manifest)
        stage = FakeLab(self.state, stage=True)
        stage.state = dict(self.state, containers={'pg': 'stage-pg', 'web': 'stage-web'})
        with mock.patch.object(cold, 'precheck', return_value=({}, {})), \
                mock.patch.object(cold, 'cold_checkpoint', return_value=manifest), \
                mock.patch.object(cold, 'copy_workspace'), mock.patch.object(cold, 'stage_config'), \
                mock.patch.object(cold, 'start_workspace', return_value={}), \
                mock.patch.object(stage, 'write_private', side_effect=OSError('disk fixture')):
            with self.assertRaises(OSError):
                cold.prepare(self.p, self.resume, self.source_lab, '', self.profile, stage)
        self.assertIn(('cleanup_stage_only',), stage.actions)
        self.assertTrue(all(self.source_lab.running.values()))
        cold.verify_checkpoint(self.p)

    def test_engine_adapter_changes_only_a_separate_module_namespace(self):
        source = SimpleNamespace(ROOT=cold.SOURCE, NAMES={'pg': 'source_pg', 'web': 'source_web'})
        independent = SimpleNamespace(PRODUCTION=('ustar_moodle', 'ustar_postgres'))
        resume = SimpleNamespace(load_engine=lambda: (independent, 'private-template'))
        stage = cold.stage_engine(resume)
        self.assertEqual(stage.ROOT, cold.WORK)
        self.assertEqual(stage.STATE, cold.WORK / 'state.json')
        self.assertEqual(stage.SCRIPT, cold.SCRIPT)
        self.assertEqual(stage.NAMES, cold.NAMES)
        self.assertEqual((stage.PORT, stage.UNIT, stage.LABEL), (18085, cold.UNIT, cold.LABEL))
        self.assertEqual(source.ROOT, cold.SOURCE)
        self.assertNotEqual(source.NAMES, stage.NAMES)

    def test_pinned_dependency_tamper_prevents_execution(self):
        path = self.source / 'dependency.py'
        path.write_text('raise RuntimeError("must not execute")')
        with mock.patch.object(cold, 'PREFLIGHT', path), mock.patch.object(cold, 'installed'):
            with self.assertRaisesRegex(cold.CheckpointError, 'checksum'):
                cold.load_preflight()


if __name__ == '__main__':
    unittest.main()
