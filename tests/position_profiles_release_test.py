"""Exercise the exact task-assignment baseline and the new additive schema checks."""

import json
import os
import unittest
import workplace_release_test as installer_fixture


class PositionProfilesReleaseTest(installer_fixture.WorkplaceReleaseTest):
    def setUp(self):
        super().setUp()
        release = self.release
        for key in ('ASSIGNMENT_STATE', 'HOME_STATE', 'REVIEW_STATE', 'PRIOR_STATE'):
            self.addCleanup(setattr, release, key, getattr(release, key))
            setattr(release, key, self.lab / key.lower())
        self.host.core.archive(installer_fixture.REPO, release.ASSIGNMENT, self.lab)
        self.baseline = self.host.manifest['before'][release.ASSIGNMENT]
        release.ASSIGNMENT_STATE.mkdir(mode=0o700)
        self.predecessor = release.ASSIGNMENT_STATE / 'state.json'
        self.write_predecessor('active')
        self.schema = None
        test_php = self.host.php

        def php(args, reason, **kwargs):
            if args == ['-r', release.PROFILE_SCHEMA_CHECK]:
                state = self.schema or (['present'] * 2 if self.runtime['version'] == release.AFTER else ['absent'] * 2)
                return json.dumps(state).encode()
            return test_php(args, reason, **kwargs)

        self.host.php = php

    def write_predecessor(self, step):
        release = self.release
        self.predecessor.write_text(json.dumps({
            'package': release.ASSIGNMENT_MANIFEST, 'baseline': release.HOME, 'step': step,
        }))
        os.chmod(self.predecessor, 0o600)

    def test_upgrade_requires_the_active_assignment_journal(self):
        self.write_predecessor('migration')
        with self.assertRaisesRegex(self.engine.Refused, 'assignment_installation_not_confirmed'):
            self.transaction.upgrade()
        self.assertEqual(self.runtime['copies'], 0)

    def test_existing_profile_schema_blocks_an_unrelated_baseline(self):
        self.schema = ['present', 'absent']
        with self.assertRaisesRegex(self.engine.Refused, 'unexpected_position_profile_schema'):
            self.transaction.upgrade()
        self.assertEqual(self.runtime['copies'], 0)

    def test_recover_a_partial_additive_migration(self):
        upgrade = self.host.upgrade_database

        def interrupt():
            self.schema = ['present', 'absent']
            raise self.engine.Refused('fixture_interrupted_additive_schema')

        self.host.upgrade_database = interrupt
        with self.assertRaisesRegex(self.engine.Refused, 'fixture_interrupted_additive_schema'):
            self.transaction.upgrade()
        self.assertEqual(self.transaction.check()['phase'], 'recovery_required')
        self.assertTrue(self.runtime['maintenance'])
        self.host.upgrade_database = upgrade
        self.schema = None
        self.assertEqual(self.transaction.recover()['phase'], 'active')
        self.assertEqual(self.runtime['copies'], 1)

    def test_active_server_missing_a_table_is_not_healthy(self):
        self.assertEqual(self.transaction.upgrade()['phase'], 'active')
        self.schema = ['present', 'absent']
        with self.assertRaisesRegex(self.engine.Refused, 'position_profile_schema_missing'):
            self.transaction.check()


if __name__ == '__main__':
    unittest.main()
