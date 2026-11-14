"""Pin the guarded deployment to the previously verified application and worker."""
import subprocess
import unittest
from unittest.mock import patch

from scripts.infra import connect_broadcast_release as broadcast
from scripts.infra import connect_checklist_widget_release as widget


class ChecklistWidgetReleaseTest(unittest.TestCase):
    def test_accepts_the_exact_predecessor_with_announcements_open(self):
        def read(commit, name):
            return subprocess.check_output(['git', 'show', commit + ':scripts/infra/' + name + '.py'])

        frozen = read(broadcast.FROZEN_COMMIT, 'connect_team_release')
        predecessor = read(widget.PREDECESSOR_COMMIT, 'connect_broadcast_release')
        target = 'e' * 40
        engine = widget.successor(frozen, target, predecessor_source=predecessor)
        self.assertEqual((widget.BASELINE,), engine.BASES)
        self.assertEqual(widget.BASELINE, engine.WORKER_BEFORE)
        self.assertEqual((2026111300, 2026111301, 2026110200), (engine.BEFORE, engine.AFTER, engine.THEME))
        self.assertEqual(target, engine.TARGET)
        self.assertEqual(widget.STATE, engine.STATE)
        host = object.__new__(engine.Host)
        with patch.object(engine.Host.__mro__[1], 'verify', return_value={'announcements_enabled': True}) as verify:
            self.assertTrue(host.verify(worker=widget.BASELINE, active=False)['announcements_enabled'])
            verify.assert_called_once_with(worker=widget.BASELINE, active=True, interrupted=False)
        with self.assertRaisesRegex(ValueError, 'frozen_broadcast_installer_changed'):
            widget.successor(frozen, target, predecessor_source=predecessor + b'\n')
