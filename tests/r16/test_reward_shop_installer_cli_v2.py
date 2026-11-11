"""Offline, read-only installer transaction and CLI failure-contract tests."""
import importlib.util, json, tempfile, unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/infra/reward_shop_release.py'
spec=importlib.util.spec_from_file_location('reward_shop_cli_v2',SCRIPT)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class ClientCLI(unittest.TestCase):
    def test_correct_root_required(self):
        calls=[]
        def run(argv, **kwargs):
            calls.append(argv)
            return SimpleNamespace(returncode=0)
        with patch.object(mod,'run',side_effect=run):
            self.assertEqual(mod.cli_preflight(),'/var/www/html/admin/cli')
        self.assertEqual(3,len(calls))
        self.assertTrue(all('/var/www/html/admin/cli/' in c[-1] for c in calls))
        self.assertTrue(all('/var/www/html/public/admin/cli' not in c[-1] for c in calls))

    def test_missing_cli_is_explicit_refusal(self):
        with patch.object(mod,'run',return_value=SimpleNamespace(returncode=1)):
            with self.assertRaisesRegex(mod.Refused,'moodle_cli_missing:/var/www/html/admin/cli/maintenance.php'):
                mod.cli_preflight()

    def test_failure_journal_acceptance_and_rejection(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)
            backup=path/'backups'/'ustar-reward-shop-prev';backup.mkdir(parents=True)
            for name in ('postgres-before.dump','local-ustar-before.tar.gz'):(backup/name).write_text('fake test-only')
            original=path/'old'/'state.json';original.parent.mkdir()
            values={'phase':'rolled_back_before_migration','database_upgrade_started':False,
                   'target_commit':mod.TARGET,'backup':str(backup)}
            with patch.object(mod,'PREVIOUS_STATE',original), patch.object(mod,'BACKUP_DIR',backup.parent):
                self.assertEqual('none',mod.predecessor_status())
                original.write_text(json.dumps(values))
                self.assertEqual('rolled_back_before_migration',mod.predecessor_status())
                for phase in ['recovery_required','migration_started','active']:
                    original.write_text(json.dumps(dict(values,phase=phase)))
                    with self.assertRaisesRegex(mod.Refused,'previous_attempt_requires_manual_review'):
                        mod.predecessor_status()

    def test_ready_preflight_checks_cli_before_backup(self):
        manifest={'before': {'a':'123'}}
        with patch.object(mod.os,'geteuid',return_value=0),\
             patch.object(mod.shutil,'which',return_value='/usr/bin/docker'),\
             patch.object(mod,'code_status',return_value=(mod.BEFORE, lambda _: [])),\
             patch.object(mod,'db_status',return_value={'version':mod.BEFORE,'tables':{x:False for x in mod.TABLES}}),\
             patch.object(mod,'relink_status'),\
             patch.object(mod,'predecessor_status',return_value='rolled_back_before_migration'),\
             patch.object(mod,'cli_preflight',return_value=mod.CONTAINER_CLI) as cli:
            x=mod.inspect(manifest)
            self.assertEqual(x['phase'],'ready')
            self.assertEqual(x['cli_root'],mod.CONTAINER_CLI)
            cli.assert_called_once()

    def test_upgrade_uses_correct_cli_for_every_step(self):
        calls=[];states=[]
        manifest={'changed':[], 'before':{},'after':{}}
        def run(argv,**kwargs):
            calls.append(argv)
            return SimpleNamespace(returncode=0)
        with patch.object(mod,'inspect',side_effect=[{'phase':'ready'}, {'phase':'active','shop_enabled':False}]),\
             patch.object(mod,'read_state',return_value=None),\
             patch.object(mod,'predecessor_status',return_value='rolled_back_before_migration'),\
             patch.object(mod,'cli_preflight',return_value=mod.CONTAINER_CLI),\
             patch.object(mod,'backup',return_value='/test-backups/backup'),\
             patch.object(mod,'write_state',side_effect=lambda x:states.append(dict(x))),\
             patch.object(mod,'replace_code',return_value=[]),\
             patch.object(mod,'php_lint'), patch.object(mod,'run',side_effect=run):
            result=mod.upgrade('/test-package',manifest)
        self.assertEqual(result['phase'],'active')
        php=[x for x in calls if 'php' in x]
        self.assertEqual(4,len(php))
        self.assertTrue(all(any('/var/www/html/admin/cli/' in a for a in x) for x in php))
        self.assertEqual([next(a.split('/')[-1] for a in x if a.startswith('/var/www/html/admin/cli/')) for x in php],
            ['maintenance.php','upgrade.php','purge_caches.php','maintenance.php'])
        self.assertEqual(states[-1]['phase'],'active')

if __name__=='__main__':unittest.main()