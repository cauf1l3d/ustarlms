"""Fixed server1 adapter; constructors are injectable only for isolated CI fixtures."""
import base64
from contextlib import contextmanager,ExitStack
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import time
from types import ModuleType
import docker_lifecycle as l

CORE=Path('/usr/local/sbin/USTAR_INFRA_ONE_PASS_20261025.py')
CORE_SHA='5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5'
BASE=Path('/var/lib/ustar-code-hardening/docker-runtime')
SOURCE=Path('/opt/ustar/apps/stack/docker-compose.yml')
HDD=Path('/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py')
CRON=Path('/usr/local/sbin/USTAR_CRON_SETUP_20261005.py')
INSTALLATION=Path('/var/lib/ustar-cron/installation.json')
SCHEDULE=Path('/etc/cron.d/ustar_moodle')
HDD_SHA='a604fbcd6a0733d29142bb3130dc4bea2e7da1797c6995699d9d5516f21b05c3'
SOURCE_SHA='41de2ed318ffb7697d92c575d1fc35ab3e6b1d16900e725aca843642d1cb7a09'
UNITS=('ustar-connect-receiver.service','ustar-connect-notify.service','ustar-connect-probe.service',
    'ustar-hdd-backup.timer','ustar-academy-cert-renew.timer',
    'ustar-console-host-state-backup.timer','ustar-console-collect.timer',
    'ustar-academy-cert-renew.service','ustar-console-collect.service',
    'ustar-hdd-backup.service','ustar-console-host-state-backup.service')

def production_profile():
    return {'source':SOURCE,'source_sha256':SOURCE_SHA,'core':CORE,'core_sha256':CORE_SHA,
        'socket':Path('/var/run/docker.sock'),'base':BASE,
        'predecessors':{
            'ustar_moodle':{'id':'64130d6157efbad3a290a39a64eaad29796b67c10004773e419cfa5ce4f0ecfc',
                'image':'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b'},
            'ustar_postgres':{'id':'0d4d016c8f3f46e3278613daa02846a1833f470b326a9833f79f9ec7a331b7b6',
                'image':'sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94'}}}

def validate_host(profile,*,socket_required=True):
    l.require(os.geteuid()==0,'root_required')
    l.require(os.uname().nodename=='server1.example.com','unexpected_host')
    l.require(Path('/run/systemd/system').is_dir(),'systemd_host_required')
    if socket_required:
        info=profile['socket'].lstat()
        l.require(stat.S_ISSOCK(info.st_mode) and info.st_uid==0 and not info.st_mode&0o002,'unsafe_local_docker_socket')

def definition(raw,name,path):
    value=ModuleType(name);value.__file__=str(path)
    sys.modules[name]=value
    exec(compile(raw,str(path),'exec'),value.__dict__)
    return value

def load_core():
    fd=os.open(CORE,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        st=os.fstat(fd)
        l.require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1
            and stat.S_IMODE(st.st_mode)==0o600 and st.st_size<=65536,'unsafe_pinned_core')
        with os.fdopen(os.dup(fd),'rb') as stream:raw=stream.read(65537)
        l.require(l.sha(raw)==CORE_SHA,'pinned_core_digest_drift')
    finally:os.close(fd)
    return definition(raw,'_ustar_runtime_core',CORE)

def docker(*args):return ['/usr/bin/docker','--host','unix:///var/run/docker.sock',*args]

class Commands:
    def __init__(self,log=None):self.log=Path(log) if log else None

    def __call__(self,argv,*,raw=None,timeout=45,check=True):
        process=subprocess.Popen(argv,stdin=subprocess.PIPE if raw is not None else subprocess.DEVNULL,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True,
            env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C','LC_ALL':'C'})
        try:
            try:out,err=process.communicate(raw,timeout=timeout)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid,signal.SIGKILL);process.communicate()
                raise l.Refusal('host_command_timeout') from None
            l.require(len(out)+len(err)<=8*1024**2,'host_output_bound')
            if self.log is not None:
                fd=os.open(self.log,os.O_WRONLY|os.O_APPEND|os.O_CREAT|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'ab') as stream:
                    st=os.fstat(stream.fileno())
                    l.require(stat.S_ISREG(st.st_mode) and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==0o600,
                        'unsafe_private_command_log')
                    stream.write(out);stream.write(err);stream.flush()
            l.require(not check or process.returncode==0,'host_command_failed_private_log')
            return subprocess.CompletedProcess(argv,process.returncode,out,err)
        finally:
            if process.poll() is None:
                os.killpg(process.pid,signal.SIGKILL);process.wait()

class Controls:
    """The same private file/consumer path is exercised by native CI and production."""
    def __init__(self,store,api,run,paths,guards):
        self.store,self.api,self.run,self.paths,self.guards=store,api,run,paths,guards
        self.files=l.ManagedFiles(store);self.core=store.core

    def original(self,state,key):
        return base64.b64decode(state['before']['controls'][key]['raw'],validate=True)

    def guard_files(self):
        state=self.store.load();l.require(state is not None,'runtime_state_missing')
        for key,old in state['before']['controls'].items():
            path=self.paths[key];managed=state['managed'].get(key)
            if managed is None or managed.get('restored'):allowed=[old['image']]
            else:allowed=managed['accepted']+([managed['pending']['image']] if managed.get('pending') else [])
            l.require(any(self.files.matches(path,item) for item in allowed),'runtime_control_file_drift')

    def write(self,key,raw):
        self.guard_files();return self.files.replace(key,self.paths[key],raw)

    def compose_command(self,*args):
        return docker('compose','--ansi','never','--project-directory',str(self.paths['compose'].parent),
            '--file',str(self.paths['compose']),*args)

    def prepare(self,rows):
        baseline=self.guards.prepare(rows)
        controls={}
        for key,path in self.paths.items():
            image,raw=self.files.image(path)
            controls[key]={'image':image,'raw':base64.b64encode(raw).decode()}
        self.guards.control_profile(controls)
        source=json.loads(self.run(self.compose_command('config','--no-env-resolution','--format','json')).stdout)
        l.require(set(source.get('services',{}))=={'moodle','postgres'},'compose_service_inventory')
        l.require(not any(v.get('env_file') for v in source['services'].values()),'unsupported_compose_env_files')
        # Fail unsupported process/mount/source shapes before stopping any writer.
        l.compose_model(rows,{'ustar_moodle':'sha256:'+'a'*64,'ustar_postgres':'sha256:'+'b'*64},
            'ustar_runtime_'+'0'*8,'0'*32,source)
        return {**baseline,'controls':controls,'source_model':source,'units':self.guards.units()}

    def quiesce(self,state,*,recovery=False):
        self.run.log=self.store.base/'private-command.log'
        managed=None if recovery else self.store.load()['managed'].get('cron_schedule')
        if not recovery and (not managed or not managed.get('restored')):
            paused=b'# USTAR runtime transition; the original schedule is retained privately.\n'
            self.write('cron_schedule',paused)
        self.guards.quiesce(state['before']['units'])

    def backup(self,state):return self.guards.backup(state)
    def cold(self):self.guards.cold()
    def no_clients(self,state):self.guards.no_clients(state)
    def recover_backup(self,state):
        if hasattr(self.guards,'recover_backup'):self.guards.recover_backup(state)

    def compose(self,state,model,raw):
        self.write('compose',raw)
        rendered=json.loads(self.run(self.compose_command('config','--no-env-resolution','--format','json')).stdout)
        labels={}
        for service in l.SERVICES:
            short='moodle' if service=='ustar_moodle' else 'postgres'
            values=rendered['services'][short]
            l.require(l.environment(values.get('environment') if isinstance(values.get('environment'),list)
                else [k+'='+str(v) for k,v in (values.get('environment') or {}).items()])
                ==l.environment(state['before']['containers'][service]['Config'].get('Env')),
                'compose_environment_interpolation_changed')
            hashes=self.run(self.compose_command('config','--hash',short)).stdout.decode().split()
            l.require(len(hashes)==2 and hashes[0]==short and re.fullmatch('[a-f0-9]{64}',hashes[1]),
                'compose_service_hash_shape')
            labels[service]={'com.docker.compose.config-hash':hashes[1],
                'com.docker.compose.project.working_dir':str(self.paths['compose'].parent),
                'com.docker.compose.project.config_files':str(self.paths['compose']),
                'com.docker.compose.container-number':'1','com.docker.compose.oneoff':'False'}
        return labels

    def consumers(self,state):
        old={n:{'id':v['Id'],'image':v['Image']} for n,v in state['before']['containers'].items()}
        old_images={n:v['image'] for n,v in old.items()}
        hdd=l.patch_images(self.original(state,'hdd_script'),old_images,state['images'])
        cron=l.patch_images(self.original(state,'cron_script'),old_images,state['images'])
        self.write('hdd_script',hdd);self.write('cron_script',cron)
        current=definition(cron,'_ustar_current_runtime_cron',self.paths['cron_script'])
        if hasattr(current,'command'):
            def local_command(argv,data=None,timeout=45):
                values=docker(*argv[1:]) if Path(argv[0]).name=='docker' else argv
                return self.run(values,raw=data,timeout=timeout).stdout.decode()
            current.command=local_command
        observed={n:current.identity(n) for n in l.SERVICES}
        expected={n:{'id':state['new_containers'][n],'image':state['images'][n]} for n in l.SERVICES}
        l.require(observed==expected,'new_cron_identity_function_drift')
        installation=l.cron_binding(self.original(state,'cron_script'),cron,
            json.loads(self.original(state,'cron_installation')),old,observed)
        self.write('cron_installation',(json.dumps(installation,sort_keys=True)+'\n').encode())

    def ready(self,service,identity):
        deadline=time.monotonic()+100
        while True:
            current=self.api.inspect(identity)
            l.require(current['State']['Running'],'runtime_exited_before_ready')
            probe=docker('exec',identity,'sh','-eu','-c',
                'exec pg_isready -q -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"') if service=='ustar_postgres' \
                else docker('exec',identity,'php','-n','-r',l.TCP_PROBE)
            ready=self.run(probe,timeout=8,check=False).returncode==0
            status=current['State'].get('Health',{}).get('Status')
            if ready and status in (None,'healthy'):return
            l.require(time.monotonic()<deadline,'runtime_readiness_timeout');time.sleep(.25)

    def verify(self,state,*,recovery=False):
        self.guard_files();self.guards.verify(state,recovery=recovery)

    def restore_files(self,state):self.files.restore()

    def resume(self,state,*,recovery=False):
        if not recovery:self.write('cron_schedule',self.original(state,'cron_schedule'))
        self.guards.resume(state['before']['units'])


class Locks:
    def __init__(self,core,*,write):
        self.core,self.write=core,write;self.stack=ExitStack();self.held={}

    def acquire(self,paths):
        for relative in paths:
            path=Path('/')/relative
            if path in self.held:continue
            fd=self.stack.enter_context(self.core.opened(path))
            row=self.core.description(fd)
            l.require(row['uid']==0 and row['mode']==0o600,'unsafe_existing_runtime_lock')
            try:fcntl.flock(fd,(fcntl.LOCK_EX if self.write else fcntl.LOCK_SH)|fcntl.LOCK_NB)
            except BlockingIOError:raise l.Refusal('managed_operation_busy_retry_later') from None
            self.held[path]=(fd,row)
        self.check()

    def check(self):
        for path,(fd,row) in self.held.items():
            st=path.lstat()
            l.require(self.core.description(fd)==row and (st.st_dev,st.st_ino)==(row['device'],row['inode']),
                'runtime_lock_replaced')

    def __enter__(self):
        try:
            self.acquire((*self.core.LOCKS[:2],'var/lib/ustar-connect-con02-update/lock',*self.core.LOCKS[2:6]))
        except BaseException:self.stack.close();raise
        return self

    def cold(self):self.acquire(self.core.LOCKS[6:])
    def __exit__(self,*args):
        try:self.check()
        finally:self.stack.close()


def verified(core,path,digest,maximum=64*1024**2):
    raw=core.read_private(path,maximum)
    l.require(l.sha(raw)==digest,'frozen_runtime_dependency_drift')
    return raw

class ProductionGuards:
    """Reuses frozen installed code/DB/HTTPS readers without their old runtime binding."""
    def __init__(self,store,api,run,locks):
        self.store,self.api,self.run,self.locks=store,api,run,locks;self.core=store.core
        code_path=Path('/root/ustar-code-hardening-20261025.py')
        code_raw=verified(self.core,code_path,self.core.CONTROLLERS[str(code_path).lstrip('/')])
        controller=definition(code_raw,'_ustar_runtime_frozen_code',code_path)
        self.code=controller.operator;self.pg=self.code.h
        verified(self.core,Path('/root/ustar-db-app-handoff-7e35795f-ttyfix.py'),
            '976afa0d2dffd58bf260dde4cb90cb5297f73d3f78fb9df10c2f9dc4b60ecf5e')
        self.loaded=self.pg.load_controller()
        self.ctx=self.loaded.t.make_context(self.loaded.bundle)
        self.private_log=os.fdopen(os.memfd_create('ustar-runtime-private',os.MFD_CLOEXEC),'w+b')
        os.fchmod(self.private_log.fileno(),0o600);self.ctx.host.log=self.private_log
        self.journal=self.loaded.journal_class(self.ctx)
        state=store.load()
        hdd_raw=base64.b64decode(state['before']['controls']['hdd_script']['raw'],validate=True) if state \
            else self.core.read_private(HDD,1024**2)
        l.require(l.sha(hdd_raw)==HDD_SHA,'accepted_HDD_source_drift')
        self.hdd=definition(hdd_raw,'_ustar_runtime_frozen_HDD',HDD)

    def identities(self,rows):
        expected=production_profile()['predecessors']
        l.require(set(rows)==set(expected) and all(v['Name']=='/'+n and v['Id']==expected[n]['id']
            and v['Image']==expected[n]['image'] and v['State']['Running'] for n,v in rows.items()),
            'predecessor_runtime_identity_drift')
        # Exact mount roots come from the accepted application primitive, rather
        # than accepting a newly observed path when the older guard disagrees.
        journal=self.journal.load()
        l.require(journal and journal.get('app',{}).get('phase')=='app_ready'
            and journal.get('runtime',{}).get('phase')=='runtime_active','accepted_application_runtime_required')
        frozen=journal['app']['primitive']['identities']
        self.ctx.host.bind_identities(frozen)
        frozen_rows=self.ctx.host.delegate().inspect()
        for n,row in rows.items():
            l.require(row['Id']==frozen[n]['Id'] and row['Image']==frozen[n]['Image']
                and row['Mounts']==frozen_rows[n]['Mounts'],'accepted_bind_identity_drift')
            l.require(row['HostConfig'].get('AutoRemove') is False
                and row['HostConfig'].get('LogConfig')=={'Type':'json-file','Config':{}}
                and not row['Config'].get('Healthcheck'),'unexpected_predecessor_lifecycle')
        l.require(l.bindings(rows['ustar_moodle'])=={'80/tcp':[('127.0.0.1','8082')]}
            and l.bindings(rows['ustar_postgres'])=={},'accepted_local_ports_changed')
        return journal

    def sql(self,text):
        shell='exec psql -X -w -qAt -P pager=off -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
        return self.run(docker('exec','-i','ustar_postgres','sh','-eu','-c',shell),raw=text.encode(),timeout=40).stdout

    def owner(self):
        # Static HTTP health, file/topology and authority checks only. No provider
        # probe or Moodle CLI bootstrap is invoked by this owner read.
        return self.ctx.host.https_snapshot()

    def code_and_database(self):
        self.code.Transaction('/opt/ustar/data/moodle/public','/var/lib/ustar-code-hardening').guard()
        state=self.pg.Store().load()
        l.require(state and state.get('phase')=='app_role_active'
            and state.get('controller_sha')==self.pg.CONTROLLER_SHA,'accepted_DB_role_required')
        b,a=(state['files']['config'][s] for s in ('before','after'))
        password=self.pg.literal(self.pg.image_raw(a),'dbpass')[1]
        l.require(self.pg.image_raw(a)==self.pg.patch_config(self.pg.image_raw(b),password)
            and self.pg.capture(self.pg.CONFIG)==a
            and a['metadata']==self.pg.ProductionHost.config_metadata(None,b),'accepted_app_config_drift')
        l.require(state['database_after']==self.pg.desired_inventory(state['database_before']),
            'accepted_ownership_delta_drift')
        current=json.loads(self.sql(self.pg.inventory_sql()),object_pairs_hook=self.pg.unique)
        counts=self.pg.validate_inventory(current,side='after')
        l.require(current==state['database_after'] and counts=={
            'relations':3479,'tables':657,'sequences':656,'indexes':2166},'accepted_catalog_inventory_drift')
        versions=json.loads(self.sql("BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; SET LOCAL ROLE ustar_app; "
            "SELECT json_object_agg(plugin,value) FROM public.mdl_config_plugins WHERE name='version' "
            "AND plugin IN ('local_ustar','theme_ustar'); ROLLBACK;"))
        l.require(versions=={'local_ustar':'2026102300','theme_ustar':'2026100202'},'accepted_addon_versions_drift')
        return counts

    def worker(self):
        journal=self.journal.load();self.ctx.host.controls(journal)
        self.loaded.runtime_class(self.ctx,self.journal,self.loaded.locks_class(self.ctx)).exact('after')
        installation=self.ctx.host.legacy().installation;current=installation.current()
        runtime=sys.modules[self.loaded.runtime_class.__module__]
        l.require(current is not None and current.name==self.ctx.bundle.reference['worker_commit']
            and installation.release_files(current)==runtime.worker_files(self.ctx.bundle),'accepted_worker_source_drift')

    def prepare(self,rows):
        self.locks.check();journal=self.identities(rows)
        self.ctx.engine.w.docker_conditions(self.ctx.engine.p,journal['app']['primitive']['identities'])
        counts=self.code_and_database();owner=self.owner()
        self.worker()
        for name,(path,digest) in self.ctx.engine.w.HELPERS.items():
            if name in ('backup_engine','hdd_scheduler'):
                l.require(self.pg.capture(path)['sha256']==digest,'accepted_backup_dependency_drift')
        l.require(not Path('/opt/ustar/apps/stack/.env').exists(),'unexpected_compose_dotenv')
        l.require(not Path('/var/lib/ustar-backup/moodle-was-running.json').exists(),'pending_backup_recovery')
        self.hdd.validate_target()
        size=sum(self.api.image(row['Image'])['Size'] for row in rows.values())+1024**3
        disk=os.statvfs('/srv/ustar-storage')
        l.require(size<8*1024**3 and disk.f_bavail*disk.f_frsize>=size+8*1024**3,'runtime_HDD_capacity_reserve')
        l.require(os.statvfs('/').f_bavail*os.statvfs('/').f_frsize>=4*1024**3,'runtime_SSD_capacity_reserve')
        return {'catalog_counts':counts,'https_owner':owner,
            'archive_budget_bytes':size,'legacy_app_sha256':self.pg.capture(self.journal.path)['sha256']}

    def control_profile(self,controls):
        source=controls['compose']['image'];meta=source['metadata']
        l.require(source['sha256']==SOURCE_SHA and (meta['uid'],meta['gid'],meta['mode'],meta['bytes'])
            ==(1000,1000,0o600,904),'accepted_compose_source_drift')
        for key,row in controls.items():
            if key!='compose':l.require(row['image']['metadata']['uid']==0
                and not row['image']['metadata']['mode']&0o022,'unsafe_runtime_control_source')
        l.require(l.sha(base64.b64decode(controls['hdd_script']['raw'],validate=True))==HDD_SHA,'accepted_HDD_source_drift')
        journal=self.journal.load()
        for key in ('cron_script','cron_installation','cron_schedule'):
            l.require(self.loaded.state.descriptor(self.loaded.state.capture({
                'cron_script':CRON,'cron_installation':INSTALLATION,'cron_schedule':SCHEDULE}[key]))
                ==journal['app']['files'][key]['after'],'accepted_cron_control_drift')
        installation=json.loads(base64.b64decode(controls['cron_installation']['raw'],validate=True))
        source=base64.b64decode(controls['cron_script']['raw'],validate=True)
        before=production_profile()['predecessors']
        l.require(installation.get('versions')=={'local_ustar':'2026102300','theme_ustar':'2026100202'}
            and installation.get('containers')==before and installation.get('script_sha256')==l.sha(source),
            'accepted_cron_installation_drift')
        # Validate the exact typed pin map before a single writer is stopped.
        dummy={'ustar_moodle':'sha256:'+'a'*64,'ustar_postgres':'sha256:'+'b'*64}
        l.patch_images(source,{n:v['image'] for n,v in before.items()},dummy)

    def unit(self,name):
        result=self.run(['/usr/bin/systemctl','show',name,'-p','LoadState','-p','ActiveState','-p','UnitFileState'])
        fields=dict(line.split('=',1) for line in result.stdout.decode().splitlines() if '=' in line)
        l.require(fields.get('LoadState')=='loaded' and fields.get('ActiveState') in ('active','inactive')
            and fields.get('UnitFileState') in ('enabled','disabled','static'),'unexpected_USTAR_unit_state')
        return {'active':fields['ActiveState']=='active','enabled':fields['UnitFileState']}

    def units(self):
        values={n:self.unit(n) for n in UNITS}
        l.require(all(not values[n]['active'] for n in ('ustar-hdd-backup.service',
            'ustar-console-host-state-backup.service','ustar-academy-cert-renew.service','ustar-connect-probe.service')),
            'managed_operation_busy_retry_later')
        return values

    def quiesce(self,units):
        for name,old in units.items():
            if old['enabled']=='enabled':self.run(['/usr/bin/systemctl','disable',name])
        for name in (*[n for n in units if n.endswith('.timer')],*[n for n in units if not n.endswith('.timer')]):
            self.run(['/usr/bin/systemctl','stop',name],timeout=60)
            l.require(not self.unit(name)['active'],'USTAR_writer_stop_failed')

    def resume(self,units):
        for name,old in reversed(list(units.items())):
            current=self.unit(name)
            if old['enabled'] in ('enabled','disabled') and current['enabled']!=old['enabled']:
                self.run(['/usr/bin/systemctl','enable' if old['enabled']=='enabled' else 'disable',name])
            if current['active']!=old['active']:
                self.run(['/usr/bin/systemctl','start' if old['active'] else 'stop',name],timeout=60)
            l.require(self.unit(name)==old,'USTAR_unit_policy_restore_failed')

    def backup(self,state):
        from datetime import datetime,timezone
        started=datetime.now(timezone.utc)
        self.run(['/usr/bin/python3','-I','-B','-u',str(HDD),'--backup','--acknowledge-outage'],timeout=900)
        success=self.core.root_json('/var/lib/ustar-backup/last-hdd-success.json')
        exported=self.core.root_json('/var/lib/ustar-backup/last-hdd-export.json')
        l.require(success.get('success') is True and success.get('phase')=='complete'
            and datetime.fromisoformat(success['started_utc'])>=started
            and success['manifest']['sha256']==exported['sha256']
            and exported.get('readback_verified') is True
            and not Path('/var/lib/ustar-backup/moodle-was-running.json').exists(),'fresh_HDD_backup_required')
        return {k:exported[k] for k in ('file','sha256','bytes')}

    def cold(self):self.locks.cold()

    def recover_backup(self,state):
        path=Path('/var/lib/ustar-backup/moodle-was-running.json')
        if not os.path.lexists(path):return
        marker=self.core.root_json(path)
        l.require(state.get('intent')=='backup_complete'
            and state.get('recover_from') in ('writers_quiesced','recovery_required','recovering')
            and marker.get('producer')=='ustar-hdd-backup-v1'
            and marker.get('container_id')==state['before']['containers']['ustar_moodle']['Id'],
            'unowned_backup_recovery_marker')
        if marker.get('stop_phase')=='inflight':
            self.api.stop(state['before']['containers']['ustar_moodle']['Id'])
        l.require(l.sha(self.core.read_private(HDD,1024**2))==HDD_SHA,'backup_recovery_source_drift')
        self.run(['/usr/bin/python3','-I','-B',str(HDD),'--recover'],timeout=120)
        l.require(not os.path.lexists(path),'backup_recovery_pending')

    def no_clients(self,state):
        result=self.sql("BEGIN READ ONLY; SET LOCAL statement_timeout='8s'; SELECT count(*) FROM pg_stat_activity "
            "WHERE datname=current_database() AND backend_type='client backend' AND pid<>pg_backend_pid(); ROLLBACK;")
        l.require(result.strip()==b'0','other_database_clients')

    def verify(self,state,*,recovery=False):
        self.locks.check();self.code_and_database();self.worker()
        l.require(self.pg.capture(self.journal.path)['sha256']==state['before']['legacy_app_sha256'],
            'legacy_journal_changed')
        owner=self.owner()
        if state['phase']!='active':l.require(owner==state['before']['https_owner'],'HTTPS_changed_during_runtime_transition')
        # Existing ordinary renewal remains the owner of future valid leaf changes.
        self.hdd.https_ready()

    @contextmanager
    def archive_anchor(self):
        with self.hdd.anchored_hdd() as mount:
            fd=os.open('backups',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=mount)
            try:
                try:os.mkdir('runtime',0o700,dir_fd=fd)
                except FileExistsError:pass
                child=os.open('runtime',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd)
                try:
                    st=os.fstat(child)
                    l.require(st.st_dev==os.fstat(mount).st_dev and st.st_uid==0
                        and stat.S_IMODE(st.st_mode)==0o700,'unsafe_HDD_runtime_directory')
                    yield child
                    self.hdd.validate_target(mount)
                finally:os.close(child)
            finally:os.close(fd)

    def close(self):
        try:
            if self.run.log is not None:
                self.private_log.seek(0);raw=self.private_log.read(8*1024**2)
                fd=os.open(self.run.log,os.O_WRONLY|os.O_APPEND|os.O_CREAT|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'ab') as out:
                    st=os.fstat(out.fileno())
                    l.require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1
                        and stat.S_IMODE(st.st_mode)==0o600,'unsafe_private_command_log')
                    out.write(raw)
        finally:self.private_log.close()


def public_error(error):
    value=str(error)
    return value if type(error).__name__=='Refusal' and re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,100}',value) \
        else type(error).__name__

def emit(value):
    try:print(json.dumps(value,sort_keys=True),flush=True)
    except OSError:pass

def interrupted(signum,frame):raise InterruptedError('operator_interrupted')

def entry(action,operator_path,*,acknowledge=False):
    l.require(action in ('check','apply','recover','status'),'unsupported_runtime_action')
    l.require(action not in ('apply','recover') or acknowledge,'outage_acknowledgement_required')
    profile=production_profile();validate_host(profile,socket_required=action!='status')
    # All frozen local helpers also inherit an explicit local daemon, regardless
    # of an interactive caller's Docker context. No shell or registry action.
    for name in ('DOCKER_CONTEXT','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH','COMPOSE_FILE','COMPOSE_PROJECT_NAME','COMPOSE_PROFILES'):
        os.environ.pop(name,None)
    os.environ['DOCKER_HOST']='unix:///var/run/docker.sock'
    os.umask(0o077);core=load_core();store=l.PrivateStore(BASE,core)
    if action=='status':
        state=store.load()
        return {'phase':state['phase'] if state else 'uninitialized','observation':'journal_only',
            'intent':state.get('intent') if state else None,'business_rows_restored':False,'provider_called':False}
    raw=core.read_private(Path(operator_path).absolute(),4*1024**2)
    api=l.DockerAPI();run=Commands();guards=None
    for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,interrupted)
    with Locks(core,write=action!='check') as locks:
        state=store.load()
        l.require(state is None or state['operator_sha256']==l.sha(raw),'current_operator_source_drift')
        try:
            guards=ProductionGuards(store,api,run,locks)
            controls=Controls(store,api,run,{'compose':SOURCE,'hdd_script':HDD,'cron_script':CRON,
                'cron_installation':INSTALLATION,'cron_schedule':SCHEDULE},guards)
            archive=l.RuntimeArchive(store,core,Path('/srv/ustar-storage/backups/runtime'),
                Path('/etc/ustar-backup/recipients.txt'),Path('/etc/ustar-backup/recovery.key'),
                lambda images:docker('image','save',*images.values()),anchor=guards.archive_anchor)
            host=l.RuntimeHost(store,api,controls,archive)
            tx=l.Transaction(store,host,raw,progress=emit)
            if action=='check':
                locks.cold()
                if state is None:
                    before=host.prepare()
                    result={'phase':'ready_for_runtime_handoff','configuration_changes':False,
                        'host_changes':False,'provider_called':False,'catalog_counts':before['catalog_counts'],
                        'requires_outage':True,'predecessors_retained':True,'business_rows_restored':False}
                elif state['phase']=='active':result=host.verify(state)
                else:result={'phase':state['phase'],'action_required':'recover','observation':'journal_only',
                    'business_rows_restored':False,'provider_called':False}
            elif action=='recover':result=tx.recover()
            else:result=tx.apply()
        finally:
            if guards is not None:guards.close()
    if action=='apply' and result.get('phase')=='runtime_active':
        # Companion owns these locks itself; invoke only after this coordinator
        # has released all of them. Its failure cannot roll back a healthy runtime.
        receipt=None
        for attempt in range(2):
            try:
                output=run(['/usr/bin/python3','-I','-B',str(CORE),'--backup-host-state'],timeout=240,check=False)
                receipt=json.loads(output.stdout)
                l.require(isinstance(receipt,dict),'companion_receipt_shape')
            except BaseException as error:receipt={'phase':'pending','reason':public_error(error)}
            if receipt.get('phase')=='complete' or receipt.get('reason')!='managed_operation_busy_retry_later':break
            if attempt==0:time.sleep(2)
        result['control_companion_complete']=receipt.get('phase')=='complete' and receipt.get('readback_verified') is True
        if result['control_companion_complete']:
            result['control_companion']={k:receipt[k] for k in ('archive','sha256','bytes','files','readback_verified','whole_host_backup')}
    return result
