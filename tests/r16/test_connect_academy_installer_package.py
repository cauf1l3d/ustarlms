"""Real immutable Git payload and packaged controller; no host mutation."""
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


def load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts/infra' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class InstallerPackage(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.builder = load('build_connect_academy_installer')
        cls.controller = load('connect_academy_installer')
        cls.temporary = tempfile.TemporaryDirectory()
        cls.addClassCleanup(cls.temporary.cleanup)
        cls.root = Path(cls.temporary.name)
        with contextlib.redirect_stdout(io.StringIO()):
            cls.package = cls.builder.build('HEAD', cls.root / 'output')
        with tarfile.open(cls.package) as archive:
            cls.files = {m.name: archive.extractfile(m).read() for m in archive}
        cls.pyz = cls.root / 'update.pyz'
        cls.pyz.write_bytes(cls.files['update.pyz'])

    def test_frozen_rc_payload_is_exact_and_all_members_have_checksums(self):
        reference, payload, manifest = self.controller.bundle(self.pyz)
        self.assertEqual(self.builder.PAYLOAD_COMMIT, manifest['payload_commit'])
        self.assertEqual(self.builder.RC_HASH, manifest['original_source_rc_sha256'])
        self.assertEqual(552, len(payload))
        self.assertEqual(99, len(reference['candidate']['tables']))
        self.assertEqual(526, len(reference['baseline']['files']))
        self.assertEqual({'update.pyz', 'INSTRUCTION.md', 'TOOL_COMMIT', 'PAYLOAD_COMMIT', 'SHA256SUMS'}, set(self.files))
        sums = dict((name, digest) for digest, name in (line.split('  ', 1)
            for line in self.files['SHA256SUMS'].decode().splitlines()))
        self.assertEqual(sums, {name: hashlib.sha256(raw).hexdigest()
            for name, raw in self.files.items() if name != 'SHA256SUMS'})

    def test_packaged_default_plan_has_no_host_access_or_dependencies(self):
        result = subprocess.run([sys.executable, '-I', '-B', str(self.pyz)], capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('PLAN_ONLY', result.stdout)
        self.assertIn('no host access', result.stdout)

    def test_deterministic_second_build(self):
        with contextlib.redirect_stdout(io.StringIO()):
            other = self.builder.build('HEAD', self.root / 'other')
        self.assertEqual(self.package.read_bytes(), other.read_bytes())

    def test_tampered_embedded_source_is_refused(self):
        target = self.root / 'bad.pyz'
        with zipfile.ZipFile(self.pyz) as original, zipfile.ZipFile(target, 'w') as changed:
            for name in original.namelist():
                raw = b'bad' if name == 'payload/local/ustar/version.php' else original.read(name)
                changed.writestr(name, raw)
        with self.assertRaisesRegex(ValueError, 'bundle_hash'):
            self.controller.bundle(target)

    def test_zip_inventory_injection_is_refused(self):
        target = self.root / 'extra.pyz'
        target.write_bytes(self.pyz.read_bytes())
        with zipfile.ZipFile(target, 'a') as archive:
            archive.writestr('../config.php', b'canary')
        with self.assertRaisesRegex(ValueError, 'bundle_inventory'):
            self.controller.bundle(target)


if __name__ == '__main__':
    unittest.main()
