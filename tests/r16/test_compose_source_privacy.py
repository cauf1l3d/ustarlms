"""Compose-source read isolation without changing bytes, owner or live containers."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))
import infra_one_pass as core
SOURCE = ROOT / 'scripts/infra/compose_source_privacy.py'

def adapter(case):
    case.assertTrue(SOURCE.is_file(), 'Compose source privacy adapter is not implemented')
    spec = importlib.util.spec_from_file_location('compose_privacy_fixture', SOURCE)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result

@unittest.skipUnless(os.geteuid() == 0, 'root filesystem fixture runs in runtime CI')
class Privacy(unittest.TestCase):
    def setUp(self):
        self.m = adapter(self)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.path = self.root / 'opt/ustar/apps/stack/docker-compose.yml'
        self.path.parent.mkdir(parents=True)
        self.raw = b'POSTGRES_PASSWORD: PRIVATE_COMPOSE_SENTINEL\n'
        self.path.write_bytes(self.raw)
        self.path.chmod(0o644)
        self.base = self.root / 'var/lib/ustar-code-hardening/compose-source'
        self.base.parent.mkdir(parents=True)
        self.base.parent.chmod(0o700)
        self.owner = (0, 0)
        self.sha = hashlib.sha256(self.raw).hexdigest()
        self.operator = b'fixture adapter bytes'

    def run_action(self, action='apply'):
        return self.m.protect(core, self.path, self.base, self.operator, action=action,
            expected_sha=self.sha, expected_size=len(self.raw), expected_owner=self.owner)

    def test_check_does_not_create_state_or_change_file(self):
        before = self.path.stat()
        result = self.run_action('check')
        self.assertEqual(result['phase'], 'ready_for_compose_source_privacy')
        self.assertFalse(self.base.exists())
        self.assertEqual(self.path.stat(), before)

    def test_apply_preserves_bytes_inode_owner_mtime_and_parent_permissions(self):
        before = self.path.stat()
        parent = self.path.parent.stat()
        result = self.run_action()
        after = self.path.stat()
        self.assertEqual(result['phase'], 'compose_source_private')
        self.assertEqual(after.st_mode & 0o777, 0o600)
        self.assertEqual((after.st_ino, after.st_uid, after.st_gid, after.st_mtime_ns),
            (before.st_ino, before.st_uid, before.st_gid, before.st_mtime_ns))
        self.assertEqual(self.path.read_bytes(), self.raw)
        self.assertEqual(self.path.parent.stat().st_mode, parent.st_mode)
        self.assertEqual((self.base / 'compose.yml.before').read_bytes(), self.raw)
        self.assertEqual((self.base / 'compose.yml.before').stat().st_mode & 0o777, 0o600)
        self.assertNotIn('PRIVATE_COMPOSE_SENTINEL', json.dumps(result))

    def test_idempotent_apply_retains_original_images_and_record(self):
        self.run_action()
        state = (self.base / 'state.json').read_bytes()
        before = (self.base / 'compose.yml.before').stat()
        self.run_action()
        self.assertEqual((self.base / 'state.json').read_bytes(), state)
        self.assertEqual((self.base / 'compose.yml.before').stat().st_ino, before.st_ino)

    def test_metadata_undo_restores_mode_and_can_reapply_same_baseline(self):
        self.run_action()
        result = self.run_action('undo')
        self.assertEqual(result['phase'], 'compose_source_metadata_restored')
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.path.read_bytes(), self.raw)
        saved = core.Store(self.base).load()['exports']
        self.run_action()
        self.assertEqual(core.Store(self.base).load()['exports'], saved)
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)

    def test_same_size_content_change_with_restored_mtime_refuses_undo(self):
        self.run_action()
        stamp = self.path.stat().st_mtime_ns
        self.path.write_bytes(b'X' * len(self.raw))
        os.utime(self.path, ns=(stamp, stamp))
        with self.assertRaises(core.Refusal):
            self.run_action('undo')
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)

    def test_new_inode_with_same_bytes_refuses(self):
        self.run_action()
        stamp = self.path.stat().st_mtime_ns
        self.path.rename(self.path.with_suffix('.old'))
        self.path.write_bytes(self.raw)
        self.path.chmod(0o600)
        os.utime(self.path, ns=(stamp, stamp))
        with self.assertRaises(core.Refusal):
            self.run_action()

    def test_foreign_before_image_refuses_without_reopening_source(self):
        self.run_action()
        (self.base / 'compose.yml.before').write_bytes(b'foreign')
        with self.assertRaises(core.Refusal):
            self.run_action('undo')
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)

    def test_interrupted_permission_change_recovers_from_original_record(self):
        original = core.Exports.apply
        def interrupted(obj, progress=lambda _: None):
            def stop(_):
                raise KeyboardInterrupt()
            return original(obj, progress=stop)
        with patch.object(core.Exports, 'apply', interrupted):
            with self.assertRaises(KeyboardInterrupt):
                self.run_action()
        self.assertEqual(core.Store(self.base).load()['phase'], 'prepared')
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)
        self.run_action()
        self.assertEqual(core.Store(self.base).load()['phase'], 'exports_restricted')

    def test_unknown_original_mode_or_hardlink_never_creates_state(self):
        self.path.chmod(0o640)
        with self.assertRaises(core.Refusal):
            self.run_action()
        self.assertFalse(self.base.exists())
        self.path.chmod(0o644)
        os.link(self.path, self.path.with_suffix('.link'))
        with self.assertRaises(core.Refusal):
            self.run_action()
        self.assertFalse(self.base.exists())

    def test_completed_source_reopened_to_world_readable_is_not_adopted(self):
        self.run_action()
        self.path.chmod(0o644)
        with self.assertRaises(core.Refusal):
            self.run_action()

    def test_existing_companion_payload_includes_private_source_and_adapter(self):
        self.run_action()
        payload = core.payload(self.root, ('var/lib/ustar-code-hardening',))
        prefix = 'var/lib/ustar-code-hardening/compose-source/'
        self.assertEqual(payload[prefix + 'compose.yml.before']['raw'], self.raw)
        self.assertEqual(payload[prefix + 'operator.py']['raw'], self.operator)
        self.assertIn(prefix + 'state.json', payload)
        self.assertTrue(all(v['uid'] == 0 and v['mode'] == 0o600 for v in payload.values()))

    def test_only_exact_private_pinned_core_bytes_are_loaded(self):
        script = self.root / 'pinned.py'
        script.write_bytes((ROOT / 'scripts/infra/infra_one_pass.py').read_bytes())
        script.chmod(0o600)
        loaded = self.m.load_core(script)
        self.assertEqual(loaded.digest(b'test'), hashlib.sha256(b'test').hexdigest())
        script.chmod(0o644)
        with self.assertRaises(self.m.Refusal):
            self.m.load_core(script)

    def test_foreign_core_is_rejected_before_execution(self):
        script = self.root / 'foreign.py'
        target = self.root / 'must-not-exist'
        script.write_text('from pathlib import Path\nPath(' + repr(str(target)) + ').touch()\n')
        script.chmod(0o600)
        with self.assertRaises(self.m.Refusal):
            self.m.load_core(script)
        self.assertFalse(target.exists())

    def test_real_UID1000_deployer_retained_UID33_denied(self):
        try:
            os.chown(self.path, 1000, 1000)
        except OSError:
            if os.environ.get('USTAR_REQUIRE_INFRA_NATIVE') == '1':
                self.fail('actual UID1000 fixture is required')
            self.skipTest('UID1000 unmapped locally; strict runtime CI covers this case')
        self.owner = (1000, 1000)
        for p in self.path.parents:
            if p == self.root.parent:
                break
            p.chmod(0o755)
        def can_read(path, uid):
            result = subprocess.run(['/usr/bin/python3', '-I', '-B', '-c',
                'import sys;open(sys.argv[1],"rb").read(1)', str(path)],
                user=uid, group=uid, extra_groups=[], stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            return result.returncode == 0
        self.assertTrue(can_read(self.path, 33))
        self.run_action()
        self.assertTrue(can_read(self.path, 1000))
        self.assertFalse(can_read(self.path, 33))
        self.assertFalse(can_read(self.base / 'compose.yml.before', 1000))
        self.assertEqual((self.path.stat().st_uid, self.path.stat().st_gid), (1000, 1000))
        print('COMPOSE_SOURCE_UID_BOUNDARY=PASS')

class OfflineEntry(unittest.TestCase):
    def test_default_entry_has_no_host_mutations(self):
        adapter(self)
        result = subprocess.run([sys.executable, '-I', '-B', str(SOURCE)],
            capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout)['phase'], 'PLAN_ONLY')
