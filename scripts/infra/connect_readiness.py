#!/usr/bin/env python3
"""Read-only Connect upgrade inventory. No PHP bootstrap, migration, service or network changes."""
import argparse
from datetime import datetime,timezone
from decimal import Decimal
import json,os,pkgutil,re,signal,sys
from pathlib import Path
from production_manifest import production_files,compare
from production_patch_readiness import Refusal,require,read_file,parse_prefix,command

CODE=Path('/opt/ustar/data/moodle/public')
BACKUP=Path('/var/lib/ustar-backup')


def load_reference():
    return validate_reference(json.loads(pkgutil.get_data('connect_preflight_data','reference.json')))


def validate_reference(value):
    require(isinstance(value,dict) and set(value)=={'schema','source_commit','baseline_commit','baseline','candidate'}
            and value['schema']==1,'reference_schema')
    for key in ('source_commit','baseline_commit'):
        require(isinstance(value[key],str) and re.fullmatch('[a-f0-9]{40}',value[key]),'reference_commit')
    for key in ('baseline','candidate'):
        item=value[key]
        require(isinstance(item,dict) and set(item)=={'files','versions','tables'},'reference_component')
        require(isinstance(item['files'],dict) and 1<=len(item['files'])<=2000,'reference_files')
        for name,digest in item['files'].items():
            require(isinstance(name,str) and name.startswith(('local/ustar/','theme/ustar/'))
                    and '..' not in Path(name).parts and not Path(name).is_absolute()
                    and isinstance(digest,str) and re.fullmatch('[a-f0-9]{64}',digest),'reference_path_or_hash')
        require(isinstance(item['versions'],dict) and set(item['versions'])=={'local_ustar','theme_ustar'}
                and all(type(v) is int and 2000000000<=v<=2999999999 for v in item['versions'].values()),'reference_versions')
        require(isinstance(item['tables'],list) and 1<=len(item['tables'])<=500
                and len(set(item['tables']))==len(item['tables'])
                and all(isinstance(t,str) and re.fullmatch('local_ustar_[a-z0-9_]{1,50}',t) for t in item['tables']),'reference_tables')
    return value


def webroot(code):
    require(code.is_absolute() and not any(p.is_symlink() for p in (code,*code.parents)),'code_layout_symlink')
    candidates=[]
    for root in (code,code/'public'):
        markers=[root/scope/'version.php' for scope in ('local/ustar','theme/ustar')]
        if all(p.is_file() for p in markers):
            require(not any(p.is_symlink() for p in [*markers,*(root/'local/ustar').parents,*(root/'theme/ustar').parents]),'code_layout_symlink')
            candidates.append(root)
    require(len(candidates)==1,'code_layout_ambiguous_or_missing')
    return candidates[0]


def disk_versions(root):
    result={}
    for name,scope in [('local_ustar','local/ustar'),('theme_ustar','theme/ustar')]:
        data=read_file(root/scope/'version.php',128*1024)
        found=re.findall(rb'(?m)^\s*\$plugin->version\s*=\s*([0-9]{10})\s*;',data)
        require(len(found)==1,'disk_version_unavailable')
        result[name]=int(found[0])
    return result


def inspect_container(runner,name,source,target):
    # Go template intentionally excludes Config.Env, arguments and other secrets.
    template='{"running":{{json .State.Running}},"mounts":{{json .Mounts}}}'
    data=json.loads(runner(['docker','inspect','--format',template,name],timeout=15))
    require(isinstance(data,dict) and set(data)=={'running','mounts'} and data['running'] is True
            and isinstance(data['mounts'],list) and len(data['mounts'])<=32,'container_metadata')
    matches=[m for m in data['mounts'] if isinstance(m,dict) and m.get('Destination')==target]
    require(len(matches)==1 and matches[0].get('Type')=='bind' and matches[0].get('Source')==str(source),'container_mount')
    return {'status':'measured','running':True,'reviewed_bind_matches':True}


def db_query(prefix):
    require(re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}',prefix),'prefix_invalid')
    stem=prefix+'local_ustar_'
    return ("BEGIN READ ONLY; SET LOCAL statement_timeout='5s'; SET LOCAL lock_timeout='500ms'; "
        "SELECT json_build_object('transaction_read_only',current_setting('transaction_read_only'),"
        "'core',(SELECT value FROM "+prefix+"config WHERE name='version'),"
        "'local_ustar',(SELECT value FROM "+prefix+"config_plugins WHERE plugin='local_ustar' AND name='version'),"
        "'theme_ustar',(SELECT value FROM "+prefix+"config_plugins WHERE plugin='theme_ustar' AND name='version'),"
        "'tables',(SELECT json_agg(substr(tablename,"+str(len(prefix)+1)+") ORDER BY tablename) "
        "FROM pg_catalog.pg_tables WHERE schemaname='public' AND left(tablename,"+str(len(stem))+")='"+stem+"')); ROLLBACK;")


def database(runner,prefix,versions,reference):
    shell='exec psql -X -q -A -t -w -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
    data=json.loads(runner(['docker','exec','-i','ustar_postgres','sh','-eu','-c',shell],db_query(prefix),15))
    require(isinstance(data,dict) and set(data)=={'transaction_read_only','core','local_ustar','theme_ustar','tables'}
            and data['transaction_read_only']=='on','database_metadata')
    for key in ('core','local_ustar','theme_ustar'):
        require(isinstance(data[key],str) and re.fullmatch(r'[0-9]{10}(?:\.[0-9]{1,2})?',data[key]),'database_version')
    tables=data['tables']
    require(isinstance(tables,list) and len(tables)<=500 and len(set(tables))==len(tables)
            and all(isinstance(t,str) and re.fullmatch('local_ustar_[a-z0-9_]{1,50}',t) for t in tables),'database_tables')
    actual={k:int(data[k]) for k in ('local_ustar','theme_ustar')}
    return {'status':'measured','transaction_read_only':True,'core_version':data['core'],
        'reviewed_core_matches':Decimal(data['core'])==Decimal('2025100608'),
        'versions':actual,'disk_versions_match':actual==versions,
        'baseline_versions_match':actual==reference['baseline']['versions'],
        'tables':len(tables),'baseline_missing_tables':sorted(set(reference['baseline']['tables'])-set(tables)),
        'baseline_extra_tables':sorted(set(tables)-set(reference['baseline']['tables'])),
        'candidate_missing_tables':sorted(set(reference['candidate']['tables'])-set(tables))}


def backup_receipt(state):
    try:
        data=json.loads(read_file(state/'last-hdd-success.json',128*1024))
        require(isinstance(data,dict) and type(data.get('success')) is bool,'backup_metadata')
        date=datetime.fromisoformat(data['finished_utc'].replace('Z','+00:00'))
        require(date.tzinfo is not None,'backup_timezone')
        return {'status':'recorded_success' if data['success'] else 'reported_failure',
            'finished_at':date.astimezone(timezone.utc).isoformat(),'archive_or_restore_verified':False}
    except Exception:return {'status':'unavailable','archive_or_restore_verified':False}


def check(reference,*,code=CODE,runner=command,backup_state=BACKUP):
    validate_reference(reference);code=Path(code);root=webroot(code)
    # Static literal extraction only. No PHP evaluation or config contents exported.
    config=read_file(code/'config.php',128*1024);prefix=parse_prefix(config.decode('utf-8'))
    versions=disk_versions(root)
    files,skipped,errors=production_files(root)
    delta=compare(files,reference['baseline']['files'])
    complete=not skipped and not errors
    baseline=complete and not any(delta[k] for k in ('changed','production_only','git_only'))
    containers={}
    for name,source,target in [('ustar_moodle',code,'/var/www/html'),
                              ('ustar_postgres',Path('/opt/ustar/data/postgres'),'/var/lib/postgresql/data')]:
        try:containers[name]=inspect_container(runner,name,source,target)
        except Exception:containers[name]={'status':'unavailable'}
    db={'status':'unavailable','disk_versions_match':False}
    if all(v['status']=='measured' for v in containers.values()):
        try:db=database(runner,prefix,versions,reference)
        except Exception:pass
    # Recheck config and file version bytes after DB observation; not an atomic snapshot.
    require(read_file(code/'config.php',128*1024)==config and disk_versions(root)==versions,'source_changed_during_check')
    vfs=os.statvfs(code)
    good=(baseline and db['status']=='measured' and db['disk_versions_match'] and db['baseline_versions_match']
          and db['reviewed_core_matches'] and not db['baseline_missing_tables'] and not db['baseline_extra_tables'])
    return {'kind':'connect_academy_readonly_preflight','measured_at':datetime.now(timezone.utc).isoformat(),
        'status':'baseline_measured' if good else 'review_required','source_commit':reference['source_commit'],
        'baseline_commit':reference['baseline_commit'],'web_root':str(root),'disk_versions':versions,
        'source':{'complete':complete,'observed_files':len(files),'baseline_files':len(reference['baseline']['files']),
            'candidate_files':len(reference['candidate']['files']),'baseline_matches':baseline,
            'baseline_comparison':{k:len(v) if k=='matching' else v for k,v in delta.items()},
            'skipped':skipped,'errors':errors,
            'candidate_delta':{k:len(v) if k=='matching' else v for k,v in compare(files,reference['candidate']['files']).items()}},
        'database':db,'containers':containers,'backup':backup_receipt(Path(backup_state)),
        'ssd':{'available_bytes':vfs.f_bavail*vfs.f_frsize,'available_inodes':vfs.f_favail},
        'not_atomic_snapshot':True,'deployment_approved':False,'php_or_moodle_bootstrap':False,
        'activation_flags_evaluated':False,'code_config_db_or_service_changes':False,
        'remaining_gates':['complete_RC_and_paired_rollback','fresh_paired_backup_before_upgrade',
            'private_HTTPS_bridge_and_scoped_keys','native_account_link_and_notification_acceptance',
            'external_HTTPS_before_MiniApp','native_auth_handoff_MFA_step_up_and_role_actions']}


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--check',action='store_true')
    args=parser.parse_args(argv)
    try:
        reference=load_reference()
        if not args.check:
            print(json.dumps({'status':'plan_only','source_commit':reference['source_commit'],
                'baseline_files':len(reference['baseline']['files']),'candidate_files':len(reference['candidate']['files']),
                'host_commands':False,'next':'--check reads fixed production source and SQL metadata only'}));return 0
        require(os.geteuid()==0 and sys.version_info>=(3,12),'root_python312_required')
        def expired(_signum,_frame):raise Refusal('probe_deadline')
        previous=signal.signal(signal.SIGALRM,expired);signal.alarm(90)
        try:result=check(reference)
        finally:signal.alarm(0);signal.signal(signal.SIGALRM,previous)
        print(json.dumps(result,ensure_ascii=False,indent=2));return 0 if result['status']=='baseline_measured' else 2
    except Exception as error:
        if isinstance(error,Refusal) and re.fullmatch('[a-z_]{1,80}',str(error)):reason=str(error)
        elif isinstance(error,FileNotFoundError):reason='required_path_missing'
        elif isinstance(error,PermissionError):reason='required_file_unreadable'
        else:reason='preflight_unavailable_no_changes'
        print(json.dumps({'status':'refused','reason':reason}));return 2


if __name__=='__main__':raise SystemExit(main())
