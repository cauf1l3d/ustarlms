#!/usr/bin/env python3
"""New Runtime on actual systemd/UID33/credentials/TLS; synthetic API/store and SQL boundary.

Never run on server1. Native application/SQL lifecycle is the separate con02-update gate.
"""
import ast
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import json
import os
from pathlib import Path
import shutil
import sqlite3
import ssl
import subprocess
import sys
import tarfile
import threading
import time
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
import connect_con02_contract as c
import connect_con02_state as state
from connect_con02_runtime import Runtime, worker_files
from connect_con02_update import load_embedded

PREFIX = Path('/usr/local/lib/ustar-con02-controller-fixture')


def run(argv, *, check=True):
    p = subprocess.run(argv,capture_output=True,text=True,timeout=90)
    if check and p.returncode: raise RuntimeError('fixture_command_failed '+str(argv[:3])+' '+p.stderr[-1200:])
    return p


def artifact_bundle(path):
    path = Path(path)
    expected = path.with_name(path.name+'.sha256').read_text().split()[0]
    c.require(c.sha(path.read_bytes()) == expected,'fixture_artifact_digest')
    parsed = ast.parse(path.read_text())
    constants = {n.targets[0].id: ast.literal_eval(n.value) for n in parsed.body if isinstance(n,ast.Assign)
                 and isinstance(n.targets[0],ast.Name) and n.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')}
    bundle=load_embedded(*[json.loads(constants[k],object_pairs_hook=state.unique)
                          for k in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')])
    for name in c.MODULES:
        path=ROOT/('scripts' if name=='production_manifest' else 'scripts/infra')/(name+'.py')
        assert bundle.modules[name+'.py']==path.read_bytes(),'fixture_controller_differs_from_artifact'
    return bundle


def write(path, raw, mode=0o600, gid=0):
    path = Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    for p in (path.parent,): p.chmod(0o755)
    state.replace(path,raw if isinstance(raw,bytes) else raw.encode(),{'uid':0,'gid':gid,'mode':mode,'xattrs':{}})


def baseline_profiles(bundle):
    modules = c.frozen_legacy(bundle)
    include = modules['connect_private_bridge'].php_settings('11'*32,'123456')
    include += ("\n// USTAR one-employee notification pilot; separate key and new events only.\n"
        "$CFG->ustar_connect_bridge_keys['tg_notify_pilot_v1']=['secret'=>'"+'22'*32+
        "','provider'=>'telegram','tenant'=>'123456','operations'=>['notification.lease','notification.begin','notification.finish']];\n"
        "$CFG->ustar_connect_notify_userids=[130];\n$CFG->ustar_connect_notify_since=100;\n"
        "$CFG->ustar_connect_notifications_enabled=true;\n").encode()
    files = {'include':include,'link_key':('11'*32+'\n').encode(),'notify_key':('22'*32+'\n').encode(),
             'token':b'123456:synthetic-fixture-never-a-provider-token',
             'notify_dropin':modules['connect_notification_pilot'].DROPIN}
    for unit in ('receiver','probe','notifications'):
        service = 'notify' if unit=='notifications' else unit; cred='/run/credentials/ustar-connect-'+service+'.service/'
        cfg={'bot_token_file':cred+'telegram.token','bot_username':'ustarcorp_bot',
             'state_directory':'/var/lib/ustar-connect-'+('notify' if service=='notify' else 'receiver'),
             'telegram_proxy':'http://127.0.0.1:18081','onboarding_enabled':False,'linking_enabled':service!='notify',
             'bridge_endpoint':'https://127.0.0.1:18443/local/ustar/connect_bridge.php',
             'bridge_key_id':'tg_notify_pilot_v1' if service=='notify' else 'tg_link_v1',
             'bridge_key_file':cred+'bridge.key','bridge_ca_file':cred+('bridge-ca.crt' if service=='notify' else 'bridge.ca')}
        if service=='notify':cfg['notification_subjects']=['77777']
        files[unit]=(json.dumps(cfg,sort_keys=True)+'\n').encode()
    for n in ('receiver','probe'):files[n+'_link_dropin']=modules['connect_private_bridge'].DROPIN
    return files


class FileHost:
    """Real source/images; application SQL is intentionally supplied by native Task7 separately."""
    def __init__(self,bundle,root):
        self.bundle=bundle;self.ctx=SimpleNamespace(root=Path(root));self.log=io.BytesIO()
    def control_paths(self):return c.Host.control_paths(self)
    def controls(self,journal):
        images={n:state.capture(p) for n,p in self.control_paths().items()}
        fence=c.read_fence(images['include']['raw'],json.loads(images['notifications']['raw']),json.loads(images['receiver']['raw']))
        return {'files':images,'fence':{**fence,'last_link_state':'revoked'}}
    def application_ready(self,journal):
        assert journal['app']['phase']=='app_ready'
        self.ctx.engine.addon.source(self.ctx.engine,True)
    def validate_units(self,values):
        for n in ('receiver','probe','notify'):
            key='units/ustar-connect-'+n+'.service'
            assert values[key]==self.control_paths()[n+'_unit'].read_bytes()
    def validate_profiles(self,values):
        # Actual PHP syntax proof is in the separate HTTPS/native gate.
        assert b'ustar_connect_ack_enabled=true' in values['include']


def populate(bundle,root,host,old_values,ca):
    root=Path(root);paths=host.control_paths();files=baseline_profiles(bundle)
    files.update(link_ca=ca,notify_ca=ca)
    for n in ('receiver','probe','notify'):files[n+'_unit']=worker_files(bundle)['units/ustar-connect-'+n+'.service']
    for n,raw in files.items():
        mode=0o640 if n=='include' else 0o644 if n.endswith(('unit','dropin')) else 0o600
        write(paths[n],raw,mode,33 if n=='include' else 0)
    for name,raw in bundle.payload.items():
        path=root/'opt/ustar/data/moodle/public/public'/name
        write(path,raw,0o644,33);os.chown(path,33,33)
    parent=root/'opt/ustar-connect/releases';parent.mkdir(parents=True,exist_ok=True);parent.chmod(0o755)
    old=parent/c.LEGACY_COMMIT
    if not old.exists():
        old.mkdir(mode=0o755)
        for n,raw in old_values.items():write(old/n,raw,0o644)
        write(old/'manifest.json',(json.dumps({'schema':1,'source_commit':c.LEGACY_COMMIT,
              'files':{n:c.sha(v) for n,v in sorted(old_values.items())}},sort_keys=True,indent=2)+'\n').encode(),0o644)
    pointer=paths['pointer']
    if os.path.lexists(pointer):pointer.unlink()
    pointer.symlink_to(old)
    return paths


def fixture_context(bundle,root,host):
    ctx=c.make_context(bundle,root=Path(root),host=host);host.ctx=ctx
    ctx.fixture_created_locks=[]
    for path in state.Locks(ctx).paths[1:]:
        if not path.exists():write(path,b'',0o600);ctx.fixture_created_locks.append(path)
    ctx.engine.base.parent.mkdir(parents=True,exist_ok=True)
    j=state.Journal(ctx)
    j.begin('app',{'files':{},'https':host.https_snapshot()},{'files':{}},generation='b'*32)
    j.checkpoint('app','app_ready')
    return ctx,j,state.Locks(ctx)


WRAPPER=r'''import io,json,os,signal,sys,time
from pathlib import Path
sys.path.insert(0,'/opt/ustar-connect/current/receiver.pyz')
from ustar_connect.receiver_runtime import load_config
from ustar_connect.receiver import TelegramReceiver,verify_bot
from ustar_connect.receiver_journal import Journal,JournalError
from ustar_connect.bridge import BridgeClient
kind,profile=sys.argv[1:3];sender=kind=='notify'
assert os.geteuid()!=0 and (os.geteuid()==33 if not sender else os.geteuid()!=33)
if kind=='receiver' and Path('/usr/local/lib/ustar-con02-controller-fixture/FAIL_RECEIVER').exists():sys.exit(77)
c=load_config(profile,notification=sender)
ack=getattr(c,'acknowledgements_enabled',False)
for source in ('telegram.token','ack-bridge.key','notifications-bridge.key'):
 try:open('/etc/ustar-connect/'+source).read();raise AssertionError('root source readable')
 except PermissionError:pass
foreign='/run/credentials/ustar-connect-receiver.service/ack-bridge.key' if sender else '/run/credentials/ustar-connect-notify.service/bridge.key'
try:open(foreign).read();raise AssertionError('foreign credential readable')
except (PermissionError,FileNotFoundError):pass
class Response(io.BytesIO):
 def __enter__(self):return self
 def __exit__(self,*args):self.close()
class API:
 def open(self,request,timeout):
  method=request.full_url.rsplit('/',1)[-1];p=json.loads(request.data)
  if method=='getMe':v={'id':123456,'is_bot':True,'username':'ustarcorp_bot'}
  elif method=='getWebhookInfo':v={'url':''}
  elif method=='getUpdates':
   assert p['allowed_updates']==(['message','callback_query'] if ack else ['message'])
   v=[{'update_id':p['offset'],'message':{'date':int(time.time()),'text':'ignored synthetic input','from':{'id':77777,'is_bot':False},'chat':{'id':77777,'type':'private'}}}]
  else:raise AssertionError('provider_send_or_unexpected_operation')
  return Response(json.dumps({'ok':True,'result':v}).encode())
c.api._opener=API();verify_bot(c.api,c.bot_id,c.bot_username)
if sender:
 if getattr(c,'notification_contract',1)==2:c.bridge.probe_notification_scope(2)
 else:c.bridge.probe_notification_scope()
else:
 c.bridge.probe_link_scope()
 if ack:
  c.ack_bridge.probe_ack_scope()
  bad=BridgeClient(c.ack_bridge._endpoint,'tg_ack_v1',Path('/run/credentials/ustar-connect-'+kind+'.service/ack-bridge.key').read_text().strip(),ca_file='/usr/local/lib/ustar-con02-controller-fixture/wrong.crt')
  try:bad.probe_ack_scope();raise AssertionError('wrong CA accepted')
  except ValueError:pass
if kind=='receiver':
 j=Journal(c.directory,c.bot_id)
 try:other=Journal(c.directory,c.bot_id);other.close();raise AssertionError('second consumer accepted')
 except JournalError as e:assert str(e)=='journal_busy'
 receiver=TelegramReceiver(c.api,c.bridge,j,bot_id=c.bot_id,bot_username=c.bot_username,linking_enabled=c.linking_enabled,**({'acknowledgements_enabled':True,'ack_bridge':c.ack_bridge} if ack else {}))
 assert receiver.step()['status']=='polled'
print('CON02_CONTROLLER_SERVICE_READY',kind,'ACK' if ack else 'v1',flush=True)
if kind!='probe':signal.pause()
'''


class SystemdHost(FileHost):
    def https_snapshot(self):return {'verified':True,'synthetic_private_TLS':c.sha(self.control_paths()['link_ca'].read_bytes())}
    def service_active(self,n):return run(['systemctl','is-active',n],check=False).stdout.strip()=='active'
    def service_enabled(self,n):
        value=run(['systemctl','is-enabled',n],check=False).stdout.strip();assert value in ('enabled','disabled');return value=='enabled'
    def service(self,action,n):
        if action=='daemon-reload':run(['systemctl',action]);return
        if action=='start' and n=='ustar-connect-receiver.service' and getattr(self,'fail_once',False):
            write(PREFIX/'FAIL_RECEIVER',b'once',0o644);self.fail_once=False
        result=run(['systemctl',action,n],check=False)
        if result.returncode:
            if (PREFIX/'FAIL_RECEIVER').exists():
                assert run(['systemctl','show',n,'--value','-p','ExecMainStatus']).stdout.strip()=='77'
                (PREFIX/'FAIL_RECEIVER').unlink();self.actual_restart_failure=True
            raise c.Refusal('actual_fixture_service_failed')
        if action=='start' and n in c.SERVICES:
            end=time.monotonic()+15
            while time.monotonic()<end:
                if run(['systemctl','show',n,'--value','-p','ActiveState']).stdout.strip()=='failed':
                    code=run(['systemctl','show',n,'--value','-p','ExecMainStatus']).stdout.strip()
                    if (PREFIX/'FAIL_RECEIVER').exists():
                        assert code=='77';(PREFIX/'FAIL_RECEIVER').unlink();self.actual_restart_failure=True
                    raise c.Refusal('actual_fixture_service_failed')
                pid=run(['systemctl','show',n,'--value','-p','MainPID']).stdout.strip()
                logs=run(['journalctl','_PID='+pid,'--no-pager','-o','cat']).stdout if pid!='0' else ''
                if 'CON02_CONTROLLER_SERVICE_READY' in logs:return
                time.sleep(.1)
            raise c.Refusal('actual_fixture_service_not_ready')
    def probe(self,profile):run(['systemctl','start','ustar-connect-probe.service'])


def main():
    if (os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('USTAR_CONNECT_CON02_FIXTURE')!='1'
            or os.geteuid()!=0 or not Path('/run/systemd/system').is_dir() or Path('/opt/ustar').exists()
            or PREFIX.exists() or c.BASE.exists() or Path('/var/lib/ustar-connect-bridge').exists()
            or Path('/var/lib/ustar-connect-notify-pilot').exists()):raise RuntimeError('disposable_ci_only')
    if len(sys.argv)!=5:raise RuntimeError('plain_artifact_old_archive_pilot_and_link_bundles_required')
    bundle=artifact_bundle(sys.argv[1]);legacy=c.frozen_legacy(bundle)
    old_archive=Path(sys.argv[2]);old_commit,old_values=legacy['connect_install'].validate_archive(old_archive,c.sha(old_archive.read_bytes()))
    assert old_commit==c.LEGACY_COMMIT
    host=SystemdHost(bundle,Path('/'))
    assert not any(host.service_active(n) for n in c.SERVICES)
    previous_enabled={n:host.service_enabled(n) for n in c.SERVICES}
    PREFIX.mkdir(mode=0o755);private=PREFIX/'private';private.mkdir(mode=0o700)
    paths=host.control_paths();saved={n:state.capture(p) for n,p in paths.items()}
    moved={};overrides=[];server=None;ctx=None
    extra_paths=[Path('/usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py'),Path('/usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py')]
    extra_saved={p:state.capture(p) for p in extra_paths}
    state_dirs=(Path('/var/lib/ustar-connect-receiver'),Path('/var/lib/ustar-connect-notify'))
    for p in state_dirs:
        if p.exists():q=p.with_name(p.name+'-con02-fixture-saved');assert not q.exists();p.rename(q);moved[p]=q
    try:
        for n in ('ca','wrong'):
            run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=CON02 fixture '+n,
                 '-addext','subjectAltName=IP:127.0.0.1','-keyout',str(private/(n+'.key')),'-out',str(private/(n+'.crt'))])
        write(PREFIX/'wrong.crt',(private/'wrong.crt').read_bytes(),0o644)
        populate(bundle,Path('/'),host,old_values,(private/'ca.crt').read_bytes())
        write(PREFIX/'worker.py',WRAPPER,0o644)
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def do_POST(self):
                value={'ok':False,'reason':'invalid_transport'};code=403
                try:
                    assert self.client_address[0]=='127.0.0.1' and self.path=='/local/ustar/connect_bridge.php'
                    raw=self.rfile.read(int(self.headers['Content-Length']));assert len(raw)<=16384
                    kid=self.headers['X-Ustar-Key'];stamp=self.headers['X-Ustar-Time'];nonce=self.headers['X-Ustar-Nonce']
                    names={'tg_link_v1':('link_key',{'link.claim'}),'tg_notify_pilot_v1':(None,{'notification.lease','notification.begin','notification.finish'}),
                           'tg_notify_v2':('notify_key',{'notification.lease','notification.begin_v2','notification.finish'}),'tg_ack_v1':('ack_key',{'notification.ack'})}
                    name,ops=names[kid];key=paths[name].read_text().strip() if name else '22'*32
                    msg='\n'.join(['USTAR-CONNECT-BRIDGE-v1','POST',self.path,kid,stamp,nonce,hashlib.sha256(raw).hexdigest()]).encode()
                    assert abs(int(stamp)-int(time.time()))<=30 and hmac.compare_digest(hmac.new(bytes.fromhex(key),msg,hashlib.sha256).hexdigest(),self.headers['X-Ustar-Signature'])
                    op=json.loads(raw)['operation'];value={'ok':False,'reason':'forbidden_operation' if op not in ops else 'invalid_payload'}
                    code=403 if op not in ops else 422
                except (KeyError,ValueError,AssertionError,OSError):pass
                body=json.dumps(value,separators=(',',':')).encode();self.send_response(code);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        server=ThreadingHTTPServer(('127.0.0.1',18443),Handler)
        tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(private/'ca.crt',private/'ca.key');server.socket=tls.wrap_socket(server.socket,server_side=True)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        for n in ('receiver','probe','notify'):
            p=Path('/etc/systemd/system')/('ustar-connect-'+n+'.service.d/90-con02-controller-fixture.conf')
            assert not p.exists();overrides.append(p)
            user='www-data' if n!='notify' else 'ustar-connect-notify';profile='notifications' if n=='notify' else n
            write(p,'[Unit]\nStartLimitIntervalSec=0\n[Service]\nUser='+user+'\nGroup='+user+'\nExecStartPre=\nExecStart=\nExecStart=/usr/bin/python3 -I -u '+str(PREFIX/'worker.py')+' '+n+' %d/'+profile+'.json\nRestart=no\n',0o644)
        run(['systemctl','daemon-reload']);run(['systemctl','enable',c.SERVICES[1]]);run(['systemctl','disable',c.SERVICES[0]])
        host.service('start',c.SERVICES[1])
        dbpath=Path('/var/lib/ustar-connect-receiver/receiver.sqlite3')
        with sqlite3.connect(dbpath) as db:
            db.execute('UPDATE state SET next_offset=42')
            db.execute("INSERT INTO events(update_id,kind,state,createdat,reason) VALUES(41,'help','uncertain',?,'preserved-fixture')",(int(time.time()),))
        ctx,journal,locks=fixture_context(bundle,Path('/'),host);op=Runtime(ctx,journal,locks)
        cursors=[]
        def cursor():
            with sqlite3.connect('file:'+str(dbpath)+'?mode=ro',uri=True) as db:
                assert db.execute('PRAGMA user_version').fetchone()[0]==1
                assert db.execute('SELECT state FROM events WHERE update_id=41').fetchone()[0]=='uncertain'
                return db.execute('SELECT next_offset FROM state').fetchone()[0]
        for action in (op.enable,op.disable,op.enable):
            assert action()['phase'] in ('runtime_active','runtime_disabled');cursors.append(cursor())
        assert cursors==sorted(cursors) and cursors[0]>=43 and len(set(cursors))==3
        assert not host.service_active(c.SERVICES[0]) and not host.service_enabled(c.SERVICES[0])
        import http.client
        sys.path.insert(0,'/opt/ustar-connect/current/receiver.pyz')
        from ustar_connect.bridge import sign_request
        signed=sign_request('link.claim',{'probe':'negative'},key_id='tg_ack_v1',
            secret_hex=paths['ack_key'].read_text().strip(),now=int(time.time()))
        for peer,headers in [('127.0.0.2',signed.headers),('127.0.0.1',{**signed.headers,'X-Ustar-Signature':'0'*64})]:
            conn=http.client.HTTPSConnection('127.0.0.1',18443,context=ssl.create_default_context(cafile=str(private/'ca.crt')),
                source_address=(peer,0),timeout=5)
            conn.request('POST','/local/ustar/connect_bridge.php',signed.body,headers)
            response=conn.getresponse();assert response.status==403 and json.loads(response.read())['reason']=='invalid_transport';conn.close()
        legacy_denials(bundle,host,sys.argv[3:5])
        # Separate generation with an active synthetic sender proves its actual
        # credentials/v2 scope while preserving the prior enabled/active state.
        op.disable();host.service('enable',c.SERVICES[0]);host.service('start',c.SERVICES[0])
        assert op.enable()['phase']=='runtime_active' and host.service_active(c.SERVICES[0])
        assert op.disable()['phase']=='runtime_disabled'
        host.service('stop',c.SERVICES[0]);host.service('disable',c.SERVICES[0])
        op.disable();host.fail_once=True
        assert op.enable()['phase']=='runtime_disabled' and host.actual_restart_failure
        assert op.enable()['phase']=='runtime_active';op.disable()
        evidence={'result':'CON02_UPDATE_RUNTIME=PASS','controller':'new Runtime enable/disable/re-enable/fallback',
            'receiver_uid':33,'systemd_LoadCredential':True,'cursor':cursors,'schema':1,'uncertain_preserved':True,
            'disabled_sender_preserved':True,'actual_service_exit77_recovered':True,'private_TLS_and_scopes':True,
            'wrong_CA_denied':True,'root_and_foreign_credentials_denied':True,'single_consumer_lock':True,
            'wrong_peer_and_HMAC_denied':True,'active_sender_credentials_and_v2_scope':True,
            'legacy_refusals':['notification_policy_drift','activation_receiver_drift'],
            'application_source':c.APP_COMMIT,'application_SQL':'modeled here; actual native lifecycle in separate Task7 gate',
            'provider_and_bridge_store':'synthetic only; no external API request'}
        out=ROOT/'artifacts/con02-update-runtime.json';out.write_text(json.dumps(evidence,indent=2)+'\n');print('CON02_UPDATE_RUNTIME=PASS')
    finally:
        for n in (*c.SERVICES,'ustar-connect-probe.service'):run(['systemctl','stop',n],check=False)
        for p in overrides:p.unlink(missing_ok=True)
        for n,image in saved.items():
            p=Path(image['path'])
            if image['kind']=='absent':p.unlink(missing_ok=True)
            elif image['kind']=='symlink':
                if os.path.lexists(p):p.unlink()
                p.symlink_to(image['raw'].decode())
            else:state.replace(p,image['raw'],image['metadata'])
        for p in state_dirs:
            shutil.rmtree(p,ignore_errors=True)
            if p in moved:moved[p].rename(p)
        for p,image in extra_saved.items():
            if image['kind']=='absent':p.unlink(missing_ok=True)
            else:state.replace(p,image['raw'],image['metadata'])
        if ctx:
            for p in ctx.fixture_created_locks:p.unlink(missing_ok=True)
        for n,enabled in previous_enabled.items():run(['systemctl','enable' if enabled else 'disable',n],check=False)
        run(['systemctl','daemon-reload'],check=False)
        if server:server.shutdown();server.server_close()
        for p in (PREFIX,c.BASE,Path('/opt/ustar'),Path('/var/lib/ustar-connect-bridge'),Path('/var/lib/ustar-connect-notify-pilot')):
            shutil.rmtree(p,ignore_errors=True)


def legacy_denials(bundle,host,bundles):
    """Run exact frozen CLIs; expected handoff drift occurs before stop/managed write."""
    legacy=c.frozen_legacy(bundle)
    write('/usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py',(ROOT/'scripts/infra/academy_tls_probe.py').read_bytes())
    assert c.sha(Path('/usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py').read_bytes())=='7b5fd3a62c3b9d9c72bcaf513d96777bf0a00926bcba91ec3ac1740896c78bd7'
    write('/usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py',bundle.legacy['scripts/infra/academy_https.py'])
    write('/opt/ustar/data/moodle/public/config.php',b'<?php // synthetic unchanged HTTPS config\n',0o640,33)
    def record(path):
        image=state.capture(path)
        return None if image['kind']=='absent' else {'sha':c.sha(image['raw']),**image['metadata']}
    paths=host.control_paths()
    lpaths={'tool':Path('/usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py'),'config':Path('/opt/ustar/data/moodle/public/config.php'),
        'receiver':paths['receiver'],'probe':paths['probe'],'key':paths['link_key'],'ca':paths['link_ca'],'include':paths['include'],
        'receiver_dropin':paths['receiver_link_dropin'],'probe_dropin':paths['probe_link_dropin']}
    baseline=baseline_profiles(bundle)
    files={n:{'before':record(p),'after':record(p)} for n,p in lpaths.items()}
    for n in ('receiver','probe'):
        files[n]['after']={**files[n]['after'],'sha':c.sha(baseline[n])};files[n]['before']=files[n]['after']
    link={'schema':1,'phase':'active','source_sha':c.sha(bundle.legacy['scripts/infra/academy_https.py']),
        'old_release':c.LEGACY_COMMIT,'new_release':c.LEGACY_COMMIT,'files':files}
    write('/var/lib/ustar-connect-bridge/state.json',(json.dumps(link)+'\n').encode())
    pilot_names=('policy','include','include_image','link_state','notifications','key','ca','dropin')
    policy=Path('/opt/ustar/data/moodle/public/public/local/ustar/classes/connect/notification_delivery.php')
    policy_meta={**record(policy),'sha':c.OVERLAY_SHA}
    pilot={'schema':1,'phase':'active','tool_commit':c.LEGACY_COMMIT,'userid':130,
           'old_release':c.LEGACY_COMMIT,'new_release':c.LEGACY_COMMIT,
           'files':{n:{'before':policy_meta,'after':policy_meta} if n=='policy' else {'before':None,'after':None} for n in pilot_names}}
    write('/var/lib/ustar-connect-notify-pilot/state.json',(json.dumps(pilot)+'\n').encode())
    # read_private requires the accepted private state directories, not fixture defaults.
    for p in (Path('/var/lib/ustar-connect-bridge'),Path('/var/lib/ustar-connect-notify-pilot')):p.chmod(0o700)
    before={n:state.descriptor(state.capture(p)) for n,p in paths.items()}
    pid=run(['systemctl','show',c.SERVICES[1],'--value','-p','MainPID']).stdout
    import tempfile
    with tempfile.TemporaryDirectory(prefix='ustar-con02-frozen-',dir='/root') as td:
        for source,reason,args in [(bundles[0],'notification_policy_drift',['--activate','--pilot-userid','130']),
                                   (bundles[1],'activation_receiver_drift',['--activate'])]:
            p=Path(td)/Path(source).name;write(p,Path(source).read_bytes())
            result=run(['/usr/bin/python3','-I','-B',str(p),*args],check=False)
            value=json.loads(result.stdout.splitlines()[-1]);assert result.returncode==2 and value['reason']==reason,value
    assert before=={n:state.descriptor(state.capture(p)) for n,p in paths.items()}
    assert pid==run(['systemctl','show',c.SERVICES[1],'--value','-p','MainPID']).stdout


if __name__=='__main__':main()
