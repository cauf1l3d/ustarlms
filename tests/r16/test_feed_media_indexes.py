"""Exact2801/2802 fresh and accepted paired legacy index profiles."""
import copy
import unittest
from tests.r16.test_studio_viewport_indexes import fixture2800,add_unique,PAIR
from tests.r16.test_feed_media_update import adapter
import connect_con02_contract as c


def fixture2801():
    before,ref=fixture2800(); before['addons']['local_ustar']='2026102802'
    ref['baseline']['versions']['local_ustar']=2026102802
    ref['candidate']['versions']['local_ustar']=2026102803
    return before,ref


class Profiles(unittest.TestCase):
    def test_both_exact_profiles_upgrade_repeat_restore_without_mutation(self):
        for fresh in (False,True):
            before,ref=fixture2801()
            if fresh:
                for table,field in PAIR:add_unique(before,table,field)
            saved=copy.deepcopy((before,ref)); expected='xml_install_indexes' if fresh else 'server1_legacy_indexes_20261023'
            self.assertEqual(expected,adapter().verify_database(before,before,ref,target=False))
            after=copy.deepcopy(before); after['addons']['local_ustar']='2026102803'
            for _ in range(2):self.assertEqual(expected,adapter().verify_database(after,before,ref,target=True))
            self.assertEqual(expected,adapter().verify_database(before,before,ref,target=False))
            self.assertEqual(saved,(before,ref))

    def test_partial_pair_and_unrelated_index_version_schema_task_drift_refused(self):
        for table,field in PAIR:
            before,ref=fixture2801(); add_unique(before,table,field)
            with self.subTest(partial=table),self.assertRaises(c.Refusal):adapter().verify_database(before,before,ref,target=False)
        before,ref=fixture2801()
        for kind in ('index','version','schema','task'):
            after=copy.deepcopy(before); after['addons']['local_ustar']='2026102803'
            if kind=='index':add_unique(after,*PAIR[0])
            if kind=='version':after['addons']['theme_ustar']='2026100202'
            if kind=='schema':after['schema'][0][8]='1'
            if kind=='task':after['tasks']['foreign']=[0,0]
            with self.subTest(kind=kind),self.assertRaises(c.Refusal):adapter().verify_database(after,before,ref,target=True)
