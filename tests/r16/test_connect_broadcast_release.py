"""Check the frozen release successor and the live announcement flag contract."""
import subprocess
import unittest
from unittest.mock import patch

from scripts.infra import connect_broadcast_release as successor


class BroadcastReleaseTest(unittest.TestCase):
    def setUp(self):
        self.source = subprocess.check_output([
            'git', 'show', successor.FROZEN_COMMIT + ':scripts/infra/connect_team_release.py',
        ])
        self.target = 'd' * 40

    def test_upgrade_targets_the_installed_pair_and_keeps_existing_activation(self):
        release = successor.successor(self.source, self.target)
        self.assertEqual((successor.BASELINE,), release.BASES)
        self.assertEqual(successor.BASELINE, release.WORKER_BEFORE)
        self.assertEqual((2026111200, 2026111201, 2026110200), (release.BEFORE, release.AFTER, release.THEME))
        self.assertEqual('ustar-connect-broadcast-20261112', release.STATE.name)
        host = object.__new__(release.Host)
        base_host = release.Host.__mro__[1]
        for flag in (False, True):
            with patch.object(base_host, 'verify', return_value={'announcements_enabled': flag}) as verify:
                self.assertEqual({'announcements_enabled': flag}, host.verify(worker=successor.BASELINE, active=False))
                verify.assert_called_once_with(worker=successor.BASELINE, active=True, interrupted=False)

    def test_modified_frozen_installer_is_refused(self):
        with self.assertRaisesRegex(ValueError, 'frozen_connect_installer_changed'):
            successor.successor(self.source + b'\n', self.target)
