#!/usr/bin/env python3
"""Disposable real systemd/UID33/credentials/TLS; synthetic provider and bridge store.

Actual Moodle ACK dispatch/races live in con02_callback_wire.py/concurrency.php.
This fixture never calls Telegram, never reads production credentials, and is CI-only.
"""
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import io
import json
import os
from pathlib import Path
import pwd
import shutil
import sqlite3
import ssl
import subprocess
import sys
import tarfile
import threading
import time

ROOT=Path(__file__).resolve().parents[2]
PREFIX=Path('/usr/local/lib/ustar-connect-ack-fixture')
STATE=Path('/var/lib/ustar-connect-ack-fixture')
ACTION='ua1_'+'A'*43
KEYS={'tg_ack':('33'*32,{'notification.ack'}),'tg_v2':('22'*32,{'notification.lease','notification.begin_v2','notification.finish'})}


def call(args):
    p=subprocess.run(args,capture_output=True,text=True,timeout=60)
    if p.returncode:
        detail=''
        if args[:2]==['systemctl','start']:
            detail=subprocess.run(['journalctl','-u',args[2],'-n','35','--no-pager','-o','cat'],capture_output=True,text=True,timeout=10).stdout
        raise RuntimeError('callback_fixture_command_failed '+str(args[:3])+' '+p.stdout+p.stderr+detail)
    return p.stdout


WRAPPER=r'''import io,json,os,signal,sys,time
from pathlib import Path
sys.path.insert(0,sys.argv[1])
from ustar_connect.receiver_runtime import load_config
from ustar_connect.receiver_journal import Journal,JournalError
from ustar_connect.receiver import TelegramReceiver,verify_bot
phase,profile=sys.argv[2:4]
sender=phase=='sender'
c=load_config(profile,notification=sender)
assert os.geteuid() != 0
assert os.geteuid()==33 if not sender else os.geteuid()!=33
for source in ('telegram.token','ack.key','sender.key'):
 try:open('/usr/local/lib/ustar-connect-ack-fixture/private/'+source).read();raise AssertionError('root credential readable')
 except PermissionError:pass
foreign='/run/credentials/ustar-connect-receiver.service/ack-bridge.key' if sender else '/run/credentials/ustar-connect-notify.service/bridge.key'
try:open(foreign).read();raise AssertionError('foreign credential readable')
except (PermissionError,FileNotFoundError):pass
class Response(io.BytesIO):
 def __enter__(self):return self
 def __exit__(self,*args):self.close()
class API:
 sent=0;answers=0;edits=0
 def open(self,request,timeout):
  method=request.full_url.rsplit('/',1)[-1];p=json.loads(request.data)
  if method=='getMe':value={'id':123456,'is_bot':True,'username':'ustarcorp_bot'}
  elif method=='getWebhookInfo':value={'url':''}
  elif method=='getUpdates':
   assert p['allowed_updates']==(['message','callback_query'] if phase=='candidate' else ['message'])
   uid={'old':100,'candidate':101,'disabled':102,'rollback':103}[phase]
   assert p['offset']==(0 if phase=='old' else uid)
   if phase=='old':
    value=[{'update_id':uid,'message':{'date':int(time.time()),'text':'/help','from':{'id':77777,'is_bot':False},
     'chat':{'id':77777,'type':'private'},'entities':[{'offset':0,'length':5,'type':'bot_command'}]}}]
   else:
    value=[{'update_id':uid,'callback_query':{'id':'fixture-query-'+str(uid),'chat_instance':'fixture',
     'data':'ua1_'+'A'*43,'from':{'id':77777,'is_bot':False},'message':{'message_id':992,'date':int(time.time())-3600,
      'from':{'id':123456,'is_bot':True},'chat':{'id':77777,'type':'private'}}}}]
  elif method=='sendMessage':
   self.sent+=1
   if sender:
    rows=p['reply_markup']['inline_keyboard'];assert rows[0][0]['callback_data']=='ua1_'+'A'*43
    assert rows[1][0]['url']=='https://ustar.local/local/ustar/tasks.php'
    assert 'PRIVATE' not in p['text']
   value={'message_id':992,'chat':{'id':p['chat_id'],'type':'private'}}
  elif method=='answerCallbackQuery':
   assert phase=='candidate' and timeout==3 and p['cache_time']==0
   assert p['text'].startswith('Подтверждено · ') and p['text'].endswith(' UTC');self.answers+=1;value=True
  elif method=='editMessageReplyMarkup':
   assert phase=='candidate' and timeout==3 and p['chat_id']==77777 and p['message_id']==992
   rows=p['reply_markup']['inline_keyboard'];assert len(rows)==1 and 'url' in rows[0][0]
   self.edits+=1;value={'message_id':992,'chat':{'id':77777,'type':'private'}}
  else:raise AssertionError('unexpected provider method')
  return Response(json.dumps({'ok':True,'result':value}).encode())
api=API();c.api._opener=api
verify_bot(c.api,c.bot_id,c.bot_username)
if sender:
 from ustar_connect.notification_worker import NotificationWorker
 c.bridge.probe_notification_scope(2)
 w=NotificationWorker(c.api,c.bridge,bot_id=c.bot_id,bot_username=c.bot_username,
  allowed_subjects=c.notification_subjects,notification_contract=c.notification_contract)
 assert w.step()=={'status':'provider_accepted'} and api.sent==1
 print('CALLBACK_SENDER_CREDENTIAL_TLS=PASS',flush=True)
else:
 if phase=='candidate':
  c.ack_bridge.probe_ack_scope()
  from ustar_connect.bridge import BridgeClient
  wrong=BridgeClient(c.ack_bridge._endpoint,'tg_ack','33'*32,ca_file=sys.argv[4])
  try:wrong.probe_ack_scope();raise AssertionError('wrong CA accepted')
  except ValueError:pass
 j=Journal(c.directory,c.bot_id)
 try:
  try:other=Journal(c.directory,c.bot_id);other.close();raise AssertionError('second consumer accepted')
  except JournalError as e:assert str(e)=='journal_busy'
  kw={'acknowledgements_enabled':True,'ack_bridge':c.ack_bridge} if phase=='candidate' else {}
  receiver=TelegramReceiver(c.api,None,j,bot_id=c.bot_id,bot_username=c.bot_username,**kw)
  assert receiver.step()['status']=='polled'
  assert j.health()['next_offset']=={'old':101,'candidate':102,'disabled':103,'rollback':104}[phase]
  if phase=='candidate':assert api.answers==1 and api.edits==1
  else:assert api.answers==0 and api.edits==0
 finally:j.close()
 print('CALLBACK_PHASE_'+phase.upper()+'=PASS',flush=True)
 signal.pause()
'''


def main():
    if (os.environ.get('USTAR_CONNECT_ACK_FIXTURE')!='1' or os.environ.get('GITHUB_ACTIONS')!='true'
            or os.geteuid()!=0 or not Path('/run/systemd/system').is_dir() or Path('/opt/ustar/data').exists()
            or PREFIX.exists() or STATE.exists()):
        raise RuntimeError('disposable_ci_systemd_required')
    compile(WRAPPER,'callback-fixture','exec')
    PREFIX.mkdir(mode=0o755);private=PREFIX/'private';private.mkdir(mode=0o700)
    def write(path,data,mode=0o600):
        path.write_bytes(data if isinstance(data,bytes) else data.encode());path.chmod(mode)
    spec=importlib.util.spec_from_file_location('callback_builder',ROOT/'connect-worker/deploy/build_release.py')
    builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
    for phase,ref in [('old','a86e208029b6104150e85d0e90384f84da214c22'),('new','HEAD')]:
        archive=builder.build(ref,private/phase)
        with tarfile.open(archive,'r:gz') as t:write(PREFIX/(phase+'.pyz'),t.extractfile('receiver.pyz').read(),0o644)
    write(PREFIX/'wrapper.py',WRAPPER,0o644)
    for name in ('ca','wrong'):
        call(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=Disposable ACK '+name,
              '-addext','subjectAltName=IP:127.0.0.1','-keyout',str(private/(name+'.key')),'-out',str(private/(name+'.crt'))])
    write(PREFIX/'wrong-ca.crt',(private/'wrong.crt').read_bytes(),0o644)
    write(private/'telegram.token','123456:synthetic-fixture-not-a-real-token')
    write(private/'ack.key','33'*32);write(private/'sender.key','22'*32)
    record={'ackat':0,'acks':0,'begins':0,'finishes':0,'nonces':set(),'peers':set()}
    class Handler(BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def do_POST(self):
            code=403;value={'ok':False,'reason':'invalid_transport'}
            try:
                assert self.client_address[0]=='127.0.0.1' and self.path=='/local/ustar/connect_bridge.php'
                raw=self.rfile.read(int(self.headers['Content-Length']));assert len(raw)<=16384
                kid=self.headers['X-Ustar-Key'];stamp=self.headers['X-Ustar-Time'];nonce=self.headers['X-Ustar-Nonce']
                secret,ops=KEYS[kid]
                message='\n'.join(['USTAR-CONNECT-BRIDGE-v1','POST',self.path,kid,stamp,nonce,hashlib.sha256(raw).hexdigest()]).encode()
                assert abs(int(stamp)-int(time.time()))<=30 and hmac.compare_digest(
                    hmac.new(bytes.fromhex(secret),message,hashlib.sha256).hexdigest(),self.headers['X-Ustar-Signature'])
                request=json.loads(raw);op=request['operation'];p=request['payload'];record['peers'].add(self.client_address[0])
                if op not in ops:value={'ok':False,'reason':'forbidden_operation'}
                else:
                    assert nonce not in record['nonces'];record['nonces'].add(nonce)
                    if op=='notification.lease':result={'delivery':1,'lease':'ud1_'+'A'*43,'expires':int(time.time())+60}
                    elif op=='notification.begin_v2':
                        record['begins']+=1;now=int(time.time())
                        result={'status':'sending','subject':'77777','category':'tasks','severity':'normal','occurred_at':now,
                                'send_before':now+10,'version':2,'ack':{'action':ACTION,'expires':now+3600}}
                    elif op=='notification.finish':
                        assert p['outcome']=='provider_accepted' and p['provider_ref']=='992'
                        record['finishes']+=1;result={'status':'provider_accepted'}
                    elif op=='notification.ack':
                        assert p=={'action':ACTION,'message_id':'992','provider':'telegram','subject':'77777','tenant':'123456'}
                        record['acks']+=1;record['ackat']=record['ackat'] or int(time.time())
                        result={'status':'acknowledged','acknowledged_at':record['ackat'],'category':'tasks'}
                    code=200;value={'ok':True,'result':result}
            except (AssertionError,KeyError,ValueError,TypeError):pass
            body=json.dumps(value,separators=(',',':')).encode();self.send_response(code)
            self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(private/'ca.crt',private/'ca.key')
    server.socket=tls.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    endpoint='https://127.0.0.1:'+str(server.server_port)+'/local/ustar/connect_bridge.php'
    # Real loopback peer denial with a valid TLS chain, separately from HMAC denial.
    import http.client
    sys.path.insert(0,str(PREFIX/'new.pyz'))
    from ustar_connect.bridge import sign_request
    signed=sign_request('link.claim',{'probe':'peer-denial'},key_id='tg_ack',secret_hex='33'*32,now=int(time.time()))
    for source,headers in [(('127.0.0.2',0),signed.headers),(('127.0.0.1',0),{**signed.headers,'X-Ustar-Signature':'0'*64})]:
        conn=http.client.HTTPSConnection('127.0.0.1',server.server_port,context=ssl.create_default_context(cafile=str(private/'ca.crt')),
                                         source_address=source,timeout=5)
        conn.request('POST','/local/ustar/connect_bridge.php',signed.body,headers)
        response=conn.getresponse()
        assert response.status==403 and json.loads(response.read())=={'ok':False,'reason':'invalid_transport'}
        conn.close()
    units=['ustar-connect-receiver.service' ,'ustar-connect-notify.service'];dropins=[]
    def configure(phase):
        sender=phase=='sender';unit=units[1 if sender else 0];mount='/run/credentials/'+unit+'/'
        c={'bot_token_file':mount+'telegram.token','bot_username':'ustarcorp_bot',
           'state_directory':'/var/lib/ustar-connect-ack-sender-fixture' if sender else str(STATE),
           'telegram_proxy':'http://127.0.0.1:18081','onboarding_enabled':False,'linking_enabled':False,
           'bridge_endpoint':endpoint,'bridge_key_id':'tg_v2' if sender else 'tg_link_unused',
           'bridge_key_file':mount+'bridge.key','bridge_ca_file':mount+'bridge-ca.crt'}
        if sender:c.update(notification_subjects=['77777'],notification_contract=2)
        if phase=='candidate':c.update(acknowledgements_enabled=True,ack_bridge_key_id='tg_ack',ack_bridge_key_file=mount+'ack-bridge.key')
        profile=private/(phase+'.json');write(profile,json.dumps(c))
        sources={'profile.json':profile,'telegram.token':private/'telegram.token','bridge-ca.crt':private/'ca.crt'}
        if sender:sources['bridge.key']=private/'sender.key'
        if phase=='candidate':sources['ack-bridge.key']=private/'ack.key'
        dropin=Path('/etc/systemd/system')/(unit+'.d')/'99-con02-ack-fixture.conf'
        if dropin not in dropins:
            if dropin.exists():raise RuntimeError('fixture_dropin_exists')
            dropin.parent.mkdir(exist_ok=True);dropins.append(dropin)
        pyz=PREFIX/('old.pyz' if phase in ('old','rollback') else 'new.pyz')
        data='[Unit]\nConditionPathExists=\n[Service]\nUser='+('ustar-connect-notify' if sender else 'www-data')+'\nGroup='+('ustar-connect-notify' if sender else 'www-data')+'\nLoadCredential=\n'
        data+=''.join('LoadCredential='+n+':'+str(p)+'\n' for n,p in sources.items())
        data+='StateDirectory=\nStateDirectory='+Path(c['state_directory']).name+'\nExecStart=\nExecStart=/usr/bin/python3 -I -u '+str(PREFIX/'wrapper.py')+' '+str(pyz)+' '+phase+' %d/profile.json '+str(PREFIX/'wrong-ca.crt')+'\nRestart=no\n'
        write(dropin,data,0o644);call(['systemctl','daemon-reload']);call(['systemctl','reset-failed',unit]);call(['systemctl','start',unit])
        marker='CALLBACK_SENDER_CREDENTIAL_TLS=PASS' if sender else 'CALLBACK_PHASE_'+phase.upper()+'=PASS'
        end=time.monotonic()+20
        while time.monotonic()<end:
            logs=call(['journalctl','-u',unit,'--no-pager','-o','cat'])
            if marker in logs:break
            status=call(['systemctl','show',unit,'-p','ActiveState','--value']).strip()
            if status=='failed':raise RuntimeError('callback_service_failed '+logs)
            time.sleep(.1)
        else:raise RuntimeError('callback_service_marker_missing '+logs)
        call(['systemctl','stop',unit])
        assert ACTION not in logs and '33'*32 not in logs and 'synthetic-fixture-not-a-real-token' not in logs
    try:
        for unit in units:call(['systemctl','stop',unit])
        configure('old');configure('sender');configure('candidate');configure('disabled');configure('rollback')
        with sqlite3.connect('file:'+str(STATE/'receiver.sqlite3')+'?mode=ro',uri=True) as db:
            assert db.execute('PRAGMA user_version').fetchone()[0]==1
            assert db.execute('SELECT next_offset FROM state').fetchone()[0]==104
            rows=db.execute('SELECT update_id,kind,state FROM events ORDER BY update_id').fetchall()
            assert len(rows)==4 and rows[1]==(101,'ack','sent')
        assert STATE.stat().st_uid==33 and STATE.stat().st_mode&0o777==0o700
        assert record['acks']==record['begins']==record['finishes']==1 and record['peers']=={'127.0.0.1'}
        evidence={'result':'CON02_CALLBACK_RUNTIME=PASS','receiver_uid':33,'schema':1,'cursor':104,
                  'handoff':['a86','v2_sender','candidate_ACK','disabled','a86'],
                  'private_TLS_verified':True,'wrong_CA_denied':True,'wrong_peer_and_HMAC_denied':True,'separate_credentials':True,
                  'single_journal_lock':True,'provider':'synthetic_only','bridge_store':'synthetic; actual Moodle in separate DB job',
                  'ACK_calls':1,'sender_begin_v2':1,'sender_finish':1,'root_credentials_unreadable':True}
        out=ROOT/'artifacts/con02-callback-runtime.json';out.parent.mkdir(exist_ok=True);out.write_text(json.dumps(evidence,indent=2)+'\n')
        print('CON02_CALLBACK_RUNTIME=PASS')
    finally:
        for unit in units:subprocess.run(['systemctl','stop',unit],capture_output=True,timeout=20)
        for p in dropins:p.unlink(missing_ok=True)
        call(['systemctl','daemon-reload']);server.shutdown();server.server_close();thread.join(timeout=5)
        shutil.rmtree(PREFIX)

if __name__=='__main__':main()
