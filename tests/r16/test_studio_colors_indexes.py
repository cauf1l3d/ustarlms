"""Accepted server1 index history after the Academy theme2700 transition."""
import copy
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'scripts/infra'), str(ROOT / 'tests/r16')]
import connect_con02_contract as c
import studio_colors_update as colors
import studio_materials_update as materials
from test_connect_con02_historical_indexes import fixture

PAIR = [('local_ustar_coin_accounts', 'userid'), ('local_ustar_test_results', 'attemptid')]


def fixture2700():
    before, ref = fixture()
    before['addons'] = {'local_ustar': '2026102700', 'theme_ustar': '2026102700'}
    ref['baseline']['versions'] = {'local_ustar': 2026102700, 'theme_ustar': 2026102700}
    ref['candidate']['versions'] = {'local_ustar': 2026102800, 'theme_ustar': 2026102700}
    return before, ref


def add_unique(snapshot, table, field):
    snapshot['indexes'].append(['mdl_' + table,
        'CREATE UNIQUE INDEX fixture_restored ON mdl_' + table + ' USING btree (' + field + ')'])


class Historical2700(unittest.TestCase):
    def test_accepted_pair_after_theme2700_without_mutating_snapshot_or_reference(self):
        before, ref = fixture2700(); saved = copy.deepcopy((before, ref))
        self.assertEqual('server1_legacy_indexes_20261023', colors.verify_database(before, before, ref, target=False))
        self.assertEqual(saved, (before, ref))

    def test_predecessor2700_admission_passes_the_same_pair(self):
        current, ref = fixture2700(); baseline = copy.deepcopy(current)
        baseline['addons'] = {'local_ustar': '2026102600', 'theme_ustar': '2026100202'}
        ref['baseline']['versions'] = {'local_ustar': 2026102600, 'theme_ustar': 2026100202}
        ref['candidate']['versions'] = {'local_ustar': 2026102700, 'theme_ustar': 2026102700}
        self.assertEqual('server1_legacy_indexes_20261023',
                         materials.verify_database(current, baseline, ref, target=True))

    def test_strict_fresh_profile_stays_strict(self):
        before, ref = fixture2700()
        for table, field in PAIR: add_unique(before, table, field)
        self.assertEqual('xml_install_indexes', colors.verify_database(before, before, ref, target=False))

    def test_partial_pair_is_not_adopted(self):
        for table, field in PAIR:
            before, ref = fixture2700(); add_unique(before, table, field)
            with self.subTest(table=table), self.assertRaises(c.Refusal):
                colors.verify_database(before, before, ref, target=False)

    def test_extra_missing_or_wrong_unique_index_is_not_adopted(self):
        before, ref = fixture2700()
        missing = copy.deepcopy(before); missing['indexes'].pop()
        extra = copy.deepcopy(before)
        extra['indexes'].append(['mdl_local_ustar_other', 'CREATE INDEX extra ON mdl_local_ustar_other USING btree (id)'])
        wrong = copy.deepcopy(before); wrong['indexes'][1][1] = wrong['indexes'][1][1].replace('CREATE INDEX', 'CREATE UNIQUE INDEX')
        for changed in (missing, extra, wrong):
            with self.subTest(indexes=changed['indexes']), self.assertRaises(c.Refusal):
                colors.verify_database(changed, changed, ref, target=False)

    def test_versions_columns_defaults_and_tasks_remain_checked(self):
        before, ref = fixture2700()
        for column, value in [(3, 'int2'), (7, 'YES'), (8, '1')]:
            changed = copy.deepcopy(before); changed['schema'][0][column] = value
            with self.subTest(column=column), self.assertRaises(c.Refusal):
                colors.verify_database(changed, changed, ref, target=False)
        for local, theme in [(2026102600, 2026102700), (2026102700, 2026100202)]:
            changed, wrongref = fixture2700()
            changed['addons'].update(local_ustar=str(local), theme_ustar=str(theme))
            wrongref['baseline']['versions'] = {'local_ustar': local, 'theme_ustar': theme}
            with self.subTest(local=local, theme=theme), self.assertRaises(c.Refusal):
                colors.verify_database(changed, changed, wrongref, target=False)
        after = copy.deepcopy(before); after['addons']['local_ustar'] = '2026102800'; after['tasks']['custom'] = [0, 0]
        with self.assertRaises(c.Refusal): colors.verify_database(after, before, ref, target=True)

    def test_upgrade_repeat_restore_preserve_the_exact_historical_indexes(self):
        before, ref = fixture2700(); after = copy.deepcopy(before)
        after['addons']['local_ustar'] = '2026102800'
        for _ in range(2):
            self.assertEqual('server1_legacy_indexes_20261023', colors.verify_database(after, before, ref, target=True))
        self.assertEqual('server1_legacy_indexes_20261023', colors.verify_database(before, before, ref, target=False))
        for table, field in PAIR:
            changed = copy.deepcopy(after); add_unique(changed, table, field)
            with self.subTest(table=table), self.assertRaises(c.Refusal):
                colors.verify_database(changed, before, ref, target=True)


if __name__ == '__main__': unittest.main()
