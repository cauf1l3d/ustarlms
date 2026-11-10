#!/usr/bin/env python3
"""Exercise the immutable2900 validator on a declared103-table projection of current fresh data."""
import json
from pathlib import Path
import sys
import connect_role_bot_native as old
from legacy_100_projection import sql

PREFIX='histw_'
assert len(PREFIX)==len('fresh_')

def main():
    original=old.query(old.db_query('fresh_'))
    old.role.c.require(len(original['tables'])==108 and original['addons']['local_ustar']=='2026110200',
        'actual_route_access_schema_required')
    old.role.c.require(old.query("SELECT count(*)::int FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'histw\\_%';")==0,
        'historical_projection_exists')
    sql("DO $clone$ DECLARE item record; field record; target text; seq text; BEGIN FOR item IN SELECT tablename FROM pg_tables "
        "WHERE schemaname='public' AND tablename LIKE 'fresh\\_%' AND tablename NOT IN ('fresh_local_ustar_route_access','fresh_local_ustar_library_offer','fresh_local_ustar_library_grant','fresh_local_ustar_library_assessment','fresh_local_ustar_library_attempt') ORDER BY tablename "
        "LOOP target:='histw_'||substring(item.tablename from 7); "
        "EXECUTE format('CREATE TABLE %I (LIKE %I INCLUDING ALL)',target,item.tablename); "
        "EXECUTE format('INSERT INTO %I SELECT * FROM %I',target,item.tablename); "
        "IF left(item.tablename,18)='fresh_local_ustar_' THEN "
        "FOR field IN SELECT c.column_name FROM information_schema.columns c WHERE c.table_schema='public' "
        "AND c.table_name=item.tablename AND c.column_default LIKE 'nextval(%' LOOP "
        "seq:=target||'_'||field.column_name||'_seq'; EXECUTE format('CREATE SEQUENCE %I',seq); "
        "EXECUTE format('ALTER TABLE %I ALTER COLUMN %I SET DEFAULT nextval(%L::regclass)',target,field.column_name,seq); "
        "EXECUTE format('ALTER SEQUENCE %I OWNED BY %I.%I',seq,target,field.column_name); "
        "EXECUTE format('SELECT setval(%L::regclass,COALESCE(max(%I),0)+1,false) FROM %I',seq,field.column_name,target); "
        "END LOOP; END IF; END LOOP; END $clone$;"
        "ALTER TABLE histw_local_ustar_route_versions DROP COLUMN accesspolicyjson;"
        "DELETE FROM histw_task_scheduled WHERE classname='\\local_ustar\\task\\reconcile_route_access';"
        "DELETE FROM histw_task_scheduled WHERE classname='\\local_ustar\\task\\sync_library_quick_checks';"
        "UPDATE histw_config_plugins SET value='2026102700' WHERE plugin='theme_ustar' AND name='version';"
        "UPDATE histw_config_plugins SET value='2026102900' WHERE plugin='local_ustar' AND name='version';")
    original_query=old.query
    try:
        old.query=lambda command: original_query(command.replace('fresh_',PREFIX))
        old.main(['--verify'])
        report=Path('/artifacts/connect-role-bot-native.json')
        data=json.loads(report.read_text())
        data.update(fixture_kind='historical103_table_projection',actual_current_candidate=False,
            actual_2900_CLI_proof='connect-role-bot-upgraded.json')
        report.write_text(json.dumps(data,sort_keys=True,indent=2)+'\n')
    finally:
        old.query=original_query
        sql("DO $drop$ DECLARE item record; BEGIN FOR item IN SELECT tablename FROM pg_tables WHERE schemaname='public' "
            "AND tablename LIKE 'histw\\_%' LOOP EXECUTE format('DROP TABLE %I CASCADE',item.tablename); END LOOP; END $drop$;")
    old.role.c.require(old.stable(original)==old.stable(old.query(old.db_query('fresh_'))),'actual_candidate_changed')
    print('FROZEN2900_HISTORICAL_PROJECTION=PASS current108_untouched=true')

if __name__=='__main__':main()
