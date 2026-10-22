"""Actual cold filesystem copies/cron pair, explicitly modeled Docker and SQL."""
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'scripts/infra'), str(ROOT / 'tests/r16')]
import connect_con02_contract as c
import connect_con02_state as state
import test_connect_academy_installer as fixture


class Host(fixture.Host):
    def prefix(self): return 'mdl_'
    def source(self, *, target): self.d.addon.source(self.d, target)
    def https_snapshot(self): return {'verified': True, 'config': c.sha((self.d.p.CODE / 'config.php').read_bytes())}
    def controls(self, journal):
        p = self.d.control
        return {'files': {'include': state.capture(p)}, 'fence': {'notification_pilot_userids': [130],
            'notification_subjects': ['12345'], 'notify_since': 100, 'notifications_enabled': True,
            'linking_enabled': True, 'last_link_state': 'revoked'}}
    def preflight(self):
        return {'restart_policies': {}, 'roots': self.d.tx.roots(self.d), 'identities': self.d.identities}
    def service_active(self, name): return self.d.services[name]['active']
    def service_enabled(self, name): return self.d.services[name]['enabled']
    def service(self, action, name):
        self.d.actions.append((action, name))
        if action in ('start', 'stop'): self.d.services[name]['active'] = action == 'start'
        elif action in ('enable', 'disable'): self.d.services[name]['enabled'] = action == 'enable'
    def probe(self, profile): self.d.actions.append(('probe', profile))
    def fresh_backup(self, row):
        self.d.actions.append(('fresh', tuple(self.d.locks.held)))
        if self.d.fail_backup: raise c.Refusal('fresh_backup_failed')
        return {'sha256': 'b' * 64}
    def recover_backup(self): pass
    def capacity(self, cold=False): pass
    def restore_capacity(self, pair): pass
    def references(self, prefix): return 1
    def cli_upgrade(self):
        self.d.attempt += 1
        db = self.database('mdl_')
        db['addons']['local_ustar'] = '2026102200'
        db['tables'] = self.d.reference['candidate']['tables']
        db['schema'] = self.d.target_schema
        db['indexes'] = self.d.target_indexes
        (self.d.p.PGDATA / 'db.json').write_text(json.dumps(db))
        (self.d.p.DATA / 'migration').write_bytes(b'candidate cache')
        if self.d.attempt == self.d.fail_at: raise c.Refusal('upgrade_failed')


class Application(unittest.TestCase):
    def module(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_application'), 'new application transaction')
        import connect_con02_application as a
        return a

    def setUp(self):
        fixture.Transaction.setUp(self)
        c.configure_primitives(self.d)
        self.d.tx.reserve = lambda p: None  # Fixture filesystem has no production reserve contract.
        self.d.addon = fixture.x
        self.d.reference['baseline']['versions']['local_ustar'] = 2026102000
        self.d.reference['candidate']['versions']['local_ustar'] = 2026102200
        db = json.loads((self.d.p.PGDATA / 'db.json').read_bytes())
        db['addons']['local_ustar'] = '2026102000'
        db['tasks']['prune'] = [1, 1]
        db['schema'] = [['local_ustar_old', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None]]
        db['indexes'] = [['local_ustar_old', 'CREATE UNIQUE INDEX old ON mdl_local_ustar_old USING btree (id)']]
        self.d.target_schema = db['schema'] + [['local_ustar_new', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None]]
        self.d.target_indexes = db['indexes'] + [['local_ustar_new', 'CREATE UNIQUE INDEX new ON mdl_local_ustar_new USING btree (id)']]
        self.d.reference['baseline'].update(schema_fields={'local_ustar_old':{'id':{'type':'int','length':'10','notnull':True}}},
            schema_indexes={'local_ustar_old':[{'fields':['id'],'unique':True}]})
        self.d.reference['candidate'].update(schema_fields={'local_ustar_new': {'id': {'type': 'int', 'length': '10', 'notnull': True}}},
            schema_indexes={'local_ustar_new': [{'fields': ['id'], 'unique': True}]})
        (self.d.p.PGDATA / 'db.json').write_text(json.dumps(db))
        self.baseline = copy.deepcopy(db)
        self.d.control = self.root / 'include.php'; self.d.control.write_bytes(b'private include'); self.d.control.chmod(0o640)
        self.d.services = {n: {'active': True, 'enabled': True} for n in c.SERVICES}
        self.d.fail_backup = False
        self.ctx = c.Context(SimpleNamespace(reference={**self.d.reference, 'tool_commit': 'a' * 40}),
                             self.root, os.getuid(), Host(self.d, self.d.identities, io.BytesIO()), self.d)
        self.d.locks = state.Locks(self.ctx)
        for path in self.d.locks.paths[1:]:
            path.parent.mkdir(parents=True, exist_ok=True); path.touch(mode=0o600)
        self.cron = self.root / 'usr/local/sbin/USTAR_CRON_SETUP_20261005.py'
        self.install = self.root / 'var/lib/ustar-cron/installation.json'
        self.schedule = self.root / 'etc/cron.d/ustar_moodle'
        for p in (self.cron, self.install, self.schedule): p.parent.mkdir(parents=True, exist_ok=True)
        self.raw_cron = b"VERSIONS = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}\n"
        self.cron.write_bytes(self.raw_cron); self.cron.chmod(0o750)
        self.install.write_text(json.dumps({'schema': 1, 'versions': {'local_ustar': '2026102000', 'theme_ustar': '2026100202'},
                                          'script_sha256': c.sha(self.raw_cron), 'preserved': 'identity'}))
        self.install.chmod(0o600)
        import cron_release_update
        self.schedule.write_bytes(cron_release_update.CRON_TEXT)
        self.before = {n: self.d.tx.inventory(self.d, t) for n, t in self.d.trees.items()}

    def operation(self):
        a = self.module()
        replacement = patch.object(a, 'CRON_BEFORE_SHA', c.sha(self.raw_cron)); replacement.start(); self.addCleanup(replacement.stop)
        return a.Application(self.ctx, state.Journal(self.ctx), self.d.locks)

    def assert_restored(self):
        self.assertEqual(self.before, {n: self.d.tx.inventory(self.d, t) for n, t in self.d.trees.items()})
        self.assertEqual(self.raw_cron, self.cron.read_bytes())
        self.assertEqual(self.baseline, self.ctx.host.database('mdl_'))

    def test_fresh_backup_child_before_cold_locks(self):
        op = self.operation(); self.assertEqual('app_ready', op.upgrade()['phase'])
        held = next(v for action, v in self.d.actions if action == 'fresh')
        self.assertEqual(tuple(self.d.locks.paths[:4]), held)

    def test_direct_upgrade_repeat_preserves_custom_task(self):
        op = self.operation(); op.upgrade()
        self.assertEqual(2, self.d.attempt)
        self.assertEqual(self.baseline['tasks'], self.ctx.host.database('mdl_')['tasks'])
        self.assertEqual('2026102200', json.loads(self.install.read_bytes())['versions']['local_ustar'])
        op.upgrade(); self.assertEqual(2, self.d.attempt)

    def test_recovered_failure_is_recorded_only_in_private_log(self):
        op=self.operation();self.d.fail_at=1
        op.host.log=io.BytesIO()
        self.assertEqual('baseline_restored',op.upgrade()['phase'])
        self.assertIn(b'upgrade_failed',op.host.log.getvalue())

    def test_before_public_restore_exact_pair(self):
        op = self.operation(); self.d.fail_at = 2
        self.assertEqual('baseline_restored', op.upgrade()['phase']); self.assert_restored()

    def test_restore_capacity_uses_remaining_copy_budget(self):
        op=self.operation();self.d.fail_at=1
        checks=[]
        def capacity(cold=False):
            checks.append('upgrade')
            if op.row().get('live_trees_modified'):raise c.Refusal('upgrade_budget_no_longer_available')
        op.host.capacity=capacity
        op.host.restore_capacity=lambda pair:checks.append('restore_remaining_copy')
        self.assertEqual('baseline_restored',op.upgrade()['phase'])
        self.assertIn('restore_remaining_copy',checks);self.assert_restored()

    def test_partial_cron_pair_recovers(self):
        op = self.operation(); original = op.journal.put
        def fail(scope, name, side):
            if name == 'cron_installation' and side == 'after': raise c.Refusal('injected_partial_cron')
            return original(scope, name, side)
        with patch.object(op.journal, 'put', fail):
            self.assertEqual('baseline_restored', op.upgrade()['phase'])
        self.assert_restored()

    def test_after_public_keeps_new_writes(self):
        op = self.operation(); self.d.fail_login = True
        self.assertEqual('recovery_required', op.upgrade()['phase'])
        (self.d.p.DATA / 'new_public_write').write_bytes(b'durable')
        self.d.fail_login = False
        self.assertEqual('app_ready', op.recover()['phase'])
        self.assertEqual(b'durable', (self.d.p.DATA / 'new_public_write').read_bytes())
        self.assertEqual('2026102200', self.ctx.host.database('mdl_')['addons']['local_ustar'])
        with self.assertRaises(c.Refusal): op.restore()

    def test_sender_disabled_stays_disabled(self):
        op = self.operation(); self.d.services[c.SERVICES[0]] = {'active': False, 'enabled': False}
        op.upgrade(); self.assertEqual({'active': False, 'enabled': False}, self.d.services[c.SERVICES[0]])
        self.assertNotIn(('start', c.SERVICES[0]), self.d.actions)

    def test_backup_failure_does_not_stop_workers(self):
        op = self.operation(); self.d.fail_backup = True
        self.assertEqual('baseline_restored', op.upgrade()['phase'])
        self.assertFalse(any(a in ('stop', 'policy') for a, _ in self.d.actions))
        self.assert_restored()

    def test_unknown_cron_refuses_before_host_stop(self):
        op = self.operation(); self.cron.write_bytes(b'unknown cron')
        with self.assertRaises(c.Refusal): op.upgrade()
        self.assertEqual([], self.d.actions)

    def test_cold_pair_preserves_extended_metadata(self):
        op = self.operation()
        p = self.d.p.CODE / 'core.txt'
        os.setxattr(p, 'user.con02', b'accepted-metadata')
        self.d.fail_at = 1
        self.assertEqual('baseline_restored', op.upgrade()['phase'])
        self.assertEqual(b'accepted-metadata', os.getxattr(p, 'user.con02'))

    def test_native_direct_upgrade_gate_is_wired(self):
        runner = ROOT/'tests/stage/run-con02-update.sh'
        self.assertTrue(runner.is_file(), 'direct2000→2200 native runner is required')
        self.assertIn('run-con02-update.sh', (ROOT/'tests/stage/run-connect.sh').read_text())
        workflow = (ROOT/'.github/workflows/ustar-review-gate.yml').read_text()
        self.assertIn('con02_update_native.py prepare', workflow)
        self.assertIn('con02_update_native.py verify', workflow)
        self.assertIn('artifacts/con02-update-*', workflow)

    def test_recovery_drift_refuses_before_journal_write_or_stop(self):
        op=self.operation();op.upgrade();self.d.actions.clear()
        self.cron.write_bytes(b'administrator drift')
        inode=op.journal.path.stat().st_ino
        with self.assertRaises(c.Refusal):op.recover()
        self.assertEqual(inode,op.journal.path.stat().st_ino)
        self.assertEqual([],self.d.actions)

    def test_restore_process_loss_keeps_owned_config_and_resumes_same_run(self):
        op=self.operation();self.d.fail_at=1
        marker=self.root/'restore-config-observed'
        pid=os.fork()
        if pid==0:
            original=self.d.u.copy_contents
            def crash(cold,source,dest,entries):
                if Path(source).name=='code' and Path(source).parent.name=='baseline':
                    marker.write_bytes(b'1' if (self.d.p.CODE/'config.php').exists() else b'0')
                    original(cold,source,dest,entries);os._exit(73)
                return original(cold,source,dest,entries)
            self.d.u.copy_contents=crash
            op.upgrade();os._exit(74)
        _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
        self.assertEqual(b'1',marker.read_bytes(),'owned config must remain available throughout cold recovery')
        generation=op.row()['generation']
        self.assertEqual('baseline_restored',op.recover()['phase'])
        self.assertEqual(generation,op.row()['generation']);self.assert_restored()


if __name__ == '__main__': unittest.main()
