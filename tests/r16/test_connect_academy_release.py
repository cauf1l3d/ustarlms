"""Real Git and archive checks for a source-only RC; no modeled prod installation."""
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]


class ConnectAcademyReleaseTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        path = ROOT / 'scripts/infra/build_connect_academy_release.py'
        if not path.is_file():
            raise AssertionError('source-only Connect Academy release builder missing')
        spec = importlib.util.spec_from_file_location('connect_academy_builder', path)
        cls.mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.mod)

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.repo = self.root / 'repo'
        self.repo.mkdir()
        self.git('init')
        for scope, version in [('local/ustar', 2026100204), ('theme/ustar', 2026100202)]:
            path = self.repo / 'moodle' / scope / 'version.php'
            path.parent.mkdir(parents=True)
            path.write_text(f'<?php\n$plugin->version = {version};\n')
        schema = self.repo / 'moodle/local/ustar/db/install.xml'
        schema.parent.mkdir()
        schema.write_text('<XMLDB><TABLES><TABLE NAME="local_ustar_test"/></TABLES></XMLDB>')
        self.commit('baseline')
        self.base = self.git('rev-parse', 'HEAD')
        for name in ('scripts/infra/build_connect_readiness.py', 'scripts/infra/connect_readiness.py',
                     'scripts/production_manifest.py', 'scripts/infra/production_patch_readiness.py'):
            target = self.repo / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((ROOT / name).read_bytes())
        marker = self.repo / 'context/code_map/generated/source_manifest.json'
        marker.parent.mkdir(parents=True)
        marker.write_text(json.dumps({'source_commit': self.base}))
        (self.repo / 'moodle/local/ustar/version.php').write_text('<?php\n$plugin->version = 2026101905;\n')
        (self.repo / 'moodle/local/ustar/connect.php').write_text('<?php // tracked candidate\n')
        self.commit('candidate')
        self.sha = self.git('rev-parse', 'HEAD')

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.repo), *args], stderr=subprocess.DEVNULL).decode().strip()

    def commit(self, message):
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-m', message)

    def build(self, ref=None):
        with contextlib.redirect_stdout(io.StringIO()):
            return self.mod.build(ref or self.sha, self.root / 'out', repo=self.repo)

    def members(self, path):
        with tarfile.open(path) as archive:
            return {m.name: archive.extractfile(m).read() for m in archive.getmembers()}

    def test_exact_candidate_and_baseline_manifests_cover_the_payload(self):
        target = self.build()
        contents = self.members(target)
        manifest = json.loads(contents['manifest.json'])
        self.assertEqual(self.sha, manifest['source_commit'])
        self.assertEqual(self.base, manifest['reference']['baseline_commit'])
        self.assertEqual(2026101905, manifest['reference']['candidate']['versions']['local_ustar'])
        self.assertEqual(self.sha + '\n', contents['SOURCE_COMMIT'].decode())
        expected = {f'payload/{name}': digest for name, digest in manifest['reference']['candidate']['files'].items()}
        actual = {name: hashlib.sha256(data).hexdigest() for name, data in contents.items() if name.startswith('payload/')}
        self.assertEqual(expected, actual)
        self.assertEqual({'payload/local/ustar/version.php', 'payload/local/ustar/connect.php',
                          'payload/local/ustar/db/install.xml', 'payload/theme/ustar/version.php'}, set(actual))
        self.assertEqual(b'<?php // tracked candidate\n', contents['payload/local/ustar/connect.php'])
        self.assertFalse(manifest['installation_supported'])
        self.assertFalse(manifest['production_mutation'])
        self.assertEqual({'SOURCE_COMMIT', 'preflight.pyz', 'manifest.json', 'SHA256SUMS'},
                         {name for name in contents if not name.startswith('payload/')})

    def test_deterministic_and_dirty_tree_does_not_enter_archive(self):
        before = self.build().read_bytes()
        (self.repo / 'moodle/local/ustar/connect.php').write_text('PRIVATE_DIRTY_CANARY')
        (self.repo / 'moodle/local/ustar/untracked.php').write_text('PRIVATE_UNTRACKED_CANARY')
        after = self.build().read_bytes()
        self.assertEqual(before, after)
        self.assertNotIn(b'PRIVATE_', b'\n'.join(self.members(self.build()).values()))

    def test_checksums_cover_every_other_member_and_outer_hash_matches(self):
        target = self.build()
        contents = self.members(target)
        sums = dict((name, digest) for digest, name in (line.split('  ', 1) for line in contents.pop('SHA256SUMS').decode().splitlines()))
        self.assertEqual({name: hashlib.sha256(data).hexdigest() for name, data in contents.items()}, sums)
        outer = target.with_suffix(target.suffix + '.sha256').read_text().strip()
        self.assertEqual(hashlib.sha256(target.read_bytes()).hexdigest() + '  ' + target.name, outer)
        with tarfile.open(target) as archive:
            for member in archive:
                self.assertTrue(member.isfile())
                self.assertNotIn('..', Path(member.name).parts)
                self.assertFalse(member.name.startswith('/'))
                self.assertEqual((0, 0, 0), (member.uid, member.gid, member.mtime))
                self.assertEqual(0o644, member.mode)

    def test_packaged_preflight_has_the_same_reference_and_defaults_to_plan(self):
        contents = self.members(self.build())
        with zipfile.ZipFile(io.BytesIO(contents['preflight.pyz'])) as archive:
            reference = json.loads(archive.read('connect_preflight_data/reference.json'))
        self.assertEqual(json.loads(contents['manifest.json'])['reference'], reference)
        target = self.root / 'preflight.pyz'
        target.write_bytes(contents['preflight.pyz'])
        result = subprocess.run([sys.executable, '-I', str(target)], capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('plan_only', json.loads(result.stdout)['status'])

    def test_symbolic_ref_and_unknown_commit_are_refused_without_artifact(self):
        for ref in ('main', 'HEAD~1', '--all', '0' * 40):
            with self.subTest(ref=ref), self.assertRaises((ValueError, subprocess.CalledProcessError)):
                self.build(ref)
        self.assertFalse((self.root / 'out').exists())

    def test_tracked_symlink_is_refused_without_artifact(self):
        (self.repo / 'moodle/local/ustar/alias.php').symlink_to('/etc/passwd')
        self.commit('unsafe symlink')
        with self.assertRaisesRegex(ValueError, 'regular_source_required'):
            self.build(self.git('rev-parse', 'HEAD'))
        self.assertFalse((self.root / 'out').exists())

    def test_tracked_sensitive_file_is_refused_not_silently_packaged(self):
        (self.repo / 'moodle/local/ustar/config-local.php').write_text('PRIVATE_CONFIG_CANARY')
        self.commit('unsafe config')
        with self.assertRaisesRegex(ValueError, 'source_path_refused'):
            self.build(self.git('rev-parse', 'HEAD'))
        self.assertFalse((self.root / 'out').exists())
