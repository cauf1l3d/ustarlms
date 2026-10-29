"""Actual PHP media normalization/presentation; native storage/ACL remain in Moodle CI."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('php'), 'PHP CLI unavailable; mandatory in source CI')
class FeedMedia(unittest.TestCase):
    def test_rss_duplicates_and_compact_cards_preserve_other_attachments(self):
        for case in ('provider_variants', 'distinct_urls', 'old_byte_duplicates',
                     'stored_provider_variants', 'employee_and_documents', 'compact_cover'):
            with self.subTest(case=case):
                r = subprocess.run(['php', str(ROOT / 'tests/release_20260912/feed_media.php'), case],
                                   capture_output=True, text=True, timeout=15)
                self.assertEqual(0, r.returncode, r.stdout + r.stderr)
                self.assertEqual({'case': case, 'status': 'passed'}, json.loads(r.stdout))
