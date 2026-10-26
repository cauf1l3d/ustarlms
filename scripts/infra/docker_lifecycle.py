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

def patch_producer_binding(raw,before,after):
    require(all(isinstance(v,str) and re.fullmatch('[a-f0-9]{64}',v) for v in (before,after))
        and before!=after and isinstance(raw,bytes) and len(raw)<=1024**2,'scheduler_binding_shape')
    try:tree=ast.parse(raw)
    except (SyntaxError,UnicodeError):raise Refusal('scheduler_source_syntax') from None
    nodes=[n for n in tree.body if isinstance(n,ast.Assign)
        and any(isinstance(t,ast.Name) and t.id=='PRODUCER_SHA' for t in n.targets)]
    require(len(nodes)==1 and len(nodes[0].targets)==1 and isinstance(nodes[0].value,ast.Constant)
        and nodes[0].value.value==before,'scheduler_producer_pin_drift')
    value=nodes[0].value;lines=raw.splitlines(keepends=True)
    first=sum(map(len,lines[:value.lineno-1]))+value.col_offset
    last=sum(map(len,lines[:value.end_lineno-1]))+value.end_col_offset
    result=raw[:first]+repr(after).encode('ascii')+raw[last:]
    compile(result,'<verified-runtime-scheduler>','exec')
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

def verify_successor(before,after,children,project,generation,*,staged=False):
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
    if staged:
        require(after['HostConfig'].get('RestartPolicy')=={'Name':'no','MaximumRetryCount':0},'successor_staging_policy')
    ignored={'LogConfig','Binds','Mounts','PortBindings'}|({'RestartPolicy'} if staged else set())
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
            self.core.atomic(self.store.base/'bytes'/(key+'.after'),raw)
            row['accepted'].append(image);row.pop('pending',None);self.store.save(state)
        return image

    def restore(self):
        state=self.store.load();require(state is not None,'runtime_state_missing')
        for key,row in reversed(list(state['managed'].items())):
            path=Path(row['path']);parked=self.store.base/'originals'/key
            with self.anchored(row) as (parent,originals):
                self.finish_retentions(state,row,parent)
                if row.get('restored'):continue
                if not os.path.lexists(parked):
                    require(self.matches(path,row['before']),'control_original_missing')
                else:
                    self.original(key,row)
                    if os.path.lexists(path):
                        accepted=row['accepted']+([row['pending']['image']] if row.get('pending') else [])
                        image=next((v for v in accepted if self.matches(path,v)),None)
                        require(image is not None,'control_live_drift')
                        require(self.parent(path.parent)==row['parent'],'control_parent_changed')
                        row.setdefault('retentions',[]).append({'name':key+'-'+uuid.uuid4().hex,'image':image})
                        self.store.save(state);self.finish_retentions(state,row,parent)
                    old=row['before']['metadata']
                    os.chown(key,old['uid'],old['gid'],dir_fd=originals,follow_symlinks=False)
                    os.chmod(key,old['mode'],dir_fd=originals,follow_symlinks=False)
                    os.rename(key,path.name,src_dir_fd=originals,dst_dir_fd=parent)
                    os.fsync(originals);os.fsync(parent)
                    require(self.matches(path,row['before']),'control_recovery_readback')
                row['restored']=True;row.pop('pending',None);self.store.save(state)

    def finish_retentions(self,state,row,parent):
        path=Path(row['path'])
        with self.core.opened(self.store.base/'retained',os.O_RDONLY|os.O_DIRECTORY) as retained:
            for item in row.get('retentions',[]):
                require(re.fullmatch('[a-z][a-z0-9_]{0,40}-[a-f0-9]{32}',item['name']),
                    'retention_record_shape')
                target=self.store.base/'retained'/item['name'];expected=item['image']
                if not os.path.lexists(target):
                    require(self.matches(path,expected) and self.parent(path.parent)==row['parent'],
                        'retention_source_drift')
                    os.rename(path.name,target.name,src_dir_fd=parent,dst_dir_fd=retained)
                image,_=self.image(target);old=expected['metadata'];now=image['metadata']
                require(image['sha256']==expected['sha256'] and all(now[k]==old[k]
                    for k in ('device','inode','bytes','mtime_ns'))
                    and now['uid'] in (0,old['uid']) and now['gid'] in (0,old['gid'])
                    and now['mode'] in (0o600,old['mode']),'retained_control_drift')
                os.chown(target.name,0,0,dir_fd=retained,follow_symlinks=False)
                os.chmod(target.name,0o600,dir_fd=retained,follow_symlinks=False);os.fsync(retained)


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
            if response.status not in (200,201,204,304):
                if b'user configured subnets' in body:
                    raise Refusal('docker_network_explicit_subnet_required')
                raise Refusal('docker_api_http_'+str(response.status))
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

    def snapshot(self,before,service,generation):
        now=self.inspect(before['Id'])
        require(now.get('Id')==before['Id'] and now.get('Image')==before['Image']
            and now.get('State',{}).get('Running') is False,'snapshot_source_not_stopped')
        require(process(now['Config'])==process(before['Config']),'snapshot_source_process_drift')
        tag='ustar-runtime-snapshot:'+generation+'-'+service
        child=self.image(tag,missing=True)
        if child is None:
            config=copy.deepcopy(before['Config']);labels=config.get('Labels') or {}
            labels.update({SNAPSHOT_PREFIX+k:v for k,v in {'generation':generation,
                'source-container':before['Id'],'source-image':before['Image'],'service':service}.items()})
            config['Labels']=labels
            query=urlencode({'container':before['Id'],'repo':'ustar-runtime-snapshot',
                'tag':generation+'-'+service,'pause':'false'})
            response=self.call('POST','/commit?'+query,config,timeout=240)
            require(re.fullmatch('sha256:[a-f0-9]{64}',response.get('Id','')),'snapshot_response_identity')
            child=self.image(response['Id'])
        return snapshot_lineage(self.image(before['Image']),child,before,generation,service)

    def policy(self,identity,policy):
        self.call('POST','/containers/'+quote(identity,safe='')+'/update',{'RestartPolicy':policy})

    def stop(self,identity):
        import signal
        row=self.inspect(identity)
        configured=row.get('Config',{}).get('StopSignal') or 'SIGTERM'
        require(isinstance(configured,str),'runtime_stop_signal_shape')
        number=int(configured) if configured.isdigit() else int(getattr(signal,configured,0))
        require(1<=number<=64 and number!=int(signal.SIGKILL),'unsupported_runtime_stop_signal')
        if row['State']['Running']:self.call('POST','/containers/'+quote(identity,safe='')+'/stop?t=45',timeout=55)
        row=self.inspect(identity)
        require(not row['State']['Running'] and not row['State'].get('OOMKilled')
            and row['State'].get('ExitCode',0) in (0,128+number),'runtime_clean_stop_failed')

    def start(self,identity):
        if not self.inspect(identity)['State']['Running']:
            self.call('POST','/containers/'+quote(identity,safe='')+'/start',timeout=60)

    def rename(self,identity,name):
        if self.inspect(identity)['Name']!='/'+name:
            require(self.inspect(name,missing=True) is None,'runtime_name_occupied')
            self.call('POST','/containers/'+quote(identity,safe='')+'/rename?'+urlencode({'name':name}))

    def disconnect(self,identity):
        for network in list(self.inspect(identity)['NetworkSettings']['Networks']):
            self.call('POST','/networks/'+quote(network,safe='')+'/disconnect',{'Container':identity,'Force':False})

    def reconnect(self,before):
        for network,old in before['NetworkSettings']['Networks'].items():
            current=self.inspect(before['Id'])['NetworkSettings']['Networks']
            if network in current:
                require(current[network].get('NetworkID')==old.get('NetworkID'),'predecessor_network_drift')
                continue
            config={k:copy.deepcopy(old[k]) for k in ('Links','DriverOpts','GwPriority') if old.get(k) is not None}
            config['Aliases']=[v for v in old.get('Aliases') or [] if v not in (before['Id'],before['Id'][:12])]
            ipam=copy.deepcopy(old.get('IPAMConfig') or {})
            if old.get('IPAddress'):ipam['IPv4Address']=old['IPAddress']
            if old.get('GlobalIPv6Address'):ipam['IPv6Address']=old['GlobalIPv6Address']
            if ipam:config['IPAMConfig']=ipam
            self.call('POST','/networks/'+quote(network,safe='')+'/connect',{'Container':before['Id'],'EndpointConfig':config})

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
            'com.docker.compose.image':image,'org.ustar.runtime.generation':generation,
            'org.ustar.runtime.source-container':before['Id']})
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


def cron_binding(before_raw,after_raw,installation,before,after):
    require(isinstance(installation,dict) and installation.get('schema')==1
        and installation.get('script_sha256')==sha(before_raw)
        and installation.get('containers')==before,'cron_installation_identity_drift')
    require(set(before)==set(after)==set(SERVICES),'cron_container_inventory')
    for identities in (before,after):
        require(all(isinstance(v,dict) and set(v)=={'id','image'}
            and re.fullmatch('[a-f0-9]{64}',v.get('id',''))
            and re.fullmatch('sha256:[a-f0-9]{64}',v.get('image','')) for v in identities.values()),
            'cron_identity_shape')
    require(after_raw==patch_images(before_raw,{n:v['image'] for n,v in before.items()},
        {n:v['image'] for n,v in after.items()}),'cron_source_transition_drift')
    return {**copy.deepcopy(installation),'script_sha256':sha(after_raw),'containers':copy.deepcopy(after)}


import subprocess

def stream_digest(source,*,target=None,expected=None,limit=12*1024**3):
    total=0;digest=hashlib.sha256()
    while chunk:=source.read(1024**2):
        total+=len(chunk);require(total<=limit,'runtime_archive_stream_limit')
        digest.update(chunk)
        if target is not None:target.write(chunk)
    result={'bytes':total,'sha256':digest.hexdigest()}
    require(expected is None or result==expected,'runtime_archive_stream_readback')
    return result

class RuntimeArchive:
    """Two authenticated encrypted streams; images are never staged as plaintext."""
    def __init__(self,store,core,target,recipients,key,save_command,*,anchor=None,
                 limit=8*1024**3,reserve=8*1024**3):
        self.store,self.core,self.target=store,core,Path(target)
        self.recipients,self.key=Path(recipients),Path(key)
        self.save_command,self.anchor=save_command,anchor
        self.limit,self.reserve=limit,reserve

    @contextmanager
    def directory(self):
        if self.anchor is not None:
            with self.anchor() as fd:yield fd
        else:
            self.core.private_dir(self.target)
            with self.core.opened(self.target,os.O_RDONLY|os.O_DIRECTORY) as fd:yield fd

    def decrypt(self,fd,verify):
        os.lseek(fd,0,os.SEEK_SET)
        process=subprocess.Popen(['/usr/bin/age','--decrypt','--identity',str(self.key)],
            stdin=fd,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,start_new_session=True,
            env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
        try:
            result=verify(process.stdout)
            trailing=0
            while chunk:=process.stdout.read(4096):
                trailing+=len(chunk);require(trailing<65536,'runtime_archive_trailing_bytes')
            require(process.wait(timeout=30)==0,'runtime_archive_authentication_failed')
            return result
        except (OSError,EOFError,subprocess.SubprocessError):
            raise Refusal('runtime_archive_readback_failed') from None
        finally:
            self.core.reap(process);process.stdout.close()

    def verify_image(self,path,expected):
        with self.core.opened(path) as fd:
            self.core.description(fd)
            return self.decrypt(fd,lambda stream:stream_digest(stream,expected=expected,limit=self.limit))

    def encrypt(self,directory,name,writer,verify):
        fd=os.open(name,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=directory)
        try:
            process=subprocess.Popen(['/usr/bin/age','--encrypt','--recipients-file',str(self.recipients)],
                stdin=subprocess.PIPE,stdout=fd,stderr=subprocess.DEVNULL,start_new_session=True,
                env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
            try:
                result=writer(process.stdin);process.stdin.close()
                require(process.wait(timeout=90)==0,'runtime_archive_encryption_failed');os.fsync(fd)
            finally:
                self.core.reap(process)
                if not process.stdin.closed:process.stdin.close()
            self.decrypt(fd,lambda stream:verify(stream,result))
            os.lseek(fd,0,os.SEEK_SET)
            with os.fdopen(os.dup(fd),'rb') as stream:cipher=stream_digest(stream,limit=self.limit)
            require(os.fstat(fd).st_nlink==1,'runtime_archive_link_drift')
            os.fsync(directory)
            return result,{'name':name,**cipher}
        finally:os.close(fd)

    def write(self,state):
        images(state['images']);generation=state['generation']
        require(re.fullmatch('[a-f0-9]{32}',generation or ''),'runtime_archive_generation')
        self.core.read_private(self.key,8192);self.core.read_private(self.recipients,8192)
        # Private controls contain exact predecessor process/Env and original file
        # before-images. No business volume, old physical pair or recovery key.
        payload={}
        raw=(json.dumps(state,sort_keys=True)+'\n').encode()
        operator=self.core.read_private(self.store.base/'operator.py',4*1024**2)
        for name,value in {'state.json':raw,'operator.py':operator}.items():
            payload[name]={'raw':value,'sha256':sha(value),'bytes':len(value),
                'uid':0,'gid':0,'mode':0o600,'mtime_ns':0}
        def save(stream):
            process=subprocess.Popen(self.save_command(state['images']),stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,start_new_session=True,
                env={'PATH':'/usr/bin:/bin','LANG':'C','LC_ALL':'C'})
            try:
                result=stream_digest(process.stdout,target=stream,limit=self.limit)
                require(result['bytes']>0 and process.wait(timeout=45)==0,'runtime_image_save_failed')
                return result
            finally:self.core.reap(process);process.stdout.close()
        prefix='ustar-runtime-'+generation
        with self.directory() as directory:
            used=0
            for name in os.listdir(directory):
                row=os.stat(name,dir_fd=directory,follow_symlinks=False)
                require(stat.S_ISREG(row.st_mode) and row.st_uid==0 and stat.S_IMODE(row.st_mode)==0o600,
                    'unsafe_runtime_archive_entry');used+=row.st_size
            free=os.fstatvfs(directory)
            budget=state.get('before',{}).get('archive_budget_bytes',self.limit)
            require(type(budget) is int and 0<budget<=self.limit and used+budget<=self.limit
                and free.f_bavail*free.f_frsize>=self.reserve+budget,
                'runtime_archive_capacity_no_deletion')
            digest,image=self.encrypt(directory,prefix+'.images.docker.tar.age',save,
                lambda stream,result:stream_digest(stream,expected=result,limit=self.limit))
            _,controls=self.encrypt(directory,prefix+'.controls.tar.gz.age',
                lambda stream:self.core.write_tar(stream,payload),
                lambda stream,result:self.core.verify_tar(stream,payload))
        return {'kind':'ustar_runtime_recovery_pair_v1','generation':generation,
            'images':state['images'],'image_stream':digest,'files':{'images':image,'controls':controls},
            'readback_verified':True,'recovery_key_included':False,'business_volumes_included':False,
            'whole_host_backup':False,'archive_deletion':False}


class RuntimeHost:
    """Native mutation sequence; controls own fixed-profile validation and file IO."""
    def __init__(self,store,api,controls,archive):
        self.store,self.api,self.controls,self.archive=store,api,controls,archive

    @staticmethod
    def project(state):return 'ustar_runtime_'+state['generation'][:8]

    @staticmethod
    def retained(state,service,*,successor=False):
        return service+'-retained-'+('new-' if successor else 'old-')+state['generation']

    def prepare(self):
        rows={n:self.api.inspect(n) for n in SERVICES}
        return {'containers':rows,**self.controls.prepare(rows)}

    def save(self,**fields):
        state=self.store.load();state.update(fields);self.store.save(state)

    def phase(self,phase,state):
        rows=state['before']['containers'];generation=state['generation'];project=self.project(state)
        if phase=='writers_quiesced':self.controls.quiesce(state)
        elif phase=='backup_complete':
            result=self.controls.backup(state);self.controls.cold()
            return {'logical_backup':result}
        elif phase=='old_stopped':
            for service in SERVICES:
                self.api.policy(rows[service]['Id'],{'Name':'no','MaximumRetryCount':0})
                self.api.stop(rows[service]['Id'])
                if service=='ustar_moodle':self.controls.no_clients(state)
        elif phase=='images_captured':
            snapshots={}
            for service in SERVICES:
                snapshots[service]=self.api.snapshot(rows[service],service,generation)
                self.save(images=snapshots)
            return {'images':images(snapshots)}
        elif phase=='archive_verified':return {'runtime_archive':self.archive.write(state)}
        elif phase=='retained':
            for service in SERVICES:
                identity=rows[service]['Id'];self.api.rename(identity,self.retained(state,service));self.api.disconnect(identity)
        elif phase=='compose_written':
            model=compose_model(rows,state['images'],project,generation,state['before']['source_model'])
            return {'compose_labels':self.controls.compose(state,model,compose_bytes(model))}
        elif phase=='created':
            identities={}
            for service in ('ustar_postgres','ustar_moodle'):
                require(self.api.inspect(service,missing=True) is None,'runtime_name_occupied')
                identity=self.api.clone(rows[service],state['images'][service],service,project,generation,state['compose_labels'][service])
                identities[service]=identity;self.save(new_containers=identities)
                verify_successor(rows[service],self.api.inspect(identity),state['images'],project,generation,staged=True)
            return {'new_containers':identities}
        elif phase=='new_running':
            for service in ('ustar_postgres','ustar_moodle'):
                identity=state['new_containers'][service];self.api.start(identity);self.controls.ready(service,identity)
                verify_successor(rows[service],self.api.inspect(identity),state['images'],project,generation,staged=True)
        elif phase=='consumers_written':self.controls.consumers(state)
        elif phase=='web_verified':self.controls.verify(state)
        elif phase=='released':
            for service in ('ustar_postgres','ustar_moodle'):
                self.api.policy(state['new_containers'][service],rows[service]['HostConfig']['RestartPolicy'])
            self.controls.resume(state)
        else:raise Refusal('unknown_runtime_stage')

    def owned_successor(self,state,service,current):
        before=state['before']['containers'][service];labels=current.get('Config',{}).get('Labels') or {}
        require(current.get('Image')==state.get('images',{}).get(service)
            and labels.get('org.ustar.runtime.generation')==state['generation']
            and labels.get('com.docker.compose.project')==self.project(state)
            and labels.get('com.docker.compose.service')==('moodle' if service=='ustar_moodle' else 'postgres')
            and labels.get('org.ustar.runtime.source-container')==before['Id'],'unowned_current_runtime')
        if service in state.get('new_containers',{}):
            require(current['Id']==state['new_containers'][service],'successor_container_id_drift')

    def recover(self,state):
        rows=state['before']['containers'];current={}
        # Resolve all public names and all predecessor objects before any stop/file mutation.
        for service,before in rows.items():
            original=self.api.inspect(before['Id'])
            require(original.get('Id')==before['Id'] and original.get('Image')==before['Image']
                and original.get('Name') in (before['Name'],'/'+self.retained(state,service)),'predecessor_object_drift')
            require(process(original['Config'])==process(before['Config']),'predecessor_process_drift')
            require(original['Config'].get('Labels')==before['Config'].get('Labels'),'predecessor_labels_drift')
            require(sorted(original['Mounts'],key=lambda m:json.dumps(m,sort_keys=True))
                ==sorted(before['Mounts'],key=lambda m:json.dumps(m,sort_keys=True)),
                'predecessor_mounts_drift')
            old_host={k:v for k,v in before['HostConfig'].items() if k!='RestartPolicy'}
            live_host={k:v for k,v in original['HostConfig'].items() if k!='RestartPolicy'}
            require(live_host==old_host and original['HostConfig'].get('RestartPolicy') in (
                before['HostConfig']['RestartPolicy'],{'Name':'no','MaximumRetryCount':0}),
                'predecessor_host_configuration_drift')
            live=self.api.inspect(service,missing=True)
            if live is not None and live['Id']!=before['Id']:
                self.owned_successor(state,service,live);current[service]=live
            parked=self.api.inspect(state.get('new_containers',{}).get(service)
                or self.retained(state,service,successor=True),missing=True)
            if parked is not None:
                self.owned_successor(state,service,parked)
                require(parked['Name'] in (before['Name'],'/'+self.retained(state,service,successor=True)),
                    'successor_name_drift')
                require(service not in current or current[service]['Id']==parked['Id'],'duplicate_successor_runtime')
                current[service]=parked
        if current:self.save(new_containers={**state.get('new_containers',{}),**{n:v['Id'] for n,v in current.items()}})
        if hasattr(self.controls,'recover_backup'):self.controls.recover_backup(state)
        self.controls.cold();self.controls.quiesce(state,recovery=True)
        for service,live in current.items():
            self.api.policy(live['Id'],{'Name':'no','MaximumRetryCount':0});self.api.stop(live['Id'])
            self.api.rename(live['Id'],self.retained(state,service,successor=True));self.api.disconnect(live['Id'])
        self.controls.restore_files(state)
        for service,before in rows.items():
            self.api.rename(before['Id'],service);self.api.reconnect(before)
        for service in ('ustar_postgres','ustar_moodle'):
            before=rows[service];self.api.start(before['Id']);self.controls.ready(service,before['Id'])
            self.api.policy(before['Id'],before['HostConfig']['RestartPolicy'])
        self.controls.verify(state,recovery=True);self.controls.resume(state,recovery=True)
        return {'old_containers_restored':True}

    def verify(self,state):
        for service,before in state['before']['containers'].items():
            current=self.api.inspect(service);self.owned_successor(state,service,current)
            verify_successor(before,current,state['images'],self.project(state),state['generation'])
            require(current['State']['Running'] and current['State'].get('Health',{}).get('Status')=='healthy',
                'successor_not_healthy')
            old=self.api.inspect(before['Id'])
            require(not old['State']['Running'] and old['HostConfig']['RestartPolicy']['Name']=='no'
                and old['Name']=='/'+self.retained(state,service),'retained_predecessor_drift')
        self.controls.verify(state)
        return {'phase':'runtime_active','generation':state['generation'],
            'containers':{n:{'id':state['new_containers'][n],'image':state['images'][n]} for n in SERVICES},
            'logging':LOGGING,'predecessors_retained':True,'business_rows_restored':False,'provider_called':False}


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


def main(argv=None,*,operator_path=None):
    import sys
    args=sys.argv[1:] if argv is None else argv
    if not args:
        print(json.dumps({'phase':'PLAN_ONLY','configuration_changes':False,'provider_called':False}));return 0
    try:import docker_lifecycle_host as host
    except ImportError:
        print(json.dumps({'phase':'refused','reason':'operator_bundle_required',
            'business_rows_restored':False,'provider_called':False}));return 2
    try:
        allowed={'--check','--apply','--recover','--status','--acknowledge-outage'}
        require(len(args)==len(set(args)) and set(args)<=allowed,'unsupported_runtime_arguments')
        actions=[value[2:] for value in args if value!='--acknowledge-outage']
        require(len(actions)==1,'single_runtime_action_required')
        host.emit(host.entry(actions[0],operator_path or Path(__file__),acknowledge='--acknowledge-outage' in args))
        return 0
    except BaseException as error:
        host.emit({'phase':'refused','reason':host.public_error(error),
            'business_rows_restored':False,'provider_called':False})
        return 2

if __name__=='__main__':
    import sys
    sys.exit(main())
