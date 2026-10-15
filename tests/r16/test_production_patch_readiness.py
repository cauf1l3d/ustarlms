"""Portable guards using real files; Docker/SQL boundaries mocked, no live claims."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('prod_preflight', Path(__file__).resolve().parents[2] / 'scripts/infra/production_patch_readiness.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class ProductionReadOnlyGuards(unittest.TestCase):
    def test_digest_reads_real_bytes_without_rewriting(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'file'
            path.write_bytes(b'original')
            before = p.signature(path.stat())
            self.assertEqual(p.read_file(path, 8, True), hashlib.sha256(b'original').hexdigest())
            self.assertEqual(p.signature(path.stat()), before)

    def test_symlink_and_oversized_inputs_refused(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'file'
            path.write_bytes(b'original')
            link = Path(folder) / 'link'
            link.symlink_to(path)
            with self.assertRaises(p.Refusal):
                p.read_file(link)
            with self.assertRaises(p.Refusal):
                p.read_file(path, 7)

    def test_hardlinked_control_refused(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'file'
            path.write_bytes(b'original')
            os.link(path, Path(folder) / 'second')
            with self.assertRaises(p.Refusal):
                p.read_file(path)

    def test_prefix_injection_never_reaches_command(self):
        for value in ("$CFG->prefix = 'mdl_; DROP';", "$CFG->prefix = getenv('PREFIX');",
                      "$CFG->prefix = 'mdl_';\n$CFG->prefix = 'other_';"):
            with self.assertRaises(p.Refusal):
                p.parse_prefix(value)
        with self.assertRaises(p.Refusal):
            p.db_query('mdl_;DELETE', ['local_p' + str(i) for i in range(57)])

    def test_homepage_expression_needs_review_without_evaluation(self):
        self.assertTrue(p.home_policy('<?php\n')['candidate_needs_reviewed_homepage_config_insertion'])
        self.assertTrue(p.home_policy("$CFG->defaulthomepage = '/local/ustar/home.php';")['ustar_relative_home'])
        with self.assertRaises(p.Refusal):
            p.home_policy("$CFG->defaulthomepage = dangerous();")

    def test_sql_is_readonly_and_keeps_enabled_tasks(self):
        rows = [{'component': 'local_p' + str(i), 'disk_version': 2026101200 + i} for i in range(57)]
        observation = {'core': '2025100601.03', 'postgres_version_num': '160015',
                       'addons': {r['component']: str(r['disk_version']) for r in rows},
                       'enabled_tasks': 42, 'activity_counts': {'scorm': 8}, 'block_counts': {}}
        with mock.patch.object(p, 'command', return_value=json.dumps(observation)) as call:
            result = p.versions('mdl_', {'core_db_version': '2025100601.03', 'additional_components': rows})
        self.assertEqual(result['enabled_tasks'], 42)
        argv, sql, timeout = call.call_args.args
        self.assertEqual(argv[:5], ['docker', 'exec', '-i', 'ustar_postgres', 'sh'])
        self.assertTrue(sql.startswith('BEGIN READ ONLY;'))
        self.assertTrue(sql.endswith('COMMIT;'))
        self.assertNotRegex(sql.upper(), r'\b(UPDATE|DELETE|INSERT|CREATE|ALTER|DROP)\b')
        self.assertIn('statement_timeout', sql)
        self.assertIn('lock_timeout', sql)

    def test_changed_database_baseline_refused(self):
        rows = [{'component': 'local_p' + str(i), 'disk_version': 2026101200 + i} for i in range(57)]
        with mock.patch.object(p, 'command', return_value=json.dumps({'core': '2025100608'})):
            with self.assertRaisesRegex(p.Refusal, 'core DB version differs'):
                p.versions('mdl_', {'core_db_version': '2025100601.03', 'additional_components': rows})

    def test_archive_checksum_mismatch_and_recovery_marker_refused(self):
        name = 'ustar-recovery-20261012T003000Z-abcdef01.tar.gz.age'
        manifest = {'file': name, 'sha256': '0' * 64, 'bytes': 8,
                    'readback_verified': True, 'hdd_uuid': p.HDD_UUID}
        scheduled = {'success': True, 'phase': 'complete', 'recovery_marker_present': False,
                     'recovery_error_type': None}
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'backups/managed').mkdir(parents=True)
            (root / 'backups/managed' / name).write_bytes(b'original')
            success = {'success': True, 'manifest': dict(manifest, archive=str(root / 'backups/managed' / name))}
            with mock.patch.object(p, 'HDD', root), mock.patch.object(p, 'existing_backup_lock') as lock, \
                    mock.patch.object(p, 'mount', return_value={}), mock.patch.object(p, 'root_input'), \
                    mock.patch.object(p, 'read_json', side_effect=[scheduled, success, manifest]), \
                    mock.patch.object(p.os.path, 'lexists', return_value=False):
                lock.return_value.__enter__.return_value = None
                with self.assertRaisesRegex(p.Refusal, 'checksum differs'):
                    p.backup()
            with mock.patch.object(p, 'existing_backup_lock'), mock.patch.object(p, 'mount'), \
                    mock.patch.object(p.os.path, 'lexists', return_value=True):
                with self.assertRaisesRegex(p.Refusal, 'recovery marker'):
                    p.backup()

    def test_tree_inventory_refuses_outside_symlink(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'outside').symlink_to('/etc')
            with self.assertRaises(p.Refusal):
                p.tree_size(root)

    def test_valid_archive_and_paired_reports_are_read_without_rewrites(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            root, state = base / 'hdd', base / 'state'
            (root / 'backups/managed').mkdir(parents=True)
            state.mkdir()
            name = 'ustar-recovery-20261012T003000Z-abcdef01.tar.gz.age'
            archive = root / 'backups/managed' / name
            archive.write_bytes(b'original')
            manifest = {'file': name, 'sha256': hashlib.sha256(b'original').hexdigest(),
                        'bytes': 8, 'readback_verified': True, 'hdd_uuid': p.HDD_UUID}
            records = {
                state / 'last-hdd-scheduled-run.json': {'success': True, 'phase': 'complete',
                    'recovery_marker_present': False, 'recovery_error_type': None},
                state / 'last-hdd-success.json': {'success': True, 'finished_utc': '2026-10-12T00:31:38Z',
                    'manifest': dict(manifest, archive=str(archive))},
                state / 'last-hdd-export.json': manifest,
                archive.with_name(name + '.json'): manifest,
            }
            for path, value in records.items():
                path.write_text(json.dumps(value))
            before = {path: p.signature(path.stat()) for path in [archive, *records]}
            with mock.patch.object(p, 'HDD', root), mock.patch.object(p, 'STATE', state), \
                    mock.patch.object(p, 'root_input'), mock.patch.object(p, 'existing_backup_lock'), \
                    mock.patch.object(p, 'mount', return_value={'uuid': p.HDD_UUID}):
                result = p.backup()
            self.assertTrue(result['archive_readback_sha256_verified'])
            self.assertFalse(result['decryption_or_restore_verified'])
            self.assertEqual({path: p.signature(path.stat()) for path in [archive, *records]}, before)

    def test_failed_command_redacts_private_stderr(self):
        with mock.patch.object(p.subprocess, 'run', return_value=mock.Mock(returncode=1, stdout='', stderr='private password')):
            with self.assertRaisesRegex(p.Refusal, '^Bounded host command failed$'):
                p.command(['psql'])
