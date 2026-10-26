#!/usr/bin/env python3
"""Actual disposable Ubuntu systemd/UID/credentials/package checks. No live provider API."""
import hashlib,importlib.util,io,json,os,subprocess,sys,tarfile,tempfile,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]

def call(args):
    result=subprocess.run(args,capture_output=True,text=True,timeout=90)
    if result.returncode:
        evidence=''
        if args[:2]==['systemctl','start']:
            evidence=subprocess.run(['journalctl','-u',args[2],'--no-pager','-n','80','-o','cat'],capture_output=True,text=True,timeout=10).stdout
        raise RuntimeError('native_command_failed: '+str(args[:3])+'\n'+result.stdout+result.stderr+evidence)
    return result.stdout

def main(archive):
    if os.geteuid()!=0 or not Path('/run/systemd/system').is_dir():raise RuntimeError('ROOT_SYSTEMD_REQUIRED')
    spec=importlib.util.spec_from_file_location('connect_install',ROOT/'connect-worker/deploy/install.py')
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
    archive=Path(archive).resolve();digest=hashlib.sha256(archive.read_bytes()).hexdigest()
    inst=mod.Installation();sha=inst.install(archive,digest)
    assert inst.current().name==sha
    assert not inst.host.active('ustar-connect-receiver.service')
    enabled=subprocess.run(['systemctl','is-enabled','ustar-connect-notify.service'],capture_output=True,text=True)
    assert enabled.stdout.strip()=='disabled'
    # Idempotent install; then real package pointer update and rollback, cursor files untouched.
    assert inst.install(archive,digest)==sha
    with tempfile.TemporaryDirectory(prefix='connect-native-') as td:
        _,files=mod.validate_archive(archive,digest);other='f'*40
        files['SOURCE_COMMIT']=(other+'\n').encode()
        manifest={'schema':1,'source_commit':other,'files':{n:hashlib.sha256(v).hexdigest() for n,v in files.items()}}
        second=Path(td)/'second.tar.gz'
        with tarfile.open(second,'w:gz') as tar:
            for name,value in {**files,'manifest.json':json.dumps(manifest).encode()}.items():
                info=tarfile.TarInfo(name);info.size=len(value);info.mode=0o644;tar.addfile(info,io.BytesIO(value))
        inst.install(second,hashlib.sha256(second.read_bytes()).hexdigest());assert inst.current().name==other
        assert inst.rollback()==sha
    token='123456:'+'synthetic-fixture-not-a-real-token'
    inst.configure(token)
    for n in ('telegram.token','receiver.json','probe.json'):
        assert (inst.config/n).stat().st_mode & 0o777==0o600
    fixture=Path('/opt/ustar-connect-native-fixture.py')
    fixture.write_text('''import io,json,os,signal,sys,time
sys.path.insert(0,"/opt/ustar-connect/current/receiver.pyz")
from ustar_connect.receiver_runtime import load_config,main
from ustar_connect.receiver_journal import Journal
from ustar_connect.receiver import TelegramReceiver
try:config=load_config(sys.argv[1])
except Exception:
 import pathlib,stat
 for text in (sys.argv[1],"/run/credentials/ustar-connect-probe.service/telegram.token","/var/lib/ustar-connect-receiver"):
  try:
   path=pathlib.Path(text);st=path.stat();print("FIXTURE_METADATA",text,st.st_uid,oct(stat.S_IMODE(st.st_mode)),st.st_nlink,flush=True)
   for parent in path.parents:
    st=parent.stat();print("FIXTURE_PARENT",str(parent),st.st_uid,oct(stat.S_IMODE(st.st_mode)),flush=True)
  except Exception as error:print("FIXTURE_PATH_ERROR",type(error).__name__,flush=True)
 raise
assert os.geteuid()!=0
assert config.onboarding_enabled is False and config.bridge is None
assert str(config.directory)=="/var/lib/ustar-connect-receiver"
try:open("/etc/ustar-connect/telegram.token").read();raise AssertionError("root credential readable")
except PermissionError:pass
class Response(io.BytesIO):
 def __enter__(self):return self
 def __exit__(self,*args):self.close()
class API:
 def __init__(self):self.sent=0
 def open(self,request,timeout):
  method=request.full_url.rsplit("/",1)[-1];payload=json.loads(request.data)
  if method=="getMe":result={"id":123456,"is_bot":True,"username":"ustarcorp_bot"}
  elif method=="getWebhookInfo":result={"url":""}
  elif method=="getUpdates":
   result=[]
   for i,verb in enumerate(("/start","/academy","/help"),100):
    result.append({"update_id":i,"message":{"date":int(time.time()),"text":verb,
     "from":{"id":i+42,"is_bot":False},"chat":{"id":i+42,"type":"private"},
     "entities":[{"offset":0,"length":len(verb),"type":"bot_command"}]}})
  elif method=="sendMessage":
   assert "Mini App пока не подключён" in payload["text"] and "reply_markup" not in payload
   self.sent+=1;result={"message_id":self.sent,"chat":{"id":payload["chat_id"],"type":"private"}}
  else:raise AssertionError("unexpected API")
  return Response(json.dumps({"ok":True,"result":result}).encode())
api=API();config.api._opener=api
if "probe" in sys.argv[1]:
 import ustar_connect.receiver_runtime as runtime
 runtime.load_config=lambda path:config
 assert main(["--config",sys.argv[1],"--probe"])==0
 print("CONNECT_NATIVE_PROBE=PASS")
else:
 journal=Journal(config.directory,config.bot_id)
 receiver=TelegramReceiver(config.api,None,journal,bot_id=config.bot_id,bot_username=config.bot_username,onboarding_enabled=False)
 assert receiver.step()["status"]=="polled"
 assert api.sent==3 and journal.health()["next_offset"]==103
 journal.close();print("CONNECT_NATIVE_RECEIVER=PASS",flush=True);signal.pause()
''')
    fixture.chmod(0o644)
    # Install a fully manifested synthetic test release, rather than unmanaged
    # drop-ins. Exercise the public admin CLI, including synchronous probe/start.
    _,fixturefiles=mod.validate_archive(archive,digest);fixturecommit='e'*40
    fixturefiles['SOURCE_COMMIT']=(fixturecommit+'\n').encode()
    for n in ('probe','receiver'):
        name='units/ustar-connect-'+n+'.service'
        text=fixturefiles[name].decode()
        lines=[('ExecStart=/usr/bin/python3 -I -u '+str(fixture)+' %d/'+n+'.json')
               if line.startswith('ExecStart=') else line for line in text.splitlines()]
        fixturefiles[name]=('\n'.join(lines)+'\n').encode()
    with tempfile.TemporaryDirectory(prefix='connect-cli-native-') as td:
        testarchive=Path(td)/'fixture.tar.gz'
        manifest={'schema':1,'source_commit':fixturecommit,'files':{
            n:hashlib.sha256(v).hexdigest() for n,v in fixturefiles.items()}}
        with tarfile.open(testarchive,'w:gz') as tar:
            for name,value in {**fixturefiles,'manifest.json':json.dumps(manifest).encode()}.items():
                info=tarfile.TarInfo(name);info.size=len(value);info.mode=0o644
                tar.addfile(info,io.BytesIO(value))
        assert inst.install(testarchive,hashlib.sha256(testarchive.read_bytes()).hexdigest())==fixturecommit
    admin=['/usr/bin/python3','-I','/opt/ustar-connect/current/admin.pyz']
    assert json.loads(call(admin+['probe']))['status']=='probe_complete'
    assert json.loads(call(admin+['start']))['status']=='start_complete'
    assert inst.host.active('ustar-connect-receiver.service')
    for _ in range(50):
        logs=call(['journalctl','-u','ustar-connect-receiver.service','--no-pager','-o','cat'])
        if 'CONNECT_NATIVE_RECEIVER=PASS' in logs:break
        time.sleep(.1)
    else:raise AssertionError('receiver fixture did not finish private command checks')
    assert json.loads(call(admin+['stop']))['status']=='stop_complete'
    logs=call(['journalctl','-u','ustar-connect-probe.service','-u','ustar-connect-receiver.service','--no-pager','-o','cat'])
    assert 'CONNECT_NATIVE_PROBE=PASS' in logs and 'CONNECT_NATIVE_RECEIVER=PASS' in logs,logs
    assert token not in logs
    # Credential source isolation, actual state UID/mode and persistent cursor survive process exit.
    import pwd,sqlite3
    state=Path('/var/lib/ustar-connect-receiver');assert state.stat().st_uid==pwd.getpwnam('ustar-connect').pw_uid
    assert state.stat().st_mode&0o777==0o700
    with sqlite3.connect('file:'+str(state/'receiver.sqlite3')+'?mode=ro',uri=True) as db:
        assert db.execute('select next_offset from state').fetchone()[0]==103
    assert inst.rollback()==sha
    assert inst.install(archive,digest)==sha
    with sqlite3.connect('file:'+str(state/'receiver.sqlite3')+'?mode=ro',uri=True) as db:
        assert db.execute('select next_offset from state').fetchone()[0]==103
    scoped_credentials(inst,mod,archive,digest,fixture,sha)
    print('CONNECT_HOST_ACCEPTANCE=PASS install/reinstall/update/rollback/CLI-probe-start-stop/LoadCredential/UID/SQLite/offline-notice; no live Telegram')


def scoped_credentials(inst,mod,archive,digest,fixture,original):
    """Actual systemd credential mounts/UID/network policy, synthetic API opener.

    No production drop-in exception is added: the test release includes its exact
    extra LoadCredential directives in its verified manifest, like the earlier fixture.
    Native TLS/PHP signature checks are exercised separately by the Apache gate.
    """
    state=Path('/var/lib/ustar-connect-receiver/receiver.sqlite3')
    cursor_before=hashlib.sha256(state.read_bytes()).hexdigest()
    token_before=hashlib.sha256((inst.config/'telegram.token').read_bytes()).hexdigest()
    configs={n:(inst.config/(n+'.json')).read_bytes() for n in ('receiver','probe')}
    key=inst.config/'bridge.key';ca=inst.config/'bridge.ca'
    mod.write_file(key,b'12'*32,0o600)
    call(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=Disposable scoped credential fixture',
          '-keyout',str(inst.config/'fixture-ca.key'),'-out',str(ca)])
    ca.chmod(0o600)
    text=fixture.read_text().replace('assert config.onboarding_enabled is False and config.bridge is None',
        'assert config.onboarding_enabled is False')
    text=text.replace('class Response(io.BytesIO):','''if config.linking_enabled:
 assert config.bridge is not None
 assert os.environ['CREDENTIALS_DIRECTORY']==os.path.dirname(sys.argv[1])
 for source in ('/etc/ustar-connect/bridge.key','/etc/ustar-connect/bridge.ca'):
  try:open(source).read();raise AssertionError('root bridge source readable')
  except PermissionError:pass
class Response(io.BytesIO):''')
    text=text.replace('  method=request.full_url.rsplit("/",1)[-1];payload=json.loads(request.data)', '''  if request.full_url.endswith('/local/ustar/connect_bridge.php'):
   import hashlib,hmac
   from urllib.error import HTTPError
   headers={k.lower():v for k,v in request.header_items()}
   assert json.loads(request.data)=={'operation':'registration.options','payload':{'probe':'link-scope-v1'}}
   assert headers['x-ustar-key']=='tg_link'
   message='\\n'.join(['USTAR-CONNECT-BRIDGE-v1','POST','/local/ustar/connect_bridge.php',
    headers['x-ustar-key'],headers['x-ustar-time'],headers['x-ustar-nonce'],hashlib.sha256(request.data).hexdigest()]).encode()
   expected=hmac.new(bytes.fromhex('12'*32),message,hashlib.sha256).hexdigest()
   reason='forbidden_operation' if hmac.compare_digest(expected,headers['x-ustar-signature']) else 'invalid_transport'
   raise HTTPError(request.full_url,403,'fixture',{},io.BytesIO(('{'+'"ok":false,"reason":"'+reason+'"}').encode()))
  method=request.full_url.rsplit("/",1)[-1];payload=json.loads(request.data)''')
    text=text.replace('api=API();config.api._opener=api',
        'api=API();config.api._opener=api\nif config.bridge is not None:config.bridge._opener=api')
    text=text.replace('if "probe" in sys.argv[1]:','if config.linking_enabled or "probe" in sys.argv[1]:')
    text=text.replace(' print("CONNECT_NATIVE_PROBE=PASS")',
        ' print("CONNECT_NATIVE_LINK_SCOPE=PASS",flush=True)\n if "probe" not in sys.argv[1]:signal.pause()')
    compile(text,str(fixture),'exec');fixture.write_text(text)
    _,files=mod.validate_archive(archive,digest);commit='d'*40
    files['SOURCE_COMMIT']=(commit+'\n').encode()
    for n in ('receiver','probe'):
        path='units/ustar-connect-'+n+'.service'
        unit=files[path].decode()
        unit=unit.replace('ExecStart=',
            'LoadCredential=bridge.key:/etc/ustar-connect/bridge.key\nLoadCredential=bridge.ca:/etc/ustar-connect/bridge.ca\nExecStart=')
        unit='\n'.join(('ExecStart=/usr/bin/python3 -I -u '+str(fixture)+' %d/'+n+'.json')
            if line.startswith('ExecStart=') else line for line in unit.splitlines())+'\n'
        files[path]=unit.encode()
        config=json.loads(configs[n]);directory='/run/credentials/ustar-connect-'+n+'.service/'
        config.update(linking_enabled=True,bridge_endpoint='https://127.0.0.1:18443/local/ustar/connect_bridge.php',
            bridge_key_id='tg_link',bridge_key_file=directory+'bridge.key',bridge_ca_file=directory+'bridge.ca')
        mod.write_file(inst.config/(n+'.json'),json.dumps(config).encode(),0o600)
    with tempfile.TemporaryDirectory(prefix='connect-link-credentials-') as td:
        package=Path(td)/'fixture.tar.gz'
        manifest={'schema':1,'source_commit':commit,'files':{n:hashlib.sha256(v).hexdigest() for n,v in files.items()}}
        with tarfile.open(package,'w:gz') as tar:
            for name,value in {**files,'manifest.json':json.dumps(manifest).encode()}.items():
                info=tarfile.TarInfo(name);info.size=len(value);info.mode=0o644;tar.addfile(info,io.BytesIO(value))
        inst.install(package,hashlib.sha256(package.read_bytes()).hexdigest())
    inst.start()
    for _ in range(40):
        logs=call(['journalctl','-u','ustar-connect-receiver.service','--no-pager','-o','cat'])
        if 'CONNECT_NATIVE_LINK_SCOPE=PASS' in logs:break
        time.sleep(.1)
    else:raise AssertionError('receiver credential scope probe missing')
    inst.stop()
    mod.write_file(key,b'34'*32,0o600)
    try:inst.start()
    except RuntimeError:pass
    else:raise AssertionError('WRONG_KEY_SCOPE_PROBE_ACCEPTED')
    assert not inst.host.active('ustar-connect-receiver.service')
    logs=call(['journalctl','-u','ustar-connect-probe.service','-u','ustar-connect-receiver.service','--no-pager','-o','cat'])
    assert '12'*32 not in logs and '34'*32 not in logs
    assert cursor_before==hashlib.sha256(state.read_bytes()).hexdigest()
    assert token_before==hashlib.sha256((inst.config/'telegram.token').read_bytes()).hexdigest()
    for n,raw in configs.items():mod.write_file(inst.config/(n+'.json'),raw,0o600)
    assert inst.install(archive,digest)==original
    print('CONNECT_SCOPED_CREDENTIALS=PASS real systemd mounts/ACL/UID/loopback units, signed synthetic denial, wrong key fails, token and SQLite unchanged')

if __name__=='__main__':
    if os.environ.get('USTAR_CONNECT_HOST_FIXTURE') != '1' or not Path('/home/runner/work').is_dir():
        raise RuntimeError('DISPOSABLE_GITHUB_RUNNER_REQUIRED')
    # Hosted runners expose /opt to the runner user. Production correctly refuses that ancestor.
    # Establish and restore root-owned test prerequisites ONLY on this disposable CI host.
    parent=Path('/opt');before=parent.stat()
    print('CI_OPT_PREREQUISITE',before.st_uid,before.st_gid,oct(before.st_mode & 0o777),flush=True)
    try:
        os.chown(parent,0,0);parent.chmod(0o755)
        main(sys.argv[1])
    finally:
        os.chown(parent,before.st_uid,before.st_gid);parent.chmod(before.st_mode & 0o777)
