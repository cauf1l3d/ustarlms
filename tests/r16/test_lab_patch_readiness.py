"""Accepted-file tamper, namespace, runtime and read-only guard tests.

Actual candidate builder filesystem fixtures; lab exec/SQL are bounded fake
adapters. No Docker daemon, private server input or Moodle bootstrap is run.
"""
import contextlib
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('patch_readiness_tested', REPO / 'scripts/infra/lab_patch_readiness.py')
r = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(r)
FIXTURE = importlib.util.spec_from_file_location('readiness_builder_fixture', REPO / 'tests/r16/test_lab_patch_candidate.py')
f = importlib.util.module_from_spec(FIXTURE)
FIXTURE.loader.exec_module(f)
c = f.c


class ReadinessTests(unittest.TestCase):
    def setUp(self):
        fixture = f.CandidateTests('runTest')
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        self.fixture = fixture
        fixture.stock['composer.json'] = json.dumps({'require': {'php': '>=8.2.0', 'ext-json': '*'}}).encode()
        fixture.stock['public/admin/environment.xml'] = (b'<COMPATIBILITY_MATRIX><MOODLE version="5.1">'
            b'<PHP version="8.2.0"/><DATABASE><VENDOR name="postgres" version="15"/></DATABASE>'
            b'<PHP_EXTENSIONS><PHP_EXTENSION name="filter" level="required"/>'
            b'<PHP_EXTENSION name="soap" level="optional"/></PHP_EXTENSIONS></MOODLE></COMPATIBILITY_MATRIX>')
        c.CORE_HASHES['composer.json'] = f.sha(fixture.stock['composer.json'])
        fixture.make_archive(fixture.stock)
        fixture.profile['patch_runtime'] = {'php_version': '8.3.33', 'postgres_version_num': '160015'}
        fixture.profile['core_db_version'] = '2025100601.03'
        for item, name in zip(fixture.profile['additional_components'], ('local_ustar', 'block_fixture')):
            item['component'] = name
        self.sql_calls = []
        self.php_calls = []
        self.php = {'version': '8.3.33', 'sapi': 'cli', 'int_size': 8,
                    'extensions': ['JSON', 'filter', 'pgsql'], 'memory_limit': '512M',
                    'max_input_vars': '5000', 'sendmail_path': '/bin/false'}
        self.pg = '160015'
        def sql(state, query):
            self.sql_calls.append(query)
            return self.pg
        def php(kind, state, argv, user):
            self.php_calls.append((kind, argv, user))
            return SimpleNamespace(stdout=json.dumps(self.php))
        self.stage = fixture.stage
        self.stage.sql = sql
        self.stage.lab_exec = php
        def readonly_database(stage, state, profile):
            self.assertIs(stage, self.stage)
            self.sql_calls.append('BEGIN READ ONLY; SET LOCAL statement_timeout=\'15s\'; version/tasks/addons; COMMIT;')
        p = SimpleNamespace(readonly_database=readonly_database)
        deps = (None, p, fixture.resume, fixture.source, self.stage, 'template', fixture.profile)
        patch = mock.patch.object(c, 'load_dependencies', return_value=deps)
        patch.start()
        self.addCleanup(patch.stop)
        stream, tar, info = c.archive_open()
        try:
            with contextlib.redirect_stdout(io.StringIO()):
                report = c.prepare(deps, tar, c.precheck(deps, tar), (stream, info))
        finally:
            tar.close()
            stream.close()
        self.root = Path(report['candidate_directory'])
        for name, value in {'ROOT_UID': os.getuid(), 'SOURCE': c.SOURCE, 'CANDIDATE': self.root,
                            'CANDIDATE_SHA': report['candidate_manifest_sha256'], 'LOCK': c.LOCK,
                            'BUILDER': c.COLD}.items():
            patch = mock.patch.object(r, name, value)
            patch.start()
            self.addCleanup(patch.stop)
        patch = mock.patch.object(r, 'trusted_parents', c.trusted_parents)
        patch.start()
        self.addCleanup(patch.stop)
        patch = mock.patch.object(r, 'load_builder', return_value=c)
        patch.start()
        self.addCleanup(patch.stop)
        self.profile = fixture.profile
        self.deps = deps

    def run_check(self):
        return r.check(c)

    def reject_before_runtime(self):
        with self.assertRaises(Exception):
            self.run_check()
        self.assertEqual(self.php_calls, [])
        self.assertEqual(self.sql_calls, [])

    def edit_json(self, name, change):
        path = self.root / name
        data = json.loads(path.read_bytes())
        change(data)
        path.write_text(json.dumps(data))

    def test_complete_readonly_check_preserves_all_files_and_boundaries(self):
        def snapshot():
            return {str(p): (p.lstat().st_ino, p.lstat().st_mtime_ns,
                    hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None)
                    for p in c.SOURCE.rglob('*')}
        before = snapshot()
        result = self.run_check()
        self.assertEqual(snapshot(), before)
        self.assertTrue(result['read_only'])
        self.assertFalse(result['candidate_activated'])
        self.assertFalse(result['full_addon_compatibility_verified'])
        self.assertFalse(result['patch_upgrade_rollback_verified'])
        self.assertFalse(result['paired_restore_space_verified'])
        self.assertEqual(result['runtime']['optional_php_extensions_missing'], ['soap'])
        self.assertEqual(len(self.php_calls), 1)
        kind, argv, user = self.php_calls[0]
        self.assertEqual((kind, user), ('web', '33:33'))
        self.assertEqual(argv[:6], ['php', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-r'])
        self.assertNotIn('require', argv[-1])
        self.assertNotIn('include', argv[-1])
        self.assertNotIn('config.php', argv[-1])
        self.assertTrue(all(q.startswith('BEGIN READ ONLY;') and q.endswith('COMMIT;') for q in self.sql_calls))
        self.assertNotIn('start', self.stage.calls)
        self.assertNotIn('stop', self.stage.calls)

    def test_altered_manifest_rejected_before_runtime(self):
        self.edit_json('manifest.json', lambda v: v.update(complete=False))
        self.reject_before_runtime()

    def test_tampered_stock_file_rejected_before_runtime(self):
        (self.root / 'code/public/index.php').write_text('<?php altered')
        self.reject_before_runtime()

    def test_tampered_legacy_sidecar_rejected_before_runtime(self):
        (self.root / 'preserved-extra-files/githash.php').write_text('altered')
        self.reject_before_runtime()

    def test_unexpected_entry_rejected_before_runtime(self):
        (self.root / 'code/unknown.php').write_text('<?php')
        self.reject_before_runtime()

    def test_missing_file_rejected_before_runtime(self):
        (self.root / 'code/public/index.php').unlink()
        self.reject_before_runtime()

    def test_symlink_rejected_before_runtime(self):
        path = self.root / 'code/public/index.php'
        path.unlink()
        path.symlink_to(self.root / 'code/public/version.php')
        self.reject_before_runtime()

    def test_hardlink_rejected_before_runtime(self):
        path = self.root / 'code/public/index.php'
        os.link(path, self.root / 'outside-hardlink')
        self.reject_before_runtime()

    def test_world_writable_acceptance_rejected(self):
        (self.root / 'manifest.json').chmod(0o666)
        self.reject_before_runtime()

    def test_code_root_mode_drift_rejected(self):
        (self.root / 'code').chmod(0o700)
        self.reject_before_runtime()

    def test_sidecar_root_mode_drift_rejected(self):
        (self.root / 'preserved-extra-files').chmod(0o755)
        self.reject_before_runtime()

    def test_candidate_parent_mismatch_rejected(self):
        with mock.patch.object(r, 'SOURCE', c.SOURCE / 'different'):
            self.reject_before_runtime()

    def test_activated_report_rejected_before_runtime(self):
        self.edit_json('report.json', lambda v: v.update(candidate_activated=True))
        self.reject_before_runtime()

    def test_missing_extension_stops_before_sql(self):
        self.php['extensions'].remove('pgsql')
        with self.assertRaisesRegex(r.ReadinessError, 'pgsql'):
            self.run_check()
        self.assertEqual(self.sql_calls, [])

    def test_unexpected_php_or_32bit_rejected(self):
        for key, value in [('version', '8.4.0'), ('int_size', 4), ('sapi', 'apache2handler')]:
            with self.subTest(key=key), mock.patch.dict(self.php, {key: value}):
                with self.assertRaisesRegex(r.ReadinessError, 'PHP version'):
                    self.run_check()

    def test_sendmail_or_memory_drift_rejected(self):
        for key, value in [('sendmail_path', '/usr/sbin/sendmail'), ('memory_limit', '128M')]:
            with self.subTest(key=key), mock.patch.dict(self.php, {key: value}):
                with contextlib.redirect_stdout(io.StringIO()) as out:
                    with self.assertRaisesRegex(r.ReadinessError, 'memory/email'):
                        self.run_check()
                self.assertIn('LAB_PATCH_PHP_OBSERVED=', out.getvalue())
                self.assertEqual(self.sql_calls, [])

    def setup_diagnostic(self):
        expected = b'memory_limit=512M\nsendmail_path=/bin/false\ndisplay_errors=Off\nlog_errors=On\n'
        self.fixture.file(c.CHECKPOINT / 'lab.ini', expected, 0o600)
        self.fixture.file(c.WORK / 'lab.ini', expected, 0o600)
        self.fixture.entries['lab.ini'] = c.metadata((c.CHECKPOINT / 'lab.ini').lstat(), False, f.sha(expected))
        patch = mock.patch.object(c, 'load_manifest', return_value=self.fixture.entries)
        patch.start()
        self.addCleanup(patch.stop)
        engine = c.SOURCE / 'engine-fixture.py'
        source = (b'def lab_exec(kind, state, argv, user=None):\n'
                  b'    defaults = ["memory_limit=256M", "sendmail_path=/bin/false", "private-secret-command"]\n'
                  b'    return defaults\n'
                  b'OTHER = "memory_limit=128M"\n')
        self.fixture.file(engine, source, 0o600)
        self.fixture.resume.ENGINE = engine
        self.fixture.resume.ENGINE_SHA = f.sha(source)
        owned = self.stage.owned
        def mount_aware(kind, state):
            item = owned(kind, state)
            if kind == 'web':
                item['Mounts'] = [{'Source': str(c.WORK / 'lab.ini'),
                                   'Destination': '/usr/local/etc/php/conf.d/zz-lab.ini', 'RW': False}]
            return item
        self.stage.owned = mount_aware
        self.php['memory_limit'] = '256M'
        self.php['ini_scanned'] = '/usr/local/etc/php/conf.d/docker.ini,\n/usr/local/etc/php/conf.d/zz-lab.ini'

    def test_diagnostic_reports_mismatch_without_sql_or_acceptance(self):
        self.setup_diagnostic()
        before = {str(p): (p.lstat().st_ino, p.lstat().st_mtime_ns) for p in c.SOURCE.rglob('*')}
        with mock.patch.object(r, 'verify_tree', side_effect=AssertionError('no full candidate scan')):
            result = r.diagnose_php(c)
        self.assertEqual(before, {str(p): (p.lstat().st_ino, p.lstat().st_mtime_ns) for p in c.SOURCE.rglob('*')})
        self.assertEqual(result['php_observed']['memory_limit'], '256M')
        self.assertFalse(result['memory_limit_matches_stage_ini'])
        self.assertTrue(result['sendmail_path_matches_stage_ini'])
        self.assertTrue(result['stage_ini_bind_listed_as_scanned'])
        self.assertTrue(result['stage_ini_bind_read_only'])
        self.assertFalse(result['readiness_accepted'])
        self.assertFalse(result['SQL_executed'])
        self.assertEqual(result['lab_exec_literal_overrides'], ['memory_limit=256M', 'sendmail_path=/bin/false'])
        self.assertNotIn('private-secret-command', json.dumps(result))
        self.assertEqual(self.sql_calls, [])

    def test_diagnostic_refuses_altered_stage_ini_before_php(self):
        self.setup_diagnostic()
        (c.WORK / 'lab.ini').write_text('memory_limit=1G\n')
        with self.assertRaisesRegex(r.ReadinessError, 'Stage lab.ini differs'):
            r.diagnose_php(c)
        self.assertEqual(self.php_calls, [])

    def test_diagnostic_refuses_bad_engine_pin_before_php(self):
        self.setup_diagnostic()
        self.fixture.resume.ENGINE.write_text('def lab_exec(): pass\n')
        with self.assertRaises(Exception):
            r.diagnose_php(c)
        self.assertEqual(self.php_calls, [])

    def test_unknown_adapter_literal_analysis_does_not_hide_php_observation(self):
        self.setup_diagnostic()
        source = b'lab_exec = delegated_callable\n'
        self.fixture.resume.ENGINE.write_bytes(source)
        self.fixture.resume.ENGINE_SHA = f.sha(source)
        result = r.diagnose_php(c)
        self.assertEqual(result['lab_exec_literal_overrides'], [])
        self.assertEqual(result['php_observed']['memory_limit'], '256M')
        self.assertIn('empty list does not exclude', result['adapter_review_scope'])
        self.assertEqual(self.sql_calls, [])

    def test_diagnostic_refuses_container_drift(self):
        self.setup_diagnostic()
        original = self.stage.lab_exec
        def drifting(*args, **kwargs):
            result = original(*args, **kwargs)
            self.stage.drift = True
            return result
        self.stage.lab_exec = drifting
        with self.assertRaisesRegex(r.ReadinessError, 'identities changed'):
            r.diagnose_php(c)

    def test_diagnostic_cli_never_emits_readiness_pass(self):
        self.setup_diagnostic()
        with mock.patch.object(sys, 'argv', ['readiness', '--diagnose-php']):
            with contextlib.redirect_stdout(io.StringIO()) as out:
                self.assertEqual(r.main(), 0)
        self.assertIn('LAB_PATCH_PHP_DIAGNOSTIC=COMPLETE', out.getvalue())
        self.assertNotIn('LAB_PATCH_READINESS=PASS', out.getvalue())
        self.assertEqual(self.sql_calls, [])

    def test_diagnostic_lock_contention_prevents_php(self):
        with c.LOCK.open('rb') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with mock.patch.object(sys, 'argv', ['readiness', '--diagnose-php']):
                with contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(r.main(), 1)
        self.assertEqual(self.php_calls, [])

    def test_diagnostic_redacts_arbitrary_commands_paths_and_values(self):
        self.setup_diagnostic()
        self.php.update(memory_limit='secret-memory-value', sendmail_path='smtp --password=secret-token',
                        ini_scanned='/private/secret-token, /usr/local/etc/php/conf.d/safe.ini')
        result = r.diagnose_php(c)
        rendered = json.dumps(result)
        self.assertNotIn('secret-token', rendered)
        self.assertNotIn('secret-memory-value', rendered)
        self.assertEqual(result['php_observed']['sendmail_path'], 'REDACTED_NONSTANDARD')
        self.assertEqual(result['php_observed']['scanned_ini_basenames'], ['REDACTED_NONSTANDARD', 'safe.ini'])

    def test_check_mismatch_diagnostic_redacts_sendmail_secrets(self):
        self.php['sendmail_path'] = 'smtp --password=secret-token'
        with contextlib.redirect_stdout(io.StringIO()) as out:
            with self.assertRaisesRegex(r.ReadinessError, 'sendmail_matches_bin_false=False'):
                self.run_check()
        self.assertNotIn('secret-token', out.getvalue())
        self.assertIn('REDACTED_NONSTANDARD', out.getvalue())
        self.assertEqual(self.sql_calls, [])

    def test_postgres_mismatch_rejected(self):
        self.pg = '170001'
        with self.assertRaisesRegex(r.ReadinessError, 'PostgreSQL differs'):
            self.run_check()

    def test_container_starttime_drift_rejected(self):
        original = self.stage.lab_exec
        def drifting(*args, **kwargs):
            result = original(*args, **kwargs)
            self.stage.drift = True
            return result
        self.stage.lab_exec = drifting
        with self.assertRaisesRegex(r.ReadinessError, 'identity or start time'):
            self.run_check()

    def test_insufficient_space_rejected_before_runtime(self):
        with mock.patch.object(r.shutil, 'disk_usage', return_value=SimpleNamespace(free=c.RESERVE - 1)):
            self.reject_before_runtime()

    def test_custom_php_not_run_to_read_addon_composer(self):
        rel = self.profile['additional_roots'][0] + '/composer.json'
        path = self.root / 'code' / rel
        path.write_text(json.dumps({'require': {'php': '^8.3', 'ext-soap': '*', 'private/pkg': '^1.2'}}))
        inventory = {rel: {'sha256': f.sha(path.read_bytes())}}
        rows = r.addon_composer_inventory(c, inventory, self.profile)
        self.assertEqual(rows[0]['package_requirement_count'], 1)
        self.assertEqual(rows[0]['extension_requirement_count'], 1)
        self.assertFalse(rows[0]['dependency_resolution_verified'])
        self.assertNotIn('private/pkg', json.dumps(rows))
        self.assertEqual(self.php_calls, [])

    def test_new_production_composer_package_requires_review(self):
        path = self.root / 'code/composer.json'
        path.write_text(json.dumps({'require': {'php': '>=8.2.0', 'unknown/pkg': '*'}}))
        digest = f.sha(path.read_bytes())
        with mock.patch.dict(c.CORE_HASHES, {'composer.json': digest}):
            inventory = {key: {'sha256': digest if key == 'composer.json' else val}
                         for key, val in c.CORE_HASHES.items()}
            with self.assertRaisesRegex(r.ReadinessError, 'Composer requirements'):
                r.platform_requirements(c, inventory)

    def test_default_mode_reads_nothing(self):
        with mock.patch.object(sys, 'argv', ['readiness']), mock.patch.object(r, 'load_builder') as loader:
            with contextlib.redirect_stdout(io.StringIO()) as out:
                self.assertEqual(r.main(), 0)
            self.assertIn('PLAN_ONLY', out.getvalue())
            loader.assert_not_called()

    def test_exclusive_lock_blocks_readiness_without_execution(self):
        with c.LOCK.open('rb') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with mock.patch.object(sys, 'argv', ['readiness', '--check']):
                with contextlib.redirect_stderr(io.StringIO()) as out:
                    self.assertEqual(r.main(), 1)
                self.assertIn('BlockingIOError', out.getvalue())
        self.assertEqual(self.php_calls, [])

    def test_replaced_lock_prevents_pass(self):
        original = r.check
        def replacing(builder):
            result = original(builder)
            c.LOCK.rename(c.SOURCE / 'lock-retained')
            c.LOCK.write_bytes(b'')
            c.LOCK.chmod(0o644)
            return result
        with mock.patch.object(r, 'check', side_effect=replacing):
            with mock.patch.object(sys, 'argv', ['readiness', '--check']):
                with contextlib.redirect_stdout(io.StringIO()) as out, contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(r.main(), 1)
                self.assertNotIn('LAB_PATCH_READINESS=PASS', out.getvalue())

    def test_dependency_pin_read_refuses_different_bytes(self):
        c.COLD.write_bytes(b'private fixture')
        c.COLD.chmod(0o600)
        with self.assertRaisesRegex(r.ReadinessError, 'checksum'):
            r.read_checked(c.COLD, '0' * 64)

    def test_unexpected_exception_redacts_private_output(self):
        with mock.patch.object(r, 'check', side_effect=RuntimeError('secret-token-value')):
            with mock.patch.object(sys, 'argv', ['readiness', '--check']):
                with contextlib.redirect_stderr(io.StringIO()) as out:
                    self.assertEqual(r.main(), 1)
                self.assertNotIn('secret-token-value', out.getvalue())
                self.assertIn('RuntimeError', out.getvalue())


if __name__ == '__main__':
    unittest.main()
