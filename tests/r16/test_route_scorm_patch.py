"""SCORM source overlay: real files/locks/journal, Docker boundary controlled."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def module():
    spec = importlib.util.spec_from_file_location('route_scorm_patch', ROOT / 'scripts/infra/route_scorm_patch.py')
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class Crash(BaseException):
    pass


class Host:
    def __init__(self):
        self.running = True
        self.calls = []
        self.fail_start = False

    def identity(self):
        return {'Id': 'fixed-web-id', 'State': {'Running': self.running}}

    def lint(self, raw):
        self.calls.append('lint')

    def stop(self):
        self.calls.append('stop')
        self.running = False

    def start(self):
        self.calls.append('start')
        if self.fail_start:
            self.fail_start = False
            raise RuntimeError('start_failed_once')
        self.running = True


class Transaction(unittest.TestCase):
    def setUp(self):
        self.m = module()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.web = self.root / self.m.WEB
        self.web.mkdir(parents=True)
        self.host = Host()
        self.before = {p: ('old-' + p).encode() for p in self.m.CHANGED}
        self.before['local/ustar/version.php'] = b'accepted2900'
        self.before['theme/ustar/version.php'] = b'accepted2700'
        self.after = {**self.before, **{p: ('new-' + p).encode() for p in self.m.FILES}}
        for name, raw in self.before.items():
            p = self.web / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(raw)
            p.chmod(0o750 if name.endswith('scorm_launch.php') else 0o640)
        # Both added files have existing, trusted parent directories on production.
        for name in self.m.ADDED:
            (self.web / name).parent.mkdir(parents=True, exist_ok=True)
        for name in self.m.LOCKS:
            p = self.root / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.touch(mode=0o600)
        def image(raw):
            return {'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw)}
        self.manifest = {'schema': 1, 'baseline_commit': self.m.BASELINE,
                         'application_commit': self.m.APPLICATION, 'tool_commit': '3' * 40,
                         'before': {p: image(raw) for p, raw in self.before.items()},
                         'after': {p: image(raw) for p, raw in self.after.items()}}
        self.payload = {p: self.after[p] for p in self.m.FILES}
        self.tx = self.m.Patch(self.root, self.manifest, self.payload, self.host)

    def check_source(self, values):
        for name, raw in values.items():
            self.assertEqual(raw, (self.web / name).read_bytes(), name)

    def test_apply_is_bounded_and_preserves_owners_modes_and_unrelated_writes(self):
        old = {p: (self.web / p).stat() for p in self.m.CHANGED}
        extra = self.web / 'local/ustar/operator-note.txt'
        extra.write_bytes(b'keep')
        self.assertEqual('ready', self.tx.check()['phase'])
        self.assertFalse(self.tx.base.exists())  # --check creates no journal or lock.
        self.assertEqual('active', self.tx.apply()['phase'])
        self.check_source(self.after)
        for name, st in old.items():
            current = (self.web / name).stat()
            self.assertEqual((st.st_uid, st.st_gid, st.st_mode & 0o777),
                             (current.st_uid, current.st_gid, current.st_mode & 0o777))
        self.assertEqual(b'keep', extra.read_bytes())
        self.assertEqual(['stop', 'start'], [c for c in self.host.calls if c != 'lint'])
        self.tx.apply()
        self.assertEqual(1, self.host.calls.count('stop'))

    def test_recover_restores_only_code_and_keeps_later_business_data(self):
        self.tx.apply()
        business = self.root / 'business-after-apply'
        business.write_bytes(b'new-results')
        self.assertEqual('rolled_back', self.tx.recover()['phase'])
        self.check_source(self.before)
        self.assertTrue(all(not (self.web / p).exists() for p in self.m.ADDED))
        self.assertEqual(b'new-results', business.read_bytes())
        self.tx.recover()
        self.assertTrue(self.host.running)

    def test_unknown_baseline_is_rejected_before_any_outage(self):
        (self.web / next(iter(self.m.CHANGED))).write_bytes(b'foreign')
        with self.assertRaises(self.m.Refusal):
            self.tx.apply()
        self.assertNotIn('stop', self.host.calls)
        self.assertTrue(self.host.running)

    def test_target_symlink_is_never_followed_or_overwritten(self):
        outside = self.root / 'outside'
        outside.write_bytes(b'untouched')
        (self.web / next(iter(self.m.ADDED))).symlink_to(outside)
        with self.assertRaises(self.m.Refusal):
            self.tx.apply()
        self.assertEqual(b'untouched', outside.read_bytes())
        self.assertNotIn('stop', self.host.calls)

    def test_partial_write_after_process_loss_is_recoverable(self):
        write = self.tx.write
        count = 0
        def interrupted(name, raw, metadata):
            nonlocal count
            write(name, raw, metadata)
            count += 1
            if count == 1:
                raise Crash()
        with patch.object(self.tx, 'write', interrupted), self.assertRaises(Crash):
            self.tx.apply()
        self.assertFalse(self.host.running)
        replacement = self.m.Patch(self.root, self.manifest, self.payload, self.host)
        replacement.recover()
        self.check_source(self.before)
        self.assertTrue(self.host.running)

    def test_start_failure_restores_old_source_and_original_running_state(self):
        self.host.fail_start = True
        with self.assertRaises(RuntimeError):
            self.tx.apply()
        self.check_source(self.before)
        self.assertTrue(self.host.running)
        self.assertEqual('rolled_back', self.tx.check()['phase'])

    def test_corrupt_backup_or_foreign_later_code_refuses_recovery_without_stopping_web(self):
        self.tx.apply()
        self.host.calls.clear()
        p = self.web / next(iter(self.m.CHANGED))
        p.write_bytes(b'later-release')
        with self.assertRaises(self.m.Refusal):
            self.tx.recover()
        self.assertNotIn('stop', self.host.calls)
        self.assertEqual(b'later-release', p.read_bytes())

    def test_existing_managed_cron_lock_blocks_changes(self):
        import fcntl
        with (self.root / 'run/ustar-cron/lock').open('rb') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(self.m.Refusal, 'managed_operation_busy_retry_later'):
                self.tx.apply()
        self.assertNotIn('stop', self.host.calls)
        self.check_source(self.before)

    def test_other_source_changes_are_rejected_before_a_recovery_outage(self):
        self.tx.apply()
        self.host.calls.clear()
        (self.web / 'local/ustar/version.php').write_bytes(b'newer-version')
        with self.assertRaises(self.m.Refusal):
            self.tx.recover()
        self.assertNotIn('stop', self.host.calls)
        self.assertTrue(self.host.running)

    def test_actual_uid33_root_group_survive_apply_and_recovery(self):
        if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE') != '1':
            self.skipTest('actual Unix owner fixture runs in CI under sudo')
        name = next(iter(self.m.CHANGED))
        os.chown(self.web / name, 33, 0)
        self.tx.apply()
        self.assertEqual((33, 0), ((self.web / name).stat().st_uid, (self.web / name).stat().st_gid))
        self.tx.recover()
        self.assertEqual((33, 0), ((self.web / name).stat().st_uid, (self.web / name).stat().st_gid))


class Package(unittest.TestCase):
    def test_exact_git_package_is_deterministic_and_plan_has_no_host_access(self):
        sys.path.insert(0, str(ROOT / 'scripts/infra'))
        try:
            import build_route_scorm_patch as builder
            # This old2900 overlay is immutable; newer application trees must be refused.
            head = '51332a218f9ff58f5aa7074f80a1a00426502108'
            with tempfile.TemporaryDirectory() as temp:
                first = builder.build(head, Path(temp) / 'a', repo=ROOT)
                second = builder.build(head, Path(temp) / 'b', repo=ROOT)
                archive = Path(first['archive'])
                self.assertEqual(archive.read_bytes(), Path(second['archive']).read_bytes())
                with tarfile.open(archive) as tar:
                    self.assertEqual({'update.py', 'INSTALL.json', 'SHA256SUMS'}, set(tar.getnames()))
                    values = {member.name: tar.extractfile(member).read() for member in tar}
                for line in values['SHA256SUMS'].decode().splitlines():
                    sha, name = line.split('  ')
                    self.assertEqual(sha, hashlib.sha256(values[name]).hexdigest())
                script = Path(temp) / 'update.py'
                script.write_bytes(values['update.py'])
                plan = subprocess.check_output([sys.executable, '-I', '-S', str(script)])
                self.assertEqual('PLAN_ONLY', json.loads(plan)['phase'])
                self.assertFalse(json.loads(plan)['host_access'])
        finally:
            sys.path.pop(0)
