import importlib.util
from pathlib import Path
import tempfile
import unittest
import yaml

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('context_governance',ROOT/'scripts/check_context.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class Governance(unittest.TestCase):
    def fixture(self,root):
        for name in ('AGENTS.md','START_HERE.md','context/tasks/ACTIVE.md'):
            p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('Current handoff')
        path=root/'context/roadmap/ACCEPTED_RISKS.yaml';path.parent.mkdir(parents=True,exist_ok=True)
        item={'id':'R1','status':'accepted_risk','owner':'Аду','accepted_on':'2026-10-26',
              'review_trigger':'Before external exposure','evidence':'START_HERE.md','scope':'LAN access'}
        path.write_text(yaml.safe_dump({'risks':[item]}));return path,item

    def test_current_handoff_and_owner_risk_are_valid(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);self.fixture(root)
            m.check_governance(root,{'INF-10':{'status':'accepted_risk','risk_id':'R1'}})

    def test_entrypoint_limit_counts_utf8_bytes(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);self.fixture(root);(root/'START_HERE.md').write_text('Я'*2100)
            with self.assertRaisesRegex(AssertionError,'entrypoint_size'):m.check_governance(root,{})

    def test_risk_cannot_replace_acceptance_without_owner_trigger_or_reference(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);path,item=self.fixture(root)
            for key in ('owner','review_trigger','accepted_on'):
                bad={**item,key:''};path.write_text(yaml.safe_dump({'risks':[bad]}))
                with self.subTest(key=key),self.assertRaises(AssertionError):m.check_governance(root,{})
            path.write_text(yaml.safe_dump({'risks':[item]}))
            with self.assertRaises(AssertionError):m.check_governance(root,{'T':{'status':'accepted_risk','risk_id':'missing'}})
