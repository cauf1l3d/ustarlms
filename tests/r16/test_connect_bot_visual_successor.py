"""Upgrade the owner's terminal cb journal without deleting or rewriting history."""
import copy
import json
from types import SimpleNamespace
from unittest.mock import patch
import unittest

from tests.r16 import test_connect_bot_visual_update as fixture
import connect_bot_visual_successor as successor

CB='cb7128faa07629182aa5f1ada3a11299ac36cf24'

class Successor(fixture.WorkerUpdate):
    def setUp(self):
        super().setUp()
        self.legacy=self.op
        self.legacy.candidate=SimpleNamespace(reference={'worker_commit':CB},
            worker_archive=fixture.archive(fixture.worker_files(self.after),CB))
        self.op=successor.VisualSuccessor(self.ctx,self.after)
        self.op.verify_application=lambda saved:None

    def rolled_back_legacy(self):
        self.legacy.run();self.legacy.run(recover=True)
        return self.legacy.path.read_bytes()

    def test_owner_rolled_back_cb_journal_is_preserved_across_success_repeat_and_recover(self):
        raw=self.rolled_back_legacy()
        self.assertEqual('ready_for_visual_update',self.op.check()['phase'])
        self.assertFalse(self.op.base.exists())
        self.assertEqual('visuals_active',self.op.run()['phase'])
        self.assertEqual(raw,self.legacy.path.read_bytes())
        self.assertEqual(raw,(self.op.base/'legacy-state.before.json').read_bytes())
        self.assertTrue(self.op.run()['verify_only'])
        self.assertEqual('rolled_back',self.op.run(recover=True)['phase'])
        self.assertEqual(raw,self.legacy.path.read_bytes());self.assert_preserved()

    def test_definitive_probe_failure_returns_old_worker_with_role_bot_still_enabled(self):
        self.rolled_back_legacy();before=copy.deepcopy(self.host.states)
        self.host.fail_once=True
        with patch.object(self.host,'service',wraps=self.host.service) as service:
            with self.assertRaisesRegex(fixture.c.Refusal,'synthetic_probe_failure'):self.op.run()
            service.assert_not_called()
        self.assertEqual(before,self.host.states)
        self.assertFalse(self.op.base.exists());self.assert_preserved()

    def test_readonly_check_does_not_call_provider(self):
        self.rolled_back_legacy()
        with patch.object(self.host,'probe',side_effect=AssertionError('provider_called')):
            self.assertFalse(self.op.check()['provider_called'])

    def test_process_loss_at_each_mutation_boundary_can_recover_without_journal_rewind(self):
        raw=self.rolled_back_legacy()
        super().test_process_loss_at_each_mutation_boundary_can_recover_without_journal_rewind()
        self.assertEqual(raw,self.legacy.path.read_bytes())

    def test_active_partial_unknown_and_changed_predecessor_legacy_are_refused(self):
        raw=self.rolled_back_legacy();state=json.loads(raw)
        for update,reason in [({'phase':'visuals_active'},'legacy_visual_recovery_required'),
                ({'phase':'workers_stopped'},'legacy_visual_recovery_required'),
                ({'worker_commit':'a'*40},'unknown_legacy_visual'),
                ({'predecessor_sha256':'0'*64},'predecessor_journal_changed')]:
            with self.subTest(update=update):
                self.legacy.path.write_text(json.dumps({**state,**update}))
                with self.assertRaisesRegex(fixture.c.Refusal,reason):self.op.check()
                self.assertFalse(self.op.base.exists());self.assert_preserved()
        self.legacy.path.write_bytes(raw)

    def test_legacy_drift_after_success_is_refused_without_pointer_or_service_changes(self):
        raw=self.rolled_back_legacy();self.op.run()
        state=json.loads(raw);state['services'][fixture.c.SERVICES[1]]['enabled']=False
        self.legacy.path.write_text(json.dumps(state))
        with self.assertRaisesRegex(fixture.c.Refusal,'legacy_visual_journal_changed'):self.op.run(recover=True)
        self.assertEqual('f'*40,self.paths['pointer'].resolve().name);self.assert_preserved()

    def test_refuse_legacy_file_symlink_before_any_mutation(self):
        raw=self.rolled_back_legacy()
        extra=self.root/'secret';extra.write_bytes(raw)
        self.legacy.path.unlink();self.legacy.path.symlink_to(extra)
        with self.assertRaises(fixture.c.Refusal):self.op.check()
        self.assertFalse(self.op.base.exists());self.assert_preserved()

if __name__=='__main__':unittest.main()
