"""Verify the pinned predecessor and the installed target and release profiles."""
import subprocess
import unittest
from unittest.mock import patch

from scripts.infra import connect_broadcast_release as broadcast
from scripts.infra import connect_delivery_repair_release as repair


def source(commit, file):
    return subprocess.check_output(['git', 'show', commit + ':scripts/infra/' + file])


class DeliveryRepairReleaseTest(unittest.TestCase):
    def test_exact_predecessor_and_enabled_announcements(self):
        frozen = source(broadcast.FROZEN_COMMIT, 'connect_team_release.py')
        predecessor = source(repair.PREDECESSOR_COMMIT, 'connect_broadcast_release.py')
        engine = repair.successor(frozen, 'c' * 40, predecessor_source=predecessor)
        self.assertEqual((repair.BASELINE,), engine.BASES)
        self.assertEqual(repair.BASELINE, engine.WORKER_BEFORE)
        self.assertEqual((2026111201, 2026111300, 2026110200), (engine.BEFORE, engine.AFTER, engine.THEME))
        self.assertEqual(repair.STATE, engine.STATE)
        host = object.__new__(engine.Host)
        with patch.object(engine.Host.__mro__[1], 'verify', return_value={'announcements_enabled': True}) as verify:
            self.assertTrue(host.verify(worker=repair.BASELINE, active=False)['announcements_enabled'])
            verify.assert_called_once_with(worker=repair.BASELINE, active=True, interrupted=False)
        with self.assertRaisesRegex(ValueError, 'frozen_broadcast_installer_changed'):
            repair.successor(frozen, 'c' * 40, predecessor_source=predecessor + b'\n')
