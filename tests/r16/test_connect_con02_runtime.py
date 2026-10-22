"""Real runtime files/pointer/SQLite; application and service controls modeled."""
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'scripts/infra'), str(ROOT / 'tests/r16')]
import connect_con02_contract as c
import connect_con02_state as s
import test_connect_con02_profiles as profile_fixture


class Host:
    def __init__(self, test):
        self.test = test; self.actions = []; self.fail_v2 = False; self.leaf = 'old'
        self.states = {n: {'active': True, 'enabled': True} for n in c.SERVICES}
    def application_ready(self, journal):
        assert journal['app']['phase'] == 'app_ready'
        assert self.test.business.read_bytes() == b'app2200_business_rows'
    def controls(self, journal):
        return {'files': {n: s.capture(p) for n, p in self.test.paths.items()}, 'fence': self.test.fence}
    def control_paths(self): return self.test.paths
    def https_snapshot(self): return {'verified': True, 'owner_leaf': self.leaf}
    def validate_units(self, values): pass
    def validate_profiles(self, values): assert b'ustar_connect_ack_enabled=true' in values['include']
    def service_active(self, n): return self.states[n]['active']
    def service_enabled(self, n): return self.states[n]['enabled']
    def service(self, action, n):
        self.actions.append((action, n))
        if action in ('start', 'stop'): self.states[n]['active'] = action == 'start'
        elif action in ('enable', 'disable'): self.states[n]['enabled'] = action == 'enable'
    def probe(self, profile):
        self.actions.append(('probe', profile))
        if self.fail_v2 and profile == 'v2': raise c.Refusal('synthetic_egress_unavailable')


class Runtime(unittest.TestCase):
    @classmethod
    def setUpClass(cls): profile_fixture.Profiles.setUpClass()

    def setUp(self):
        example = profile_fixture.Profiles(); example.setUp()
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name); controls = self.root / 'controls'; controls.mkdir(mode=0o700)
        self.paths = {n: controls / n for n in example.before['files']}
        self.paths.update({n: controls / n for n in ('ack_key', 'receiver_ack_dropin', 'probe_ack_dropin')})
        for n, image in example.before['files'].items():
            self.paths[n].write_bytes(image['raw']); self.paths[n].chmod(0o640 if n == 'include' else 0o600)
        releases = self.root / 'opt/ustar-connect/releases'; releases.mkdir(parents=True)
        old = releases / c.LEGACY_COMMIT; old.mkdir(mode=0o755)
        self.paths['pointer'] = releases.parent / 'current'; self.paths['pointer'].symlink_to(old)
        self.fence = example.before['fence']
        self.host = Host(self)
        self.ctx = c.Context(profile_fixture.Profiles.bundle, self.root, os.getuid(), self.host, SimpleNamespace(base=self.root/'state'))
        self.journal = s.Journal(self.ctx); self.locks = s.Locks(self.ctx)
        for p in self.locks.paths[1:]:
            p.parent.mkdir(parents=True, exist_ok=True); p.touch(mode=0o600)
        self.journal.begin('app', {'files': {}}, {'files': {}}, generation='b'*32)
        self.journal.checkpoint('app', 'app_ready')
        self.business = self.root/'business'; self.business.write_bytes(b'app2200_business_rows')
        self.db = self.root/'receiver.sqlite3'
        with sqlite3.connect(self.db) as db:
            db.execute('CREATE TABLE journal (cursor INTEGER, uncertain TEXT)')
            db.execute("INSERT INTO journal VALUES (42,'preserved')")
        self.before = {n: s.capture(p) for n, p in self.paths.items()}

    def operation(self):
        import connect_con02_runtime as r
        self.assertTrue(hasattr(r, 'Runtime'), 'runtime state machine interface')
        return r.Runtime(self.ctx, self.journal, self.locks)

    def test_enable_does_not_touch_business_pair(self):
        self.assertEqual('runtime_active', self.operation().enable()['phase'])
        self.assertEqual(b'app2200_business_rows', self.business.read_bytes())
        self.assertEqual('app_ready', self.journal.load()['app']['phase'])
        self.assertEqual(c.APP_COMMIT, self.paths['pointer'].resolve().name)

    def test_fallback_restores_previous_runtime(self):
        op = self.operation(); op.enable(); op.disable()
        self.assertEqual(self.before, {n: s.capture(p) for n, p in self.paths.items()})
        self.assertEqual(b'app2200_business_rows', self.business.read_bytes())

    def test_disabled_sender_not_enabled(self):
        self.host.states[c.SERVICES[0]] = {'active': False, 'enabled': False}
        op = self.operation(); op.enable(); op.disable()
        self.assertEqual({'active': False, 'enabled': False}, self.host.states[c.SERVICES[0]])
        self.assertNotIn(('start', c.SERVICES[0]), self.host.actions)

    def test_polling_cursor_never_rewinds(self):
        op = self.operation(); op.enable()
        with sqlite3.connect(self.db) as db: db.execute('UPDATE journal SET cursor=84')
        op.disable(); op.enable()
        with sqlite3.connect(self.db) as db: self.assertEqual([(84, 'preserved')], db.execute('SELECT * FROM journal').fetchall())

    def test_probe_failure_does_not_claim_active(self):
        self.host.fail_v2 = True
        self.assertEqual('runtime_disabled', self.operation().enable()['phase'])
        self.assertEqual(self.before, {n: s.capture(p) for n, p in self.paths.items()})

    def test_generation_cutoff_and_keys_survive_process_loss(self):
        op = self.operation()
        for boundary in ('prepared','workers_stopped','private_files','include_handoff','pointer_switched','probed','runtime_active'):
            with self.subTest(boundary=boundary):
                pid = os.fork()
                if pid == 0:
                    original = self.journal.begin if boundary=='prepared' else self.journal.checkpoint
                    def crash(scope,*args,**fields):
                        row = original(scope,*args,**fields)
                        if boundary=='prepared' or args[0]==boundary: os._exit(73)
                        return row
                    if boundary=='prepared':self.journal.begin=crash
                    else:self.journal.checkpoint=crash
                    op.enable(); os._exit(74)
                _, status = os.waitpid(pid, 0); self.assertEqual(73, os.waitstatus_to_exitcode(status))
                before = self.journal.load()['runtime']
                self.assertEqual('runtime_active', op.enable()['phase'])
                after = self.journal.load()['runtime']
                self.assertEqual(before['generation'], after['generation']); self.assertEqual(before['cutoff'], after['cutoff'])
                self.assertEqual(before['files']['ack_key'], after['files']['ack_key'])
                self.assertEqual(before['files']['notify_key'], after['files']['notify_key'])
                op.disable()

    def test_revoked_recipient_and_authorized_renewal_unchanged(self):
        fence = dict(self.fence); self.host.leaf = 'new-authorized-leaf'
        op = self.operation(); op.enable(); op.disable()
        self.assertEqual(fence, self.fence)
        self.assertEqual('revoked', self.fence['last_link_state'])

    def test_runtime_and_https_gates_required(self):
        for name in ('con02_update_systemd.py','con02_update_https.py'):
            self.assertTrue((ROOT/'tests/stage'/name).is_file(), 'actual controller fixture required: '+name)
        workflow = (ROOT/'.github/workflows/ustar-review-gate.yml').read_text()
        self.assertIn('USTAR_REQUIRE_CON02_OWNER=1',workflow)
        self.assertIn('python3 tests/stage/con02_update_systemd.py',workflow)
        self.assertIn('python3 tests/stage/con02_update_https.py',workflow)
        self.assertIn('artifacts/con02-update-runtime.json',workflow)
        self.assertIn('artifacts/con02-update-https-owner.json',workflow)


if __name__ == '__main__': unittest.main()
