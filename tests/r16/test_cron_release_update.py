import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = ROOT / 'scripts/infra/cron_release_update.py'
if SPEC.exists():
    spec = importlib.util.spec_from_file_location('cron_release_update', SPEC)
    x = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(x)
else:
    x = SimpleNamespace()

OLD = b"VERSIONS = {'local_ustar': '2026100204', 'theme_ustar': '2026100202'}"
SOURCE = b'#!/usr/bin/env python3\n' + OLD + b'\n# All other worker guards stay unchanged.\n'
UPDATED = SOURCE.replace(b'2026100204', b'2026101905')
digest = lambda b: hashlib.sha256(b).hexdigest()


class Transform(unittest.TestCase):
    def test_exact_reviewed_source_changes_only_version_constant(self):
        self.assertTrue(callable(getattr(x, 'patch_source', None)), 'Reviewed source transformer is missing')
        with patch.object(x, 'LEGACY_SHA', digest(SOURCE)), patch.object(x, 'UPDATED_SHA', digest(UPDATED)):
            self.assertEqual(UPDATED, x.patch_source(SOURCE))
            for bad in (SOURCE + b'# drift\n', UPDATED):
                with self.assertRaises(x.Refusal):
                    x.patch_source(bad)


class NativeBoundary:
    def __init__(self):
        self.identities = {'ustar_moodle': {'id': 'web', 'image': 'webimage'},
                           'ustar_postgres': {'id': 'db', 'image': 'dbimage'}}
        self.versions = {'local_ustar': '2026101905', 'theme_ustar': '2026100202'}
        self.needs_upgrade = False
        self.worker_calls = 0

    def inspect(self, source):
        return {'containers': self.identities, 'versions': self.versions,
                'needs_upgrade': self.needs_upgrade, 'cron_enabled': 1,
                'maintenance': 0, 'core': '2025100608.00', 'cron_service': 'active'}


class Transaction(unittest.TestCase):
    def setUp(self):
        self.assertTrue(callable(getattr(x, 'Manager', None)), 'Guarded release transaction is missing')
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.p = x.Paths(Path(self.tmp.name))
        for folder in (self.p.script.parent, self.p.installation.parent,
                       self.p.cron.parent, self.p.cron_lock.parent,
                       self.p.backup_lock.parent, self.p.receipt.parent):
            folder.mkdir(parents=True, exist_ok=True)
        for folder in self.p.root.rglob('*'):
            if folder.is_dir():
                folder.chmod(0o700)
        self.p.script.write_bytes(SOURCE)
        self.p.script.chmod(0o700)
        os.setxattr(self.p.script, 'user.ustar-test', b'preserved')
        self.host = NativeBoundary()
        self.original_state = {'schema': 1, 'script_sha256': digest(SOURCE),
                               'versions': {'local_ustar': '2026100204', 'theme_ustar': '2026100202'},
                               'containers': self.host.identities,
                               'created_utc': '2026-10-05T00:00:00Z', 'retained_field': 'keep'}
        self.p.installation.write_text(json.dumps(self.original_state))
        self.p.installation.chmod(0o600)
        self.history = self.p.installation.with_name('last-wrapper-error.json')
        self.history.write_bytes(b'{"error":"retain historical refusal"}')
        self.history.chmod(0o600)
        self.p.cron.write_bytes(x.CRON_TEXT)
        self.p.cron.chmod(0o644)
        for path in (self.p.cron_lock, self.p.backup_lock):
            path.write_bytes(b'')
            path.chmod(0o600)
        self.p.receipt.write_text(json.dumps({'phase': 'upgrade_complete', 'public_released': True,
                                            'payload_commit': x.PAYLOAD}))
        self.p.receipt.chmod(0o600)
        self.before = self.p.installation.read_bytes()
        self.sha1 = patch.object(x, 'LEGACY_SHA', digest(SOURCE))
        self.sha2 = patch.object(x, 'UPDATED_SHA', digest(UPDATED))
        self.sha1.start(); self.addCleanup(self.sha1.stop)
        self.sha2.start(); self.addCleanup(self.sha2.stop)
        self.manager = x.Manager(self.p, self.host, uid=os.geteuid())

    def assert_original_pair(self):
        self.assertEqual(SOURCE, self.p.script.read_bytes())
        self.assertEqual(self.before, self.p.installation.read_bytes())

    def test_check_has_no_file_changes_and_no_worker(self):
        before = {str(p): p.read_bytes() for p in self.p.root.rglob('*') if p.is_file()}
        self.assertEqual('ready_for_cron_release_update', self.manager.check()['phase'])
        self.assertEqual(before, {str(p): p.read_bytes() for p in self.p.root.rglob('*') if p.is_file()})
        self.assertEqual(0, self.host.worker_calls)

    def test_apply_repeat_preserves_schedule_identity_history_permissions_and_xattrs(self):
        self.assertEqual('applied_waiting_for_scheduled_run', self.manager.apply()['phase'])
        self.assertEqual(UPDATED, self.p.script.read_bytes())
        state = json.loads(self.p.installation.read_text())
        self.assertEqual({**self.original_state, 'script_sha256': digest(UPDATED),
                          'versions': self.host.versions}, state)
        self.assertEqual(x.CRON_TEXT, self.p.cron.read_bytes())
        self.assertEqual(b'{"error":"retain historical refusal"}', self.history.read_bytes())
        self.assertEqual(0o700, self.p.script.stat().st_mode & 0o777)
        self.assertEqual(b'preserved', os.getxattr(self.p.script, 'user.ustar-test'))
        self.assertEqual('already_applied', self.manager.apply()['phase'])
        self.assertEqual(0, self.host.worker_calls)
        self.assertEqual('rolled_back', self.manager.rollback()['phase'])
        self.assert_original_pair()

    def test_wrong_runtime_versions_or_pending_upgrade_refuse_before_writes(self):
        self.host.versions = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}
        with self.assertRaises(x.Refusal): self.manager.apply()
        self.assert_original_pair()
        self.host.versions = x.TARGET
        self.host.needs_upgrade = True
        with self.assertRaises(x.Refusal): self.manager.apply()
        self.assert_original_pair()

    def test_modified_script_state_schedule_or_install_receipt_refuses(self):
        for path in (self.p.script, self.p.installation, self.p.cron, self.p.receipt):
            with self.subTest(path=path):
                original = path.read_bytes()
                path.write_bytes(original + b'changed')
                with self.assertRaises(x.Refusal): self.manager.apply()
                self.assertEqual(original + b'changed', path.read_bytes())
                path.write_bytes(original)

    def test_real_cron_exclusion_and_backup_exclusion(self):
        import fcntl
        with self.manager.locked():
            with self.assertRaises(x.Refusal):
                with self.manager.locked(): pass
        with self.p.backup_lock.open('r+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(x.Refusal): self.manager.apply()
        self.assert_original_pair()

    def test_second_write_failure_restores_known_pair(self):
        real = self.manager.replace
        count = 0
        def fail_second(path, raw, meta):
            nonlocal count
            count += 1
            if count == 2: raise OSError('write failed')
            return real(path, raw, meta)
        with patch.object(self.manager, 'replace', side_effect=fail_second):
            with self.assertRaises(OSError): self.manager.apply()
        self.assert_original_pair()

    def test_interrupted_pair_requires_recovery_then_can_apply(self):
        real = self.manager.replace
        def crash_after_script(path, raw, meta):
            real(path, raw, meta)
            if path == self.p.script: raise SystemExit('simulated power loss')
        with patch.object(self.manager, 'replace', side_effect=crash_after_script):
            with self.assertRaises(SystemExit): self.manager.apply()
        # The implementation catches ordinary failures; a process death leaves a
        # durable prepared transaction and a mismatched pair, refusing scheduled work.
        self.assertEqual(UPDATED, self.p.script.read_bytes())
        self.assertEqual(self.before, self.p.installation.read_bytes())
        self.assertEqual('prepared', json.loads(self.p.record.read_text())['phase'])
        self.assertEqual('applied_waiting_for_scheduled_run', self.manager.apply()['phase'])
        self.assertEqual(UPDATED, self.p.script.read_bytes())

    def test_rollback_refuses_administrator_drift(self):
        self.manager.apply()
        self.p.script.write_bytes(b'admin replacement')
        with self.assertRaises(x.Refusal): self.manager.rollback()
        self.assertEqual(b'admin replacement', self.p.script.read_bytes())

    def test_symlink_input_refused_without_touching_target(self):
        original = self.p.script.read_bytes()
        other = self.p.script.with_name('other.py'); other.write_bytes(original)
        self.p.script.unlink(); self.p.script.symlink_to(other)
        with self.assertRaises(x.Refusal): self.manager.apply()
        self.assertEqual(original, other.read_bytes())


if __name__ == '__main__': unittest.main()
