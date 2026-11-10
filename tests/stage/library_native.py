#!/usr/bin/env python3
"""Real PG16 profile after exact accepted1100 -> unified Library0200 and repeated upgrade."""
import json
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'tests/stage')]
import library_update as update
from build_library_update import profile
from connect_role_bot_native import query,stable
from connect_academy_installer import db_query

def main():
    reference=ROOT/'.stage-input/library-reference.json'
    if sys.argv[1:]==['--prepare']:
        before,_=profile(ROOT,update.BASELINES[0]);candidate,_=profile(ROOT,update.APPLICATION)
        reference.parent.mkdir(exist_ok=True)
        reference.write_text(json.dumps({'before':before,'candidate':candidate},sort_keys=True)+'\n')
        return
    update.require(sys.argv[1:]==['--verify'],'fixture_mode')
    data=json.loads(reference.read_text());before_profile=data['before'];candidate=data['candidate']
    # Capture the real1100 profile before the successor, including accepted task/index metadata.
    before=json.loads(Path('/artifacts/library-baseline-native.json').read_text())
    update.verify_database(before,before_profile)
    current=query(db_query('botold_'));style=update.verify_database(current,candidate,before)
    fresh=query(db_query('fresh_'));update.require(update.verify_database(fresh,candidate)=='xml_install_indexes','fresh_library_schema')
    denied=[]
    baseline={**fresh,'tasks':{k:v for k,v in fresh['tasks'].items() if k!=update.NEW_TASK}}
    mutations={
        'changed_grant_uid':'ALTER TABLE fresh_local_ustar_library_grant ALTER COLUMN userid TYPE integer;',
        'unexpected_index':'CREATE INDEX unexpected_library_idx ON fresh_local_ustar_library_attempt(userid);',
        'task_disabled':"UPDATE fresh_task_scheduled SET disabled=1 WHERE classname='\\local_ustar\\task\\sync_library_quick_checks';",
    }
    # Actual generated index names are measured, never guessed.
    for name,definition in fresh['indexes']:
        if name.endswith('local_ustar_library_grant') and 'idempotencykey' in definition and 'UNIQUE' in definition:
            import re
            match=re.search(r'INDEX (\w+) ON',definition)
            update.require(bool(match),'grant_index_name');mutations['missing_idempotency_index']='DROP INDEX "'+match[1]+'";'
    update.require('missing_idempotency_index' in mutations,'measured_grant_idempotency_index_required')
    for label,mutation in mutations.items():
        sql=db_query('fresh_');changed=query(sql.replace('BEGIN READ ONLY;','BEGIN; '+mutation,1).removesuffix('COMMIT;')+'ROLLBACK;')
        try:update.verify_database(changed,candidate,baseline)
        except update.Refusal:denied.append(label)
        else:raise AssertionError('Foreign metadata accepted: '+label)
    update.require(stable(fresh)==stable(query(db_query('fresh_'))),'fixture_not_rolled_back')
    report={'result':'PASS','actual_PostgreSQL':True,'exact_1100_upgrade_repeat':True,'tables':108,
        'index_profile':style,'mutations_denied':denied,'worker_changed':False,'business_database_restored':False}
    Path('/artifacts/library-native.json').write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    print('LIBRARY_NATIVE=PASS '+json.dumps(report,sort_keys=True))
if __name__=='__main__':main()
