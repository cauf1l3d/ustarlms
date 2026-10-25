#!/usr/bin/env python3
"""Preserve current container runtimes while advancing an explicit identity generation."""
import ast
import copy
import json
import re

SERVICES=('ustar_moodle','ustar_postgres')
LOGGING={'driver':'json-file','options':{'max-size':'20m','max-file':'3','compress':'true'}}
SNAPSHOT_PREFIX='org.ustar.snapshot.'
TCP_PROBE="$s=@fsockopen('127.0.0.1',80,$e,$m,2);if($s){fclose($s);exit(0);}exit(1);"

class Refusal(ValueError):
    """Bounded public codes only, never runtime values or private source text."""

def require(value,reason):
    if not value:raise Refusal(reason)

def images(value):
    require(isinstance(value,dict) and set(value)==set(SERVICES)
        and all(isinstance(v,str) and re.fullmatch('sha256:[a-f0-9]{64}',v) for v in value.values())
        and len(set(value.values()))==2,'image_vector_shape')
    return value

def patch_images(raw,before,after):
    images(before);images(after)
    require(all(before[n]!=after[n] for n in SERVICES),'unchanged_snapshot_image')
    require(isinstance(raw,bytes) and len(raw)<=8*1024**2 and not raw.startswith(b'\xef\xbb\xbf'),'helper_source_shape')
    try:tree=ast.parse(raw)
    except (SyntaxError,UnicodeError):raise Refusal('helper_syntax_invalid') from None
    found=[n for n in tree.body if isinstance(n,ast.Assign)
        and any(isinstance(t,ast.Name) and t.id=='IMAGES' for t in n.targets)]
    require(len(found)==1 and len(found[0].targets)==1 and isinstance(found[0].value,ast.Dict),'helper_image_map_shape')
    node=found[0].value
    require(len(node.keys)==2 and all(isinstance(k,ast.Constant) and isinstance(k.value,str) for k in node.keys),
        'helper_image_map_shape')
    keys=[k.value for k in node.keys]
    require(len(set(keys))==2 and set(keys)==set(SERVICES),'helper_image_map_shape')
    lines=raw.splitlines(keepends=True);replacements=[]
    for key,value in zip(keys,node.values):
        require(isinstance(value,ast.Constant) and value.value==before[key],'helper_image_pin_drift')
        first=sum(map(len,lines[:value.lineno-1]))+value.col_offset
        last=sum(map(len,lines[:value.end_lineno-1]))+value.end_col_offset
        replacements.append((first,last,repr(after[key]).encode('ascii')))
    result=raw
    for first,last,value in sorted(replacements,reverse=True):result=result[:first]+value+result[last:]
    try:compile(result,'<verified-runtime-consumer>','exec')
    except SyntaxError:raise Refusal('helper_patch_syntax') from None
    return result

def environment(value):
    require(value is None or isinstance(value,list),'environment_shape')
    result={}
    for item in value or []:
        require(isinstance(item,str) and '=' in item,'environment_shape')
        key,val=item.split('=',1)
        require(key and key not in result,'duplicate_environment')
        result[key]=val
    return result

def process(config):
    result={key:config.get(key) for key in ('Env','Entrypoint','Cmd','User','WorkingDir',
        'ExposedPorts','Volumes','StopSignal','Shell','OpenStdin','Tty')}
    result['Env']=environment(config.get('Env'))
    for key in ('Entrypoint','Cmd','Shell'):result[key]=config.get(key) or []
    for key in ('User','WorkingDir','StopSignal'):result[key]=config.get(key) or ''
    for key in ('ExposedPorts','Volumes'):result[key]=config.get(key) or {}
    for key in ('OpenStdin','Tty'):result[key]=config.get(key) or False
    return result

def snapshot_lineage(parent,child,container,generation,service):
    require(service in SERVICES and re.fullmatch('[a-f0-9]{32}',generation or ''),'snapshot_generation')
    require(parent.get('Id')==container.get('Image') and re.fullmatch('sha256:[a-f0-9]{64}',child.get('Id',''))
        and child['Id']!=parent['Id'],'snapshot_image_identity')
    base=parent.get('RootFS',{}).get('Layers');layers=child.get('RootFS',{}).get('Layers')
    require(parent.get('RootFS',{}).get('Type')=='layers' and child.get('RootFS',{}).get('Type')=='layers'
        and isinstance(base,list) and isinstance(layers,list)
        and all(isinstance(v,str) and re.fullmatch('sha256:[a-f0-9]{64}',v) for v in base+layers)
        and layers[:len(base)]==base
        and len(base)<=len(layers)<=len(base)+1,'snapshot_layer_lineage')
    labels=child.get('Config',{}).get('Labels') or {}
    wanted={'generation':generation,'source-container':container['Id'],
            'source-image':container['Image'],'service':service}
    require(all(labels.get(SNAPSHOT_PREFIX+k)==v for k,v in wanted.items()),'snapshot_owner')
    child_process=process(child['Config']);old_process=process(container['Config'])
    for key in ('OpenStdin','Tty'):child_process.pop(key);old_process.pop(key)
    require(child_process==old_process,'snapshot_process_changed')
    old_labels=container['Config'].get('Labels') or {}
    require({k:v for k,v in labels.items() if not k.startswith(SNAPSHOT_PREFIX)}==old_labels,'snapshot_labels_changed')
    return child['Id']

def health(service):
    test=['CMD-SHELL','pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'] if service=='ustar_postgres' else ['CMD','php','-n','-r',TCP_PROBE]
    return {'test':test,'interval':'30s','timeout':'5s','retries':3,'start_period':'20s'}

def bindings(row):
    requested=row['HostConfig'].get('PortBindings') or {}
    live=row.get('NetworkSettings',{}).get('Ports') or {}
    return {k:sorted(((v.get('HostIp',''),v['HostPort']) for v in (live.get(k) or values)),key=str)
            for k,values in requested.items() if values}

def compose_model(containers,children,project,generation,source_model):
    images(children)
    require(set(containers)==set(SERVICES) and re.fullmatch('ustar_runtime_[a-f0-9]{8}',project or '')
        and re.fullmatch('[a-f0-9]{32}',generation or ''),'compose_generation')
    result={'name':project,'services':{},'networks':{}}
    networks=sorted({n for c in containers.values() for n in c['NetworkSettings']['Networks']})
    require(networks,'runtime_network_missing')
    logical={name:'runtime'+str(i) for i,name in enumerate(networks)}
    for name,key in logical.items():result['networks'][key]={'external':True,'name':name}
    for service,row in containers.items():
        short='moodle' if service=='ustar_moodle' else 'postgres'
        cfg=row['Config'];hc=row['HostConfig'];environment(cfg.get('Env'))
        original=(source_model.get('services') or {}).get(short) or {}
        item=copy.deepcopy(original)
        for k in ('build','extends','profiles','env_file','network_mode','volumes_from','links'):
            item.pop(k,None)
        item.update(image=children[service],pull_policy='never',container_name=row['Name'].removeprefix('/'),
            environment=copy.deepcopy(cfg.get('Env') or []),entrypoint=cfg.get('Entrypoint'),command=cfg.get('Cmd'),
            user=cfg.get('User') or '',working_dir=cfg.get('WorkingDir') or '',hostname=cfg.get('Hostname') or '',
            tty=cfg.get('Tty',False),stdin_open=cfg.get('OpenStdin',False),
            logging=copy.deepcopy(LOGGING),healthcheck=health(service),
            labels={k:v for k,v in (cfg.get('Labels') or {}).items() if not k.startswith('com.docker.compose.')})
        item['labels']['org.ustar.runtime.generation']=generation
        if cfg.get('StopSignal'):item['stop_signal']=cfg['StopSignal']
        if cfg.get('StopTimeout') is not None:item['stop_grace_period']=str(cfg['StopTimeout'])+'s'
        policy=hc.get('RestartPolicy') or {'Name':'no'}
        item['restart']=policy['Name'] or 'no'
        if item['restart']=='on-failure' and policy.get('MaximumRetryCount'):item['restart']+=':'+str(policy['MaximumRetryCount'])
        item['read_only']=hc.get('ReadonlyRootfs',False);item['privileged']=hc.get('Privileged',False)
        item['volumes']=[]
        for mount in row['Mounts']:
            require(mount.get('Type')=='bind' and mount.get('Mode','rw') in ('rw','ro'),'unsupported_runtime_mount')
            item['volumes'].append({'type':'bind','source':mount['Source'],'target':mount['Destination'],
                'read_only':not mount['RW'],'bind':{'create_host_path':False,'propagation':mount.get('Propagation') or 'rprivate'}})
        item['ports']=[]
        for target,values in bindings(row).items():
            for host,port in values:
                host=('['+host+']') if ':' in host else host
                item['ports'].append((host+':' if host else '')+port+':'+target)
        item['networks']={}
        for name,endpoint in row['NetworkSettings']['Networks'].items():
            aliases=[v for v in endpoint.get('Aliases') or [] if v not in (row['Id'],row['Id'][:12])]
            entry={'aliases':aliases};ipam=endpoint.get('IPAMConfig') or {}
            if ipam.get('IPv4Address') or endpoint.get('IPAddress'):entry['ipv4_address']=ipam.get('IPv4Address') or endpoint['IPAddress']
            if ipam.get('IPv6Address') or endpoint.get('GlobalIPv6Address'):entry['ipv6_address']=ipam.get('IPv6Address') or endpoint['GlobalIPv6Address']
            item['networks'][logical[name]]=entry
        result['services'][short]=item
    return result

def verify_successor(before,after,children,project,generation):
    images(children)
    service=next((n for n,v in children.items() if v==after.get('Image')),None)
    require(service is not None and before.get('Name')==after.get('Name') and before.get('Id')!=after.get('Id'),
        'successor_identity')
    cfg=after['Config'];old=before['Config']
    require(process(cfg)==process(old),'successor_process_changed')
    require(cfg.get('Hostname')==old.get('Hostname'),'successor_hostname_changed')
    labels=cfg.get('Labels') or {}
    require(labels.get('com.docker.compose.project')==project and labels.get('org.ustar.runtime.generation')==generation,
        'successor_project_owner')
    def custom(value):return {k:v for k,v in value.items() if not k.startswith(('com.docker.compose.',SNAPSHOT_PREFIX,'org.ustar.runtime.'))}
    require(custom(labels)==custom(old.get('Labels') or {}),'successor_custom_labels_changed')
    require(after['HostConfig'].get('LogConfig')=={'Type':LOGGING['driver'],'Config':LOGGING['options']},'successor_logging')
    desired=health(service)
    require(cfg.get('Healthcheck')=={'Test':desired['test'],'Interval':30000000000,'Timeout':5000000000,
        'Retries':3,'StartPeriod':20000000000},'successor_health')
    def mounts(row):
        return sorted(({k:m.get(k) for k in ('Type','Source','Destination','Mode','RW','Propagation')}
            for m in row['Mounts']),key=lambda m:m['Destination'])
    require(mounts(before)==mounts(after),'successor_bind_changed')
    require(bindings(before)==bindings(after),'successor_ports_changed')
    ignored={'LogConfig','Binds','Mounts','PortBindings'}
    def host(row):return {k:v for k,v in row['HostConfig'].items() if k not in ignored}
    require(host(before)==host(after),'successor_host_configuration_changed')
    require(before.get('Path')==after.get('Path') and before.get('Args')==after.get('Args'),'successor_execution_changed')
    require(set(before['NetworkSettings']['Networks'])==set(after['NetworkSettings']['Networks']),'successor_network_changed')
    for network,old_endpoint in before['NetworkSettings']['Networks'].items():
        endpoint=after['NetworkSettings']['Networks'][network]
        require(endpoint.get('NetworkID')==old_endpoint.get('NetworkID'),'successor_network_changed')
        dynamic={before['Id'],before['Id'][:12],after['Id'],after['Id'][:12]}
        old_alias=set(old_endpoint.get('Aliases') or [])-dynamic
        aliases=set(endpoint.get('Aliases') or [])-dynamic
        allowed=old_alias|{before['Name'].removeprefix('/'),'moodle' if service=='ustar_moodle' else 'postgres'}
        require(old_alias<=aliases<=allowed,'successor_alias_changed')
        old_ipam=old_endpoint.get('IPAMConfig') or {};wanted=copy.deepcopy(old_ipam)
        for actual,key in (('IPAddress','IPv4Address'),('GlobalIPv6Address','IPv6Address')):
            if old_endpoint.get(actual):wanted[key]=old_endpoint[actual]
            require(not endpoint.get(actual) or endpoint.get(actual)==old_endpoint.get(actual),'successor_address_changed')
        require((endpoint.get('IPAMConfig') or {}) in (old_ipam,wanted),'successor_address_changed')
    return True

import hashlib
import os
from pathlib import Path
import stat
import uuid
from contextlib import contextmanager


def sha(raw):return hashlib.sha256(raw).hexdigest()

class PrivateStore:
    def __init__(self,base,core):
        self.base=Path(base);self.core=core;self.path=self.base/'state.json'

    def create(self,generation,operator,before):
        require(re.fullmatch('[a-f0-9]{32}',generation or '') and not os.path.lexists(self.path),'existing_runtime_transaction')
        self.core.private_dir(self.base)
        for name in ('originals','retained','bytes'):self.core.private_dir(self.base/name)
        self.core.atomic(self.base/'operator.py',operator)
        state={'schema':1,'kind':'docker_runtime_successor_v1','generation':generation,'phase':'prepared',
               'operator_sha256':sha(operator),'before':before,'managed':{}}
        self.save(state);return state

    def load(self):
        if not os.path.lexists(self.base):return None
        self.core.private_dir(self.base)
        if not os.path.lexists(self.path):return None
        state=json.loads(self.core.read_private(self.path,64*1024**2))
        require(isinstance(state,dict) and state.get('schema')==1 and state.get('kind')=='docker_runtime_successor_v1'
            and re.fullmatch('[a-f0-9]{32}',state.get('generation','')) and isinstance(state.get('managed'),dict),
            'runtime_state_identity')
        require(sha(self.core.read_private(self.base/'operator.py',4*1024**2))==state.get('operator_sha256'),
            'runtime_operator_image_drift')
        return state

    def save(self,state):
        self.core.atomic(self.path,(json.dumps(state,sort_keys=True)+'\n').encode())

    def step(self,phase,**fields):
        state=self.load();require(state is not None,'runtime_state_missing')
        state.update(phase=phase,**fields);self.save(state);return state


class ManagedFiles:
    """Preserve original control-file inodes; publish only known atomic candidates."""
    def __init__(self,store):self.store=store;self.core=store.core

    def image(self,path):
        with self.core.opened(path) as fd:
            meta=self.core.description(fd)
            require(meta['bytes']<=8*1024**2,'control_file_size')
            with os.fdopen(os.dup(fd),'rb') as stream:raw=stream.read(8*1024**2+1)
            require(self.core.description(fd)==meta and len(raw)==meta['bytes'],'control_file_changed')
        return {'metadata':meta,'sha256':sha(raw)},raw

    def parent(self,path):
        with self.core.opened(path,os.O_RDONLY|os.O_DIRECTORY) as fd:
            st=os.fstat(fd)
        return {'device':st.st_dev,'inode':st.st_ino,'uid':st.st_uid,'gid':st.st_gid,'mode':stat.S_IMODE(st.st_mode)}

    @contextmanager
    def anchored(self,row):
        path=Path(row['path'])
        require(self.parent(path.parent)==row['parent'],'control_parent_changed')
        with self.core.opened(path.parent,os.O_RDONLY|os.O_DIRECTORY) as parent:
            require(self.parent(path.parent)==row['parent'],'control_parent_changed')
            with self.core.opened(self.store.base/'originals',os.O_RDONLY|os.O_DIRECTORY) as originals:
                yield parent,originals

    def matches(self,path,image):
        if not os.path.lexists(path):return False
        current,_=self.image(path);return current==image

    def original(self,key,row):
        path=self.store.base/'originals'/key
        current,raw=self.image(path);old=row['before']['metadata'];now=current['metadata']
        require(current['sha256']==row['before']['sha256'] and all(now[k]==old[k]
            for k in ('device','inode','bytes','mtime_ns')) and now['uid'] in (0,old['uid'])
            and now['gid'] in (0,old['gid']) and now['mode'] in (0o600,old['mode']),
            'parked_original_changed')
        return path

    def replace(self,key,path,raw):
        require(re.fullmatch('[a-z][a-z0-9_]{0,40}',key or '') and isinstance(raw,bytes) and len(raw)<=8*1024**2,
            'control_replacement_shape')
        path=Path(path).absolute();require('..' not in path.parts,'control_path_shape')
        state=self.store.load();require(state is not None,'runtime_state_missing')
        row=state['managed'].get(key)
        if row is None:
            before,original_raw=self.image(path)
            require(before['metadata']['device']==self.store.base.stat().st_dev,'control_cross_filesystem')
            self.core.atomic(self.store.base/'bytes'/(key+'.before'),original_raw)
            row={'path':str(path),'parent':self.parent(path.parent),'before':before,'accepted':[],'restored':False}
            state['managed'][key]=row;self.store.save(state)
        require(row['path']==str(path) and row.get('restored') is False,'control_path_or_phase_drift')
        allowed=[row['before']]+row['accepted']+([row['pending']['image']] if row.get('pending') else [])
        require(any(self.matches(path,v) for v in allowed),'control_live_drift')
        parked=self.store.base/'originals'/key
        if os.path.lexists(parked):self.original(key,row)
        with self.anchored(row) as (parent,originals):
            candidate='.ustar-runtime-'+state['generation']+'-'+uuid.uuid4().hex
            fd=os.open(candidate,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=parent)
            try:
                with os.fdopen(os.dup(fd),'wb') as out:out.write(raw);out.flush();os.fsync(out.fileno())
                meta=row['before']['metadata'];os.fchown(fd,meta['uid'],meta['gid']);os.fchmod(fd,meta['mode']);os.fsync(fd)
                image={'metadata':self.core.description(fd),'sha256':sha(raw)}
            finally:os.close(fd)
            row['pending']={'candidate':candidate,'image':image};self.store.save(state)
            if not os.path.lexists(parked):
                os.rename(path.name,key,src_dir_fd=parent,dst_dir_fd=originals)
                os.chown(key,0,0,dir_fd=originals,follow_symlinks=False)
                os.chmod(key,0o600,dir_fd=originals,follow_symlinks=False)
                os.fsync(originals);os.fsync(parent)
            self.original(key,row)
            os.replace(candidate,path.name,src_dir_fd=parent,dst_dir_fd=parent);os.fsync(parent)
            require(self.parent(path.parent)==row['parent'] and self.matches(path,image),'control_install_readback')
            row['accepted'].append(image);row.pop('pending',None);self.store.save(state)
        return image

    def restore(self):
        state=self.store.load();require(state is not None,'runtime_state_missing')
        for key,row in reversed(list(state['managed'].items())):
            if row.get('restored'):continue
            path=Path(row['path']);parked=self.store.base/'originals'/key
            with self.anchored(row) as (parent,originals):
                if not os.path.lexists(parked):
                    require(self.matches(path,row['before']),'control_original_missing')
                else:
                    self.original(key,row)
                    if os.path.lexists(path):
                        accepted=row['accepted']+([row['pending']['image']] if row.get('pending') else [])
                        require(any(self.matches(path,v) for v in accepted),'control_live_drift')
                        target=self.store.base/'retained'/(key+'-'+uuid.uuid4().hex)
                        require(self.parent(path.parent)==row['parent'],'control_parent_changed')
                        with self.core.opened(target.parent,os.O_RDONLY|os.O_DIRECTORY) as retained:
                            os.rename(path.name,target.name,src_dir_fd=parent,dst_dir_fd=retained)
                            os.chown(target.name,0,0,dir_fd=retained,follow_symlinks=False)
                            os.chmod(target.name,0o600,dir_fd=retained,follow_symlinks=False)
                            os.fsync(retained)
                    old=row['before']['metadata']
                    os.chown(key,old['uid'],old['gid'],dir_fd=originals,follow_symlinks=False)
                    os.chmod(key,old['mode'],dir_fd=originals,follow_symlinks=False)
                    os.rename(key,path.name,src_dir_fd=originals,dst_dir_fd=parent)
                    os.fsync(originals);os.fsync(parent)
                    require(self.matches(path,row['before']),'control_recovery_readback')
                row['restored']=True;row.pop('pending',None);self.store.save(state)


def compose_bytes(model):
    def escaped(value):
        if isinstance(value,str):return value.replace('$','$$')
        if isinstance(value,list):return [escaped(v) for v in value]
        if isinstance(value,dict):return {k:escaped(v) for k,v in value.items()}
        return value
    return (json.dumps(escaped(model),sort_keys=True,ensure_ascii=False,indent=2)+'\n').encode('utf-8')

import http.client
import socket
from urllib.parse import quote, urlencode

class _UnixHTTP(http.client.HTTPConnection):
    def __init__(self,path,timeout):super().__init__('localhost',timeout=timeout);self.path=path
    def connect(self):
        self.sock=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout);self.sock.connect(str(self.path))

class DockerAPI:
    def __init__(self,path=Path('/var/run/docker.sock')):self.path=Path(path);self.version=None

    def _http(self,method,path,data=None,*,missing=False,timeout=45):
        connection=_UnixHTTP(self.path,timeout)
        raw=None if data is None else json.dumps(data,separators=(',',':')).encode()
        try:
            connection.request(method,path,body=raw,headers={'Content-Type':'application/json'})
            response=connection.getresponse();body=response.read(32*1024**2+1)
            require(len(body)<=32*1024**2,'docker_api_response_size')
            if missing and response.status==404:return None
            require(response.status in (200,201,204,304),'docker_api_http_error')
            if not body:return {}
            try:return json.loads(body)
            except ValueError:raise Refusal('docker_api_response_shape') from None
        except (OSError,http.client.HTTPException):raise Refusal('docker_api_unavailable') from None
        finally:connection.close()

    def call(self,method,path,data=None,*,missing=False,timeout=45):
        if self.version is None:
            value=self._http('GET','/version')
            version=value.get('ApiVersion','')
            require(re.fullmatch(r'1\.[0-9]{2}',version) and int(version.split('.')[1])>=44,'docker_api_version')
            self.version=version
        return self._http(method,'/v'+self.version+path,data,missing=missing,timeout=timeout)

    def inspect(self,name,*,missing=False):return self.call('GET','/containers/'+quote(name,safe='')+'/json',missing=missing)

    def image(self,name,*,missing=False):return self.call('GET','/images/'+quote(name,safe='')+'/json',missing=missing)

    def clone(self,before,image,service,project,generation,compose_labels):
        require(service in SERVICES and re.fullmatch('sha256:[a-f0-9]{64}',image or '')
            and image!=before.get('Image') and re.fullmatch('[a-f0-9]{32}',generation or '')
            and re.fullmatch('ustar_runtime_[a-f0-9]{8}',project or ''),'clone_identity_shape')
        cfg=copy.deepcopy(before['Config']);hc=copy.deepcopy(before['HostConfig'])
        require(not cfg.get('MacAddress'),'unsupported_runtime_mac_profile');cfg.pop('MacAddress',None)
        cfg['Image']=image;hc['LogConfig']={'Type':LOGGING['driver'],'Config':copy.deepcopy(LOGGING['options'])}
        hc['RestartPolicy']={'Name':'no','MaximumRetryCount':0}
        hc['PortBindings']={k:[{'HostIp':host,'HostPort':port} for host,port in values] for k,values in bindings(before).items()}
        desired=health(service)
        cfg['Healthcheck']={'Test':desired['test'],'Interval':30000000000,'Timeout':5000000000,
            'Retries':3,'StartPeriod':20000000000}
        labels=cfg.get('Labels') or {};labels.update(compose_labels)
        labels.update({'com.docker.compose.project':project,
            'com.docker.compose.service':'moodle' if service=='ustar_moodle' else 'postgres',
            'com.docker.compose.image':image,'org.ustar.runtime.generation':generation})
        cfg['Labels']=labels;endpoints={}
        for network,old in before['NetworkSettings']['Networks'].items():
            endpoint={k:copy.deepcopy(old[k]) for k in ('IPAMConfig','Links','DriverOpts','GwPriority') if old.get(k) is not None}
            ipam=endpoint.get('IPAMConfig') or {}
            if old.get('IPAddress'):ipam['IPv4Address']=old['IPAddress']
            if old.get('GlobalIPv6Address'):ipam['IPv6Address']=old['GlobalIPv6Address']
            if ipam:endpoint['IPAMConfig']=ipam
            aliases=set(old.get('Aliases') or [])-{before['Id'],before['Id'][:12]}
            aliases|={before['Name'].removeprefix('/'),'moodle' if service=='ustar_moodle' else 'postgres'}
            endpoint['Aliases']=sorted(aliases);endpoints[network]=endpoint
        cfg['HostConfig']=hc;cfg['NetworkingConfig']={'EndpointsConfig':endpoints}
        response=self.call('POST','/containers/create?'+urlencode({'name':before['Name'].removeprefix('/')}),cfg,timeout=120)
        require(isinstance(response,dict) and re.fullmatch('[a-f0-9]{64}',response.get('Id','')),'docker_create_identity')
        return response['Id']


TRANSITION=('writers_quiesced','backup_complete','old_stopped','images_captured',
    'archive_verified','retained','compose_written','created','new_running',
    'consumers_written','web_verified','released')

class Transaction:
    """Persist each external-operation intent before calling the idempotent host."""
    def __init__(self,store,host,operator,*,generation=None,progress=None):
        self.store,self.host,self.operator=store,host,operator
        self.generation=generation or uuid.uuid4().hex
        self.progress=progress or (lambda value:None)

    def apply(self):
        state=self.store.load()
        if state is not None:
            require(state['phase']=='active' and not state.get('intent'),'runtime_recovery_required')
            return self.host.verify(state)
        self.store.create(self.generation,self.operator,self.host.prepare())
        try:
            for phase in TRANSITION:
                self.store.step(self.store.load()['phase'],intent=phase)
                updates=self.host.phase(phase,self.store.load()) or {}
                require(isinstance(updates,dict) and not set(updates)&{
                    'schema','kind','generation','operator_sha256','before','managed','phase','intent'},
                    'invalid_host_journal_update')
                self.store.step(phase,intent=None,**updates)
                self.progress({'phase':phase})
            self.store.step('active',intent=None)
            return self.host.verify(self.store.load())
        except BaseException:
            # A kill after the mutation but before its response remains recoverable:
            # host recovery resolves generation labels and predecessor identities.
            self.recover()
            raise

    def recover(self):
        state=self.store.load();require(state is not None,'runtime_state_missing')
        if state['phase']=='recovered':return {'phase':'predecessor_restored','business_rows_restored':False}
        self.store.step('recovering',recover_from=state['phase'])
        try:
            updates=self.host.recover(self.store.load()) or {}
            require(isinstance(updates,dict) and not set(updates)&{
                'schema','kind','generation','operator_sha256','before','managed','phase','intent'},
                'invalid_host_recovery_update')
            self.store.step('recovered',intent=None,**updates)
        except BaseException:
            self.store.step('recovery_required')
            raise
        return {'phase':'predecessor_restored','business_rows_restored':False}


def main(argv=None):
    import sys
    args=sys.argv[1:] if argv is None else argv
    if not args:
        print(json.dumps({'phase':'PLAN_ONLY','configuration_changes':False,'provider_called':False}));return 0
    print(json.dumps({'phase':'refused','reason':'runtime_transition_not_qualified',
        'configuration_changes':False,'provider_called':False}));return 2

if __name__=='__main__':
    import sys
    sys.exit(main())
