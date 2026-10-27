"""Real production PHP with a standalone SQL fixture; native Moodle/PG is a separate CI gate."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('php'), 'PHP CLI unavailable')
class NotificationAcknowledgement(unittest.TestCase):
    def run_case(self, case):
        result = subprocess.run(['php', str(ROOT/'tests/release_20260912/notification_ack.php'), case],
                                capture_output=True, text=True, timeout=15)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual({'case': case, 'status': 'passed'}, json.loads(result.stdout))

    def test_stale_read_cannot_erase_committed_acknowledgement(self):
        self.run_case('stale_read')

    def test_stale_ack_preserves_first_committed_timestamp(self):
        self.run_case('stale_ack')

    def test_repeat_ack_and_read_preserve_receipt(self):
        self.run_case('repeat')

    def test_mark_all_read_does_not_create_or_clear_ack(self):
        self.run_case('all_read')
