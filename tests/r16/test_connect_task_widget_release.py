"""Verify the allowed upgrade path without reading or writing a host."""
import subprocess
import unittest

from scripts.infra import connect_broadcast_release as broadcast
from scripts.infra import connect_checklist_widget_release as checklist
from scripts.infra import connect_task_widget_release as task


def installer(commit, name):
    return subprocess.check_output(['git', 'show', f'{commit}:scripts/infra/{name}.py'])


class TaskWidgetReleaseTest(unittest.TestCase):
    def test_upgrade_requires_the_exact_checklist_application_and_worker(self):
        frozen = installer(broadcast.FROZEN_COMMIT, 'connect_team_release')
        previous = installer(checklist.PREDECESSOR_COMMIT, 'connect_broadcast_release')
        baseline = installer(task.BASELINE, 'connect_checklist_widget_release')
        target = 'e' * 40
        engine = task.successor(frozen, target, predecessor_source=previous, checklist_source=baseline)
        self.assertEqual((task.BASELINE,), engine.BASES)
        self.assertEqual(task.BASELINE, engine.WORKER_BEFORE)
        self.assertEqual((2026111301, 2026111400, 2026110200), (engine.BEFORE, engine.AFTER, engine.THEME))
        self.assertEqual(target, engine.TARGET)
        self.assertEqual(task.STATE, engine.STATE)
        with self.assertRaisesRegex(ValueError, 'frozen_checklist_installer_changed'):
            task.successor(frozen, target, predecessor_source=previous, checklist_source=baseline + b'\n')
