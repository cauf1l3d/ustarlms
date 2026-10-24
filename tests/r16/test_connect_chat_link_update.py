"""Real paired filesystem/cron rollback; Docker, SQL and HTTPS transport modeled."""
import copy
import fcntl
import hashlib
import importlib
import io
import json
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT/'scripts/infra'), str(ROOT/'scripts'), str(ROOT/'tests/r16')]
import test_connect_academy_installer as fixtures
import connect_chat_link_update as update


class Host(fixtures.Host):
    def cli_upgrade(self):
        self.d.attempt += 1
        data = self.database('mdl_')
        data['addons'].update({k:str(v) for k,v in update.TARGET.items()})
        (self.d.p.PGDATA/'db.json').write_text(json.dumps(data))
        (self.d.p.DATA/'migration').write_bytes(b'candidate cache')
        if self.d.attempt == self.d.fail_at: raise RuntimeError('PRIVATE_SENTINEL')


class PairedUpdate(unittest.TestCase):
    def setUp(self):
        fixtures.Transaction.setUp(self)
        self.d.reference['baseline']['versions'] = update.CURRENT
        self.d.reference['candidate']['versions'] = update.TARGET
        self.d.reference['candidate']['tables'] = self.d.reference['baseline']['tables']
        self.d.reference['source_commit'] = update.PAYLOAD
        self.d.manifest = {'tool_commit':'a'*40}
        db = json.loads((self.d.p.PGDATA/'db.json').read_bytes())
        db['addons'].update({k:str(v) for k,v in update.CURRENT.items()})
        db['tasks'][update.engine.NEW_TASK] = [1,1]
        self.baseline = copy.deepcopy(db)
        (self.d.p.PGDATA/'db.json').write_text(json.dumps(db))
        self.before = {n:self.d.tx.inventory(self.d,t) for n,t in self.d.trees.items()}
        self.source = b"VERSIONS = {'local_ustar': '2026101905', 'theme_ustar': '2026100202'}\n"
        self.target = self.source.replace(b'2026101905',b'2026102000')
        self.d.controls = update.Controls(root=self.root,uid=os.getuid())
        cp = self.d.controls.p
        for p in (cp.script,cp.installation,cp.cron): p.parent.mkdir(parents=True,exist_ok=True)
        cp.script.write_bytes(self.source);cp.script.chmod(0o750)
        self.installation = {'schema':1,'script_sha256':hashlib.sha256(self.source).hexdigest(),
            'versions':{k:str(v) for k,v in update.CURRENT.items()},'containers':{'preserved':'identity'}}
        cp.installation.write_text(json.dumps(self.installation));cp.installation.chmod(0o600)
        cp.cron.write_bytes(update.cron.CRON_TEXT);cp.cron.chmod(0o644)
        for p in (cp.cron_lock,cp.backup_lock,self.d.controls.https_lock):
            p.parent.mkdir(parents=True,exist_ok=True);p.touch(mode=0o600)
        self.cron_before = (cp.script.read_bytes(),cp.installation.read_bytes())
        for target,name,value in ((update,'CURRENT_CRON_SHA',hashlib.sha256(self.source).hexdigest()),
                (update,'TARGET_CRON_SHA',hashlib.sha256(self.target).hexdigest()),
                (update,'Host',Host),(update,'preflight',lambda d:None),
                (update,'https_snapshot',lambda d:{'accepted':'unchanged'}),
                (update.engine,'fresh_hdd',lambda d,m,h:{'sha256':'b'*64}),
                (update.engine,'references',lambda *args:1),
                (update.engine,'capacity',lambda *args:{}),
                (update.engine,'ROOT_UID',os.getuid())):
            p = patch.object(target,name,value);p.start();self.addCleanup(p.stop)

    def run_update(self):
        with patch.object(update.sys,'stdout',new_callable=io.StringIO) as output:
            result=update.upgrade(self.d)
        self.assertNotIn('PRIVATE_SENTINEL',output.getvalue())
        return result

    def assert_restored(self):
        self.assertEqual(self.before,{n:self.d.tx.inventory(self.d,t) for n,t in self.d.trees.items()})
        cp=self.d.controls.p
        self.assertEqual(self.cron_before,(cp.script.read_bytes(),cp.installation.read_bytes()))
        self.assertTrue(all(self.d.running.values()))

    def test_success_preserves_schema_task_flags_https_and_updates_matching_cron_pair(self):
        self.assertEqual(0,self.run_update());self.assertEqual(2,self.d.attempt)
        db=json.loads((self.d.p.PGDATA/'db.json').read_bytes())
        self.assertEqual(self.baseline['schema'],db['schema'])
        self.assertEqual(self.baseline['tasks'],db['tasks'])
        cp=self.d.controls.p
        self.assertEqual(self.target,cp.script.read_bytes())
        self.assertEqual(0o750,cp.script.stat().st_mode & 0o7777)
        self.assertEqual('2026102000',json.loads(cp.installation.read_bytes())['versions']['local_ustar'])
        self.assertEqual(self.config,(self.d.p.CODE/'config.php').read_bytes())

    def test_upgrade_and_repeat_failures_restore_code_db_data_and_old_cron(self):
        self.d.fail_at=1;self.assertEqual(1,self.run_update());self.assert_restored()

    def test_repeat_failure_restores_pair(self):
        self.d.fail_at=2;self.assertEqual(1,self.run_update());self.assert_restored()

    def test_failure_between_two_cron_writes_restores_entire_pair(self):
        original=self.d.controls.writer.replace
        def fail(path,raw,meta):
            if path==self.d.controls.p.installation and b'2026102000' in raw: raise OSError('PRIVATE_SENTINEL')
            return original(path,raw,meta)
        with patch.object(self.d.controls.writer,'replace',side_effect=fail):
            self.assertEqual(1,self.run_update())
        self.assert_restored()

    def test_schema_or_task_mutation_refuses_and_recovers(self):
        original=Host.cli_upgrade
        def altered(host):
            original(host);data=host.database('mdl_');data['tasks']['existing']=[0,0]
            (host.d.p.PGDATA/'db.json').write_text(json.dumps(data))
        with patch.object(Host,'cli_upgrade',altered):self.assertEqual(1,self.run_update())
        self.assert_restored()

    def test_post_release_failure_keeps_new_data_and_matching_cron(self):
        self.d.fail_login=True
        self.assertEqual(1,self.run_update())
        self.assertEqual(self.target,self.d.controls.p.script.read_bytes())
        with self.assertRaisesRegex(ValueError,'late_restore_requires_discard_consent'):update.restore(self.d)

    def test_explicit_restore_returns_matching_baseline_and_cron(self):
        self.assertEqual(0,self.run_update());update.restore(self.d,discard=True);self.assert_restored()

    def test_drifted_cron_does_not_get_overwritten_during_restore(self):
        self.assertEqual(0,self.run_update());self.d.controls.p.script.write_bytes(b'admin drift')
        with self.assertRaises(Exception):update.restore(self.d,discard=True)
        self.assertEqual(b'admin drift',self.d.controls.p.script.read_bytes())
        self.assertFalse(any(self.d.running.values()))

    def test_wrong_cron_digest_refuses_before_container_actions(self):
        self.d.controls.p.script.write_bytes(b'unknown')
        with self.assertRaises(Exception):self.run_update()
        self.assertFalse(self.d.actions)

    def test_default_plan_does_not_load_any_host_input(self):
        with patch.object(update,'dependencies',side_effect=AssertionError('host')),patch.object(update.sys,'stdout',new_callable=io.StringIO) as out:
            self.assertEqual(0,update.main([]))
        self.assertIn('PLAN_ONLY',out.getvalue())

    def test_group_writable_code_and_readable_private_state_refuse_before_actions(self):
        cp=self.d.controls.p
        for path,mode,original in ((cp.script,0o770,0o750),(cp.installation,0o640,0o600)):
            path.chmod(mode)
            with self.assertRaises(Exception):self.run_update()
            self.assertFalse(self.d.actions);path.chmod(original)

    def test_schedule_drift_refuses_before_stop(self):
        self.d.controls.p.cron.write_bytes(b'admin schedule')
        with self.assertRaises(Exception):self.run_update()
        self.assertFalse(self.d.actions)

    def test_https_lock_contention_does_not_start_backup_or_stop(self):
        with self.d.controls.https_lock.open('rb') as lock:
            fcntl.flock(lock.fileno(),fcntl.LOCK_EX)
            with self.assertRaisesRegex(ValueError,'managed_lock_busy'):self.run_update()
        self.assertFalse(self.d.actions);self.assertFalse(self.d.marker.exists())

    def test_failed_hdd_backup_leaves_baseline_running_and_cron_unchanged(self):
        with patch.object(update.engine,'fresh_hdd',side_effect=RuntimeError('PRIVATE_SENTINEL')):
            self.assertEqual(1,self.run_update())
        self.assert_restored();self.assertEqual(0,self.d.attempt)

    def test_other_database_clients_refuse_without_activation(self):
        db=copy.deepcopy(self.baseline);db['other_clients']=1
        (self.d.p.PGDATA/'db.json').write_text(json.dumps(db))
        self.assertEqual(1,self.run_update());self.assertEqual(0,self.d.attempt)
        self.assertEqual(b'old',(self.d.web/'local/ustar/version.php').read_bytes())

    def test_failed_clean_stop_never_captures_pair(self):
        self.d.bad_stop=True
        self.assertEqual(1,self.run_update());self.assert_restored();self.assertEqual(0,self.d.attempt)

    def test_tampered_pair_refuses_before_changing_cron_or_live_files(self):
        self.assertEqual(0,self.run_update())
        m=json.loads(self.d.marker.read_bytes())
        (Path(m['run_directory'])/'baseline/code/config.php').write_bytes(b'bad pair')
        with self.assertRaises(Exception):update.restore(self.d,discard=True)
        self.assertEqual(self.target,self.d.controls.p.script.read_bytes())
        self.assertEqual(b'new',(self.d.web/'local/ustar/version.php').read_bytes())

    def test_cannot_stop_means_no_recovery_writes(self):
        self.assertEqual(0,self.run_update());self.d.cannot_stop=True
        with self.assertRaises(Exception):update.restore(self.d,discard=True)
        self.assertEqual(self.target,self.d.controls.p.script.read_bytes())

    def test_real_uid33_and_root_group_preserved_on_update_and_recovery(self):
        if os.getuid()!=0:self.skipTest('actual ownership needs disposable privileged CI')
        p=self.d.web/'local/ustar/version.php'
        try:os.chown(p,33,0)
        except OSError:
            if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')=='1':raise
            self.skipTest('UID33 is not mapped locally; privileged CI is mandatory')
        self.before={n:self.d.tx.inventory(self.d,t) for n,t in self.d.trees.items()}
        self.assertEqual(0,self.run_update())
        self.assertEqual((33,0),(p.stat().st_uid,p.stat().st_gid))
        update.restore(self.d,discard=True);self.assert_restored()
