#!/usr/bin/env python3
"""Actual PG metadata plus an exact2803 source input; disposable stage only."""
import json
from pathlib import Path
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
import connect_role_bot_update as role
from connect_academy_installer import db_query

REFERENCE=ROOT/'.stage-input/connect-role-bot-reference.json'
PAIR=[('local_ustar_coin_accounts','userid'),('local_ustar_test_results','attemptid')]


def query(sql):
    role.c.require(ROOT==Path('/source') and Path('/stage/moodle/config.php').is_file(),'isolated_stage_required')
    raw=subprocess.check_output(['psql','-X','-qAt','-w','-h','db','-U','ustar_fixture','-d','ustar_stage1',
        '-v','ON_ERROR_STOP=1'],input=sql.encode(),timeout=60)
    return json.loads(raw)


def omit_indexes(prefix,pair):
    sql=''
    for table,field in pair:
        role.c.require((table,field) in PAIR and prefix in ('fresh_','botold_'),'fixture_index_allowlist')
        sql+=("DO $legacy$ DECLARE item record; n integer:=0; BEGIN FOR item IN SELECT schemaname,indexname FROM pg_indexes "
            "WHERE schemaname='public' AND tablename='"+prefix+table+"' AND indexdef ~ '^CREATE UNIQUE INDEX .* USING btree \\("+field+"\\)$' "
            "LOOP EXECUTE format('DROP INDEX %I.%I',item.schemaname,item.indexname); n:=n+1; END LOOP; "
            "IF n<>1 THEN RAISE EXCEPTION 'unexpected fixture index count'; END IF; END $legacy$;")
    return sql


def snapshot(version,*,baseline=False,pair=(),mutation=''):
    setup="UPDATE fresh_config_plugins SET value='"+str(version)+"' WHERE plugin='local_ustar' AND name='version';"
    if baseline:
        for name in sorted(role.NEW_TABLES):setup+='DROP TABLE fresh_'+name+';'
    setup+=omit_indexes('fresh_',pair)+mutation
    sql=db_query('fresh_')
    role.c.require(sql.startswith('BEGIN READ ONLY;') and sql.endswith('COMMIT;'),'fixture_query_shape')
    return query(sql.replace('BEGIN READ ONLY;','BEGIN; '+setup,1).removesuffix('COMMIT;')+'ROLLBACK;')


def stable(value):return {k:v for k,v in value.items() if k!='other_clients'}


def main(args):
    if args==['--prepare']:
        from build_connect_role_bot_update import reference_from_git,derive_observer
        sha=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
        bundle=reference_from_git(ROOT,sha);REFERENCE.parent.mkdir(exist_ok=True)
        REFERENCE.write_text(json.dumps(bundle.reference,sort_keys=True)+'\n')
        (REFERENCE.parent/'connect-role-bot-observe.py').write_bytes(derive_observer((ROOT/'scripts/infra/workday_observer.py').read_bytes()))
        out=ROOT/'.stage-input/role-bot-baseline/moodle'
        role.c.require(not out.exists(),'fixture_input_exists')
        for name,raw in bundle.predecessor.payload.items():
            path=out/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw)
        print('CONNECT_ROLE_BOT_FIXED2803_INPUT=READY');return
    ref=json.loads(REFERENCE.read_text());role.validate_reference(ref)
    if args==['--legacy-baseline']:
        before=query(db_query('botold_'))
        role.c.require(before['addons']['local_ustar']=='2026102803' and len(before['tables'])==100,'native2803_install_required')
        # This prefix was just installed from the exact frozen2803 source.
        query('BEGIN; '+omit_indexes('botold_',PAIR)+"SELECT '{}'::json; COMMIT;")
        baseline=query(db_query('botold_'))
        role.c.require(role.verify_database(baseline,baseline,ref,target=False)=='server1_legacy_indexes_20261023','native2803_legacy_required')
        Path('/artifacts/connect-role-bot-baseline.json').write_text(json.dumps(baseline,sort_keys=True)+'\n')
        print('CONNECT_ROLE_BOT_ACTUAL2803_LEGACY=READY');return
    if args in (['--after-upgrade'],['--after-repeat']):
        baseline=json.loads(Path('/artifacts/connect-role-bot-baseline.json').read_text());current=query(db_query('botold_'))
        role.c.require(role.verify_database(current,baseline,ref,target=True)=='server1_legacy_indexes_20261023','native2900_legacy_required')
        path=Path('/artifacts/connect-role-bot-upgraded.json')
        if args==['--after-upgrade']:path.write_text(json.dumps(stable(current),sort_keys=True)+'\n')
        else:role.c.require(json.loads(path.read_text())==stable(current),'native_repeat_changed')
        print('CONNECT_ROLE_BOT_ACTUAL2803_TO2900_REPEAT=PASS' if args==['--after-repeat'] else 'CONNECT_ROLE_BOT_ACTUAL_UPGRADE=PASS');return
    role.c.require(args==['--verify'],'fixture_mode')
    original=query(db_query('fresh_'))
    role.c.require(len(original['tables'])==103 and original['addons']['local_ustar']=='2026102900','native2900_fresh_required')
    profiles=[]
    for pair,expected in [((),'xml_install_indexes'),(PAIR,'server1_legacy_indexes_20261023')]:
        baseline=snapshot(2026102803,baseline=True,pair=pair)
        role.c.require(role.verify_database(baseline,baseline,ref,target=False)==expected,'native_baseline_profile')
        target=snapshot(2026102900,pair=pair);repeat=snapshot(2026102900,pair=pair)
        role.c.require(role.verify_database(target,baseline,ref,target=True)==expected,'native_target_profile')
        role.c.require(stable(target)==stable(repeat),'native_repeat_changed')
        restored=snapshot(2026102803,baseline=True,pair=pair)
        role.c.require(stable(restored)==stable(baseline),'native_metadata_restore_changed');profiles.append(expected)
    denied=[]
    for pair in ([PAIR[0]],[PAIR[1]]):
        value=snapshot(2026102803,baseline=True,pair=pair)
        try:role.verify_database(value,value,ref,target=False)
        except role.c.Refusal:denied.append('partial_'+pair[0][0])
        else:raise AssertionError('partial legacy profile accepted')
    baseline=snapshot(2026102803,baseline=True,pair=PAIR)
    for label,mutation in {
        'new_column_length':'ALTER TABLE fresh_local_ustar_conn_actions ALTER COLUMN authority TYPE varchar(32);',
        'extra_new_index':'CREATE INDEX role_unknown_index ON fresh_local_ustar_conn_updates(userid);',
        'missing_new_table':'DROP TABLE fresh_local_ustar_conn_dialogs;',
        'old_column_default':'ALTER TABLE fresh_local_ustar_notifications ALTER COLUMN ackat SET DEFAULT 1;',
        'scheduled_task_policy':'UPDATE fresh_task_scheduled SET disabled=1-disabled;',
    }.items():
        value=snapshot(2026102900,pair=PAIR,mutation=mutation)
        try:role.verify_database(value,baseline,ref,target=True)
        except role.c.Refusal:denied.append(label)
        else:raise AssertionError('foreign SQL profile accepted: '+label)
    role.c.require(stable(original)==stable(query(db_query('fresh_'))),'fixture_changes_not_rolled_back')
    observer={'__name__':'_qualified_role_observer'}
    exec(compile((REFERENCE.parent/'connect-role-bot-observe.py').read_bytes(),'<qualified-role-observer>','exec'),observer)
    observed=observer['database_shape'](query(observer['database_sql']('fresh_')))
    role.c.require(observed['status']=='ok' and observed['versions']['local_ustar']=='2026102900','observer2900_native_SQL')
    report={'result':'PASS','actual_postgres_metadata':True,'tables':103,'baseline_tables':100,'profiles':profiles,
        'SQL_mutations_denied':denied,'all_metadata_fixture_SQL_rolled_back':True,'observer2900_fixed_SQL_executed':True,
        'note':'Metadata projections are separate from the actual frozen2803 CLI upgrade/repeat and physical pair recovery tests.'}
    Path('/artifacts/connect-role-bot-native.json').write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    print('CONNECT_ROLE_BOT_NATIVE=PASS '+json.dumps(report,sort_keys=True))


if __name__=='__main__':main(sys.argv[1:])
