"""Telegram relink source overlay, including recovery and prior Preview identity."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('_connect_relink_transactions', ROOT / 'tests/r16/test_route_editor_patch.py')
shared = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shared)
shared.MODULE = 'connect_relink_patch'
Contract = shared.Contract


class Transaction(shared.Transaction):
    def setUp(self):
        shared.Transaction.setUp(self)
        for name in (self.fix.CSP_JOURNAL, self.fix.PREVIEW_JOURNAL):
            journal = self.root / name
            journal.parent.chmod(0o700)
            journal.write_text('{"phase":"active"}\n')
            journal.chmod(0o600)

    def test_non_active_preview_is_refused_before_outage(self):
        (self.root / self.fix.PREVIEW_JOURNAL).write_text('{"phase":"files_applied"}')
        with self.assertRaisesRegex(self.m.Refusal, 'previous_studio_preview_update_not_active'):
            self.op.apply()
        self.assertNotIn('stop', self.host.calls)

    def test_missing_preview_is_refused_before_outage(self):
        (self.root / self.fix.PREVIEW_JOURNAL).unlink()
        with self.assertRaisesRegex(self.m.Refusal, 'previous_studio_preview_update_required'):
            self.op.apply()
        self.assertNotIn('stop', self.host.calls)


class RealPhp(unittest.TestCase):
    setUp = Transaction.setUp
    prepare_real_php_host = shared.RealPhp.prepare_real_php_host

    def test_php_lint_and_source_recovery(self):
        self.prepare_real_php_host()
        self.assertEqual('active', self.op.apply()['phase'])
        parsed = [c[1] for c in self.host.calls if isinstance(c, tuple) and c[0] == 'php-lint']
        self.assertCountEqual([self.new[p] for p in self.fix.FILES if p.endswith('.php')], parsed)
        self.assertEqual('rolled_back', self.op.recover()['phase'])

    def test_invalid_php_prevents_restart(self):
        self.prepare_real_php_host(invalid=True)
        with self.assertRaisesRegex(self.m.Refusal, 'php_lint_failed:local/ustar/classes/connect/identity_service.php'):
            self.op.apply()
        self.assertNotIn('stop', self.host.calls)
