"""Portable offline safety tests; no real Docker, PHP, SQL or server access."""
import contextlib
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
from types import SimpleNamespace
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('patch_preflight_tested', REPO / 'scripts/infra/lab_patch_preflight.py')
preflight = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(preflight)


class FakeLab:
    NAMES = {'pg': 'ustar_recovery_lab_20261004_pg', 'web': 'ustar_recovery_lab_20261004_web'}
    PRODUCTION = ('ustar_moodle', 'ustar_postgres')

    def __init__(self, state, profile):
        self.state, self.profile = state, profile
        self.actions = []
        self.offline = True
        self.mounts_match = True
        self.enabled = 0
        self.db_version = profile['core_db_version']
        self.versions = {c['component']: str(c['disk_version']) for c in profile['additional_components']}
        self.production_reads = 0
        self.production_drift = False
        self.php_version = '8.3.33'

    def load_state(self):
        self.actions.append('load_state')
        return self.state

    def production_state(self):
        self.actions.append('production_inspect')
        self.production_reads += 1
        return {'id': 'production', 'start': 'changed' if self.production_drift and self.production_reads > 1 else 'same'}

    def owned(self, kind, _):
        self.actions.append('owned_' + kind)
        if not self.mounts_match:
            raise preflight.PreflightError('Lab mount boundary mismatch')
        return {'State': {'Running': True}}

    def assert_offline(self, _):
        self.actions.append('namespace_inspect')
        preflight.require(self.offline, 'Lab has an external interface')

    def strip_php_comments(self, text):
        return text

    def sql(self, _, query):
        if not query.startswith('BEGIN READ ONLY;') or any(s in query for s in ('UPDATE ', 'DELETE ', 'DROP ', 'INSERT ', 'CREATE ')):
            raise AssertionError('Unexpected SQL write')
        if "statement_timeout='15s'" not in query or "lock_timeout='3s'" not in query:
            raise AssertionError('Unbounded read')
        self.actions.append('lab_readonly_sql')
        return json.dumps({'core': self.db_version, 'enabled_tasks': self.enabled, 'versions': self.versions})

    def lab_exec(self, kind, _, argv, user=None):
        if kind != 'web' or argv[:2] != ['php', '-r'] or user != '33:33' or any('require' in a for a in argv):
            raise AssertionError('Unexpected lab or production exec/bootstrap')
        self.actions.append('lab_php_cli_settings')
        return SimpleNamespace(stdout=json.dumps({'version': self.php_version, 'sapi': 'cli',
            'max_input_vars': '1000', 'soap': False, 'exif': False}).encode())


class PatchPlanningTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'retained'
        self.root.mkdir(mode=0o700)
        self.code = self.root / 'site/public'
        self.code.mkdir(parents=True)
        for path in ('public/local/ustar', 'public/theme/ustar', 'public/blocks/rbreport'):
            (self.code / path).mkdir(parents=True)
        for parent in ('site/moodledata/filedir', 'postgres/base', 'postgres/global', 'postgres/pg_wal'):
            (self.root / parent).mkdir(parents=True, mode=0o700)
        (self.root / 'site/moodledata').chmod(0o700)
        def write(path, content):
            path.write_text(content)
            path.chmod(0o600)
        write(self.code / 'public/local/ustar/version.php', '<?php\n$plugin->version = 2026100204;\n$plugin->requires = 1;\n')
        write(self.code / 'public/theme/ustar/version.php', '<?php\n$plugin->version = 2026100202;\n$plugin->requires = 1.00;\n')
        write(self.code / 'public/blocks/rbreport/version.php', '<?php\n$plugin->version = 2024010100;\n')
        write(self.code / 'public/version.php', 'baseline-core-metadata')
        write(self.code / 'public/index.php', 'baseline-ustar-entry')
        write(self.code / 'config.php', 'exact offline config including private secret')
        write(self.root / 'postgres/PG_VERSION', '16\n')
        write(self.root / 'site/moodledata/filedir/content', 'file fixture')
        self.state = {'ready': True, 'stopped': False, 'token': 'private-token-not-in-report',
            'archive_sha256': 'b' * 64, 'containers': {'web': 'lab-web', 'pg': 'lab-pg'},
            'images': {'web': 'sha256:' + 'c' * 64, 'pg': 'sha256:' + 'd' * 64},
            'preflight': {'images': {'ustar_moodle': {'id': 'sha256:' + 'c' * 64},
                                     'ustar_postgres': {'id': 'sha256:' + 'd' * 64}}}}
        for name in ('state.json', 'pg.env', 'lab.ini', 'apache.conf'):
            write(self.root / name, 'private-test-input')
        write(self.root / 'report.json', json.dumps({'snapshot_sha256': self.state['archive_sha256'],
            'database_restore': 'PASS', 'moodle_bootstrap': 'PASS'}))
        files = {p.relative_to(self.code).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
                 for parent in ('public/local/ustar', 'public/theme/ustar') for p in (self.code / parent).rglob('*') if p.is_file()}
        self.profile = {'core_metadata_sha256': {'public/version.php': preflight.file_hash(self.code / 'public/version.php')},
            'index_sha256': preflight.file_hash(self.code / 'public/index.php'), 'application_files': files,
            'additional_roots': ['public/local/ustar', 'public/theme/ustar', 'public/blocks/rbreport'],
            'additional_components': [
                {'component': 'local_ustar', 'disk_version': 2026100204, 'version_file': 'local/ustar/version.php', 'requires_literal': 1},
                {'component': 'theme_ustar', 'disk_version': 2026100202, 'version_file': 'theme/ustar/version.php', 'requires_literal': 1},
                {'component': 'block_rbreport', 'disk_version': 2024010100, 'version_file': 'blocks/rbreport/version.php', 'requires_literal': None}],
            'core_db_version': '2025100601.03', 'patch_runtime': {'php_version': '8.3.33', 'moodle_tag': 'v5.1.8'},
            'coverage': 'synthetic test reference'}
        self.lab = FakeLab(self.state, self.profile)
        self.resume = SimpleNamespace(check_config=self.config_check)
        patches = [mock.patch.object(preflight, 'ROOT', self.root),
                   mock.patch.object(preflight, 'trust_parents'),
                   mock.patch.object(preflight.shutil, 'disk_usage', return_value=SimpleNamespace(free=12 * preflight.GIB))]
        original = preflight.trusted
        self.original_trusted = original
        patches.append(mock.patch.object(preflight, 'trusted', side_effect=lambda p, **kw:
            original(p, **{**kw, 'root_owned': False})))  # Portable fixture UID only; real permissions/links checked.
        for patch in patches:
            patch.start()
            self.addCleanup(patch.stop)

    def config_check(self, *_):
        preflight.require((self.code / 'config.php').read_text() == 'exact offline config including private secret',
                          'Offline configuration changed')

    def check(self):
        return preflight.check(self.resume, self.lab, None, self.profile)

    def test_success_is_readonly_and_sanitized_with_no_backup_or_upgrade(self):
        before = {p.relative_to(self.root): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        result = self.check()
        after = {p.relative_to(self.root): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        self.assertEqual(before, after)
        self.assertTrue(result['read_only'])
        self.assertFalse(result['core_upgrade_executed'])
        self.assertFalse(result['rollback_created_or_verified'])
        self.assertFalse(result['full_addon_compatibility_verified'])
        self.assertEqual(result['addon_requires_nonliteral'], ['block_rbreport'])
        text = json.dumps(result)
        self.assertNotIn(self.state['token'], text)
        self.assertNotIn('private secret', text)
        self.assertEqual(self.lab.actions.count('lab_readonly_sql'), 1)
        self.assertEqual(self.lab.actions.count('lab_php_cli_settings'), 1)

    def test_stopped_or_incomplete_lab_refused_before_sql(self):
        self.state['stopped'] = True
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.state['stopped'] = False
        self.state['containers'].pop('web')
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.assertNotIn('lab_readonly_sql', self.lab.actions)

    def test_network_or_mount_drift_refused_before_sql(self):
        self.lab.offline = False
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.lab.offline = True
        self.lab.mounts_match = False
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.assertNotIn('lab_readonly_sql', self.lab.actions)

    def test_config_and_image_drift_refused_without_bootstrap(self):
        (self.code / 'config.php').write_text('dangerous extra PHP')
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.state['images']['web'] = 'sha256:' + 'f' * 64
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.assertNotIn('lab_php_cli_settings', self.lab.actions)

    def test_core_or_application_drift_refused(self):
        p = self.code / 'public/version.php'
        old = p.read_bytes()
        p.write_text('changed core')
        with self.assertRaises(preflight.PreflightError):
            self.check()
        p.write_bytes(old)
        (self.code / 'public/local/ustar/version.php').write_text('changed USTAR')
        with self.assertRaises(preflight.PreflightError):
            self.check()

    def test_added_application_file_or_nonliteral_addon_metadata_refused(self):
        p = self.code / 'public/local/ustar/extra.php'
        p.write_text('extra')
        p.chmod(0o600)
        with self.assertRaises(preflight.PreflightError):
            self.check()
        p.unlink()
        (self.code / 'public/blocks/rbreport/version.php').write_text('<?php $plugin->version = calculate_version();')
        with self.assertRaises(preflight.PreflightError):
            self.check()

    def test_symlink_hardlink_and_writable_code_refused(self):
        p = self.root / 'postgres/escape'
        p.symlink_to(self.root.parent)
        with self.assertRaises(preflight.PreflightError):
            self.check()
        p.unlink()
        os.link(self.code / 'public/index.php', p)
        with self.assertRaises(preflight.PreflightError):
            self.check()
        p.unlink()
        (self.code / 'public/index.php').chmod(0o666)
        with self.assertRaises(preflight.PreflightError):
            self.check()

    def test_space_or_inode_shortage_refused_before_sql(self):
        with mock.patch.object(preflight.shutil, 'disk_usage', return_value=SimpleNamespace(free=4 * preflight.GIB)):
            with self.assertRaises(preflight.PreflightError):
                self.check()
        with mock.patch.object(preflight.os, 'statvfs', return_value=SimpleNamespace(f_frsize=4096, f_favail=5)):
            with self.assertRaises(preflight.PreflightError):
                self.check()
        self.assertNotIn('lab_readonly_sql', self.lab.actions)

    def test_refusal_identifies_control_scope_without_reading_private_content(self):
        path = self.root / 'state.json'
        path.write_text('secret-control-content')
        path.chmod(0o644)
        with self.assertRaises(preflight.PreflightError) as error:
            preflight.trusted(path, private=True)
        message = str(error.exception)
        self.assertIn('scope=retained_state_json', message)
        self.assertIn('failed=permissions', message)
        self.assertIn('mode=0644', message)
        self.assertNotIn('secret-control-content', message)
        self.assertNotIn(str(self.tmp.name), message)

    def test_runtime_entry_names_and_symlink_targets_are_redacted_on_refusal(self):
        path = self.root / 'site/moodledata/filedir/private-session-token'
        path.write_text('private-file-content')
        path.chmod(0o666)
        with self.assertRaises(preflight.PreflightError) as error:
            self.check()
        self.assertIn('scope=moodledata_tree_entry', str(error.exception))
        self.assertIn('failed=permissions', str(error.exception))
        for secret in ('private-session-token', 'private-file-content', str(self.root)):
            self.assertNotIn(secret, str(error.exception))
        path.unlink()
        path.symlink_to(self.root.parent / 'private-target-name')
        with self.assertRaises(preflight.PreflightError) as error:
            self.check()
        self.assertIn('failed=entry_type', str(error.exception))
        self.assertIn('type=l', str(error.exception))
        self.assertNotIn('private-target-name', str(error.exception))
        self.assertNotIn('lab_readonly_sql', self.lab.actions)

    def test_hardlink_refusal_reports_metadata_without_disclosing_entry_names(self):
        path = self.root / 'postgres/private-database-entry'
        os.link(self.code / 'public/index.php', path)
        with self.assertRaises(preflight.PreflightError) as error:
            self.check()
        message = str(error.exception)
        self.assertIn('failed=hardlinks', message)
        self.assertIn('links=2', message)
        self.assertNotIn('private-database-entry', message)

    def test_wrong_root_owner_remains_refused_with_diagnostic_metadata(self):
        info = SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=999, st_gid=999, st_nlink=1)
        # Patch the underlying stat only; do not use the portable fixture's owner override.
        with mock.patch.object(Path, 'lstat', return_value=info):
            with self.assertRaises(preflight.PreflightError) as error:
                self.original_trusted(self.root / 'state.json')
        self.assertIn('failed=root_owner', str(error.exception))
        self.assertIn('uid=999; gid=999', str(error.exception))

    def test_db_version_tasks_or_addon_drift_refused(self):
        for field, value in [('db_version', '2025100608.00'), ('enabled', 1), ('versions', {})]:
            with self.subTest(field=field):
                old = getattr(self.lab, field)
                setattr(self.lab, field, value)
                with self.assertRaises(preflight.PreflightError):
                    self.check()
                setattr(self.lab, field, old)

    def test_php_or_production_drift_refused(self):
        self.lab.php_version = '8.2.30'
        with self.assertRaises(preflight.PreflightError):
            self.check()
        self.lab.php_version = '8.3.33'
        self.lab.production_reads = 0
        self.lab.production_drift = True
        with self.assertRaises(preflight.PreflightError):
            self.check()

    def test_sparse_or_dense_copy_budget_uses_logical_size(self):
        trees = {name: {'copy_budget_bytes': size, 'files': 5, 'directories': 2}
                 for name, size in [('code', 2 * preflight.GIB), ('moodledata', preflight.GIB), ('postgres', preflight.GIB)]}
        with self.assertRaises(preflight.PreflightError):
            preflight.budget(trees, 12 * preflight.GIB, 100000)

    def test_invalid_component_query_name_and_reference_path_refused(self):
        self.profile['additional_components'][0]['component'] = "local_x'); DROP TABLE mdl_user; --"
        with self.assertRaises(preflight.PreflightError):
            preflight.readonly_database(self.lab, self.state, self.profile)
        for path in ('../config.php', '/etc/passwd', 'public//version.php', 'public/../../etc', 'public\\config.php'):
            with self.subTest(path=path), self.assertRaises(preflight.PreflightError):
                preflight.relative_path(path)

    def test_main_opens_existing_lock_readonly_and_honors_exclusive_lock(self):
        lock = self.root / 'coordination.lock'
        lock.touch(mode=0o600)
        real_open, real_fstat = os.open, os.fstat
        opened = []
        def read_open(path, flags, *args):
            opened.append(flags)
            return real_open(path, flags, *args)
        def fstat(fd):
            info = real_fstat(fd)
            return SimpleNamespace(st_mode=info.st_mode, st_uid=0, st_nlink=info.st_nlink)
        with mock.patch.object(preflight, 'LOCK', lock), mock.patch.object(preflight, 'load_profile', return_value=self.profile), \
                mock.patch.object(preflight, 'load_resume', return_value=(self.resume, self.lab, None)), \
                mock.patch.object(preflight.os, 'geteuid', return_value=0), mock.patch.object(preflight.os, 'open', side_effect=read_open), \
                mock.patch.object(preflight.os, 'fstat', side_effect=fstat), mock.patch.object(preflight.sys, 'argv', ['helper', '--check']):
            with contextlib.redirect_stdout(io.StringIO()):
                preflight.main()
            self.assertTrue(all(not f & (os.O_CREAT | os.O_WRONLY | os.O_RDWR) for f in opened))
            with lock.open('rb') as held:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaises(BlockingIOError):
                    preflight.main()

    def test_default_plan_does_not_load_private_dependencies(self):
        with mock.patch.object(preflight.sys, 'argv', ['helper']), \
                mock.patch.object(preflight, 'load_resume') as loader, contextlib.redirect_stdout(io.StringIO()):
            preflight.main()
            loader.assert_not_called()

    def test_published_profile_hash_and_tamper_guard(self):
        path = self.root / 'planning-reference.json'
        path.write_bytes((REPO / 'scripts/infra/lab_patch_profile_20261005.json').read_bytes())
        path.chmod(0o600)
        with mock.patch.object(preflight, 'PROFILE', path):
            profile = preflight.load_profile()
            self.assertEqual(len(profile['application_files']), 526)
            self.assertEqual(len(profile['additional_components']), 57)
            self.assertEqual(profile['patch_ci']['run'], 37311910916)
            path.write_bytes(path.read_bytes() + b' ')
            with self.assertRaises(preflight.PreflightError):
                preflight.load_profile()


if __name__ == '__main__':
    unittest.main()
