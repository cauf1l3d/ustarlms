"""Guarded owner installer contracts: busy retry, lock ordering and DB gate."""
import importlib.util
import json
import os
import tempfile
import unittest
from contextlib import nullcontext
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPT=Path(os.environ.get('USTAR_SHOP_INSTALLER_SOURCE',Path(__file__).resolve().parents[2]/'scripts/infra/reward_shop_release.py'))
spec=importlib.util.spec_from_file_location('shop_lock_fix_v3',SCRIPT)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class LockAndRetry(unittest.TestCase):
    def test_readonly_busy_is_retried_bounded_then_active(self):
        calls=[]
        def run(argv,**kwargs):
            calls.append(argv)
            if len(calls)<3:
                return SimpleNamespace(returncode=2,stdout=b'{"phase":"refused","reason":"managed_operation_busy_retry_later"}')
            return SimpleNamespace(returncode=0,stdout=b'{"phase":"active"}')
        with patch.object(mod,'RELINK',Path(__file__)),\
             patch.object(mod,'run',side_effect=run),patch.object(mod.time,'sleep') as sleep:
            self.assertEqual(mod.relink_status()['phase'],'active')
        self.assertEqual(len(calls),3)
        self.assertEqual(sleep.call_count,2)
        self.assertTrue(all(c[-1]=='--check' for c in calls))

    def test_persistent_busy_preserves_guard_and_refuses(self):
        x=SimpleNamespace(returncode=2,stdout=b'{"phase":"refused","reason":"managed_operation_busy_retry_later"}')
        with patch.object(mod,'RELINK',Path(__file__)),\
             patch.object(mod,'run',return_value=x) as run,patch.object(mod.time,'sleep') as sleep:
            with self.assertRaisesRegex(mod.Refused,'managed_operation_busy_retry_later'):
                mod.relink_status()
        self.assertEqual(run.call_count,5)
        self.assertEqual(sleep.call_count,4)

    def test_non_busy_refusal_is_not_retried(self):
        x=SimpleNamespace(returncode=2,stdout=b'{"phase":"refused","reason":"unsafe_managed_lock"}')
        with patch.object(mod,'RELINK',Path(__file__)),\
             patch.object(mod,'run',return_value=x) as run,patch.object(mod.time,'sleep') as sleep:
            with self.assertRaisesRegex(mod.Refused,'relink_check_refused:unsafe_managed_lock'):
                mod.relink_status()
        run.assert_called_once();sleep.assert_not_called()

    def test_guard_is_exclusive_and_never_deletes_locks(self):
        with tempfile.TemporaryDirectory() as tmp:
            guard=Path(tmp)/'operator.lock';guard.write_bytes(b'');guard.chmod(0o600)
            with patch.object(mod,'GUARD_PATHS',[guard]):
                with mod.exclusive_operator_guards():
                    with self.assertRaisesRegex(mod.Refused,'managed_operation_busy_retry_later'):
                        with mod.exclusive_operator_guards():pass
                with mod.exclusive_operator_guards():pass
            self.assertTrue(guard.is_file())

    def test_symlink_lock_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            base=Path(tmp)/'real';base.write_bytes(b'');base.chmod(0o600)
            link=Path(tmp)/'link';link.symlink_to(base)
            with patch.object(mod,'GUARD_PATHS',[link]):
                with self.assertRaisesRegex(mod.Refused,'managed_lock_missing'):
                    with mod.exclusive_operator_guards():pass

    def test_upgrade_holds_guard_through_transaction(self):
        calls=[]
        def guard():
            class TestGuard:
                def __enter__(self):calls.append('lock_enter')
                def __exit__(self,*exc):calls.append('lock_exit')
            return TestGuard()
        def run(argv,**kwargs):
            calls.append(argv)
            return SimpleNamespace(returncode=0)
        manifest={'changed':[],'before':{},'after':{}}
        before={'version':mod.BEFORE,'tables':{t:False for t in mod.TABLES}}
        with patch.object(mod,'inspect',side_effect=[{'phase':'ready'}, {'phase':'active','shop_enabled':False}]),\
             patch.object(mod,'read_state',return_value=None),\
             patch.object(mod,'predecessor_status',return_value='rolled_back_before_migration'),\
             patch.object(mod,'cli_preflight',return_value=mod.CONTAINER_CLI),\
             patch.object(mod,'exclusive_operator_guards',side_effect=guard),\
             patch.object(mod,'code_status',return_value=(mod.BEFORE,lambda _: [])),\
             patch.object(mod,'db_status',return_value=before),\
             patch.object(mod,'backup',return_value='/test-backups/backup'),\
             patch.object(mod,'write_state'),patch.object(mod,'replace_code',return_value=[]),\
             patch.object(mod,'php_lint'),patch.object(mod,'run',side_effect=run):
            result=mod.upgrade('/test-package',manifest)
        self.assertEqual(result['phase'],'active')
        self.assertEqual(calls[0],'lock_enter')
        self.assertEqual(calls[-1],'lock_exit')
        self.assertEqual([next(a.split('/')[-1] for a in c if a.startswith('/var/www/html/admin/cli/'))
                          for c in calls if isinstance(c,list)],
                         ['maintenance.php','upgrade.php','purge_caches.php','maintenance.php'])

    def test_database_drift_under_guard_prevents_backup(self):
        manifest={'changed':[],'before':{},'after':{}}
        mismatched={'version':mod.AFTER,'tables':{t:False for t in mod.TABLES}}
        with patch.object(mod,'read_state',return_value=None),\
             patch.object(mod,'code_status',return_value=(mod.BEFORE,lambda _: [])),\
             patch.object(mod,'db_status',return_value=mismatched),\
             patch.object(mod,'backup') as backup:
            with self.assertRaisesRegex(mod.Refused,'db_changed_while_acquiring_lock'):
                mod.upgrade_locked('/test-package',manifest)
        backup.assert_not_called()

if __name__=='__main__':unittest.main()