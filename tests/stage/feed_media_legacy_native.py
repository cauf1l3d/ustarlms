#!/usr/bin/env python3
"""Real PG16 metadata regression; disposable fresh_ tables, all changes ROLLBACK."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'scripts/infra'), str(ROOT / 'scripts')]
import feed_media_update as perf
from connect_academy_installer import db_query

PAIR = [('local_ustar_coin_accounts', 'userid'), ('local_ustar_test_results', 'attemptid')]
REFERENCE = ROOT / '.stage-input/feed-media-legacy-reference.json'


def query(sql):
    perf.c.require(ROOT == Path('/source') and Path('/stage/moodle/config.php').is_file(), 'isolated_stage_required')
    raw = subprocess.check_output(['psql', '-X', '-q', '-t', '-A', '-w', '-h', 'db', '-U',
        'ustar_fixture', '-d', 'ustar_stage1', '-v', 'ON_ERROR_STOP=1'], input=sql.encode(), timeout=45)
    return json.loads(raw)


def snapshot(version, pair=(), mutation=''):
    setup = "UPDATE fresh_config_plugins SET value='" + str(version) + "' WHERE plugin='local_ustar' AND name='version';"
    setup += "UPDATE fresh_config_plugins SET value='2026102700' WHERE plugin='theme_ustar' AND name='version';"
    for table, field in pair:
        perf.c.require((table, field) in PAIR, 'fixture_index_allowlist')
        setup += ("DO $legacy$ DECLARE item record; removed integer := 0; BEGIN "
            "FOR item IN SELECT schemaname,indexname FROM pg_catalog.pg_indexes WHERE schemaname='public' "
            "AND tablename='fresh_" + table + "' AND indexdef ~ '^CREATE UNIQUE INDEX .* USING btree \\(" + field + "\\)$' "
            "LOOP EXECUTE format('DROP INDEX %I.%I',item.schemaname,item.indexname); removed := removed + 1; END LOOP; "
            "IF removed <> 1 THEN RAISE EXCEPTION 'unexpected legacy fixture index count'; END IF; END $legacy$;")
    sql = db_query('fresh_')
    perf.c.require(sql.startswith('BEGIN READ ONLY;') and sql.endswith('COMMIT;'), 'fixture_query_shape')
    return query(sql.replace('BEGIN READ ONLY;', 'BEGIN; ' + setup + mutation, 1).removesuffix('COMMIT;') + 'ROLLBACK;')


def main(args):
    if args == ['--prepare']:
        from build_feed_media_update import reference_from_git
        commit = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
        bundle = reference_from_git(ROOT, commit)
        REFERENCE.write_text(json.dumps(bundle.reference, sort_keys=True) + '\n')
        print('FEED2803_LEGACY_REFERENCE_READY'); return
    perf.c.require(args == ['--verify'], 'fixture_mode')
    reference = json.loads(REFERENCE.read_text()); perf.validate_reference(reference)
    original = query(db_query('fresh_'))
    perf.c.require(perf.c.Decimal(original['core']) == perf.c.Decimal(perf.c.CORE) and len(original['tables']) == 100, 'native_patch_profile_required')
    profiles = []
    for pair, expected in [((), 'xml_install_indexes'), (PAIR, 'server1_legacy_indexes_20261023')]:
        baseline = snapshot(2026102802, pair)
        perf.c.require(perf.verify_database(baseline, baseline, reference, target=False) == expected, 'native_baseline_profile')
        target = snapshot(2026102803, pair); repeat = snapshot(2026102803, pair)
        for value in (target, repeat):
            perf.c.require(perf.verify_database(value, baseline, reference, target=True) == expected, 'native_target_profile')
        stable = lambda value: {k: v for k, v in value.items() if k != 'other_clients'}
        perf.c.require(stable(target) == stable(repeat), 'native_repeat_changed')
        restored = snapshot(2026102802, pair)
        perf.c.require(stable(restored) == stable(baseline), 'native_restore_changed')
        profiles.append(expected)
    denied = []
    for pair in ([PAIR[0]], [PAIR[1]]):
        value = snapshot(2026102802, pair)
        try: perf.verify_database(value, value, reference, target=False)
        except perf.c.Refusal: denied.append('partial_' + pair[0][0])
        else: raise AssertionError('partial historical profile adopted')
    baseline = snapshot(2026102802, PAIR)
    mutations = {
        'extra_index': 'CREATE INDEX feed2803_unknown ON fresh_local_ustar_notifications(userid);',
        'column_default': 'ALTER TABLE fresh_local_ustar_notifications ALTER COLUMN ackat SET DEFAULT 1;',
        'task': 'UPDATE fresh_task_scheduled SET disabled=1-disabled;',
        'extra_table': 'CREATE TABLE fresh_local_ustar_unknown(id bigint);',
        'missing_ACK_index': "DO $ack$ DECLARE item record; BEGIN SELECT schemaname,indexname INTO STRICT item "
            "FROM pg_indexes WHERE schemaname='public' AND tablename='fresh_local_ustar_conn_acktokens' "
            "AND indexdef ~ '^CREATE INDEX .* USING btree \\(userid\\)$' LIMIT 1; "
            "EXECUTE format('DROP INDEX %I.%I',item.schemaname,item.indexname); END $ack$;",
    }
    for name, sql in mutations.items():
        changed = snapshot(2026102803, PAIR, sql)
        try: perf.verify_database(changed, baseline, reference, target=True)
        except perf.c.Refusal: denied.append(name)
        else: raise AssertionError('native SQL mutation adopted: ' + name)
    after = query(db_query('fresh_'))
    perf.c.require(stable(original) == stable(after), 'fixture_changes_not_rolled_back')
    report = {'result': 'PASS', 'tables': 100, 'profiles': profiles, 'SQL_mutations_denied': denied,
        'versions': [2026102802, 2026102803, 2026102803, 2026102802],
        'actual_postgres_metadata': True, 'all_fixture_SQL_rolled_back': True,
        'scope': 'DB metadata validation across version transitions; physical transaction recovery is tested separately'}
    import workday_observer as observer
    observed = observer.database_shape(query(observer.database_sql('fresh_')))
    perf.c.require(observed['status'] == 'ok' and observed['versions']['local_ustar'] == '2026102803', 'observer_native_SQL')
    denied_write = subprocess.run(['psql','-X','-qAt','-w','-h','db','-U','ustar_fixture','-d','ustar_stage1',
        '-v','ON_ERROR_STOP=1'], input=b'BEGIN READ ONLY; UPDATE fresh_task_scheduled SET disabled=disabled; ROLLBACK;',
        capture_output=True,timeout=15)
    perf.c.require(denied_write.returncode != 0 and b'read-only transaction' in denied_write.stderr, 'observer_native_write_denial')
    perf.c.require(stable(original) == stable(query(db_query('fresh_'))), 'observer_fixture_write_changed')
    report.update(observer_fixed_SQL_executed=True,observer_WRITE_refused=True,query_text_exported=False)
    Path('/artifacts/feed-media-legacy-native.json').write_text(json.dumps(report, indent=2) + '\n')
    print('FEED2803_LEGACY_NATIVE=PASS ' + json.dumps(report, sort_keys=True))


if __name__ == '__main__': main(sys.argv[1:])
