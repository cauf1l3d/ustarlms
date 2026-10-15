"""Release scope stays on native Moodle even when archived clients change."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('release_scope_tested', REPO / 'scripts/ci/release_manifest.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseScope(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.git('init', '-q', '--initial-branch=main')
        self.git('config', 'user.name', 'Release fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for path, text in {
            'moodle/local/ustar/version.php': '<?php // plugin fixture\n',
            'moodle/theme/ustar/version.php': '<?php // theme fixture\n',
            'tests/stage/runtime.json': '{}',
            'tests/stage/python-requirements.txt': 'fixture==1\n',
            'tests/mobile/package-lock.json': '{}',
            'tests/release_20260912/package-lock.json': '{}',
            'frontend/package-lock.json': '{"historical":true}',
            'frontend/.env.production': 'IGNORED_FIXTURE=1\n',
        }.items():
            p = self.root / path; p.parent.mkdir(parents=True, exist_ok=True); p.write_text(text)
        (self.root / 'frontend/archived-link').symlink_to('package-lock.json')
        baseline = self.commit('baseline')
        (self.root / 'tests/stage/production.json').write_text(json.dumps({'source_commit': baseline}))
        self.head = self.commit('candidate')
        self.patch = mock.patch.object(release, 'ROOT', self.root)
        self.patch.start(); self.addCleanup(self.patch.stop)

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], stderr=subprocess.DEVNULL).decode().strip()

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-q', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def test_manifest_does_not_read_or_package_legacy_client(self):
        result = release.build(self.head)
        self.assertEqual(set(result['source_files']), {'local/ustar/version.php', 'theme/ustar/version.php'})
        self.assertNotIn('frontend_source_files', result)
        self.assertEqual(result['legacy_excluded'], ['frontend/'])
        self.assertFalse(any(p.startswith('frontend/') for p in result['dependency_lock_sha256']))
        self.assertEqual(len(result['dependency_lock_sha256']), 3)

    def test_legacy_changes_do_not_change_native_release_content_or_upgrade_plan(self):
        before = release.build(self.head)
        (self.root / 'frontend/package-lock.json').write_text('{"historical":false}')
        after = release.build(self.commit('legacy only'))
        for key in ('source_files', 'upgrade_plan', 'dependency_lock_sha256'):
            self.assertEqual(before[key], after[key])

    def test_native_change_remains_part_of_hash_guarded_upgrade_plan(self):
        (self.root / 'moodle/local/ustar/version.php').write_text('<?php // changed native fixture\n')
        result = release.build(self.commit('native change'))
        self.assertIn('local/ustar/version.php', result['upgrade_plan']['changed'])


class WorkflowScope(unittest.TestCase):
    def test_required_job_graph_has_no_legacy_dependency_or_dangling_job(self):
        import yaml
        config = yaml.safe_load((REPO / '.github/workflows/ustar-review-gate.yml').read_text())
        jobs = config['jobs']
        self.assertNotIn('frontend', jobs)
        for job in jobs.values():
            self.assertLessEqual(set(job.get('needs', [])), set(jobs))
        self.assertEqual(set(jobs['gate']['needs']), {'source', 'rollback', 'prepare-rc', 'moodle-db'})
        self.assertIn('moodle-db', jobs)
        self.assertIn('rollback', jobs)
        for name in ('source', 'prepare-rc'):
            steps = jobs[name]['steps']
            archives = [s['run'] for s in steps if 'git archive ' in s.get('run', '')]
            self.assertTrue(archives)
            for command in archives:
                self.assertNotIn(' frontend', command)
                self.assertIn('moodle/local/ustar moodle/theme/ustar', command)


if __name__ == '__main__':
    unittest.main()
