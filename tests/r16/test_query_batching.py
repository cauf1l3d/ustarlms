"""Production PHP + SQLite; does not claim native Moodle/PostgreSQL or page latency."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('php'), 'PHP CLI unavailable; required in source CI')
class QueryBatching(unittest.TestCase):
    def test_rss_and_operator_query_bounds_preserve_results(self):
        for case in ('rss_statuses', 'rss_empty', 'open', 'open_filtered', 'closed',
                     'closed_missing', 'closed_limit', 'empty', 'denied'):
            with self.subTest(case=case):
                result = subprocess.run(['php', str(ROOT / 'tests/release_20260912/query_batching.php'), case],
                                        capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                value = json.loads(result.stdout)
                self.assertEqual((value['case'], value['status']), (case, 'passed'))
