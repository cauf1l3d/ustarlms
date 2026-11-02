"""Keep the frozen installer task contract strict while checking the declared successor task."""
import copy
import importlib
from pathlib import Path
import sys
from types import SimpleNamespace
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'tests/stage'),str(ROOT/'scripts/infra')]

class TaskProjection(unittest.TestCase):
    def setUp(self):
        self.p=importlib.import_module('route_access_task_projection')
        self.x=importlib.import_module('connect_academy_installer')
        self.before={'core':self.x.CORE,'addons':{'local_ustar':'2026101904','theme_ustar':'2026102700'},
            'tables':[],'tasks':{'existing_custom_task':[1,1]},'schema':[],'indexes':[]}
        self.after=copy.deepcopy(self.before)
        self.after['addons']['local_ustar']='2026110100'
        self.after['tasks'].update({self.x.NEW_TASK:[0,0],self.p.TASK:[0,0]})
        self.ref=SimpleNamespace(reference={'baseline':{'tables':[]},'candidate':{'tables':[],
            'versions':{'local_ustar':2026110100,'theme_ustar':2026102700}}})

    def test_verified_successor_task_is_the_only_projection_and_raw_snapshot_is_unchanged(self):
        original=copy.deepcopy(self.after)
        with self.assertRaisesRegex(ValueError,'scheduled_task_flags'):
            self.x.verify_versions(self.ref,self.after,self.before,True)
        projected=self.p.project(self.after)
        self.assertEqual(original,self.after)
        self.assertEqual({**self.before['tasks'],self.x.NEW_TASK:[0,0]},projected['tasks'])
        self.x.verify_versions(self.ref,projected,self.before,True)

    def test_missing_changed_or_unexpected_version_is_never_projected(self):
        for flags in (None,[1,0],[0,1],[1,1]):
            row=copy.deepcopy(self.after)
            if flags is None:row['tasks'].pop(self.p.TASK)
            else:row['tasks'][self.p.TASK]=flags
            with self.assertRaises(ValueError):self.p.project(row)
        row=copy.deepcopy(self.after);row['addons']['local_ustar']='2026102900'
        with self.assertRaises(ValueError):self.p.project(row)

    def test_foreign_legacy_task_drift_is_still_rejected_by_the_frozen_validator(self):
        for key,flags in [('existing_custom_task',[0,0]),(self.x.NEW_TASK,[1,0])]:
            row=copy.deepcopy(self.after);row['tasks'][key]=flags
            with self.assertRaisesRegex(ValueError,'scheduled_task_flags'):
                self.x.verify_versions(self.ref,self.p.project(row),self.before,True)

if __name__=='__main__':unittest.main()
