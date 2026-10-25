#!/usr/bin/env python3
"""Missing runtime transition delta, using this CI job's existing PHP/PG16 images."""
import base64
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import urllib.request
import uuid

ROOT=Path(__file__).resolve().parents[2]
if (os.geteuid()!=0 or os.environ.get('GITHUB_ACTIONS')!='true'
        or os.environ.get('USTAR_DOCKER_LIFECYCLE_FIXTURE')!='1' or Path('/opt/ustar').exists()):
    raise RuntimeError('disposable_existing_CI_runtime_required')
sys.path.insert(0,str(ROOT/'scripts/infra'))
import docker_lifecycle as l
import docker_lifecycle_host as h
import infra_one_pass as core

COMPOSE=['/usr/bin/docker','--host','unix:///var/run/docker.sock','compose',
    '-f',str(ROOT/'tests/stage/patch-compose.yaml'),'-f',str(ROOT/'tests/stage/connect-compose.yaml')]
tmp=tempfile.TemporaryDirectory(prefix='ustar-docker-delta-',dir='/var/lib');root=Path(tmp.name);root.chmod(0o711)
run=h.Commands(root/'private-command.log');api=l.DockerAPI();owned=[];generation=uuid.uuid4().hex
network='ustar-lifecycle-'+generation;label='org.ustar.fixture.run='+generation
proof={}
stage='starting_fixture'
real_stop=api.stop
def stop_with_metadata(identity):
    try:return real_stop(identity)
    except BaseException:
        row=api.inspect(identity)
        proof['failed_stop']={k:row['State'].get(k) for k in ('Running','ExitCode','OOMKilled')}
        proof['failed_stop']['configured_signal']=row['Config'].get('StopSignal')
        raise
api.stop=stop_with_metadata

def command(*args,**kw):return run(h.docker(*args),**kw).stdout
def sql(text):
    return command('exec','-i','ustar_postgres','psql','-X','-w','-qAt','-v','ON_ERROR_STOP=1',
        '-U','fixture_runtime','-d','moodle',raw=text.encode()).strip()

def container_proof(name):
    if name=='ustar_moodle':
        text=command('exec',name,'sh','-eu','-c',
            "stat -c '%u:%g:%a:%Y' /usr/share/ustar-fixture-proof; "
            "sha256sum /usr/share/ustar-fixture-proof; /usr/bin/ustar-fixture-tool; "
            "test ! -e /usr/local/bin/docker-php-source")
    else:text=command('exec','-u','0:0',name,'cat','/root/ustar-fixture-proof')
    return hashlib.sha256(text).hexdigest()

class Guards:
    def __init__(self,base):self.base=base;self.cold_held=False
    def prepare(self,rows):
        assert {n:v['Id'] for n,v in rows.items()}==predecessors
        assert all(v['State']['Running'] for v in rows.values())
        return {'proof':{n:container_proof(n) for n in l.SERVICES},'source_inode':source.stat().st_ino,
            'archive_budget_bytes':sum(api.image(v['Image'])['Size'] for v in rows.values())+32*1024**2}
    def control_profile(self,controls):
        assert set(controls)==set(paths)
        assert controls['compose']['image']['metadata']['uid']==1000
        assert controls['compose']['image']['metadata']['mode']==0o600
    def units(self):return {}
    def quiesce(self,units):assert units=={}
    def resume(self,units):assert units=={}
    def cold(self):self.cold_held=True
    def backup(self,state):
        raw=command('exec','ustar_postgres','pg_dump','-U','fixture_runtime','-d','moodle','-Fc')
        assert raw.startswith(b'PGDMP');(self.base/'fresh-private.dump').write_bytes(raw)
        (self.base/'fresh-private.dump').chmod(0o600)
        return {'native_pg_dump':True,'sha256':l.sha(raw)}
    def no_clients(self,state):assert sql("SELECT count(*) FROM pg_stat_activity WHERE datname='moodle' AND pid<>pg_backend_pid();")==b'0'
    def verify(self,state,*,recovery=False):
        assert {n:container_proof(n) for n in l.SERVICES}==state['before']['proof']
        assert int(sql('SELECT count(*) FROM business_evidence;'))>=1
        if recovery:assert source.stat().st_ino==state['before']['source_inode']

def make_transaction(*,archive=True):
    gen=uuid.uuid4().hex;base=root/('private-'+gen);base.mkdir(mode=0o700)
    store=l.PrivateStore(base,core);guards=Guards(base)
    controls=h.Controls(store,api,run,paths,guards)
    class NoArchive:
        def write(self,state):return {'failure_fixture_only':True}
    sealed=l.RuntimeArchive(store,core,target,recipients,key,lambda images:h.docker('image','save',*images.values()),
        limit=4*1024**3,reserve=0) if archive else NoArchive()
    host=l.RuntimeHost(store,api,controls,sealed)
    tx=l.Transaction(store,host,b'fixture runtime operator',generation=gen)
    return tx,host,store,controls

try:
    assert all(api.inspect(n,missing=True) is None for n in l.SERVICES),'fixture_names_already_present'
    ids={}
    model=json.loads(run(COMPOSE+['config','--format','json']).stdout)
    for service,keyname in (('moodle','runner'),('postgres','db')):
        item=model['services'][keyname]
        # Compose's documented local build tag when image is omitted.
        image=item.get('image') or model['name']+'-'+keyname
        ids[service]=api.image(image)['Id']
    command('network','create','--internal','--label',label,network)
    data=root/'postgres';data.mkdir();code=root/'code';code.mkdir(mode=0o755)
    moodledata=root/'moodledata';moodledata.mkdir(mode=0o770)
    index=b'''<?php
if ($_SERVER['REQUEST_URI']==='/noise') {
    $out=fopen('php://stderr','wb');
    for ($i=0;$i<9500;$i++) { fwrite($out,str_repeat('X',8191)."\\n"); }
    fclose($out);
}
echo 'fixture-ready';
'''
    (code/'index.php').write_bytes(index);(code/'index.php').chmod(0o644)
    command('run','-d','--pull=never','--name','ustar_postgres','--label',label,
        '--network',network,'--network-alias','postgres','--restart','unless-stopped',
        '-v',str(data)+':/var/lib/postgresql/data:rw','-e','POSTGRES_USER=fixture_runtime',
        '-e','POSTGRES_DB=moodle','-e','POSTGRES_PASSWORD=fixture-only-$literal',ids['postgres'])
    owned.append('ustar_postgres')
    command('run','-d','--pull=never','--name','ustar_moodle','--label',label,
        '--network',network,'--network-alias','moodle','--restart','unless-stopped',
        '-v',str(code)+':/work:rw','-v',str(moodledata)+':/var/www/moodledata:rw',
        '-e','FIXTURE_LITERAL=private-$same${literal}','--entrypoint','php','-p','127.0.0.1::80',
        ids['moodle'],'-S','0.0.0.0:80','-t','/work')
    owned.append('ustar_moodle')
    until=time.monotonic()+60
    while run(h.docker('exec','ustar_postgres','pg_isready','-q','-h','127.0.0.1','-U','fixture_runtime','-d','moodle'),check=False).returncode:
        assert time.monotonic()<until;time.sleep(.2)
    stage='initial_SQL'
    sql('CREATE TABLE business_evidence(id integer PRIMARY KEY, note text); INSERT INTO business_evidence VALUES(1,\'before\');')
    command('exec','ustar_moodle','sh','-eu','-c',
        "printf 'preserve actual writable bytes\\n' > /usr/share/ustar-fixture-proof; "
        "chown 1000:1000 /usr/share/ustar-fixture-proof; chmod 640 /usr/share/ustar-fixture-proof; "
        "touch -d @1580000000 /usr/share/ustar-fixture-proof; "
        "printf '#!/bin/sh\\necho fixture-tool\\n' > /usr/bin/ustar-fixture-tool; "
        "chmod 755 /usr/bin/ustar-fixture-tool; rm /usr/local/bin/docker-php-source")
    command('exec','-u','0:0','ustar_postgres','sh','-eu','-c',"printf postgres-layer > /root/ustar-fixture-proof")
    predecessors={n:api.inspect(n)['Id'] for n in l.SERVICES}
    parents={n:api.inspect(n)['Image'] for n in l.SERVICES}
    deployment=root/'deployment';deployment.mkdir(mode=0o775);os.chown(deployment,1000,1000)
    source=deployment/'docker-compose.yml';source.write_text(json.dumps({'services':{
        'moodle':{'image':parents['ustar_moodle'],'container_name':'ustar_moodle'},
        'postgres':{'image':parents['ustar_postgres'],'container_name':'ustar_postgres'}}}))
    source.chmod(0o600);os.chown(source,1000,1000)
    helpers=root/'helpers';helpers.mkdir(mode=0o700)
    paths={'compose':source,**{k:helpers/k for k in ('hdd_script','hdd_scheduler','cron_script','cron_installation','cron_schedule')}}
    helper=('import json,subprocess\nIMAGES = '+repr(parents)+'''\ndef identity(name):
    row=json.loads(subprocess.check_output(['/usr/bin/docker','--host','unix:///var/run/docker.sock','inspect',name]))[0]
    if row['Image']!=IMAGES[name] or not row['State']['Running']: raise ValueError('fixture_identity_changed')
    return {'id':row['Id'],'image':row['Image']}
''').encode()
    paths['hdd_script'].write_bytes(helper);paths['cron_script'].write_bytes(helper)
    scheduler=(ROOT/'scripts/infra/hdd_backup_schedule.py').read_bytes()
    paths['hdd_scheduler'].write_bytes(l.patch_producer_binding(scheduler,h.HDD_SHA,l.sha(helper)))
    paths['cron_installation'].write_text(json.dumps({'schema':1,'script_sha256':l.sha(helper),
        'containers':{n:{'id':predecessors[n],'image':parents[n]} for n in l.SERVICES},
        'versions':{'local_ustar':'2026102300','theme_ustar':'2026100202'},'created_utc':'retained-fixture'}))
    paths['cron_schedule'].write_bytes(b'# fixture schedule, no host cron entry\n')
    for name,path in paths.items():path.chmod(0o600)
    key=root/'recovery.key';recipients=root/'recipients.txt'
    run(['/usr/bin/age-keygen','-o',str(key)])
    recipients.write_bytes(run(['/usr/bin/age-keygen','-y',str(key)]).stdout);recipients.chmod(0o600)
    target=root/'hdd';target.mkdir(mode=0o700)

    stage='native_apply'
    tx,host,store,controls=make_transaction();result=tx.apply();assert result['phase']=='runtime_active'
    state=store.load();assert state['runtime_archive']['readback_verified']
    successor_ids={n:api.inspect(n)['Id'] for n in l.SERVICES}
    assert successor_ids!=predecessors
    assert all(api.inspect(n)['Image']==state['images'][n] for n in l.SERVICES)
    from types import SimpleNamespace
    actual=(ROOT/'scripts/infra/hdd_backup.py').read_bytes()
    pinned={n:v['image'] for n,v in h.production_profile()['predecessors'].items()}
    actual=h.definition(l.patch_images(actual,pinned,state['images']),'_native_actual_HDD_producer',h.HDD)
    checked=actual.validate_live(SimpleNamespace(MOODLE='ustar_moodle',POSTGRES='ustar_postgres',inspect=api.inspect))
    assert {n:v['Id'] for n,v in zip(l.SERVICES,checked)}==state['new_containers']
    proof['actual_HDD_image_guard_and_scheduler_loader_accept_successor']=True
    before_source_inode=state['before']['source_inode'];assert source.stat().st_ino!=before_source_inode
    assert (source.stat().st_uid,source.stat().st_gid,stat.S_IMODE(source.stat().st_mode))==(1000,1000,0o600)
    tx.apply();assert {n:api.inspect(n)['Id'] for n in l.SERVICES}==successor_ids
    sql("INSERT INTO business_evidence VALUES(2,'after successor released');")
    (moodledata/'after-release.txt').write_text('new learner file')
    old_logs={n:Path(api.inspect(identity)['LogPath']).stat() for n,identity in predecessors.items()}
    stage='actual_log_rotation'
    current=api.inspect('ustar_moodle');port=current['NetworkSettings']['Ports']['80/tcp'][0]['HostPort']
    with urllib.request.urlopen('http://127.0.0.1:'+port+'/noise',timeout=60) as response:assert response.read()==b'fixture-ready'
    log=Path(current['LogPath']);until=time.monotonic()+40
    while True:
        files=list(log.parent.glob(log.name+'*'))
        if len(files)<=3 and any(p.name!=log.name for p in files):break
        assert time.monotonic()<until,'bounded_rotation_did_not_settle';time.sleep(.2)
    assert sum(p.stat().st_size for p in files)<=64*1024**2
    assert all(Path(api.inspect(predecessors[n])['LogPath']).stat().st_size==old_logs[n].st_size for n in l.SERVICES)
    proof['actual_log_rotation']={'files':len(files),'maximum_files':3,'bounded':True,'predecessor_logs_retained':True}
    receipt=state['runtime_archive'];cipher=target/receipt['files']['images']['name']
    with cipher.open('rb') as stream:
        decrypt=subprocess.Popen(['/usr/bin/age','-d','-i',str(key)],stdin=stream,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
        loader=subprocess.Popen(h.docker('image','load'),stdin=decrypt.stdout,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
        decrypt.stdout.close();out,_=loader.communicate(timeout=180)
        assert loader.returncode==0 and decrypt.wait(timeout=10)==0
    assert all(api.image(value)['Id']==value for value in state['images'].values())
    proof['encrypted_snapshot_save_readback_load']=True
    stage='native_recovery'
    tx.recover();assert store.load()['phase']=='recovered'
    assert {n:api.inspect(n)['Id'] for n in l.SERVICES}==predecessors
    assert sql('SELECT count(*) FROM business_evidence;')==b'2'
    assert (moodledata/'after-release.txt').read_text()=='new learner file'
    assert source.stat().st_ino==before_source_inode
    proof['business_writes_and_original_inode_survive_recovery']=True

    failures=[]
    for point in ('retained','created','consumers_written'):
        stage='failure_'+point
        tx,host,store,controls=make_transaction(archive=False);phase=host.phase
        def injected(name,state):
            result=phase(name,state)
            if name==point:raise RuntimeError('injected_native_delta_failure')
            return result
        host.phase=injected
        try:tx.apply()
        except RuntimeError as error:assert str(error)=='injected_native_delta_failure'
        else:raise AssertionError('injection_did_not_fire')
        assert store.load()['phase']=='recovered'
        assert {n:api.inspect(n)['Id'] for n in l.SERVICES}==predecessors
        assert sql('SELECT count(*) FROM business_evidence;')==b'2'
        failures.append(point)
    proof.update(native_failure_recovery=failures,snapshot_layer_metadata_and_deletion_preserved=True,
        exact_environment_mounts_addresses_hostconfig=True,original_objects_and_images_retained=True,
        business_rows_restored=False,production_access=False,provider_called=False)
    output=ROOT/'artifacts/docker-lifecycle-native.json';output.write_text(json.dumps(proof,indent=2)+'\n')
    print('DOCKER_RUNTIME_SUCCESSOR_NATIVE=PASS')
except BaseException as error:
    failure={'stage':stage,'type':type(error).__name__,'reason':h.public_error(error),'production_access':False}
    if 'failed_stop' in proof:failure['stop']=proof['failed_stop']
    if 'store' in globals() and store.load() is not None:failure['journal_phase']=store.load()['phase']
    if run.log and run.log.exists():
        raw=run.log.read_bytes()[-32768:]
        if b'.s.PGSQL.' in raw and (b'No such file' in raw or b'Connection refused' in raw):
            failure['diagnostic']='postgres_socket_unavailable'
        elif b'database system is shutting down' in raw:failure['diagnostic']='postgres_shutting_down'
    out=ROOT/'artifacts/docker-lifecycle-native-failure.json'
    out.write_text(json.dumps(failure,indent=2)+'\n');print(json.dumps(failure),flush=True)
    raise
finally:
    # Disposal is restricted to this CI fixture's exact run label, never production.
    result=run(h.docker('ps','-aq','--filter','label='+label),check=False)
    identities=result.stdout.decode().split()
    if identities:run(h.docker('rm','-f',*identities),check=False,timeout=100)
    run(h.docker('network','rm',network),check=False)
    tmp.cleanup()
