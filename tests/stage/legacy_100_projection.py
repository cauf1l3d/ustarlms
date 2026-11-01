#!/usr/bin/env python3
"""Run frozen100-table validator regressions on an explicit historical projection, not release2900."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys

# Prefix substitution must preserve db_query's numeric substring/left widths.
HISTORICAL='histx_'
assert len(HISTORICAL)==len('fresh_')
ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
from connect_academy_installer import db_query
from connect_role_bot_native import query,stable


def sql(command):
    subprocess.run(['psql','-X','-q','-w','-h','db','-U','ustar_fixture','-d','ustar_stage1','-v','ON_ERROR_STOP=1'],
        input=command.encode(),check=True,stdout=subprocess.DEVNULL,timeout=120)


def main():
    if ROOT!=Path('/source') or not Path('/stage/moodle/config.php').is_file():raise ValueError('isolated_stage_required')
    original=query(db_query('fresh_'))
    if len(original['tables'])!=103:raise ValueError('actual2900_schema_required')
    if query("SELECT count(*)::int FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'histx\\_%';"):
        raise ValueError('historical_projection_exists')
    # LIKE copies serial defaults verbatim. Each historical plugin table needs its
    # own correctly named sequence so the frozen default-name assertions stay strict.
    sql("DO $clone$ DECLARE item record; field record; target text; seq text; BEGIN FOR item IN SELECT tablename FROM pg_tables "
        "WHERE schemaname='public' AND tablename LIKE 'fresh\\_%' AND tablename NOT IN "
        "('fresh_local_ustar_conn_dialogs','fresh_local_ustar_conn_actions','fresh_local_ustar_conn_updates') ORDER BY tablename "
        "LOOP target:='histx_'||substring(item.tablename from 7); "
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
        "UPDATE histx_config_plugins SET value='2026102803' WHERE plugin='local_ustar' AND name='version';")
    try:
        historical=query(db_query('histx_'))
        if len(historical['tables'])!=100:raise ValueError('historical100_projection_required')
        for stem in ('studio_colors','studio_viewport','perf_query','feed_media'):
            path=ROOT/'tests/stage'/(stem+'_legacy_native.py')
            spec=importlib.util.spec_from_file_location('_historical_'+stem,path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            original_query=module.query
            module.query=lambda command,_query=original_query:_query(command.replace('fresh_',HISTORICAL))
            module.main(['--verify'])
            report_path=Path('/artifacts')/(stem.replace('_','-')+'-legacy-native.json')
            report=json.loads(report_path.read_text())
            report.update(fixture_kind='historical100_table_projection',actual_release2900_schema=False,
                actual_release2900_proof='connect-role-bot-native.json')
            report_path.write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    finally:
        sql("DO $drop$ DECLARE item record; BEGIN FOR item IN SELECT tablename FROM pg_tables WHERE schemaname='public' "
            "AND tablename LIKE 'histx\\_%' LOOP EXECUTE format('DROP TABLE %I CASCADE',item.tablename); END LOOP; END $drop$;")
    if stable(original)!=stable(query(db_query('fresh_'))):raise ValueError('actual_fresh_schema_changed')
    print('FROZEN100_HISTORICAL_PROJECTION=PASS actual2900_schema_untouched=true')


if __name__=='__main__':main()
