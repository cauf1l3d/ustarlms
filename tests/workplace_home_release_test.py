"""Verify the new production baseline and retain earlier transitions and recovery tests."""
import json
import os
import unittest
from workplace_release_test import REPO, WorkplaceReleaseTest
from workplace_review_release_test import ReviewedBaseline


class HomeBaseline(WorkplaceReleaseTest):
    def setUp(self):
        self.release.BEFORE = 2026111800
        super().setUp()
        release = self.release
        self.addCleanup(setattr, release, 'REVIEW_STATE', release.REVIEW_STATE)
        self.addCleanup(setattr, release, 'BEFORE', 2026111800)
        release.REVIEW_STATE = self.lab / 'review-state'
        self.host.core.archive(REPO, release.REVIEW, self.lab)
        self.baseline = self.host.manifest['before'][release.REVIEW]
        self.runtime.update(version=2026112300, cron=2026112300)
        self.transaction.select_baseline()

    def test_unfinished_review_blocks_new_upgrade(self):
        release = self.release
        release.REVIEW_STATE.mkdir(mode=0o700)
        journal = release.REVIEW_STATE / 'state.json'
        state = {'package': release.HOME_REVIEW_MANIFEST, 'baseline': release.WORKPLACE, 'step': 'migration'}
        journal.write_text(json.dumps(state))
        os.chmod(journal, 0o600)
        with self.assertRaisesRegex(self.engine.Refused, 'unfinished_review_installation'):
            self.transaction.upgrade()
        self.assertEqual(self.runtime['copies'], 0)
        journal.write_text(json.dumps({**state, 'step': 'active'}))
        self.assertEqual(self.transaction.upgrade()['phase'], 'active')


if __name__ == '__main__':
    unittest.main()
