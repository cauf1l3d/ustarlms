"""Cumulative Studio CSP source patch uses the already qualified transaction contract."""
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]

class Delivery(unittest.TestCase):
    def test_default_is_offline_plan_before_any_host_access(self):
        source=ROOT/'scripts/infra/studio_csp_patch.py'
        self.assertTrue(source.is_file(),'Cumulative Studio CSP owner operation is missing')
        result=subprocess.run(['python3','-B',str(source)],capture_output=True,text=True,check=True)
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'source_only':True},json.loads(result.stdout))

# Private test-module instance prevents changing old route-editor test globals.
spec=importlib.util.spec_from_file_location('_studio_csp_transactions',ROOT/'tests/r16/test_route_editor_patch.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared);shared.MODULE='studio_csp_patch'
Contract=shared.Contract
Transaction=shared.Transaction

class RealPhp(unittest.TestCase):
    setUp=shared.Transaction.setUp
    prepare_real_php_host=shared.RealPhp.prepare_real_php_host
    test_invalid_php_is_still_refused_before_the_outage=shared.RealPhp.test_invalid_php_is_still_refused_before_the_outage

    def test_actual_php_lints_all_seven_php_files_without_parsing_templates(self):
        self.prepare_real_php_host()
        self.assertEqual('ready',self.op.check()['phase'])
        self.assertEqual('active',self.op.apply()['phase'])
        expected=[self.new[p] for p in self.fix.FILES if p.endswith('.php')]
        actual=[c[1] for c in self.host.calls if isinstance(c,tuple) and c[0]=='php-lint']
        self.assertCountEqual(expected,actual)
        self.assertEqual(7,len(actual))
        self.assertEqual('rolled_back',self.op.recover()['phase'])
