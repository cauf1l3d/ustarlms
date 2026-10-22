#!/usr/bin/env python3
"""Native PG/controller contract differential. Logical restore; physical files are a separate gate."""
import copy
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT/'scripts/infra')]
import connect_con02_contract as c
from build_connect_con02_update import reference_from_git
from connect_academy_installer import db_query


def main():
    mode = sys.argv[1:]
    inputs = ROOT/'.stage-input'; outputs = ROOT/'artifacts'
    if mode == ['prepare']:
        commit = subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip()
        bundle = reference_from_git(ROOT,commit)
        (inputs/'con02-update-reference.json').write_text(json.dumps(bundle.reference,sort_keys=True)+'\n')
        for prefix, label in [('stage_','stage'),('fresh_','fresh')]:
            (inputs/('con02-update-'+label+'.sql')).write_text(db_query(prefix)+'\n')
        changes = {
            'column': 'ALTER TABLE stage_local_ustar_notifications ALTER COLUMN ackat SET DEFAULT 1;',
            'index': 'CREATE INDEX con02_update_unknown_idx ON stage_local_ustar_notifications (userid);',
            'task': "UPDATE stage_task_scheduled SET disabled=0 WHERE classname='\\local_ustar\\task\\prune_connect_identity';",
            'extra': 'CREATE TABLE stage_local_ustar_con02_unknown (id bigint);'}
        for name, change in changes.items():
            sql = db_query('stage_').replace('BEGIN READ ONLY;', 'BEGIN; '+change,1)
            c.require(sql.endswith('COMMIT;'),'fixture_query_shape')
            (inputs/('con02-update-'+name+'.sql')).write_text(sql.removesuffix('COMMIT;')+'ROLLBACK;\n')
        return
    c.require(mode == ['verify'],'native_fixture_mode')
    reference = json.loads((inputs/'con02-update-reference.json').read_text())
    phases = {n: json.loads((outputs/('con02-update-'+n+'.json')).read_text())
              for n in ('baseline','target','repeat','fresh','restored')}
    baseline = phases['baseline']
    c.verify_database(baseline,baseline,reference,target=False)
    for n in ('target','repeat'): c.verify_database(phases[n],baseline,reference,target=True)
    c.verify_database(phases['restored'],baseline,reference,target=False)
    c.require(phases['target']==phases['repeat'],'native_repeat_drift')
    table = 'local_ustar_conn_acktokens'
    def new_schema(value, prefix):
        return ([[(v.replace(prefix,'PREFIX_') if isinstance(v,str) else v) for v in row]
                 for row in value['schema'] if row[0]==table],
                [[row[0],row[1].replace(prefix,'PREFIX_')] for row in value['indexes'] if row[0]==table])
    c.require(new_schema(phases['target'],'stage_') == new_schema(phases['fresh'],'fresh_'),'native_new_table_not_fresh')
    for name in ('column','index','task','extra'):
        mutated = json.loads((outputs/('con02-update-mutated-'+name+'.json')).read_text())
        try: c.verify_database(mutated,baseline,reference,target=True)
        except c.Refusal: pass
        else: raise RuntimeError('native_mutation_adopted_'+name)
    task = '\\local_ustar\\task\\prune_connect_identity'
    c.require(baseline['tasks'][task] == phases['target']['tasks'][task] == [1,1], 'native_custom_task_changed')
    c.require((outputs/'con02-update-lifecycle.txt').read_text().strip() == 'CON02_UPDATE_NATIVE_LIFECYCLE=PASS','native_lifecycle_marker')
    evidence = {'result':'CON02_UPDATE_NATIVE=PASS','application_commit':c.APP_COMMIT,
        'direct_versions':[2026102000,2026102200,2026102200,2026102000], 'tables':[99,100,100,99],
        'official_CLI_upgrade_and_repeat':True,'fresh_new_table_parity':True,'old99_schema_and_indexes_preserved':True,
        'custom_task':[1,1], 'native_SQL_mutations_denied':['column','index','task','extra'],
        'history_ACK_provider_receipt_preferences_revoked_link_preserved':True,
        'restore':'logical PG dump plus actual source/config/moodledata in disposable runner',
        'physical_copy':'separate real-filesystem CON02 transaction tests; not full remote PG restore',
        'production_or_provider_access':False}
    (outputs/'con02-update-native.json').write_text(json.dumps(evidence,indent=2)+'\n')
    print('CON02_UPDATE_NATIVE=PASS')


if __name__=='__main__':main()
