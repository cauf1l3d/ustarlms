"""Observed server1 legacy indexes, with strict rejection outside that exact profile."""
import copy
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))
import connect_con02_contract as c


def fixture():
    # Literal signatures from owner23Oct pg_indexes. Minimal columns isolate
    # index behavior; full99 column/default parity remains a separate native gate.
    fields = {
        'local_ustar_coin_accounts': {'id': {'type': 'int', 'length': '10', 'notnull': True},
                                    'userid': {'type': 'int', 'length': '10', 'notnull': True},
                                    'status': {'type': 'char', 'length': '16', 'notnull': True, 'default': 'active'}},
        'local_ustar_test_results': {'id': {'type': 'int', 'length': '10', 'notnull': True},
                                   'attemptid': {'type': 'int', 'length': '10', 'notnull': True}},
        'local_ustar_other': {'id': {'type': 'int', 'length': '10', 'notnull': True}},
    }
    indexes = {
        'local_ustar_coin_accounts': [(('id',), True), (('status',), False), (('userid',), False), (('userid',), True)],
        'local_ustar_test_results': [(('attemptid',), False), (('attemptid',), True), (('id',), True),
                                   (('testcode', 'timecreated'), False), (('userid',), False),
                                   (('userid', 'testcode', 'timecreated'), False)],
        'local_ustar_other': [(('id',), True)],
    }
    # Index names are immaterial to the fixed XMLDB signature check.
    def definition(table, n, item):
        columns, unique = item
        return ['mdl_' + table, 'CREATE ' + ('UNIQUE ' if unique else '') + 'INDEX index_' + str(n)
                + ' ON public.mdl_' + table + ' USING btree (' + ', '.join(columns) + ')']
    sql = [definition(t, n, item) for t, values in indexes.items() for n, item in enumerate(values)
           if (t, item) not in {('local_ustar_coin_accounts', (('userid',), True)),
                                ('local_ustar_test_results', (('attemptid',), True))}]
    schema = [
        ['local_ustar_coin_accounts', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None],
        ['local_ustar_coin_accounts', 'userid', 'bigint', 'int8', None, 64, 0, 'NO', None],
        ['local_ustar_coin_accounts', 'status', 'character varying', 'varchar', 16, None, None, 'NO', "'active'::character varying"],
        ['local_ustar_other', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None],
        ['local_ustar_test_results', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None],
        ['local_ustar_test_results', 'attemptid', 'bigint', 'int8', None, 64, 0, 'NO', None],
    ]
    profile = {'tables': sorted(fields), 'versions': {'local_ustar': 2026102000, 'theme_ustar': 2026100202},
               'schema_fields': fields, 'schema_indexes': {t: [{'fields': list(f), 'unique': u} for f, u in v]
                                                         for t, v in indexes.items()}}
    ref = {'baseline': profile, 'candidate': copy.deepcopy(profile)}
    ref['candidate']['versions']['local_ustar'] = 2026102200
    before = {'core': c.CORE, 'addons': {'local_ustar': '2026102000', 'theme_ustar': '2026100202'},
              'tasks': {'custom': [1, 1]}, 'tables': profile['tables'], 'schema': schema, 'indexes': sql}
    return before, ref


class HistoricalIndexes(unittest.TestCase):
    def test_observed_pair_is_accepted_without_rewriting_metadata(self):
        before, ref = fixture()
        saved = copy.deepcopy(before)
        self.assertEqual('server1_legacy_indexes_20261023',
                         c.verify_database(before, before, ref, target=False))
        self.assertEqual(saved, before)

    def test_strict_fresh_profile_still_passes(self):
        before, ref = fixture()
        for table, field in [('local_ustar_coin_accounts', 'userid'), ('local_ustar_test_results', 'attemptid')]:
            before['indexes'].append(['mdl_' + table, 'CREATE UNIQUE INDEX fresh ON mdl_' + table + ' USING btree (' + field + ')'])
        self.assertEqual('xml_install_indexes', c.verify_database(before, before, ref, target=False))

    def test_historical_exception_requires_the_frozen2000_baseline(self):
        before, ref = fixture()
        before['addons']['local_ustar'] = '2026101905'
        ref['baseline']['versions']['local_ustar'] = 2026101905
        with self.assertRaises(c.Refusal): c.verify_database(before, before, ref, target=False)

    def test_only_one_missing_unique_index_is_not_the_observed_profile(self):
        before, ref = fixture()
        for table, field in [('local_ustar_coin_accounts', 'userid'), ('local_ustar_test_results', 'attemptid')]:
            bad = copy.deepcopy(before)
            bad['indexes'].append(['mdl_' + table, 'CREATE UNIQUE INDEX restored ON public.mdl_' + table + ' USING btree (' + field + ')'])
            with self.subTest(table=table), self.assertRaises(c.Refusal):
                c.verify_database(bad, bad, ref, target=False)

    def test_additional_missing_extra_or_wrong_unique_index_is_refused(self):
        before, ref = fixture()
        changes = []
        missing = copy.deepcopy(before); missing['indexes'] = missing['indexes'][1:]; changes.append(missing)
        extra = copy.deepcopy(before); extra['indexes'].append(['mdl_local_ustar_other', 'CREATE INDEX extra ON mdl_local_ustar_other USING btree (id)']); changes.append(extra)
        wrong = copy.deepcopy(before); wrong['indexes'][1][1] = wrong['indexes'][1][1].replace('CREATE INDEX', 'CREATE UNIQUE INDEX'); changes.append(wrong)
        for n, bad in enumerate(changes):
            with self.subTest(change=n), self.assertRaises(c.Refusal):
                c.verify_database(bad, bad, ref, target=False)

    def test_legacy_columns_and_defaults_are_still_checked(self):
        before, ref = fixture()
        for column, value in [(3, 'int2'), (7, 'YES'), (8, '1')]:
            bad = copy.deepcopy(before); bad['schema'][0][column] = value
            with self.subTest(column=column), self.assertRaises(c.Refusal):
                c.verify_database(bad, bad, ref, target=False)

    def test_upgrade_must_preserve_historical_indexes_exactly(self):
        before, ref = fixture(); after = copy.deepcopy(before)
        after['addons']['local_ustar'] = '2026102200'
        c.verify_database(after, before, ref, target=True)
        for table, field in [('local_ustar_coin_accounts', 'userid'), ('local_ustar_test_results', 'attemptid')]:
            changed = copy.deepcopy(after)
            changed['indexes'].append(['mdl_' + table, 'CREATE UNIQUE INDEX repair ON mdl_' + table + ' USING btree (' + field + ')'])
            with self.subTest(table=table), self.assertRaises(c.Refusal):
                c.verify_database(changed, before, ref, target=True)

    def test_historical_exception_cannot_apply_to_the_new_ACK_table(self):
        before, ref = fixture(); after = copy.deepcopy(before)
        after['addons']['local_ustar'] = '2026102200'
        table = 'local_ustar_conn_acktokens'
        ref['candidate']['tables'] = sorted(ref['candidate']['tables'] + [table])
        ref['candidate']['schema_fields'][table] = {'id': {'type': 'int', 'length': '10', 'notnull': True}}
        ref['candidate']['schema_indexes'][table] = [{'fields': ['id'], 'unique': True}]
        after['tables'] = ref['candidate']['tables']
        after['schema'].append([table, 'id', 'bigint', 'int8', None, 64, 0, 'NO', None])
        after['indexes'].append(['mdl_' + table, 'CREATE UNIQUE INDEX ack ON mdl_' + table + ' USING btree (id)'])
        c.verify_database(after, before, ref, target=True)
        after['indexes'].pop()
        with self.assertRaises(c.Refusal): c.verify_database(after, before, ref, target=True)


if __name__ == '__main__': unittest.main()
