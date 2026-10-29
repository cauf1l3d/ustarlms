#!/usr/bin/env python3
"""Run frozen100-table validator regressions on an explicit historical projection, not release2900."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys

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
    if query("SELECT count(*)::int FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'hist\\_%';"):
        raise ValueError('historical_projection_exists')
    # Serial default sequences may still refer to fresh_: the fixture never inserts a
    # generated ID. Column/index definitions and copied synthetic rows are sufficient.
    sql("DO $clone$ DECLARE item record; target text; BEGIN FOR item IN SELECT tablename FROM pg_tables "
        "WHERE schemaname='public' AND tablename LIKE 'fresh\\_%' AND tablename NOT IN "
        "('fresh_local_ustar_conn_dialogs','fresh_local_ustar_conn_actions','fresh_local_ustar_conn_updates') ORDER BY tablename "
        "LOOP target:='hist_'||substring(item.tablename from 7); "
        "EXECUTE format('CREATE TABLE %I (LIKE %I INCLUDING ALL)',target,item.tablename); "
        "EXECUTE format('INSERT INTO %I SELECT * FROM %I',target,item.tablename); END LOOP; END $clone$;"
        "UPDATE hist_config_plugins SET value='2026102803' WHERE plugin='local_ustar' AND name='version';")
    try:
        historical=query(db_query('hist_'))
        if len(historical['tables'])!=100:raise ValueError('historical100_projection_required')
        for stem in ('studio_colors','studio_viewport','perf_query','feed_media'):
            path=ROOT/'tests/stage'/(stem+'_legacy_native.py')
            spec=importlib.util.spec_from_file_location('_historical_'+stem,path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            original_query=module.query
            module.query=lambda command,_query=original_query:_query(command.replace('fresh_','hist_'))
            module.main(['--verify'])
            report_path=Path('/artifacts')/(stem.replace('_','-')+'-legacy-native.json')
            report=json.loads(report_path.read_text())
            report.update(fixture_kind='historical100_table_projection',actual_release2900_schema=False,
                actual_release2900_proof='connect-role-bot-native.json')
            report_path.write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    finally:
        sql("DO $drop$ DECLARE item record; BEGIN FOR item IN SELECT tablename FROM pg_tables WHERE schemaname='public' "
            "AND tablename LIKE 'hist\\_%' LOOP EXECUTE format('DROP TABLE %I CASCADE',item.tablename); END LOOP; END $drop$;")
    if stable(original)!=stable(query(db_query('fresh_'))):raise ValueError('actual_fresh_schema_changed')
    print('FROZEN100_HISTORICAL_PROJECTION=PASS actual2900_schema_untouched=true')


if __name__=='__main__':main()
