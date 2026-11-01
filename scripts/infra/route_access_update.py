#!/usr/bin/env python3
"""Exact2900/SCORM-overlay -> versioned route review access. Recovery only moves forward."""
import argparse
import copy
from contextlib import contextmanager
from decimal import Decimal
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import traceback
import time
from connect_con02_contract import Refusal, require, sha, verify_schema_profile, CORE
from connect_con02_state import capture, descriptor, replace, private_directory, sync
from connect_academy_installer import db_query

APPLICATION='757dca12c9e9bc548be24b9bcc464796796e3024'
WORKER='267e55874eb8f6c36259d748f28a2c636adc9810'
BASELINES=('5cc02944e1059ba015afcc7c5e8068ca56c34a92','3f8521ec0b23ab0a974440d8eb3eaf378d21d741')
WEB='opt/ustar/data/moodle/public/public'
CONFIG='opt/ustar/data/moodle/public/config.php'
DATA='opt/ustar/data/moodle/moodledata'
CRON='usr/local/sbin/USTAR_CRON_SETUP_20261005.py'
CRON_INFO='var/lib/ustar-cron/installation.json'
CRON_SCHEDULE='etc/cron.d/ustar_moodle'
CRON_TEXT=(b'# Managed by USTAR_CRON_SETUP_20261005.py\nSHELL=/bin/sh\n'
    b'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
    b'MAILTO=""\n* * * * * root /usr/bin/python3 '
    b'/usr/local/sbin/USTAR_CRON_SETUP_20261005.py --tick >/dev/null 2>&1\n')

LOCKS=('var/lib/ustar-connect-academy-update/lock','var/lib/ustar-connect-chat-link-update/lock',
       'var/lib/ustar-academy-https/lock','run/ustar-cron/lock','run/ustar-backup/lock','run/ustar-connect-install.lock')
SERVICES=('ustar-connect-notify.service','ustar-connect-receiver.service')
BEFORE_VERSIONS={'local_ustar':'2026102900','theme_ustar':'2026102700'}
VERSIONS={'local_ustar':'2026110100','theme_ustar':'2026102700'}
CRON_ORIGINAL_SHA='ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751'
CRON_2000=b"VERSIONS = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}"
CRON_2900=b"VERSIONS = {'local_ustar': '2026102900', 'theme_ustar': '2026102700'}"
CRON_TARGET=b"VERSIONS = {'local_ustar': '2026110100', 'theme_ustar': '2026102700'}"
NEW_TASK='\\local_ustar\\task\\reconcile_route_access'
MAINTENANCE=b'<!doctype html><meta charset="utf-8"><title>USTAR</title><p>USTAR: technical maintenance. Please return shortly.</p>\n'


def patch_cron(raw,record):
    require(raw.count(CRON_2900)==1 and sha(raw.replace(CRON_2900,CRON_2000))==CRON_ORIGINAL_SHA,'cron_source_changed')
    require(record.get('schema')==1 and record.get('versions')==BEFORE_VERSIONS
        and record.get('script_sha256')==sha(raw),'cron_installation_changed')
    target=raw.replace(CRON_2900,CRON_TARGET);compile(target,'<reviewed-route-access-cron>','exec')
    return target,{**record,'versions':VERSIONS,'script_sha256':sha(target)}


def verify_database(current,profile,baseline=None):
    require(Decimal(current['core'])==Decimal(CORE),'core_version_changed')
    require(current['tables']==profile['tables'],'database_table_inventory')
    require({k:current['addons'].get(k) for k in profile['versions']}==
        {k:str(v) for k,v in profile['versions'].items()},'database_versions')
    if baseline:
        expected={**baseline['addons'],**{k:str(v) for k,v in profile['versions'].items()}}
        require(current['addons']==expected,'other_plugin_versions_changed')
        require(current['tasks']=={**baseline['tasks'],NEW_TASK:[0,0]},'scheduled_task_flags_changed')
    indexes=[];prefixes=set()
    for name,definition in current['indexes']:
        matches=[t for t in profile['tables'] if name.endswith(t)]
        require(bool(matches),'database_index_table')
        table=max(matches,key=len);prefix=name[:-len(table)];prefixes.add(prefix);indexes.append([table,definition])
    require(len(prefixes)==1,'database_index_prefix');prefix=next(iter(prefixes))
    try:
        verify_schema_profile(current,indexes,profile,set(profile['tables']),prefix)
        return 'xml_install_indexes'
    except Refusal as error:
        if str(error)!='schema_indexes':raise
    # Preserve exactly the accepted paired omission, never adopt or repair arbitrary drift.
    alternate=copy.deepcopy(profile)
    for table,field in [('local_ustar_coin_accounts','userid'),('local_ustar_test_results','attemptid')]:
        found=[i for i in alternate['schema_indexes'][table] if i['fields']==[field] and i['unique']]
        require(len(found)==1,'schema_indexes');alternate['schema_indexes'][table].remove(found[0])
    verify_schema_profile(current,indexes,alternate,set(profile['tables']),prefix)
    return 'server1_legacy_indexes_20261023'


def dump_image(path):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd,'rb') as stream:
        meta=os.fstat(stream.fileno())
        require(stat.S_ISREG(meta.st_mode) and meta.st_uid==os.geteuid() and meta.st_nlink==1
            and stat.S_IMODE(meta.st_mode)==0o600,'unsafe_database_copy')
        digest=hashlib.file_digest(stream,'sha256').hexdigest()
        return {'path':path.name,'bytes':meta.st_size,'sha256':digest}


class Host:
    def __init__(self,log):self.log=log;self.ids=None
    def run(self,args,raw=None,timeout=90):
        result=subprocess.run(args,input=raw,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout)
        if result.returncode:
            if self.log:self.log.write(result.stdout+result.stderr);self.log.flush()
            raise Refusal('host_command_failed')
        require(len(result.stdout)<=16*1024**2,'host_output_size')
        return result.stdout
    def identity(self):
        result={}
        for key,name,image,source,target in [
                ('web','ustar_moodle','sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b',
                    '/opt/ustar/data/moodle/public','/var/www/html'),
                ('db','ustar_postgres','sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94',
                    '/opt/ustar/data/postgres','/var/lib/postgresql/data')]:
            row=json.loads(self.run(['docker','inspect','--format',
                '{"Id":{{json .Id}},"Image":{{json .Image}},"Mounts":{{json .Mounts}},"Running":{{json .State.Running}}}',name]))
            require(row['Image']==image and (key=='web' or row['Running']),'runtime_image_or_state_changed')
            require(any(m['Source']==source and m['Destination']==target for m in row['Mounts']),'runtime_bind_changed')
            result[key]=row['Id']
        require(self.ids is None or result==self.ids,'container_replaced');self.ids=result
        return result
    def services(self):
        result={}
        for unit in SERVICES:
            value=self.run(['systemctl','show','-p','ActiveState','--value',unit]).strip()
            require(value in (b'active',b'inactive'),'worker_state_changed');result[unit]=value==b'active'
        return result
    def stop_workers(self,states):
        for unit,active in states.items():
            if active:self.run(['systemctl','stop',unit])
    def restore_workers(self,states):
        for unit,active in states.items():
            if active:self.run(['systemctl','start',unit])
    def database(self,prefix):
        command='exec psql -X -qAt -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
        return json.loads(self.run(['docker','exec','-i',self.ids['db'],'sh','-eu','-c',command],db_query(prefix).encode(),timeout=40))
    def dump(self,path):
        command='exec pg_dump -Fc -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
        fd=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as out:
            result=subprocess.run(['docker','exec',self.ids['db'],'sh','-eu','-c',command],stdout=out,stderr=subprocess.PIPE,timeout=600)
            if result.returncode:
                if self.log:self.log.write(result.stderr);self.log.flush()
                raise Refusal('database_copy_failed')
            out.flush();os.fsync(out.fileno())
            require(out.tell()>0,'database_copy_empty')
    def lint(self,raw):self.run(['docker','exec','-i','-u','33:33',self.ids['web'],'php','-n','-l'],raw)
    def stop(self):self.run(['docker','stop','--time','30',self.ids['web']])
    def start(self):self.run(['docker','start',self.ids['web']])
    def upgrade(self):
        self.run(['docker','exec','-u','33:33',self.ids['web'],'php','-d','memory_limit=512M',
            '-d','sendmail_path=/bin/false','-d','opcache.enable_cli=0','/var/www/html/admin/cli/upgrade.php','--non-interactive'],timeout=600)
    def login(self):
        for attempt in range(5):
            status=self.run(['curl','--silent','--show-error','--noproxy','*','--connect-timeout','2',
                '--max-time','4','--output','/dev/null','--write-out','%{http_code}',
                '--header','Host: ustar.local','http://127.0.0.1/login/index.php'],timeout=6).strip()
            if status==b'200':return True
            if attempt<4:time.sleep(1)
        return False
    def healthy(self):
        self.identity()
        raw=self.run(['docker','inspect','--format','{{.State.Running}}',self.ids['web']])
        return raw.strip()==b'true'


class Update:
    def __init__(self,root,manifest,payload,host):
        self.root=Path(root);self.manifest=manifest;self.payload=payload;self.host=host
        self.web=self.root/WEB;self.base=self.root/('var/lib/ustar-route-review-update-'+APPLICATION[:12])
        self.maintenance=self.root/DATA/'climaintenance.html'
        require(manifest.get('schema')==1 and manifest.get('application_commit')==APPLICATION
            and set(manifest['baselines'])<=set(BASELINES) and bool(manifest['baselines']),'fixed_release_required')
        needed=set();after=manifest['candidate']['files']
        for profile in manifest['baselines'].values():
            require(set(profile['files'])<=set(after),'source_removal_forbidden')
            needed|={p for p in after if profile['files'].get(p)!=after[p]}
        require(set(payload)==needed,'payload_inventory')
        for path,digest in after.items():
            require(path.startswith(('local/ustar/','theme/ustar/')) and '..' not in Path(path).parts
                and '\\' not in path and re.fullmatch('[0-9a-f]{64}',digest),'payload_path')
        require(all(sha(raw)==after[p] for p,raw in payload.items()),'payload_digest')
    @contextmanager
    def locks(self,writing):
        handles=[]
        try:
            paths=[self.root/p for p in LOCKS]
            if writing:
                private_directory(self.base,os.geteuid());paths.insert(0,self.base/'lock')
            for path in paths:
                flags=os.O_NOFOLLOW|os.O_NONBLOCK|(os.O_CREAT|os.O_RDWR if path==self.base/'lock' else os.O_RDONLY)
                fd=os.open(path,flags,0o600);handles.append(fd);s=os.fstat(fd)
                require(stat.S_ISREG(s.st_mode) and s.st_uid==os.geteuid() and s.st_nlink==1
                    and stat.S_IMODE(s.st_mode)==0o600,'unsafe_managed_lock')
                try:fcntl.flock(fd,(fcntl.LOCK_EX if writing else fcntl.LOCK_SH)|fcntl.LOCK_NB)
                except BlockingIOError:raise Refusal('managed_operation_busy_retry_later') from None
            yield
        finally:
            for fd in reversed(handles):os.close(fd)
    def load(self):
        path=self.base/'state.json'
        if not os.path.lexists(path):return None
        image=capture(path);require(image['metadata']['uid']==os.geteuid() and image['metadata']['mode']==0o600,'unsafe_journal')
        state=json.loads(image['raw']);require(state['manifest_sha']==sha(json.dumps(self.manifest,sort_keys=True).encode()),'journal_source_changed')
        return state
    def checkpoint(self,state,phase):
        state['phase']=phase
        replace(self.base/'state.json',(json.dumps(state,sort_keys=True)+'\n').encode(),
            {'uid':os.geteuid(),'gid':os.getegid(),'mode':0o600,'xattrs':{}})
        try:print(json.dumps({'phase':phase}),flush=True)
        except OSError:pass
    def source(self,profile):
        for name,digest in profile['files'].items():require(sha(capture(self.web/name)['raw'])==digest,'application_source_changed')
    def controls(self):
        return {p:descriptor(capture(self.root/p)) for p in self.manifest.get('preserved',[])}
    def cron(self):
        require(capture(self.root/CRON_SCHEDULE)['raw']==CRON_TEXT,'cron_schedule_changed')
        script=capture(self.root/CRON);info=capture(self.root/CRON_INFO)
        require(all(i['metadata']['uid']==os.geteuid() and not i['metadata']['mode']&0o022 for i in (script,info)),'unsafe_cron_controls')
        require(info['metadata']['mode']==0o600,'unsafe_cron_metadata')
        raw,record=patch_cron(script['raw'],json.loads(info['raw']))
        return {CRON:(script,raw),CRON_INFO:(info,(json.dumps(record,sort_keys=True)+'\n').encode())}
    def baseline(self):
        for key,profile in self.manifest['baselines'].items():
            try:self.source(profile)
            except (Refusal,FileNotFoundError):continue
            require(all(not os.path.lexists(self.web/p) for p in set(self.manifest['candidate']['files'])-set(profile['files'])),'new_source_path_exists')
            return key,profile
        raise Refusal('unknown_application_source')
    def prepare(self):
        key,profile=self.baseline();ids=self.host.identity()
        require(self.host.healthy() and not os.path.lexists(self.maintenance),'existing_maintenance_or_stopped_web')
        raw=capture(self.root/CONFIG)['raw'].decode()
        prefix=re.findall(r'''(?m)^\s*\$CFG->prefix\s*=\s*['"]([A-Za-z][A-Za-z0-9_]{0,31})['"]\s*;''',raw)
        require(len(prefix)==1,'database_prefix');database=self.host.database(prefix[0])
        indexprofile=verify_database(database,profile)
        cron=self.cron();controls=self.controls()
        pointer='opt/ustar-connect/current'
        if pointer in controls:
            image=capture(self.root/pointer)
            require(image['kind']=='symlink' and image['raw'].decode()=='/opt/ustar-connect/releases/'+WORKER,
                'accepted_worker_pointer_changed')

        for p,raw in self.payload.items():
            if p.endswith('.php'):self.host.lint(raw)
        state={'manifest_sha':sha(json.dumps(self.manifest,sort_keys=True).encode()),'baseline':key,'database':database,
            'index_profile':indexprofile,'ids':ids,'prefix':prefix[0],'services':self.host.services(),
            'controls':controls,'files':{},'dump':None,'phase':'prepared'}
        entries={str(self.web/p):capture(self.web/p) for p in self.payload if profile['files'].get(p)!=self.manifest['candidate']['files'][p]}
        entries.update({str(self.root/p):image for p,(image,_) in cron.items()})
        for number,(path,image) in enumerate(sorted(entries.items())):
            name='before-'+str(number)
            if image['raw'] is not None:
                replace(self.base/name,image['raw'],{'uid':os.geteuid(),'gid':os.getegid(),'mode':0o600,'xattrs':{}})
            targetmeta=image.get('metadata') or {'uid':0,'gid':Path(path).parent.stat().st_gid,'mode':0o644,'xattrs':{}}
            state['files'][path]={'before':descriptor(image),'backup':name,'target_metadata':targetmeta}
        self.checkpoint(state,'prepared');return state
    def saved_images(self,state):
        for item in state['files'].values():
            if item['before']['kind']=='absent':continue
            image=capture(self.base/item['backup'])
            require(image['kind']=='file' and image['metadata']['uid']==os.geteuid() and image['metadata']['mode']==0o600
                and sha(image['raw'])==item['before']['sha256'],'private_image_changed')
    def desired(self,state):
        oldscript=(self.base/state['files'][str(self.root/CRON)]['backup']).read_bytes()
        oldinfo=json.loads((self.base/state['files'][str(self.root/CRON_INFO)]['backup']).read_bytes())
        script,record=patch_cron(oldscript,oldinfo)
        return {str(self.web/p):raw for p,raw in self.payload.items() if str(self.web/p) in state['files']}|{
            str(self.root/CRON):script,str(self.root/CRON_INFO):(json.dumps(record,sort_keys=True)+'\n').encode()}
    def guard(self,state):
        require(self.host.identity()==state['ids'],'container_replaced')
        require(self.controls()==state['controls'],'preserved_controls_changed')
        self.saved_images(state)
        if state['dump']:require(dump_image(self.base/state['dump']['path'])==state['dump'],'database_copy_changed')
        for path,raw in self.desired(state).items():
            current=capture(Path(path));before=state['files'][path]['before']
            require(descriptor(current)==before or (current['kind']=='file' and sha(current['raw'])==sha(raw)
                and current['metadata']==state['files'][path]['target_metadata']), 'transaction_file_drift')
        if self.maintenance.exists():require(capture(self.maintenance)['raw']==MAINTENANCE,'maintenance_changed')
    def check(self):
        with self.locks(False):
            state=self.load()
            if not state:
                key,profile=self.baseline();self.host.identity();require(self.host.healthy() and not self.maintenance.exists(),'web_not_ready')
                raw=capture(self.root/CONFIG)['raw'].decode();prefix=re.findall(r'''\$CFG->prefix\s*=\s*['"]([A-Za-z][A-Za-z0-9_]{0,31})['"]\s*;''',raw)
                require(len(prefix)==1,'database_prefix');verify_database(self.host.database(prefix[0]),profile);self.cron()
                return {'phase':'ready','baseline':key,'host_changes':False}
            self.guard(state)
            if state['phase']!='active':return {'phase':'recovery_required','host_changes':False}
            self.verify_target(state);require(not self.maintenance.exists(),'maintenance_still_active')
            return {'phase':'active','local_ustar':'2026110100','host_changes':False,'worker_changed':False}
    def verify_target(self,state):
        self.source(self.manifest['candidate'])
        require(verify_database(self.host.database(state['prefix']),self.manifest['candidate'],state['database'])==state['index_profile'],
            'accepted_index_profile_changed')
        for path,raw in self.desired(state).items():require(sha(capture(Path(path))['raw'])==sha(raw),'target_control_changed')
        require(self.host.healthy(),'web_not_running')
    def apply(self):
        with self.locks(True):
            state=self.load() or self.prepare()
            if state['phase']=='active':self.guard(state);self.verify_target(state);return {'phase':'active'}
            return self.resume(state)
    def recover(self):return self.apply()
    def resume(self,state):
        self.guard(state)
        if state['phase']=='publication_committed':return self.finish(state)
        if not self.maintenance.exists():
            replace(self.maintenance,MAINTENANCE,{'uid':0,'gid':0,'mode':0o644,'xattrs':{}})
        self.host.stop_workers(state['services']);self.host.stop()
        if not state['dump']:
            self.checkpoint(state,'database_copy')
            path=self.base/'before.dump'
            if os.path.lexists(path):
                s=path.lstat();require(stat.S_ISREG(s.st_mode) and s.st_uid==os.geteuid() and s.st_nlink==1,'unsafe_database_copy');path.unlink()
            self.host.dump(path)
            state['dump']=dump_image(path)
            self.checkpoint(state,'database_copied')
        self.checkpoint(state,'source_switching')
        for path,raw in self.desired(state).items():
            if path in (str(self.root/CRON),str(self.root/CRON_INFO)):continue
            p=Path(path);replace(p,raw,state['files'][path]['target_metadata'])
        self.host.start()
        for phase in ('upgrade_1','upgrade_2'):
            self.checkpoint(state,phase);self.host.upgrade()
            verify_database(self.host.database(state['prefix']),self.manifest['candidate'],state['database'])
        for path,raw in self.desired(state).items():
            if path in (str(self.root/CRON),str(self.root/CRON_INFO)):
                replace(Path(path),raw,state['files'][path]['before']['metadata'])
        self.verify_target(state)
        self.checkpoint(state,'publication_committed')
        return self.finish(state)
    def finish(self,state):
        self.guard(state);self.verify_target(state)
        if self.maintenance.exists():self.maintenance.unlink();sync(self.maintenance.parent)
        require(self.host.login(),'application_login_failed')
        self.host.restore_workers(state['services'])
        require(self.host.services()==state['services'],'worker_states_not_restored')
        self.checkpoint(state,'active')
        return {'phase':'active','local_ustar':'2026110100','worker_changed':False,'database_restore':False}


def main(argv=None,*,bundle=None):
    p=argparse.ArgumentParser(description=__doc__);g=p.add_mutually_exclusive_group()
    for name in ('check','upgrade','recover'):g.add_argument('--'+name,action='store_true')
    p.add_argument('--acknowledge-outage',action='store_true');args=p.parse_args(argv)
    if args.upgrade or args.recover:
        if not args.acknowledge_outage:p.error('upgrade/recover requires --acknowledge-outage')
    elif args.acknowledge_outage:p.error('outage flag requires upgrade/recover')
    if not any((args.check,args.upgrade,args.recover)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    log=None
    try:
        script=Path(sys.argv[0]).absolute();s=script.lstat();parent=script.parent.stat()
        require(os.geteuid()==0 and s.st_uid==0 and s.st_nlink==1 and stat.S_ISREG(s.st_mode)
            and stat.S_IMODE(s.st_mode)==0o600 and parent.st_uid==0 and stat.S_IMODE(parent.st_mode)==0o700,'private_root_artifact_required')
        require(bundle is not None,'embedded_bundle_required')
        host=Host(None);operation=Update(Path('/'),bundle[0],bundle[1],host)
        if not args.check:
            private_directory(operation.base,0);path=operation.base/'private.log'
            image=capture(path)
            if image['kind']!='absent':require(image['metadata']['uid']==0 and image['metadata']['mode']==0o600,'unsafe_private_log')
            fd=os.open(path,os.O_CREAT|os.O_APPEND|os.O_WRONLY|os.O_NOFOLLOW,0o600);log=os.fdopen(fd,'ab');host.log=log
        result=operation.check() if args.check else operation.recover() if args.recover else operation.apply()
        print(json.dumps(result,sort_keys=True),flush=True);return 2 if result['phase']=='recovery_required' else 0
    except BaseException as error:
        if log:log.write(traceback.format_exc().encode());log.flush();os.fsync(log.fileno())
        reason=str(error) if isinstance(error,Refusal) and re.fullmatch('[a-z0-9_]+',str(error)) else 'route_access_operation_failed'
        print(json.dumps({'phase':'refused','reason':reason,'recovery_command':'--recover --acknowledge-outage'}),flush=True);return 2
    finally:
        if log:log.close()

if __name__=='__main__':raise SystemExit(main())
