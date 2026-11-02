import copy
import importlib
from pathlib import Path
import sys
import unittest
from types import SimpleNamespace
ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'tests/stage'),str(ROOT/'scripts/infra')]
class TaskProjection(unittest.TestCase):
    def setUp(self):
        self.p=importlib.import_module('library_task_projection');self.old=importlib.import_module('connect_academy_installer')
        self.before={'core':self.old.CORE,'addons':{'local_ustar':'2026101904','theme_ustar':'2026102700'},'tables':[],'tasks':{'existing':[1,1]},'schema':[],'indexes':[]}
        self.after=copy.deepcopy(self.before);self.after['addons'].update(local_ustar='2026110200',theme_ustar='2026110200')
        self.after['tasks'].update({self.p.TASK:[0,0],self.p.ROUTE_TASK:[0,0],self.old.NEW_TASK:[0,0]})
        self.ref=SimpleNamespace(reference={'baseline':{'tables':[]},'candidate':{'tables':[],'versions':{'local_ustar':2026110200,'theme_ustar':2026110200}}})
    def test_explicit_projection_keeps_raw_evidence_and_old_task_flags(self):
        raw=copy.deepcopy(self.after)
        self.old.verify_versions(self.ref,self.p.project(self.after),self.before,True)
        self.assertEqual(raw,self.after)
        self.assertEqual({**self.before['tasks'],self.old.NEW_TASK:[0,0]},self.p.project(self.after)['tasks'])
    def test_wrong_version_or_changed_successor_task_never_projects(self):
        for key in [self.p.TASK,self.p.ROUTE_TASK]:
            for flags in [None,[1,0],[0,1],[1,1]]:
                row=copy.deepcopy(self.after)
                if flags is None:row['tasks'].pop(key)
                else:row['tasks'][key]=flags
                with self.assertRaises(ValueError):self.p.project(row)
        row=copy.deepcopy(self.after);row['addons']['local_ustar']='2026110100'
        with self.assertRaises(ValueError):self.p.project(row)
    def test_foreign_task_drift_still_reaches_and_fails_frozen_validator(self):
        row=copy.deepcopy(self.after);row['tasks']['existing']=[0,0]
        with self.assertRaisesRegex(ValueError,'scheduled_task_flags'):self.old.verify_versions(self.ref,self.p.project(row),self.before,True)
