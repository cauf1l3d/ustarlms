"""Operator preflight must detect drift and avoid secrets, symlinks and repairs."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/infra/core_preflight.py'
SPEC = importlib.util.spec_from_file_location('core_preflight', SCRIPT)
CORE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CORE)


class CorePreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.repo = self.base / 'reference'
        self.repo.mkdir()
        self.git('init', '--quiet')
        self.git('config', 'user.name', 'Synthetic fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.files = {'public/version.php': '<?php $version = 2025100601.03;',
                      'public/index.php': '<?php echo "fixture";',
                      'composer.json': '{}'}
        self.root = self.base / 'installed'
        for path, text in self.files.items():
            for directory in (self.repo, self.root):
                target = directory / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(text)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'synthetic reference')
        self.sha = self.git('rev-parse', 'HEAD').strip()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.repo), *args],
                                       text=True, stderr=subprocess.DEVNULL)

    def audit(self):
        return CORE.audit(self.repo, self.root, self.sha)

    def test_clean_tree_matches(self):
        result = self.audit()
        self.assertTrue(result['complete'])
        self.assertTrue(result['match'])
        self.assertEqual(len(result['matching']), 3)

    def test_changed_and_missing_files_are_not_repaired(self):
        changed = self.root / 'public/index.php'
        changed.write_text('changed fixture')
        missing = self.root / 'composer.json'
        missing.unlink()
        result = self.audit()
        self.assertTrue(result['complete'])
        self.assertFalse(result['match'])
        self.assertEqual(result['changed'][0]['path'], 'public/index.php')
        self.assertEqual(result['missing'], ['composer.json'])
        self.assertEqual(changed.read_text(), 'changed fixture')
        self.assertFalse(missing.exists())

    def test_extra_core_file_detected_known_plugins_and_credentials_excluded(self):
        for rel in ('config.php', 'public/config.php', 'public/.secret',
                    'vendor/fixture.php', 'public/local/ustar/private.php',
                    'public/local/ai_manager/private.php', 'public/tests/private.php'):
            path = self.root / rel
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('SECRET_CANARY')
        (self.root / 'public/extra.php').write_text('EXTRA_CANARY')
        result = self.audit()
        self.assertEqual(result['extra'], ['public/extra.php'])
        self.assertNotIn('CANARY', json.dumps(result))

    def test_file_symlink_is_incomplete_without_reading_target(self):
        outside = self.base / 'private'
        outside.write_text('SECRET_CANARY')
        path = self.root / 'public/index.php'
        path.unlink()
        path.symlink_to(outside)
        result = self.audit()
        self.assertFalse(result['complete'])
        self.assertEqual(result['errors']['public/index.php'], 'symlink')
        self.assertNotIn('CANARY', json.dumps(result))

    def test_parent_and_root_symlinks_refused(self):
        target = self.root / 'public'
        moved = self.base / 'moved'
        target.rename(moved)
        target.symlink_to(moved, target_is_directory=True)
        self.assertFalse(self.audit()['complete'])
        root_alias = self.base / 'alias'
        root_alias.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(ValueError):
            CORE.audit(self.repo, root_alias, self.sha)

    def test_mutable_ref_is_not_accepted(self):
        with self.assertRaises(ValueError):
            CORE.audit(self.repo, self.root, 'HEAD')

    def test_reports_cannot_be_written_in_application(self):
        with self.assertRaises(ValueError):
            CORE.save_report(self.audit(), self.root / 'report')
        self.assertFalse((self.root / 'report').exists())

    def test_private_report_and_no_overwrite(self):
        out = self.base / 'report'
        result = self.audit()
        summary = CORE.save_report(result, out)
        self.assertTrue(summary['match'])
        self.assertEqual(out.stat().st_mode & 0o777, 0o700)
        original = (out / 'core_comparison.json').read_bytes()
        with self.assertRaises(FileExistsError):
            CORE.save_report(result, out)
        self.assertEqual((out / 'core_comparison.json').read_bytes(), original)
        public = self.base / 'public-report'
        public.mkdir(mode=0o755)
        with self.assertRaises(ValueError):
            CORE.save_report(result, public)


if __name__ == '__main__':
    unittest.main()
