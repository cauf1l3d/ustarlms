"""Exercise all accepted baselines and the Workplace Home predecessor journal."""
import json
import os
import unittest
from workplace_home_release_test import HomeBaseline
from workplace_release_test import REPO, WorkplaceReleaseTest
from workplace_review_release_test import ReviewedBaseline


class AssignmentBaseline(WorkplaceReleaseTest):
    def setUp(self):
        self.release.BEFORE = 2026111800
        super().setUp()
        release = self.release
        self.addCleanup(setattr, release, 'HOME_STATE', release.HOME_STATE)
        self.addCleanup(setattr, release, 'BEFORE', 2026111800)
        release.HOME_STATE = self.lab / 'home-state'
        self.host.core.archive(REPO, release.HOME, self.lab)
        self.baseline = self.host.manifest['before'][release.HOME]
        self.runtime.update(version=2026112301, cron=2026112301)
        self.transaction.select_baseline()

    def test_home_journal_guards_upgrade(self):
        release = self.release
        release.HOME_STATE.mkdir(mode=0o700)
        journal = release.HOME_STATE / 'state.json'
        state = {'package': release.ASSIGNMENT_HOME_MANIFEST, 'baseline': release.REVIEW, 'step': 'migration'}
        journal.write_text(json.dumps(state))
        os.chmod(journal, 0o600)
        with self.assertRaisesRegex(self.engine.Refused, 'unfinished_home_installation'):
            self.transaction.upgrade()
        self.assertEqual(self.runtime['copies'], 0)
        journal.write_text(json.dumps({**state, 'step': 'active'}))
        self.assertEqual(self.transaction.upgrade()['phase'], 'active')


if __name__ == '__main__':
    unittest.main()
