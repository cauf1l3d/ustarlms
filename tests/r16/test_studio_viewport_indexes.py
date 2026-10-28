"""Strict2800/2801 fresh and previously accepted paired-index profiles."""
import copy
import unittest
from tests.r16.test_studio_colors_indexes import fixture2700, add_unique, PAIR
from tests.r16.test_studio_viewport_update import adapter
import connect_con02_contract as c


def fixture2800():
    before, ref = fixture2700()
    before['addons']['local_ustar'] = '2026102800'
    ref['baseline']['versions']['local_ustar'] = 2026102800
    ref['candidate']['versions']['local_ustar'] = 2026102801
    return before, ref


class Profiles(unittest.TestCase):
    def test_legacy_and_fresh_upgrade_repeat_restore_preserve_raw_definitions(self):
        for fresh in (False, True):
            before, ref = fixture2800()
            if fresh:
                for table, field in PAIR: add_unique(before, table, field)
            frozen = copy.deepcopy((before, ref))
            expected = 'xml_install_indexes' if fresh else 'server1_legacy_indexes_20261023'
            self.assertEqual(expected, adapter().verify_database(before, before, ref, target=False))
            after = copy.deepcopy(before); after['addons']['local_ustar'] = '2026102801'
            for _ in range(2):
                self.assertEqual(expected, adapter().verify_database(after, before, ref, target=True))
            self.assertEqual(expected, adapter().verify_database(before, before, ref, target=False))
            self.assertEqual(frozen, (before, ref))

    def test_partial_pair_extra_index_version_schema_task_drift_refused(self):
        for table, field in PAIR:
            before, ref = fixture2800(); add_unique(before, table, field)
            with self.subTest(partial=table), self.assertRaises(c.Refusal):
                adapter().verify_database(before, before, ref, target=False)
        before, ref = fixture2800()
        for kind in ('index', 'version', 'schema', 'task'):
            after = copy.deepcopy(before); after['addons']['local_ustar'] = '2026102801'
            if kind == 'index': add_unique(after, *PAIR[0])
            if kind == 'version': after['addons']['theme_ustar'] = '2026100202'
            if kind == 'schema': after['schema'][0][8] = '1'
            if kind == 'task': after['tasks']['foreign'] = [0, 0]
            with self.subTest(kind=kind), self.assertRaises(c.Refusal):
                adapter().verify_database(after, before, ref, target=True)
