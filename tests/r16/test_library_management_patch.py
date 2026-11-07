"""Incremental preview source transaction reuses the qualified bounded contract."""
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]

class Delivery(unittest.TestCase):
    def test_default_is_offline_before_any_host_access(self):
        source=ROOT/'scripts/infra/library_management_patch.py'
        self.assertTrue(source.is_file(),'Library management owner successor is missing')
        result=subprocess.run(['python3','-B',str(source)],capture_output=True,text=True,check=True)
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'source_only':True},json.loads(result.stdout))

spec=importlib.util.spec_from_file_location('_management_transactions',ROOT/'tests/r16/test_route_editor_patch.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared);shared.MODULE='library_management_patch'
Contract=shared.Contract

class Transaction(shared.Transaction):
    def setUp(self):
        shared.Transaction.setUp(self)
        for name in self.fix.ADDED:(self.web/name).parent.mkdir(parents=True,exist_ok=True)
        for name in (self.fix.CSP_JOURNAL,self.fix.PREVIEW_JOURNAL):
            journal=self.root/name;journal.parent.chmod(0o700)
            journal.write_text('{"phase":"active"}\n');journal.chmod(0o600)

    def test_process_loss_between_six_files_recovers_complete_baseline(self):
        """Interrupt after the first source replacement; all old and added paths must recover."""
        class Crash(BaseException):pass
        original=self.op.write
        def crash(name,raw,metadata):
            original(name,raw,metadata)
            raise Crash()
        with patch.object(self.op,'write',side_effect=crash),self.assertRaises(Crash):self.op.apply()
        self.assertEqual('recovery_required',self.op.check()['phase'])
        self.assertFalse(self.host.running)
        self.assertEqual('rolled_back',self.op.recover()['phase'])
        self.assertTrue(self.host.running)
        for name,raw in self.old.items():self.assertEqual(raw,(self.web/name).read_bytes())

    def test_current_csp_parent_must_be_active_before_the_outage(self):
        (self.root/self.fix.CSP_JOURNAL).write_text('{"phase":"source_switching"}')
        with self.assertRaisesRegex(self.m.Refusal,'previous_studio_csp_update_not_active'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_missing_csp_parent_is_rejected_before_the_outage(self):
        (self.root/self.fix.CSP_JOURNAL).unlink()
        with self.assertRaisesRegex(self.m.Refusal,'previous_studio_csp_update_required'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_preview_parent_must_be_active_before_the_outage(self):
        (self.root/self.fix.PREVIEW_JOURNAL).write_text('{"phase":"source_switching"}')
        with self.assertRaisesRegex(self.m.Refusal,'previous_studio_preview_update_not_active'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_missing_preview_parent_is_rejected_before_the_outage(self):
        (self.root/self.fix.PREVIEW_JOURNAL).unlink()
        with self.assertRaisesRegex(self.m.Refusal,'previous_studio_preview_update_required'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)

    def test_process_loss_after_an_added_file_removes_only_new_source(self):
        class Crash(BaseException):pass
        original=self.op.write
        def crash(name,raw,metadata):
            original(name,raw,metadata)
            if name==self.fix.ADDED[0]:raise Crash()
        with patch.object(self.op,'write',side_effect=crash),self.assertRaises(Crash):self.op.apply()
        self.assertEqual('recovery_required',self.op.check()['phase'])
        self.assertEqual('rolled_back',self.op.recover()['phase'])
        for name in self.fix.ADDED:self.assertFalse((self.web/name).exists())
        for name,raw in self.old.items():self.assertEqual(raw,(self.web/name).read_bytes())

class RealPhp(unittest.TestCase):
    setUp=Transaction.setUp
    prepare_real_php_host=shared.RealPhp.prepare_real_php_host

    def test_actual_php_lints_all_php_without_parsing_javascript(self):
        self.prepare_real_php_host()
        self.assertEqual('ready',self.op.check()['phase']);self.assertEqual('active',self.op.apply()['phase'])
        parsed=[c[1] for c in self.host.calls if isinstance(c,tuple) and c[0]=='php-lint']
        self.assertCountEqual([self.new[name] for name in self.fix.FILES if name.endswith('.php')],parsed)
        self.assertEqual('rolled_back',self.op.recover()['phase'])

    def test_invalid_php_is_refused_before_the_outage(self):
        self.prepare_real_php_host(invalid=True)
        with self.assertRaisesRegex(self.m.Refusal,'php_lint_failed:local/ustar/library_manage.php'):self.op.apply()
        self.assertNotIn('stop',self.host.calls)
        self.assertTrue(self.host.running)
        self.assertFalse((self.op.base/'state.json').exists())
