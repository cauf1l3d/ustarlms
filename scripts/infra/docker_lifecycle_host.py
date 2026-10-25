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
    'ustar-academy-cert-renew.service','ustar-console-collect.service')

def production_profile():
    return {'source':SOURCE,'source_sha256':SOURCE_SHA,'core':CORE,'core_sha256':CORE_SHA,
        'socket':Path('/var/run/docker.sock'),'base':BASE,
        'predecessors':{
            'ustar_moodle':{'id':'64130d6157efbad3a290a39a64eaad29796b67c10004773e419cfa5ce4f0ecfc',
                'image':'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b'},
            'ustar_postgres':{'id':'0d4d016c8f3f46e3278613daa02846a1833f470b326a9833f79f9ec7a331b7b6',
                'image':'sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94'}}}

def validate_host(profile):
    l.require(os.geteuid()==0,'root_required')
    l.require(os.uname().nodename=='server1.example.com','unexpected_host')
    l.require(Path('/run/systemd/system').is_dir(),'systemd_host_required')
    info=profile['socket'].lstat()
    l.require(stat.S_ISSOCK(info.st_mode) and info.st_uid==0 and not info.st_mode&0o002,'unsafe_local_docker_socket')

def definition(raw,name,path):
    value=ModuleType(name);value.__file__=str(path)
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
        return {**baseline,'controls':controls,'source_model':source,'units':self.guards.units()}

    def quiesce(self,state):
        self.run.log=self.store.base/'private-command.log'
        managed=self.store.load()['managed'].get('cron_schedule')
        if not managed or not managed.get('restored'):
            paused=b'# USTAR runtime transition; the original schedule is retained privately.\n'
            self.write('cron_schedule',paused)
        self.guards.quiesce(state['before']['units'])

    def backup(self,state):return self.guards.backup(state)
    def cold(self):self.guards.cold()
    def no_clients(self,state):self.guards.no_clients(state)

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
