#!/usr/bin/env python3
"""Read real PG metadata after exact2900 -> route access and validate the delivered contract."""
import json
from pathlib import Path
import sys

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'tests/stage')]
import route_access_update as update
from build_route_access_update import profile
from connect_role_bot_native import query,stable
from connect_academy_installer import db_query

def main(args):
    path=ROOT/'.stage-input/route-access-reference.json'
    if args==['--prepare']:
        before,_=profile(ROOT,update.BASELINES[0]);after,_=profile(ROOT,update.APPLICATION)
        path.parent.mkdir(exist_ok=True);path.write_text(json.dumps({'baseline':before,'candidate':after,'fresh':profile(ROOT,'HEAD')[0]},sort_keys=True)+'\n')
        return
    update.require(args==['--verify'],'fixture_mode')
    ref=json.loads(path.read_text());baseline=json.loads(Path('/artifacts/connect-role-bot-upgraded.json').read_text())
    before_profile=update.verify_database(baseline,ref['baseline'])
    current=query(db_query('botold_'))
    update.require(update.verify_database(current,ref['candidate'],baseline)==before_profile,'accepted_legacy_indexes_changed')
    fresh=query(db_query('fresh_'));fresh_profile=ref.get('fresh',ref['candidate'])
    update.require(update.verify_database(fresh,fresh_profile)=='xml_install_indexes','current_fresh_schema')
    denied=[]
    test_baseline={**fresh,'tasks':{k:v for k,v in fresh['tasks'].items() if k!=update.NEW_TASK}}
    for label,mutation in {
        'access_deadline_type':'ALTER TABLE fresh_local_ustar_route_access ALTER COLUMN deadline TYPE integer;',
        'unexpected_index':'CREATE INDEX unexpected_review_index ON fresh_local_ustar_route_access(userid);',
        'changed_old_field':'ALTER TABLE fresh_local_ustar_route_progress ALTER COLUMN status TYPE varchar(32);',
        'changed_task':"UPDATE fresh_task_scheduled SET disabled=1 WHERE classname='\\local_ustar\\task\\reconcile_route_access';",
    }.items():
        sql=db_query('fresh_');update.require(sql.startswith('BEGIN READ ONLY;') and sql.endswith('COMMIT;'),'read_query_shape')
        changed=query(sql.replace('BEGIN READ ONLY;','BEGIN; '+mutation,1).removesuffix('COMMIT;')+'ROLLBACK;')
        try:update.verify_database(changed,fresh_profile,test_baseline)
        except update.Refusal:denied.append(label)
        else:raise AssertionError('Foreign metadata accepted: '+label)
    update.require(stable(fresh)==stable(query(db_query('fresh_'))),'fixture_not_rolled_back')
    report={'result':'PASS','actual_PostgreSQL':True,'actual_2900_CLI_upgrade_repeat':True,
        'current_version':current['addons']['local_ustar'],'tables':len(current['tables']),
        'baseline_index_profile':before_profile,'fresh_index_profile':'xml_install_indexes','mutations_denied':denied,
        'business_database_restored':False,'operator_contract_used':True}
    Path('/artifacts/route-access-native.json').write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    print('ROUTE_ACCESS_ACTUAL_SQL=PASS '+json.dumps(report,sort_keys=True))

if __name__=='__main__':main(sys.argv[1:])
