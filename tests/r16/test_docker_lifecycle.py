"""Runtime handoff must preserve actual process/volume state and typed helper pins."""
import ast
import copy
import importlib.util
import json
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT/'scripts/infra/docker_lifecycle.py'
PARENTS={'ustar_moodle':'sha256:'+'a'*64,'ustar_postgres':'sha256:'+'b'*64}
CHILDREN={'ustar_moodle':'sha256:'+'c'*64,'ustar_postgres':'sha256:'+'d'*64}
GEN='e'*32
PROJECT='ustar_runtime_'+GEN[:8]


def module(case):
    case.assertTrue(SOURCE.is_file(),'Docker lifecycle preservation contract not implemented')
    spec=importlib.util.spec_from_file_location('docker_lifecycle_fixture',SOURCE)
    result=importlib.util.module_from_spec(spec);spec.loader.exec_module(result)
    return result


def row(name):
    moodle=name=='ustar_moodle';service='moodle' if moodle else 'postgres'
    return {'Name':'/'+name,'Id':('1' if moodle else '2')*64,'Image':PARENTS[name],
      'Path':'/entrypoint','Args':['run'],
      'Config':{'Image':'old-alias:'+service,'Hostname':service+'-original',
        'Entrypoint':['/entrypoint'],'Cmd':['run'],'User':'','WorkingDir':'/work',
        'Env':['PATH=/usr/bin:/bin','PG_SECRET=PRIVATE_SENTINEL'],
        'Labels':{'com.docker.compose.project':'stack','application':'fixture'},
        'ExposedPorts':{'80/tcp':{}} if moodle else {'5432/tcp':{}},
        'Volumes':{},'StopSignal':'SIGTERM','OpenStdin':False,'Tty':False,
        'Healthcheck':None},
      'HostConfig':{'RestartPolicy':{'Name':'unless-stopped','MaximumRetryCount':0},
        'NetworkMode':'stack_default','LogConfig':{'Type':'json-file','Config':{}},
        'PortBindings':{'80/tcp':[{'HostIp':'127.0.0.1','HostPort':'8082'}]} if moodle else {},
        'ReadonlyRootfs':False,'Privileged':False,'Memory':0,'SecurityOpt':None,
        'Binds':['/fixture/'+service+':/data:rw'],'Mounts':None},
      'Mounts':[{'Type':'bind','Source':'/fixture/'+service,'Destination':'/data',
                 'Mode':'rw','RW':True,'Propagation':'rprivate'}],
      'NetworkSettings':{'Networks':{'stack_default':{'NetworkID':'f'*64,
        'Aliases':[name,service],'IPAMConfig':None,'IPAddress':'172.29.0.'+('2' if moodle else '3')}}}}


def snapshot(name):
    before=row(name)
    parent={'Id':PARENTS[name],'RootFS':{'Type':'layers','Layers':['sha256:'+'4'*64]}}
    config=copy.deepcopy(before['Config'])
    config['Labels'].update({'org.ustar.snapshot.generation':GEN,
        'org.ustar.snapshot.source-container':before['Id'],
        'org.ustar.snapshot.source-image':before['Image'],
        'org.ustar.snapshot.service':name})
    child={'Id':CHILDREN[name],'Config':config,
       'RootFS':{'Type':'layers','Layers':['sha256:'+'4'*64,'sha256:'+'5'*64]}}
    return parent,child,before


class Pins(unittest.TestCase):
    def test_scheduler_tracks_only_the_changed_producer_digest(self):
        m=module(self);self.assertTrue(hasattr(m,'patch_producer_binding'),'scheduled producer handoff missing')
        raw=(ROOT/'scripts/infra/hdd_backup_schedule.py').read_bytes()
        before='a604fbcd6a0733d29142bb3130dc4bea2e7da1797c6995699d9d5516f21b05c3';after='c'*64
        changed=m.patch_producer_binding(raw,before,after)
        self.assertEqual(changed,raw.replace(("'"+before+"'").encode(),("'"+after+"'").encode(),1))
        with self.assertRaises(m.Refusal):m.patch_producer_binding(raw,'d'*64,after)
        with self.assertRaises(m.Refusal):m.patch_producer_binding(raw+b'\nPRODUCER_SHA="duplicate"\n',before,after)

    def source(self):
        return ("# UTF-8: сохранённый оператор\nIMAGES = {\n"
            " 'ustar_moodle': '"+PARENTS['ustar_moodle']+"',\n"
            " 'ustar_postgres': '"+PARENTS['ustar_postgres']+"',\n"
            "}\nSECRET='PRIVATE_SENTINEL'\ndef identity(name): return IMAGES[name]\n").encode()

    def test_only_typed_image_literals_change_and_runtime_observes_new_pins(self):
        m=module(self);raw=self.source();after=m.patch_images(raw,PARENTS,CHILDREN)
        ns={};exec(after,ns)
        self.assertEqual(ns['identity']('ustar_moodle'),'sha256:'+'c'*64)
        self.assertEqual(ns['identity']('ustar_postgres'),'sha256:'+'d'*64)
        self.assertEqual(ns['SECRET'],'PRIVATE_SENTINEL')
        self.assertTrue(after.startswith('# UTF-8: сохранённый оператор\n'.encode()))
        self.assertEqual(after.split(b'\nSECRET=',1)[1],raw.split(b'\nSECRET=',1)[1])

    def test_duplicate_computed_or_unowned_image_map_is_refused(self):
        m=module(self)
        bad=[self.source().replace(b'IMAGES =',b'OTHER ='),
             self.source().replace(b"'ustar_postgres':",b"'ustar_moodle':"),
             self.source().replace(("'"+PARENTS['ustar_moodle']+"'").encode(),b'get_image()'),
             self.source()+b'\nIMAGES = {}\n']
        for raw in bad:
            with self.subTest(raw=raw[:20]),self.assertRaises(m.Refusal):
                m.patch_images(raw,PARENTS,CHILDREN)

    def test_malformed_successor_hash_refuses_without_echoing_input(self):
        m=module(self);wrong=dict(CHILDREN,ustar_moodle='PRIVATE_SENTINEL')
        with self.assertRaises(m.Refusal) as caught:m.patch_images(self.source(),PARENTS,wrong)
        self.assertNotIn('PRIVATE_SENTINEL',str(caught.exception))


class Snapshot(unittest.TestCase):
    def test_child_requires_original_layers_and_exact_process_environment(self):
        m=module(self);parent,child,container=snapshot('ustar_moodle')
        self.assertEqual(m.snapshot_lineage(parent,child,container,GEN,'ustar_moodle'),CHILDREN['ustar_moodle'])
        child['RootFS']['Layers'][0]='sha256:'+'6'*64
        with self.assertRaises(m.Refusal):m.snapshot_lineage(parent,child,container,GEN,'ustar_moodle')

    def test_changed_command_environment_or_snapshot_owner_refuses(self):
        m=module(self)
        for field,value in [('Env',['PG_SECRET=WRONG_PRIVATE_VALUE']),('Cmd',['different'])]:
            parent,child,container=snapshot('ustar_moodle');child['Config'][field]=value
            with self.assertRaises(m.Refusal) as caught:m.snapshot_lineage(parent,child,container,GEN,'ustar_moodle')
            self.assertNotIn('PRIVATE_VALUE',str(caught.exception))
        parent,child,container=snapshot('ustar_moodle')
        child['Config']['Labels']['org.ustar.snapshot.generation']='9'*32
        with self.assertRaises(m.Refusal):m.snapshot_lineage(parent,child,container,GEN,'ustar_moodle')


class Compose(unittest.TestCase):
    def test_successor_uses_live_env_exact_snapshot_and_existing_external_network(self):
        m=module(self);rows={n:row(n) for n in PARENTS}
        source={'services':{'moodle':{'build':{'context':'/old/build'},'image':'old:tag'},'postgres':{}}}
        result=m.compose_model(rows,CHILDREN,PROJECT,GEN,source)
        web=result['services']['moodle']
        self.assertNotIn('build',web)
        self.assertEqual(web['image'],'sha256:'+'c'*64)
        self.assertEqual(web['pull_policy'],'never')
        self.assertEqual(web['environment'],['PATH=/usr/bin:/bin','PG_SECRET=PRIVATE_SENTINEL'])
        self.assertEqual(web['container_name'],'ustar_moodle')
        self.assertEqual(web['logging']['options'],{'max-size':'20m','max-file':'3','compress':'true'})
        self.assertEqual(result['name'],PROJECT)
        self.assertEqual(list(result['networks'].values()),[{'external':True,'name':'stack_default'}])
        self.assertEqual(web['volumes'][0]['source'],'/fixture/moodle')
        self.assertFalse(web['volumes'][0]['bind']['create_host_path'])
        self.assertNotEqual(result['name'],'stack')
        self.assertEqual(source['services']['moodle']['image'],'old:tag')

    def successor(self):
        result=row('ustar_moodle');result['Id']='3'*64;result['Image']=CHILDREN['ustar_moodle']
        result['Config']['Image']=CHILDREN['ustar_moodle']
        result['Config']['Labels']['com.docker.compose.project']=PROJECT
        result['Config']['Labels']['org.ustar.runtime.generation']=GEN
        result['Config']['Healthcheck']={'Test':['CMD','php','-n','-r',"$s=@fsockopen('127.0.0.1',80,$e,$m,2);if($s){fclose($s);exit(0);}exit(1);"],
              'Interval':30000000000,'Timeout':5000000000,'Retries':3,'StartPeriod':20000000000}
        result['HostConfig']['LogConfig']={'Type':'json-file','Config':{'max-size':'20m','max-file':'3','compress':'true'}}
        return result

    def test_runtime_equivalence_accepts_only_planned_delta(self):
        m=module(self);after=self.successor()
        after['Config']['Env'].reverse()
        m.verify_successor(row('ustar_moodle'),after,CHILDREN,PROJECT,GEN)

    def test_changed_bind_command_or_memory_is_refused_before_start(self):
        m=module(self)
        cases=[('Mounts',None),('Cmd',None),('Memory',1)]
        for field,value in cases:
            after=self.successor()
            if field=='Mounts':after['Mounts'][0]['Source']='/wrong/live/data'
            elif field=='Cmd':after['Config']['Cmd']=['different']
            else:after['HostConfig'][field]=value
            with self.subTest(field=field),self.assertRaises(m.Refusal):
                m.verify_successor(row('ustar_moodle'),after,CHILDREN,PROJECT,GEN)

    def test_duplicate_environment_or_foreign_compose_generation_is_refused(self):
        m=module(self)
        for mutate in (lambda a:a['Config']['Env'].append('PG_SECRET=OTHER'),
                       lambda a:a['Config']['Labels'].update({'com.docker.compose.project':'stack'})):
            after=self.successor();mutate(after)
            with self.assertRaises(m.Refusal):m.verify_successor(row('ustar_moodle'),after,CHILDREN,PROJECT,GEN)

import os
import tempfile
from unittest.mock import patch
sys.path.insert(0,str(ROOT/'scripts/infra'))
import infra_one_pass as pinned_core

@unittest.skipUnless(os.geteuid()==0,'private root metadata fixture')
class Files(unittest.TestCase):
    def setUp(self):
        self.m=module(self)
        self.assertTrue(hasattr(self.m,'PrivateStore'),'private metadata storage not implemented')
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.source=self.root/'source'/'compose.yml'
        self.source.parent.mkdir();self.source.write_bytes(b'PRIVATE_ORIGINAL\n');self.source.chmod(0o600)
        self.base=self.root/'state';self.base.mkdir(mode=0o700)
        self.store=self.m.PrivateStore(self.base,pinned_core)
        self.store.create(GEN,b'fixture operator',{})
        self.files=self.m.ManagedFiles(self.store)

    def test_source_recovery_returns_original_inode_metadata_and_bytes(self):
        before=self.source.stat()
        self.files.replace('compose',self.source,b'PRIVATE_SUCCESSOR\n')
        self.assertNotEqual(self.source.stat().st_ino,before.st_ino)
        self.assertEqual(self.source.read_bytes(),b'PRIVATE_SUCCESSOR\n')
        self.files.restore()
        after=self.source.stat()
        self.assertEqual(after.st_ino,before.st_ino)
        self.assertEqual((after.st_uid,after.st_gid,after.st_mode,after.st_mtime_ns),
                         (before.st_uid,before.st_gid,before.st_mode,before.st_mtime_ns))
        self.assertEqual(self.source.read_bytes(),b'PRIVATE_ORIGINAL\n')

    def test_interrupted_atomic_install_is_recoverable_from_persisted_intent(self):
        actual=os.replace;source=self.source
        def install_then_interrupt(src,dst,*args,**kwargs):
            result=actual(src,dst,*args,**kwargs)
            if Path(dst)==source or kwargs.get('dst_dir_fd') is not None and str(dst)==source.name:raise OSError('injected interruption after actual install')
            return result
        with patch('os.replace',side_effect=install_then_interrupt):
            with self.assertRaises(OSError):self.files.replace('compose',self.source,b'SUCCESSOR\n')
        reloaded=self.m.PrivateStore(self.base,pinned_core)
        self.m.ManagedFiles(reloaded).restore()
        self.assertEqual(self.source.read_bytes(),b'PRIVATE_ORIGINAL\n')

    def test_unknown_live_bytes_are_preserved_and_refused(self):
        self.files.replace('compose',self.source,b'SUCCESSOR\n')
        self.source.write_bytes(b'UNRELATED_EDIT\n')
        with self.assertRaises(self.m.Refusal):self.files.restore()
        self.assertEqual(self.source.read_bytes(),b'UNRELATED_EDIT\n')
        self.assertEqual((self.base/'originals'/'compose').read_bytes(),b'PRIVATE_ORIGINAL\n')

    def test_repeated_managed_write_keeps_first_inode_for_recovery(self):
        first=self.source.stat().st_ino
        self.files.replace('compose',self.source,b'paused\n')
        self.files.replace('compose',self.source,b'active\n')
        self.assertEqual((self.base/'originals'/'compose').stat().st_ino,first)
        self.files.restore();self.assertEqual(self.source.stat().st_ino,first)

    def test_interrupted_retention_move_is_finished_with_private_metadata_on_retry(self):
        self.source.chmod(0o644);before=self.source.stat().st_ino
        self.files.replace('cron',self.source,b'SUCCESSOR\n');rename=os.rename
        def moved_then_interrupted(src,dst,*args,**kw):
            value=rename(src,dst,*args,**kw)
            if str(dst).startswith('cron-'):raise OSError('after retained move before metadata')
            return value
        with patch('os.rename',side_effect=moved_then_interrupted):
            with self.assertRaises(OSError):self.files.restore()
        self.m.ManagedFiles(self.m.PrivateStore(self.base,pinned_core)).restore()
        self.assertEqual(self.source.stat().st_ino,before)
        retained=list((self.base/'retained').iterdir());self.assertEqual(len(retained),1)
        self.assertEqual(retained[0].read_bytes(),b'SUCCESSOR\n')
        self.assertEqual((retained[0].stat().st_uid,retained[0].stat().st_gid,retained[0].stat().st_mode&0o777),(0,0,0o600))

    def test_parent_replacement_is_refused_without_overwriting_foreign_file(self):
        self.files.replace('compose',self.source,b'SUCCESSOR\n')
        self.source.parent.rename(self.root/'parked-parent')
        self.source.parent.mkdir();self.source.write_bytes(b'FOREIGN\n')
        with self.assertRaises(self.m.Refusal):self.files.restore()
        self.assertEqual(self.source.read_bytes(),b'FOREIGN\n')

    def test_status_read_does_not_create_an_absent_store(self):
        path=self.root/'absent-state'
        self.assertIsNone(self.m.PrivateStore(path,pinned_core).load())
        self.assertFalse(path.exists())

    def test_parent_race_cannot_move_a_foreign_live_source(self):
        self.files.replace('compose',self.source,b'SUCCESSOR\n')
        real=self.files.matches;source=self.source;changed=[False]
        def validated_then_parent_swapped(path,image):
            yes=real(path,image)
            if yes and Path(path)==source and not changed[0]:
                changed[0]=True
                source.parent.rename(self.root/'raced-parent')
                source.parent.mkdir();source.write_bytes(b'FOREIGN\n')
            return yes
        with patch.object(self.files,'matches',side_effect=validated_then_parent_swapped):
            with self.assertRaises(self.m.Refusal):self.files.restore()
        self.assertTrue(source.exists(),'a parent race must not move the foreign file')
        self.assertEqual(source.read_bytes(),b'FOREIGN\n')


class SourceEncoding(unittest.TestCase):
    def test_compose_source_protects_literal_dollars_from_host_interpolation(self):
        m=module(self);self.assertTrue(hasattr(m,'compose_bytes'),'safe Compose encoding missing')
        value={'environment':['PASSWORD=PRIVATE_$value'],
               'healthcheck':{'test':['CMD-SHELL','pg_isready -U "$POSTGRES_USER"']}}
        decoded=json.loads(m.compose_bytes(value))
        self.assertEqual(decoded['environment'],['PASSWORD=PRIVATE_$$value'])
        self.assertEqual(decoded['healthcheck']['test'],['CMD-SHELL','pg_isready -U "$$POSTGRES_USER"'])
        self.assertEqual(value['environment'],['PASSWORD=PRIVATE_$value'])

class ContractEdges(unittest.TestCase):
    def test_snapshot_accepts_image_config_that_omits_container_stdio_flags(self):
        m=module(self);parent,child,container=snapshot('ustar_moodle')
        child['Config'].pop('OpenStdin');child['Config'].pop('Tty')
        child['Config'].pop('Volumes')
        self.assertEqual(m.snapshot_lineage(parent,child,container,GEN,'ustar_moodle'),CHILDREN['ustar_moodle'])

    def test_malformed_layer_descriptor_is_refused(self):
        m=module(self);parent,child,container=snapshot('ustar_moodle')
        parent['RootFS']['Layers']=['not-a-layer'];child['RootFS']['Layers']=['not-a-layer','sha256:'+'5'*64]
        with self.assertRaises(m.Refusal):m.snapshot_lineage(parent,child,container,GEN,'ustar_moodle')

    def test_compose_preserves_empty_working_directory_and_existing_dependency(self):
        m=module(self);rows={n:row(n) for n in PARENTS};rows['ustar_postgres']['Config']['WorkingDir']=''
        old={'services':{'moodle':{'depends_on':{'postgres':{'condition':'service_started'}}},'postgres':{}}}
        result=m.compose_model(rows,CHILDREN,PROJECT,GEN,old)
        self.assertEqual(result['services']['postgres']['working_dir'],'')
        self.assertEqual(result['services']['moodle']['depends_on'],{'postgres':{'condition':'service_started'}})

    def test_runtime_equivalence_refuses_network_identity_alias_or_static_address_drift(self):
        m=module(self)
        for field,value in [('NetworkID','9'*64),('Aliases',['unrelated']),('IPAMConfig',{'IPv4Address':'172.29.0.8'})]:
            before=row('ustar_moodle');after=Compose().successor()
            after['NetworkSettings']['Networks']['stack_default'][field]=value
            with self.subTest(field=field),self.assertRaises(m.Refusal):
                m.verify_successor(before,after,CHILDREN,PROJECT,GEN)

    def test_current_endpoint_address_can_be_explicitly_reserved_for_the_successor(self):
        m=module(self);before=row('ustar_moodle');after=Compose().successor()
        after['NetworkSettings']['Networks']['stack_default']['IPAMConfig']={'IPv4Address':'172.29.0.2'}
        m.verify_successor(before,after,CHILDREN,PROJECT,GEN)
        model=m.compose_model({n:row(n) for n in PARENTS},CHILDREN,PROJECT,GEN,{'services':{}})
        self.assertEqual(model['services']['moodle']['networks']['runtime0']['ipv4_address'],'172.29.0.2')

import http.server
import socketserver
import threading
import urllib.parse

class DockerSocket(unittest.TestCase):
    def setUp(self):
        self.m=module(self)
        self.assertTrue(hasattr(self.m,'DockerAPI'),'local Docker API boundary missing')
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)/'docker.sock';self.requests=[];self.fail=False;self.created_id='3'*64
        owner=self
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def do_GET(self):self.respond()
            def do_POST(self):self.respond()
            def respond(self):
                data=self.rfile.read(int(self.headers.get('Content-Length','0')))
                body=json.loads(data) if data else None
                owner.requests.append((self.command,self.path,body))
                if owner.fail:status,value=400,{'message':'PRIVATE_SENTINEL: do not echo request data'}
                elif self.path=='/version':status,value=200,{'ApiVersion':'1.52'}
                elif '/containers/create?' in self.path:status,value=201,{'Id':owner.created_id,'Warnings':[]}
                elif '/containers/missing/json' in self.path:status,value=404,{'message':'PRIVATE_SENTINEL missing object'}
                elif self.path.endswith('/json'):status,value=200,row('ustar_moodle')
                else:status,value=204,None
                raw=b'' if value is None else json.dumps(value).encode()
                self.send_response(status);self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
        try:self.server=socketserver.UnixStreamServer(str(self.path),Handler)
        except PermissionError:
            if os.environ.get('USTAR_REQUIRE_INFRA_NATIVE')=='1':raise
            self.skipTest('Unix sockets are forbidden in this local executor; strict native CI required')
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
        self.addCleanup(self.server.server_close);self.addCleanup(self.thread.join,2);self.addCleanup(self.server.shutdown)
        self.api=self.m.DockerAPI(self.path)

    def test_exact_clone_is_created_stopped_with_original_environment_and_reserved_address(self):
        before=row('ustar_moodle')
        created=self.api.clone(before,CHILDREN['ustar_moodle'],'ustar_moodle',PROJECT,GEN,{'com.docker.compose.config-hash':'7'*64})
        self.assertEqual(created,'3'*64)
        method,path,body=next(v for v in self.requests if '/containers/create?' in v[1])
        self.assertEqual(method,'POST')
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(path).query),{'name':['ustar_moodle']})
        self.assertEqual(body['Image'],'sha256:'+'c'*64)
        self.assertEqual(body['Env'],['PATH=/usr/bin:/bin','PG_SECRET=PRIVATE_SENTINEL'])
        self.assertEqual(body['Cmd'],['run'])
        self.assertEqual(body['HostConfig']['RestartPolicy'],{'Name':'no','MaximumRetryCount':0})
        self.assertEqual(body['HostConfig']['LogConfig'],{'Type':'json-file','Config':{'max-size':'20m','max-file':'3','compress':'true'}})
        self.assertEqual(body['HostConfig']['Binds'],['/fixture/moodle:/data:rw'])
        self.assertEqual(body['NetworkingConfig']['EndpointsConfig']['stack_default']['IPAMConfig'],{'IPv4Address':'172.29.0.2'})
        self.assertEqual(body['Labels']['com.docker.compose.project'],PROJECT)
        self.assertEqual(body['Labels']['org.ustar.runtime.generation'],GEN)
        self.assertFalse(any('/start' in v[1] for v in self.requests))

    def test_http_failure_does_not_expose_docker_error_or_environment(self):
        self.fail=True
        with self.assertRaises(self.m.Refusal) as caught:self.api.inspect('ustar_moodle')
        self.assertNotIn('PRIVATE_SENTINEL',str(caught.exception))
        self.assertRegex(str(caught.exception),'^[a-z_]+$')

    def test_absent_object_is_accepted_only_for_explicit_missing_lookup(self):
        self.assertIsNone(self.api.inspect('missing',missing=True))
        with self.assertRaises(self.m.Refusal):self.api.inspect('missing')

    def test_invalid_create_identity_is_refused_without_echoing_it(self):
        self.created_id='PRIVATE_SENTINEL'
        with self.assertRaises(self.m.Refusal) as caught:
            self.api.clone(row('ustar_moodle'),CHILDREN['ustar_moodle'],'ustar_moodle',PROJECT,GEN,{})
        self.assertNotIn('PRIVATE_SENTINEL',str(caught.exception))


class OfflineCLI(unittest.TestCase):
    def test_default_is_explicit_plan_only_and_does_not_touch_the_working_directory(self):
        import subprocess
        with tempfile.TemporaryDirectory() as td:
            result=subprocess.run([sys.executable,'-I','-B',str(SOURCE)],cwd=td,capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertTrue(result.stdout.strip(),'default must explicitly report PLAN_ONLY')
            self.assertEqual(json.loads(result.stdout)['phase'],'PLAN_ONLY')
            self.assertEqual(list(Path(td).iterdir()),[])

    def test_incomplete_transition_cannot_report_success_for_apply(self):
        import subprocess
        result=subprocess.run([sys.executable,'-I','-B',str(SOURCE),'--apply'],capture_output=True,text=True)
        self.assertNotEqual(result.returncode,0,'unqualified apply must fail closed')
        self.assertEqual(json.loads(result.stdout)['phase'],'refused')


@unittest.skipUnless(os.geteuid()==0,'private transaction fixture')
class Coordinator(unittest.TestCase):
    """Exercise the persisted boundary, including a crash after an external mutation."""
    class Host:
        def __init__(self):
            self.events=[];self.fail=None;self.business=['original'];self.current='old'
            self.folders_restored=False
        def prepare(self):return {'containers':{n:row(n) for n in PARENTS}}
        def phase(self,name,state):
            self.events.append(name)
            if name=='created':self.current='new'
            if self.fail==name:raise RuntimeError('PRIVATE_FAILURE')
            return {'completed_'+name:True}
        def recover(self,state):
            self.events.append('recover');self.current='old'
            return {'old_containers_restored':True}
        def verify(self,state):
            if self.current!='new':raise RuntimeError('not_active')
            return {'phase':'runtime_active','business_rows_restored':False}

    def setup_tx(self,host=None):
        m=module(self);self.assertTrue(hasattr(m,'Transaction'),'durable runtime transaction missing')
        tmp=tempfile.TemporaryDirectory();self.addCleanup(tmp.cleanup)
        base=Path(tmp.name)/'private';base.mkdir(mode=0o700)
        store=m.PrivateStore(base,pinned_core);host=host or self.Host()
        tx=m.Transaction(store,host,b'fixture operator',generation=GEN)
        return m,store,host,tx

    def test_active_repeat_only_verifies_and_does_not_repeat_backup_or_create(self):
        m,store,host,tx=self.setup_tx();report=tx.apply()
        self.assertEqual(report['phase'],'runtime_active')
        events=list(host.events);self.assertTrue(events and 'backup_complete' in events)
        tx.apply();self.assertEqual(host.events,events)
        self.assertEqual(store.load()['phase'],'active')

    def test_failure_after_every_mutation_recovers_original_runtime_without_data_restore(self):
        m,_,_,_=self.setup_tx()
        for phase in m.TRANSITION:
            with self.subTest(phase=phase):
                host=self.Host();host.fail=phase
                _,store,host,tx=self.setup_tx(host)
                with self.assertRaises(RuntimeError):tx.apply()
                self.assertEqual(host.current,'old')
                self.assertEqual(store.load()['phase'],'recovered')
                self.assertEqual(host.events[-1],'recover')
                self.assertFalse(host.folders_restored)
                self.assertEqual(host.business,['original'])

    def test_explicit_recovery_preserves_business_writes_after_public_release(self):
        _,store,host,tx=self.setup_tx();tx.apply();host.business.append('new learner write')
        tx.recover()
        self.assertEqual(host.current,'old');self.assertEqual(host.business,['original','new learner write'])
        self.assertEqual(store.load()['phase'],'recovered')

    def test_interrupted_intent_requires_recovery_and_cannot_start_another_generation(self):
        m,store,host,tx=self.setup_tx()
        store.create(GEN,b'fixture operator',host.prepare());store.step('created',intent='new_running')
        with self.assertRaises(m.Refusal):tx.apply()
        self.assertEqual(host.events,[])
        tx.recover();self.assertEqual(store.load()['phase'],'recovered')

    def test_recovery_failure_retains_intent_and_never_reports_success(self):
        _,store,host,tx=self.setup_tx();host.fail='created'
        def fail(state):raise RuntimeError('PRIVATE_RECOVERY_FAILURE')
        host.recover=fail
        with self.assertRaises(RuntimeError):tx.apply()
        state=store.load();self.assertEqual(state['phase'],'recovery_required')
        self.assertEqual(state['intent'],'created')

@unittest.skipUnless(os.geteuid()==0,'actual deployer/root boundary runs in root CI')
class DeployerBoundary(unittest.TestCase):
    def test_deployer_keeps_source_read_and_cannot_read_parked_original(self):
        import subprocess
        m=module(self)
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o711)
            parent=root/'deployment';parent.mkdir(mode=0o775);parent.chmod(0o775)
            source=parent/'compose.yml';source.write_bytes(b'PRIVATE_ORIGINAL\n');source.chmod(0o600)
            try:os.chown(parent,1000,1000);os.chown(source,1000,1000)
            except OSError:
                if os.environ.get('USTAR_REQUIRE_INFRA_NATIVE')=='1':raise
                self.skipTest('UID1000 is not mapped in the local executor; strict root CI required')
            before=source.stat();base=root/'private';base.mkdir(mode=0o700)
            store=m.PrivateStore(base,pinned_core);store.create(GEN,b'fixture operator',{})
            files=m.ManagedFiles(store);files.replace('compose',source,b'PRIVATE_SUCCESSOR\n')
            def read_as(uid,path):
                def drop():os.setgroups([]);os.setgid(uid);os.setuid(uid)
                code='from pathlib import Path; import sys;\ntry: Path(sys.argv[1]).read_bytes()\nexcept PermissionError: sys.exit(13)'
                return subprocess.run([sys.executable,'-I','-B','-c',code,str(path)],preexec_fn=drop,capture_output=True).returncode
            self.assertEqual(read_as(1000,source),0)
            self.assertEqual(read_as(33,source),13)
            self.assertEqual(read_as(1000,base/'originals'/'compose'),13)
            self.assertEqual((source.stat().st_uid,source.stat().st_gid,source.stat().st_mode&0o777),(1000,1000,0o600))
            files.restore()
            self.assertEqual(source.stat().st_ino,before.st_ino)
            self.assertEqual(read_as(1000,source),0)
            self.assertEqual(source.read_bytes(),b'PRIVATE_ORIGINAL\n')
            print('DOCKER_RUNTIME_DEPLOYER_BOUNDARY=PASS')


class RuntimeEdges(unittest.TestCase):
    def test_api_failure_classifies_subnet_refusal_without_echoing_message(self):
        from unittest.mock import patch
        from types import SimpleNamespace
        m=module(self);api=m.DockerAPI()
        response=SimpleNamespace(status=400,read=lambda size:b'{"message":"PRIVATE_SENTINEL user specified IP address is supported only when connecting to networks with user configured subnets"}')
        connection=SimpleNamespace(request=lambda *a,**k:None,getresponse=lambda:response,close=lambda:None)
        with patch.object(m,'_UnixHTTP',return_value=connection),self.assertRaises(m.Refusal) as caught:
            api._http('POST','/fixture')
        self.assertEqual(str(caught.exception),'docker_network_explicit_subnet_required')

    def test_clean_stop_accepts_only_success_or_the_configured_signal_exit(self):
        m=module(self);api=m.DockerAPI();events=[]
        running={'Config':{'StopSignal':'SIGINT'},'State':{'Running':True,'ExitCode':0,'OOMKilled':False}}
        stopped=copy.deepcopy(running);stopped['State'].update(Running=False,ExitCode=130)
        responses=iter([running,stopped]);api.inspect=lambda value:next(responses)
        api.call=lambda *args,**kw:events.append(args)
        api.stop('1'*64);self.assertEqual(len(events),1)
        for code,oom in ((137,False),(139,False),(130,True)):
            stopped['State'].update(ExitCode=code,OOMKilled=oom)
            responses=iter([running,copy.deepcopy(stopped)]);api.inspect=lambda value:next(responses)
            with self.subTest(code=code,oom=oom),self.assertRaises(m.Refusal):api.stop('1'*64)

    def test_staged_successor_requires_restart_no_and_active_requires_original_policy(self):
        m=module(self);old=row('ustar_moodle');new=Compose().successor()
        new['HostConfig']['RestartPolicy']={'Name':'no','MaximumRetryCount':0}
        m.verify_successor(old,new,CHILDREN,PROJECT,GEN,staged=True)
        with self.assertRaises(m.Refusal):m.verify_successor(old,new,CHILDREN,PROJECT,GEN)
        new['HostConfig']['RestartPolicy']={'Name':'always','MaximumRetryCount':0}
        with self.assertRaises(m.Refusal):m.verify_successor(old,new,CHILDREN,PROJECT,GEN,staged=True)

    def test_snapshot_api_refuses_running_source_and_checks_returned_layer_lineage(self):
        m=module(self);self.assertTrue(hasattr(m.DockerAPI,'snapshot'),'stopped runtime snapshot missing')
        api=m.DockerAPI();parent,child,old=snapshot('ustar_moodle');old['State']={'Running':False}
        seen=[]
        def call(method,path,data=None,**kw):
            seen.append((method,path,copy.deepcopy(data)))
            if method=='POST':return {'Id':CHILDREN['ustar_moodle']}
            raise AssertionError('unexpected request')
        api.call=call;api.inspect=lambda name:copy.deepcopy(old)
        api.image=lambda image,**kw: (None if kw.get('missing') else parent) if image!=CHILDREN['ustar_moodle'] else child
        self.assertEqual(api.snapshot(old,'ustar_moodle',GEN),CHILDREN['ustar_moodle'])
        self.assertEqual(len(seen),1)
        self.assertIn('/commit?',seen[0][1]);self.assertEqual(seen[0][2]['Env'],old['Config']['Env'])
        old['State']['Running']=True;seen.clear()
        with self.assertRaises(m.Refusal):api.snapshot(old,'ustar_moodle',GEN)
        self.assertEqual(seen,[])

    def test_current_cron_binding_keeps_unrelated_state_and_rejects_changed_old_identity(self):
        m=module(self);self.assertTrue(hasattr(m,'cron_binding'),'typed cron handoff missing')
        source=Pins().source();old={n:{'id':row(n)['Id'],'image':PARENTS[n]} for n in PARENTS}
        new={n:{'id':('3' if n=='ustar_moodle' else '4')*64,'image':CHILDREN[n]} for n in PARENTS}
        state={'schema':1,'script_sha256':m.sha(source),'versions':{'local_ustar':'2026102300'},
            'containers':copy.deepcopy(old),'created_utc':'retained-owner-receipt','other_field':'PRESERVED'}
        updated=m.cron_binding(source,m.patch_images(source,PARENTS,CHILDREN),state,old,new)
        self.assertEqual(updated['containers'],new);self.assertEqual(updated['other_field'],'PRESERVED')
        self.assertEqual(updated['created_utc'],state['created_utc'])
        state['containers']['ustar_moodle']['id']='9'*64
        with self.assertRaises(m.Refusal):m.cron_binding(source,source,state,old,new)

    def test_unowned_current_container_refuses_before_recovery_mutates_anything(self):
        m=module(self);self.assertTrue(hasattr(m,'RuntimeHost'),'runtime host boundary missing')
        rows={n:row(n) for n in PARENTS}
        foreign=Compose().successor();foreign['Config']['Labels']['org.ustar.runtime.generation']='9'*32
        class API:
            def inspect(self,name,**kw):return foreign if name=='ustar_moodle' else rows['ustar_postgres']
            def call(self,*args,**kw):raise AssertionError('must refuse before mutation')
        class Controls:
            def quiesce(self,state):raise AssertionError('must validate objects first')
        host=m.RuntimeHost(None,API(),Controls(),None)
        with self.assertRaises(m.Refusal):host.recover({'generation':GEN,'before':{'containers':rows},'images':CHILDREN})

    def test_recovery_disconnects_a_successor_parked_before_an_interrupted_response(self):
        m=module(self);rows={n:row(n) for n in PARENTS};events=[]
        rows['ustar_moodle']['Mounts'].append(dict(rows['ustar_moodle']['Mounts'][0],Destination='/second'))
        old=copy.deepcopy(rows);old['ustar_moodle']['Mounts'].reverse();new=Compose().successor()
        for n,v in old.items():v['Name']='/'+m.RuntimeHost.retained({'generation':GEN},n);v['State']={'Running':False}
        new['Name']='/'+m.RuntimeHost.retained({'generation':GEN},'ustar_moodle',successor=True)
        new['State']={'Running':False};new['Config']['Labels'].update({
            'com.docker.compose.service':'moodle','org.ustar.runtime.source-container':rows['ustar_moodle']['Id']})
        objects={v['Id']:v for v in list(old.values())+[new]}
        class API:
            def inspect(self,name,*,missing=False):
                found=next((v for k,v in objects.items() if k==name or v['Name']=='/'+name),None)
                if found is None and not missing:raise AssertionError('required object missing')
                return copy.deepcopy(found)
            def policy(self,identity,policy):objects[identity]['HostConfig']['RestartPolicy']=policy
            def stop(self,identity):objects[identity]['State']['Running']=False
            def rename(self,identity,name):objects[identity]['Name']='/'+name
            def disconnect(self,identity):events.append(('disconnect',identity));objects[identity]['NetworkSettings']['Networks']={}
            def reconnect(self,before):events.append(('reconnect',before['Id']))
            def start(self,identity):objects[identity]['State']['Running']=True
        class Controls:
            def quiesce(self,state,**kw):pass
            def cold(self):pass
            def restore_files(self,state):pass
            def ready(self,*args):pass
            def verify(self,*args,**kw):pass
            def resume(self,*args,**kw):pass
        class Store:
            def load(self):return copy.deepcopy(state)
            def save(self,value):state.update(value)
        state={'generation':GEN,'before':{'containers':rows},'images':CHILDREN,
            'new_containers':{'ustar_moodle':new['Id']}}
        host=m.RuntimeHost(Store(),API(),Controls(),None);host.recover(state)
        self.assertIn(('disconnect',new['Id']),events)
        self.assertLess(events.index(('disconnect',new['Id'])),events.index(('reconnect',rows['ustar_postgres']['Id'])))
        self.assertTrue(all(objects[v['Id']]['State']['Running'] for v in rows.values()))
        objects[rows['ustar_moodle']['Id']]['Mounts'][0]['Source']='/foreign-source'
        with self.assertRaises(m.Refusal) as caught:host.recover(state)
        self.assertEqual(str(caught.exception),'predecessor_mounts_drift')


class ArchiveContract(unittest.TestCase):
    def test_image_archive_boundary_exists_without_enabling_the_cli(self):
        m=module(self);self.assertTrue(hasattr(m,'RuntimeArchive'),'encrypted snapshot archive missing')

    def test_bounded_stream_readback_rejects_changed_payload_and_excess_size(self):
        import io
        m=module(self);self.assertTrue(hasattr(m,'stream_digest'),'bounded image stream verifier missing')
        payload=b'PRIVATE_IMAGE_BYTES'*17000
        expected=m.stream_digest(io.BytesIO(payload),limit=len(payload))
        self.assertEqual(expected,{'bytes':len(payload),'sha256':m.sha(payload)})
        with self.assertRaises(m.Refusal):m.stream_digest(io.BytesIO(payload),limit=len(payload)-1)
        with self.assertRaises(m.Refusal):m.stream_digest(io.BytesIO(payload+b'x'),expected=expected,limit=len(payload)+1)

    def test_actual_age_readback_controls_and_cipher_corruption(self):
        import shutil,subprocess
        m=module(self);self.assertTrue(hasattr(m,'RuntimeArchive'),'encrypted snapshot archive missing')
        if not shutil.which('age') or not shutil.which('age-keygen'):
            if os.environ.get('USTAR_REQUIRE_INFRA_NATIVE')=='1':self.fail('actual age required')
            self.skipTest('actual age is required in the existing strict runtime CI')
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);key=root/'recovery.key';recipients=root/'recipients.txt'
            subprocess.run(['age-keygen','-o',str(key)],check=True,capture_output=True)
            recipients.write_bytes(subprocess.check_output(['age-keygen','-y',str(key)]));recipients.chmod(0o600)
            base=root/'private';base.mkdir(mode=0o700)
            store=m.PrivateStore(base,pinned_core);store.create(GEN,b'PRIVATE_OPERATOR',{})
            target=root/'hdd';target.mkdir(mode=0o700)
            save=lambda value:[sys.executable,'-I','-B','-c',"import sys;sys.stdout.buffer.write(b'PRIVATE_SNAPSHOT'*20000)"]
            archive=m.RuntimeArchive(store,pinned_core,target,recipients,key,save,limit=4*1024**2,reserve=0)
            state=store.load();state.update(images=CHILDREN)
            receipt=archive.write(state)
            self.assertTrue(receipt['readback_verified']);self.assertFalse(receipt['recovery_key_included'])
            self.assertEqual(set(receipt['files']),{'images','controls'})
            self.assertEqual(list(base.glob('*.tar')),[])
            image=target/receipt['files']['images']['name']
            raw=image.read_bytes();image.write_bytes(raw[:-1]+bytes([raw[-1]^1]))
            with self.assertRaises(m.Refusal):archive.verify_image(image,receipt['image_stream'])
            self.assertTrue(image.exists(),'corrupt evidence must be retained')
            self.assertNotIn('PRIVATE',json.dumps(receipt))
            print('DOCKER_RUNTIME_AGE_READBACK=PASS')
