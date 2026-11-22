"""Exercise the new baseline and the predecessor journal using the existing host fixture."""
import json
import os
import unittest
from workplace_release_test import REPO, WorkplaceReleaseTest as _Base


class ReviewedBaseline(_Base):
    def setUp(self):
        self.release.BEFORE = 2026111800
        super().setUp()
        release = self.release
        self.addCleanup(setattr, release, 'PRIOR_STATE', release.PRIOR_STATE)
        self.addCleanup(setattr, release, 'BEFORE', 2026111800)
        release.PRIOR_STATE = self.lab / 'previous'
        self.host.core.archive(REPO, release.WORKPLACE, self.lab)
        self.baseline = self.host.manifest['before'][release.WORKPLACE]
        self.runtime.update(version=2026112200, cron=2026112200)
        self.transaction.select_baseline()

    def test_prior_incomplete_journal_blocks_upgrade(self):
        release = self.release
        release.PRIOR_STATE.mkdir(mode=0o700)
        journal = release.PRIOR_STATE / 'state.json'
        state = {'package': release.PRIOR_MANIFEST, 'baseline': release.BASELINE, 'step': 'migration'}
        journal.write_text(json.dumps(state))
        os.chmod(journal, 0o600)
        with self.assertRaisesRegex(self.engine.Refused, 'unfinished_prior_workplace_installation'):
            self.transaction.upgrade()
        self.assertEqual(self.runtime['copies'], 0)
        journal.write_text(json.dumps({**state, 'step': 'active'}))
        self.assertEqual(self.transaction.upgrade()['phase'], 'active')


if __name__ == '__main__':
    unittest.main()
