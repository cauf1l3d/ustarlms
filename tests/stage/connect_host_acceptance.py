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
    print('CONNECT_HOST_ACCEPTANCE=PASS install/reinstall/update/rollback/CLI-probe-start-stop/LoadCredential/UID/SQLite/offline-notice; no live Telegram')

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
