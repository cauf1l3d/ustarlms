"""Exercise the source-only successor using actual local file writes and restores."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from scripts.infra import reward_shop_ux_release as ux


class RewardShopUxReleaseTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        core_bytes = subprocess.check_output([
            'git', 'show', ux.CORE_COMMIT + ':scripts/infra/reward_shop_release.py',
        ])
        self.core = ux.engine(core_bytes)
        self.core.HOST_APP = self.root / 'public'
        self.core.STATE_DIR = self.root / 'state'
        self.folder = self.root / 'update'
        self.live = self.core.HOST_APP / 'local/ustar'
        self.before = {}
        self.after = {}
        for name in sorted(ux.CHANGES):
            payload = self.folder / 'payload' / name
            payload.parent.mkdir(parents=True, exist_ok=True)
            payload.write_text('new ' + name)
            self.after[name] = self.core.digest(payload)
            if name not in ux.ADDED:
                path = self.live / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('old ' + name)
                self.before[name] = self.core.digest(path)
        (self.live / 'version.php').write_text(f'<?php $plugin->version = {ux.VERSION};')
        self.before['version.php'] = self.after['version.php'] = self.core.digest(self.live / 'version.php')
        self.manifest = {
            'before': self.before, 'after': self.after, 'theme': {}, 'changed': sorted(ux.CHANGES),
            'target_commit': 'fixture',
        }
        installed = self.root / 'v3'
        installed.mkdir(mode=0o700)
        journal = installed / 'state.json'
        journal.write_text(json.dumps({'phase': 'active', 'target_commit': ux.BASE, 'database_upgrade_started': True}))
        journal.chmod(0o600)
        self.core.GUARD_PATHS = [self.root / 'lock']
        self.core.GUARD_PATHS[0].touch(mode=0o600)
        db = {'version': ux.VERSION, 'tables': dict.fromkeys(self.core.TABLES, True)}
        for context in (
            patch.object(ux, 'STATE', self.core.STATE_DIR),
            patch.object(ux, 'INSTALLED', journal),
            patch.object(ux, 'MAINTENANCE', self.root / 'maintenance'),
            patch.object(self.core, 'db_status', return_value=db),
            patch.object(self.core, 'cli_preflight'),
        ):
            self.enterContext(context)
        self.enable = self.enterContext(patch.object(ux, 'maintenance', side_effect=self.switch_maintenance))
        self.purge = self.enterContext(patch.object(self.core, 'cli'))
        self.lint = self.enterContext(patch.object(self.core, 'php_lint'))

    def switch_maintenance(self, core, mode):
        if mode == '--enable':
            ux.MAINTENANCE.touch()
        else:
            ux.MAINTENANCE.unlink(missing_ok=True)

    def test_update_matches_candidate_and_preserves_predecessor(self):
        predecessor = ux.INSTALLED.read_bytes()
        self.assertEqual(ux.inspect(self.core, self.manifest)['phase'], 'ready')
        self.assertEqual(ux.upgrade(self.core, self.folder, self.manifest)['phase'], 'active')
        self.assertEqual(self.core.scan(self.live), self.after)
        self.assertEqual(ux.inspect(self.core, self.manifest)['phase'], 'active')
        self.assertFalse(ux.MAINTENANCE.exists())
        self.assertEqual(ux.INSTALLED.read_bytes(), predecessor)
        self.purge.assert_called_once_with('purge_caches.php')

    def test_failed_lint_restores_changed_and_added_paths(self):
        self.lint.side_effect = self.core.Refused('lint_failed')
        with self.assertRaisesRegex(self.core.Refused, 'lint_failed'):
            ux.upgrade(self.core, self.folder, self.manifest)
        self.assertEqual(self.core.scan(self.live), self.before)
        self.assertFalse(ux.MAINTENANCE.exists())
        self.assertEqual(ux.private_state(self.core, ux.STATE)['phase'], 'rolled_back')
        self.assertEqual(ux.inspect(self.core, self.manifest)['phase'], 'ready')

    def test_existing_maintenance_refuses_before_writes(self):
        ux.MAINTENANCE.touch()
        with self.assertRaisesRegex(self.core.Refused, 'existing_maintenance'):
            ux.upgrade(self.core, self.folder, self.manifest)
        self.assertEqual(self.core.scan(self.live), self.before)
        self.assertFalse(ux.STATE.exists())
        self.enable.assert_not_called()
