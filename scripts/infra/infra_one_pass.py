#!/usr/bin/env python3
"""Additional USTAR infrastructure batch. Default is entirely offline.

Restricts legacy exports without moving/deleting bytes; creates an encrypted
control-state companion, not a whole-host or business-data restore. Existing
container/config/DB identities are unchanged. Public output is whitelisted.
"""
import argparse
import base64
from contextlib import contextmanager,ExitStack
from datetime import datetime,timezone
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import uuid

BASE=Path('/var/lib/ustar-infra-one-pass')
OPERATOR=Path('/usr/local/sbin/USTAR_INFRA_ONE_PASS_20261025.py')
TARGET=Path('/srv/ustar-storage/backups/host-state')
MOUNT=Path('/srv/ustar-storage')
HDD_UUID='359a2bae-4e79-461a-ab72-1597f605d801'
HDD_RESERVE=8*1024**3
BACKUP_SERVICE='ustar-console-host-state-backup.service'
BACKUP_TIMER='ustar-console-host-state-backup.timer'
COLLECTOR='ustar-console-collect.service'
COLLECT_TIMER='ustar-console-collect.timer'
DROPIN=Path('/etc/systemd/system/ustar-console-collect.service.d/90-ustar-infra-one-pass.conf')
PROBE=Path('/var/lib/ustar-console-collector/infra-journal-probe.json')
SNAPSHOT=Path('/run/ustar-console-data/snapshot.json')
KEY=Path('/etc/ustar-backup/recovery.key')
RECIPIENTS=Path('/etc/ustar-backup/recipients.txt')
LIMIT=192*1024**2
LOCKS=('var/lib/ustar-code-hardening/lock','var/lib/ustar-postgres-app-handoff/lock',
 'var/lib/ustar-connect-telegram-update/lock','var/lib/ustar-connect-academy-update/lock',
 'var/lib/ustar-connect-chat-link-update/lock','var/lib/ustar-academy-https/lock',
 'run/ustar-cron/lock','run/ustar-backup/lock','run/ustar-connect-install.lock')
CONTROLLERS={
 'root/ustar-telegram-update-3d1938ae.py':'6f7729459e1ef25271fd86324e441c823a6d39763c7fec84baf7cf640a41eb31',
 'root/ustar-db-app-handoff-7e35795f-ttyfix.py':'976afa0d2dffd58bf260dde4cb90cb5297f73d3f78fb9df10c2f9dc4b60ecf5e',
 'root/ustar-code-hardening-20261025.py':'6447d5d642c136ef5352690be19076636463a49a91cd0b9acb384440721a30d5'}
SCOPE=('var/lib/ustar-code-hardening','var/lib/ustar-postgres-app-handoff',
 'var/lib/ustar-connect-telegram-update','var/lib/ustar-connect-con02-update',
 'var/lib/ustar-connect-academy-update','var/lib/ustar-connect-chat-link-update',
 'var/lib/ustar-academy-https','etc/ustar-backup','etc/ustar-connect',
 'etc/ustar-console','etc/ustar-console-egress')+tuple(CONTROLLERS)
REMAINING=['Docker log rotation/health lifecycle with frozen IDs',
 'verified alternative SSH key access and shared-host network policy',
 'external clean-host restore result, offsite copy and separate key custody',
 'physical UPS/shutdown confirmation','independent host-loss observer and delivery receipt',
 'approved SMTP delivery test or existing receipt','representative performance/SLO decision']

class Refusal(ValueError):pass
def require(value,reason):
    if not value:raise Refusal(reason)
def digest(raw):return hashlib.sha256(raw).hexdigest()
def utc():return datetime.now(timezone.utc).isoformat()
def public_error(error):
    return str(error) if isinstance(error,Refusal) and re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,100}',str(error)) else type(error).__name__

@contextmanager
def opened(path,flags=os.O_RDONLY):
    """Anchor every component; O_NOFOLLOW on the final component alone is insufficient."""
    p=Path(path);require(p.is_absolute() and '..' not in p.parts,'unsafe_absolute_path')
    fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY)
    try:
        for part in p.parts[1:-1]:
            nxt=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd)
            os.close(fd);fd=nxt
        result=os.open(p.name,flags|os.O_NOFOLLOW|os.O_NONBLOCK,0o600,dir_fd=fd)
        try:yield result
        finally:os.close(result)
    finally:os.close(fd)

def description(fd):
    st=os.fstat(fd)
    require(stat.S_ISREG(st.st_mode) and st.st_nlink==1,'unsafe_file_type_or_links')
    require(not os.listxattr(fd),'unreviewed_file_xattrs')
    return dict(device=st.st_dev,inode=st.st_ino,uid=st.st_uid,gid=st.st_gid,
                mode=stat.S_IMODE(st.st_mode),bytes=st.st_size,mtime_ns=st.st_mtime_ns)

def private_dir(path):
    p=Path(path)
    if not os.path.lexists(p):
        st=p.parent.lstat()
        require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022,'unsafe_private_parent')
        p.mkdir(mode=0o700)
    with opened(p,os.O_RDONLY|os.O_DIRECTORY) as fd:
        st=os.fstat(fd);require(st.st_uid==0 and stat.S_IMODE(st.st_mode)==0o700,'unsafe_private_directory')

def sync(path):
    with opened(path,os.O_RDONLY|os.O_DIRECTORY) as fd:os.fsync(fd)

def atomic(path,raw,mode=0o600):
    path=Path(path)
    st=path.parent.lstat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022,'unsafe_atomic_parent')
    with opened(path.parent,os.O_RDONLY|os.O_DIRECTORY):pass
    fd,tmp=tempfile.mkstemp(prefix='.ustar-infra-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            os.fchown(out.fileno(),0,0);os.fchmod(out.fileno(),mode)
            out.write(raw);out.flush();os.fsync(out.fileno())
        os.replace(tmp,path);sync(path.parent)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def read_private(path,maximum=16*1024**2):
    with opened(path) as fd:
        before=description(fd);require(before['uid']==0 and not before['mode']&0o077 and before['bytes']<=maximum,'unsafe_private_file')
        with os.fdopen(os.dup(fd),'rb') as stream:raw=stream.read(maximum+1)
        require(len(raw)==before['bytes'] and description(fd)==before,'private_file_changed')
    return raw

class Store:
    def __init__(self,base):self.base=Path(base);self.path=self.base/'state.json'
    def load(self):
        if not os.path.lexists(self.path):return None
        private_dir(self.base)
        value=json.loads(read_private(self.path))
        require(value.get('schema')==1 and value.get('operation')=='infra_one_pass_v1','unknown_state')
        return value
    def save(self,value):
        private_dir(self.base);atomic(self.path,(json.dumps(value,sort_keys=True)+'\n').encode())
    @contextmanager
    def locked(self,create=False):
        if create:private_dir(self.base)
        if not os.path.lexists(self.base/'lock') and not create:yield;return
        with opened(self.base/'lock',os.O_RDWR|os.O_CREAT if create else os.O_RDONLY) as fd:
            row=description(fd);require(row['uid']==0 and row['mode']==0o600,'unsafe_batch_lock')
            try:fcntl.flock(fd,(fcntl.LOCK_EX if create else fcntl.LOCK_SH)|fcntl.LOCK_NB)
            except BlockingIOError:raise Refusal('batch_busy_retry_later') from None
            yield

def candidate(name):
    return bool(name.lower().endswith(('.sql','.dump','.age','.key')) or name=='.env' or
        re.fullmatch(r'(?i)(ustar|moodle|backup|recovery|snapshot|academy|dr)[-_].*\.(zip|tgz|tar|tar\.gz)',name))

def export_plan(root=Path('/'),limit=10000):
    root=Path(root);rows=[];skipped=[];visited=0
    for relative in ('home/aduk','opt/ustar'):
        base=root/relative
        if not os.path.lexists(base):continue
        with opened(base,os.O_RDONLY|os.O_DIRECTORY) as fd:
            info=os.fstat(fd);require(info.st_uid in (0,1000) and not stat.S_IMODE(info.st_mode)&0o022,'unsafe_export_root')
        def walk(directory,depth):
            nonlocal visited
            with opened(directory,os.O_RDONLY|os.O_DIRECTORY) as parent:
                for name in sorted(os.listdir(parent)):
                    if name.startswith('.') and name!='.env':continue
                    visited+=1;require(visited<=limit,'export_scan_limit_refused')
                    st=os.stat(name,dir_fd=parent,follow_symlinks=False);p=directory/name
                    if stat.S_ISLNK(st.st_mode):
                        if candidate(name) or depth==0:skipped.append(str(p))
                        continue
                    if stat.S_ISDIR(st.st_mode):
                        if depth==0 and name not in ('data','node_modules') and st.st_dev==info.st_dev:
                            require(st.st_uid in (0,1000) and not st.st_mode&0o002
                                    and (not st.st_mode&0o020 or st.st_gid in (0,1000)),'unsafe_export_parent')
                            walk(p,1)
                    elif candidate(name):
                        with opened(p) as fd:
                            row=description(fd)
                            require(row['uid'] in (0,1000) and not row['mode']&0o7111,'unsafe_export_owner_or_mode')
                        after=row['mode']&0o700
                        if after!=row['mode']:rows.append(dict(path=str(p),before=row,after_mode=after))
        walk(base,0)
    return dict(rows=rows,visited=visited,skipped_links=skipped,complete_for_defined_depth=True)

class Exports:
    def __init__(self,base):self.store=Store(base)
    def prepare(self,plan):
        require(self.store.load() is None,'existing_operation_requires_recovery')
        state=dict(schema=1,operation='infra_one_pass_v1',phase='prepared',created_utc=utc(),
                   exports=plan['rows'],scan={k:v for k,v in plan.items() if k!='rows'},managed=[],units={})
        self.store.save(state);return state
    def guard(self):
        state=self.store.load();require(state is not None,'missing_batch_state')
        for row in state['exports']:
            with opened(row['path']) as fd:
                got=description(fd);before=row['before']
                modes=((row['after_mode'],) if state['phase'] in ('exports_restricted','active') else
                       (before['mode'],) if state['phase']=='metadata_restored' else (before['mode'],row['after_mode']))
                require({**got,'mode':before['mode']}==before and got['mode'] in modes,'export_identity_or_content_drift')
        return state
    def apply(self,progress=lambda _:None):
        state=self.guard()
        for row in state['exports']:
            with opened(row['path']) as fd:
                require({**description(fd),'mode':row['before']['mode']}==row['before'],'export_identity_or_content_drift')
                os.fchmod(fd,row['after_mode']);os.fsync(fd)
            progress('export_restricted')
        state['phase']='exports_restricted';self.store.save(state)
    def undo(self):
        state=self.guard()
        state['phase']='restoring';self.store.save(state)
        for row in reversed(state['exports']):
            with opened(row['path']) as fd:
                got=description(fd)
                require({**got,'mode':row['before']['mode']}==row['before'] and got['mode'] in (row['before']['mode'],row['after_mode']),'export_identity_or_content_drift')
                os.fchmod(fd,row['before']['mode']);os.fsync(fd)
        state['phase']='metadata_restored';self.store.save(state)

def payload(root,scope):
    """Take bounded in-memory images while existing writer locks are held."""
    result={};total=0;root=Path(root)
    for relative in scope:
        require(not Path(relative).is_absolute() and '..' not in Path(relative).parts,'unsafe_payload_scope')
        base=root/relative
        if not os.path.lexists(base):continue
        if base.is_symlink():raise Refusal('payload_symlink_refused')
        for directory in ([base] if base.is_dir() else [])+list(base.parents):
            if not directory.is_relative_to(root):break
            st=directory.lstat()
            require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022,'unsafe_payload_parent')
            if directory==root:break
        paths=[]
        if base.is_dir():
            for directory,dirs,files in os.walk(base,followlinks=False):
                for name in dirs:
                    p=Path(directory)/name
                    st=p.lstat();require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022,'unsafe_payload_directory')
                paths.extend(Path(directory)/n for n in files if n!='lock' and not n.startswith('private-'))
        else:paths=[base]
        for path in sorted(paths):
            name=path.relative_to(root).as_posix()
            if name=='etc/ustar-backup/recovery.key':continue
            if name in result:continue
            require(len(result)<10000,'payload_entry_bound')
            require(stat.S_ISREG(path.lstat().st_mode),'payload_special_file_refused')
            with opened(path) as fd:
                before=description(fd)
                require(before['uid']==0 and not before['mode']&0o022 and before['bytes']<=64*1024**2,'unsafe_payload_file')
                total+=before['bytes'];require(total<=LIMIT,'payload_byte_bound')
                with os.fdopen(os.dup(fd),'rb') as stream:raw=stream.read(before['bytes']+1)
                require(len(raw)==before['bytes'] and description(fd)==before,'payload_changed')
            if name in CONTROLLERS:require(digest(raw)==CONTROLLERS[name],'frozen_controller_drift')
            result[name]=dict(raw=raw,sha256=digest(raw),**{k:before[k] for k in ('uid','gid','mode','bytes','mtime_ns')})
    return result

def manifest(data):
    return dict(schema=1,kind='ustar_config_control_companion',whole_host_backup=False,
        recovery_key_included=False,files=[dict(path=name,**{k:v for k,v in row.items() if k!='raw'}) for name,row in sorted(data.items())])

def write_tar(stream,data):
    with tarfile.open(fileobj=stream,mode='w|gz',format=tarfile.PAX_FORMAT) as archive:
        entries=[('manifest.json',json.dumps(manifest(data),sort_keys=True).encode(),0,0,0o600,0)]
        entries.extend(('payload/'+name,row['raw'],row['uid'],row['gid'],row['mode'],row['mtime_ns']//10**9) for name,row in sorted(data.items()))
        for name,raw,uid,gid,mode,mtime in entries:
            info=tarfile.TarInfo(name);info.size=len(raw);info.uid=uid;info.gid=gid;info.mode=mode;info.mtime=mtime
            archive.addfile(info,io.BytesIO(raw))

def verify_tar(stream,data):
    expected=manifest(data);seen=set();manifest_seen=False
    try:
        with tarfile.open(fileobj=stream,mode='r|gz') as archive:
            for member in archive:
                require(member.isfile() and member.name not in seen,'archive_member_type_or_duplicate')
                seen.add(member.name)
                if member.name=='manifest.json':
                    require(member.size<=16*1024**2 and json.load(archive.extractfile(member))==expected,'archive_manifest_mismatch')
                    manifest_seen=True;continue
                require(member.name.startswith('payload/'),'archive_member_scope')
                name=member.name[8:];require(name in data,'archive_unexpected_member');row=data[name]
                require((member.size,member.uid,member.gid,member.mode)==(row['bytes'],row['uid'],row['gid'],row['mode']),'archive_member_metadata')
                actual=hashlib.sha256()
                source=archive.extractfile(member)
                while chunk:=source.read(1024**2):actual.update(chunk)
                require(actual.hexdigest()==row['sha256']==digest(row['raw']),'archive_member_digest')
    except (tarfile.TarError,EOFError,OSError,ValueError) as error:
        if isinstance(error,Refusal):raise
        raise Refusal('archive_readback_failed') from None
    require(manifest_seen and seen=={'manifest.json'}|{'payload/'+n for n in data},'archive_members_missing')

def journal_adjustment(probe):
    if probe.get('reason')=='timeout':return 'CPUQuota=100%\n'
    if probe.get('reason')=='permission_denied':return 'SupplementaryGroups=systemd-journal\n'
    return None

class Managed:
    """Only brand-new, specifically named files; unknown existing bytes refuse."""
    def __init__(self,store):self.store=store
    def current(self,path):
        if not os.path.lexists(path):return None
        with opened(path) as fd:
            row=description(fd)
            require(row['uid']==0 and row['gid']==0 and row['bytes']<=4*1024**2,'unsafe_managed_file')
            with os.fdopen(os.dup(fd),'rb') as source:raw=source.read(4*1024**2+1)
        return dict(sha256=digest(raw),mode=row['mode'])
    def record(self,path):
        return next((v for v in self.store.load()['managed'] if v['path']==str(path)),None)
    def guard(self,path):
        row=self.record(path);now=self.current(path)
        require(row is not None and (now is None or now in row['versions']),'managed_file_foreign_drift')
        if self.store.load()['phase']=='active':require(now==row.get('desired'),'active_managed_file_drift')
    def write(self,path,raw,mode=0o600):
        path=Path(path);state=self.store.load();row=next((v for v in state['managed'] if v['path']==str(path)),None)
        if row is None:
            require(not os.path.lexists(path),'managed_path_already_exists')
            row=dict(path=str(path),versions=[]);state['managed'].append(row)
        else:self.guard(path)
        version=dict(sha256=digest(raw),mode=mode)
        if version not in row['versions']:row['versions'].append(version)
        row['desired']=version
        state.setdefault('created_dirs',[])
        if not path.parent.exists():
            require(path.parent.parent.is_dir(),'managed_parent_missing')
            state['created_dirs'].append(str(path.parent))
        self.store.save(state)
        if not path.parent.exists():path.parent.mkdir(mode=0o755)
        atomic(path,raw,mode);require(self.current(path)==version,'managed_readback_failed')
    def remove(self,path):
        self.guard(path)
        state=self.store.load()
        next(v for v in state['managed'] if v['path']==str(path))['desired']=None
        self.store.save(state)
        if os.path.lexists(path):os.unlink(path);sync(Path(path).parent)
    def restore(self):
        state=self.store.load()
        for row in state['managed']:self.guard(Path(row['path']))
        for row in reversed(state['managed']):self.remove(Path(row['path']))
        for directory in reversed(state.get('created_dirs',[])):
            if Path(directory).exists():
                try:Path(directory).rmdir()
                except OSError:pass  # Retain a directory containing somebody else's new files.

def repair_journal(store,host,dropin=DROPIN):
    before=host.snapshot()
    if before.get('fresh') and before.get('events_status')=='ok':
        return dict(status='measured',action='none',before=before,after=before)
    manager=Managed(store);timer=host.active(COLLECT_TIMER)
    state=store.load();state['collector_timer_was_active']=timer;store.save(state)
    result=dict(status='unresolved',before=before)
    try:
        if timer:host.systemctl('stop',COLLECT_TIMER)
        # The production Host runs the bounded probe through ExecStartPost in
        # the actual collector unit. Test hosts supply only this slow boundary.
        probe=host.probe();result['probe']=probe
        if manager.record(dropin):manager.remove(dropin)
        adjustment=journal_adjustment(probe)
        if adjustment:manager.write(dropin,('[Service]\n'+adjustment).encode(),0o644)
        host.systemctl('daemon-reload');host.systemctl('start',COLLECTOR)
        after=host.snapshot();result['after']=after
        if after.get('fresh') and after.get('events_status')=='ok':
            result.update(status='measured',action=adjustment.strip() if adjustment else 'fresh_collection_no_policy_change')
        else:
            if manager.record(dropin):manager.remove(dropin)
            host.systemctl('daemon-reload')
            result['adjustment_retained']=False
    except (Exception,KeyboardInterrupt) as error:
        if manager.record(dropin):manager.remove(dropin)
        host.systemctl('daemon-reload')
        if isinstance(error,KeyboardInterrupt):raise
        result.update(reason=public_error(error),adjustment_retained=False)
    finally:
        if timer:host.systemctl('start',COLLECT_TIMER)
    return result

class Host:
    def __init__(self,store):self.store=store
    def command(self,argv,*,raw=None,timeout=30,check=True):
        p=subprocess.run(argv,input=raw,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout,
             env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C','LC_ALL':'C'})
        require(len(p.stdout)+len(p.stderr)<=4*1024**2,'command_output_bound')
        if check:require(p.returncode==0,'host_command_failed')
        return p
    def systemctl(self,*args):return self.command(['/usr/bin/systemctl',*args],timeout=60)
    def active(self,unit):return self.command(['/usr/bin/systemctl','is-active','--quiet',unit],check=False).returncode==0
    def snapshot(self):
        try:
            # Snapshot is intentionally API-readable0640, never the raw journal.
            with opened(SNAPSHOT) as fd:
                row=description(fd);require(row['uid']==0 and not row['mode']&0o022 and row['bytes']<=4*1024**2,'unsafe_snapshot')
                with os.fdopen(os.dup(fd),'rb') as stream:value=json.load(stream)
            at=datetime.fromisoformat(value['observed_at'].replace('Z','+00:00')).timestamp()
            web=value.get('web_observability',{})
            return dict(fresh=-5<=time.time()-at<=45,observed_at=value['observed_at'],
                events_status=web.get('events_status'),events_reason_code=web.get('events_reason_code'),
                categories=len(web.get('events',[])))
        except (OSError,ValueError,KeyError,TypeError):return dict(fresh=False,events_status='unavailable')
    def probe(self):
        path=self.store.base/'probe.json'
        if path.exists():
            read_private(path,65536);path.unlink()
        text=('[Service]\nTimeoutStartSec=30\nReadWritePaths='+str(self.store.base)+'\n'
              'ExecStartPost=/usr/bin/python3 -I -B '+str(OPERATOR)+' --internal-journal-probe\n')
        Managed(self.store).write(DROPIN,text.encode(),0o644)
        self.systemctl('daemon-reload');self.systemctl('start',COLLECTOR)
        value=json.loads(read_private(path,65536));path.unlink()
        require(value.get('reason') in ('measured','timeout','permission_denied','process_failed','source_invalid','probe_failed'),'invalid_probe_result')
        return value

JOURNAL_PROBE_CODE=r'''
import json,subprocess,time
from ustar_console import web_observability as web
start=time.monotonic();result={'reason':'probe_failed'}
try:
    web._journal(time.time());result['reason']='measured'
except TimeoutError:result['reason']='timeout'
except OSError:
    now=int(time.time())
    argv=['/usr/bin/journalctl','--no-pager','--quiet','--output=json','--since=@'+str(now-300),
          '--until=@'+str(now),'--lines=1','--unit=ustar-console-collect.service']
    try:
        p=subprocess.run(argv,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,timeout=3,
                         env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
        result.update(reason='permission_denied' if b'permission' in p.stderr.lower() or b'access denied' in p.stderr.lower() else 'process_failed',exit_code=p.returncode)
    except subprocess.TimeoutExpired:result['reason']='timeout'
except (ValueError,UnicodeError):result['reason']='source_invalid'
except Exception:pass
result['elapsed_ms']=round((time.monotonic()-start)*1000)
print(json.dumps(result))
'''

def internal_probe():
    """Runs as ExecStartPost, with the collector's capabilities/groups/quotas."""
    path=BASE/'probe.json';require(not os.path.lexists(path),'probe_path_exists')
    p=subprocess.run(['/opt/ustar-console/venv/bin/python','-I','-B','-c',JOURNAL_PROBE_CODE],
          stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=8,
          env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
    require(p.returncode==0 and len(p.stdout)<65536,'probe_command_failed')
    value=json.loads(p.stdout)
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as stream:json.dump(value,stream);stream.flush();os.fsync(stream.fileno())

def reap(process):
    if process.poll() is None:
        try:os.killpg(process.pid,signal.SIGKILL)
        except ProcessLookupError:pass
    process.wait()

def verify_encrypted(path,data,key=KEY):
    process=subprocess.Popen(['/usr/bin/age','--decrypt','--identity',str(key),str(path)],
         stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,start_new_session=True,
         env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
    try:
        verify_tar(process.stdout,data)
        # Read through the final authenticated age chunk, not just tar's end marker.
        remaining=0
        while chunk:=process.stdout.read(4096):
            remaining+=len(chunk);require(remaining<65536,'archive_trailing_bytes')
        require(process.wait(timeout=15)==0,'archive_cipher_authentication_failed')
    except (OSError,EOFError,ValueError,subprocess.SubprocessError) as error:
        if isinstance(error,Refusal):raise
        raise Refusal('encrypted_readback_failed') from None
    finally:
        reap(process);process.stdout.close()

def encrypted_copy(path,data,recipients=RECIPIENTS,key=KEY):
    require(not os.path.lexists(path),'cipher_output_already_exists')
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb') as output:
        process=subprocess.Popen(['/usr/bin/age','--encrypt','--recipients-file',str(recipients)],
            stdin=subprocess.PIPE,stdout=output,stderr=subprocess.DEVNULL,start_new_session=True,
            env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
        try:
            write_tar(process.stdin,data);process.stdin.close()
            require(process.wait(timeout=90)==0,'archive_encryption_failed')
            output.flush();os.fsync(output.fileno())
        except (OSError,subprocess.SubprocessError):raise Refusal('archive_encryption_failed') from None
        finally:
            reap(process)
            if not process.stdin.closed:process.stdin.close()
    verify_encrypted(path,data,key)

@contextmanager
def recovery_locks(root=Path('/')):
    # Same order as code -> DB -> legacy -> HTTPS -> cron -> backup. Read locks
    # freeze controls while images are copied to RAM; encryption runs after release.
    with ExitStack() as stack:
        for name in LOCKS:
            path=Path(root)/name
            fd=stack.enter_context(opened(path));row=description(fd)
            require(row['uid']==0 and row['mode']==0o600,'unsafe_existing_recovery_lock')
            try:fcntl.flock(fd,fcntl.LOCK_SH|fcntl.LOCK_NB)
            except BlockingIOError:raise Refusal('managed_operation_busy_retry_later') from None
        yield

def root_json(path,maximum=16*1024**2):
    with opened(path) as fd:
        row=description(fd);require(row['uid']==0 and not row['mode']&0o022 and row['bytes']<=maximum,'unsafe_root_json')
        with os.fdopen(os.dup(fd),'rb') as stream:value=json.load(stream)
        require(description(fd)==row,'root_json_changed')
    return value

def hdd_target(host):
    r=host.command(['/usr/bin/findmnt','--json','--task','1','--mountpoint',str(MOUNT),'--output','TARGET,UUID,FSTYPE,OPTIONS'])
    rows=json.loads(r.stdout)['filesystems']
    require(len(rows)==1 and rows[0]['target']==str(MOUNT) and rows[0]['uuid']==HDD_UUID
        and rows[0]['fstype']=='ext4' and 'rw' in rows[0]['options'].split(','),'HDD_mount_identity_or_mode')
    private_dir(TARGET)
    r=host.command(['/usr/bin/findmnt','--json','--target',str(TARGET),'--output','TARGET,UUID,FSTYPE,OPTIONS'])
    rows=json.loads(r.stdout)['filesystems']
    require(len(rows)==1 and rows[0]['uuid']==HDD_UUID and rows[0]['fstype']=='ext4'
            and 'rw' in rows[0]['options'].split(','),'HDD_companion_namespace_readonly_or_wrong_disk')
    used=0
    for entry in TARGET.iterdir():
        st=entry.lstat();require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and not st.st_mode&0o077,'unsafe_HDD_companion_entry')
        used+=st.st_size
    require(used+LIMIT<5*1024**3,'companion_quota_no_deletion_performed')
    disk=os.statvfs(TARGET);require(disk.f_bavail*disk.f_frsize>=HDD_RESERVE+LIMIT,'HDD_free_space_reserve')

def backup_controls(store,host):
    hdd_target(host)
    read_private(KEY,8192);recipients=read_private(RECIPIENTS,8192)
    lines=[v.strip() for v in recipients.decode('ascii').splitlines() if v.strip() and not v.startswith('#')]
    require(lines and all(re.fullmatch('age1[a-z0-9]{58}',v) for v in lines),'unreviewed_age_recipient_format')
    for binary in ('/usr/bin/age','/usr/bin/age-keygen'):
        with opened(binary) as fd:
            row=description(fd);require(row['uid']==0 and not row['mode']&0o022,'unsafe_age_binary')
    with recovery_locks():
        require(root_json('/var/lib/ustar-code-hardening/state.json').get('phase')=='active','code_hardening_not_active')
        require(root_json('/var/lib/ustar-postgres-app-handoff/state.json').get('phase')=='app_role_active','DB_handoff_not_active')
        scope=SCOPE+(str(OPERATOR).lstrip('/'),str(store.path).lstrip('/'),
            'etc/systemd/system/'+BACKUP_SERVICE,'etc/systemd/system/'+BACKUP_TIMER,str(DROPIN).lstrip('/'))
        data=payload(Path('/'),scope)
        require(all(name in data for name in CONTROLLERS),'missing_recovery_controller')
    identifier=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:8]
    partial=TARGET/('ustar-control-'+identifier+'.partial.age')
    archive=TARGET/('ustar-control-'+identifier+'.tar.gz.age')
    started=utc();encrypted_copy(partial,data)
    sha=hashlib.sha256()
    with partial.open('rb') as source:
        while chunk:=source.read(1024**2):sha.update(chunk)
    os.rename(partial,archive);sync(TARGET)
    receipt=dict(schema=1,kind='ustar_config_control_companion',phase='complete',success=True,
        started_utc=started,finished_utc=utc(),archive=str(archive),bytes=archive.stat().st_size,
        sha256=sha.hexdigest(),files=len(data),readback_verified=True,recovery_key_included=False,
        whole_host_backup=False,archive_deletion=False)
    raw=(json.dumps(receipt,sort_keys=True)+'\n').encode()
    atomic(archive.with_suffix('.json'),raw);atomic(store.base/'last-success.json',raw)
    return receipt

def service_files():
    service=('[Unit]\nDescription=USTAR encrypted configuration and recovery-control companion\n'
      'After=local-fs.target\nRequiresMountsFor=/srv/ustar-storage\n\n[Service]\n'
      'Type=oneshot\nUser=root\nGroup=root\nUMask=0077\n'
      'ExecStart=/usr/bin/python3 -I -B '+str(OPERATOR)+' --backup-host-state\n'
      'TimeoutStartSec=300\nNice=15\nIOSchedulingClass=idle\nMemoryMax=1G\n'
      'ProtectSystem=strict\nProtectHome=read-only\nPrivateTmp=yes\nNoNewPrivileges=yes\n'
      'ReadWritePaths='+str(BASE)+' '+str(TARGET)+'\n'
      'StandardOutput=journal\nStandardError=journal\n')
    timer=('[Unit]\nDescription=USTAR nightly encrypted control-state companion\n\n[Timer]\n'
      'OnCalendar=*-*-* 01:00:00 UTC\nRandomizedDelaySec=120\nPersistent=true\n'
      'Unit='+BACKUP_SERVICE+'\n\n[Install]\nWantedBy=timers.target\n')
    return {Path('/etc/systemd/system')/BACKUP_SERVICE:service.encode(),
            Path('/etc/systemd/system')/BACKUP_TIMER:timer.encode()}

def maintenance_metadata(host):
    result={}
    try:
        value=root_json('/var/lib/ustar-cron/last-run.json',256*1024)
        allowed={'finished_utc','started_utc','exit_code','success','scheduled_failed',
                 'scheduled_overdue_10m','adhoc_queued','adhoc_failed','ustar_error_lines'}
        def selected(v):
            if not isinstance(v,dict):return {}
            out={}
            for key,item in v.items():
                if key in allowed and (type(item) in (bool,int,float) or isinstance(item,str) and re.fullmatch(r'\d{4}-\d\d-\d\dT[0-9:.+-]+Z?',item)):out[key]=item
                if isinstance(item,dict):
                    child=selected(item)
                    if child:out[key if key in ('runtime','tasks','last_run','result') else 'nested']=child
            return out
        result['cron_receipt']=selected(value)
    except (OSError,ValueError,KeyError) as error:result['cron_receipt']={'reason':public_error(error)}
    try:
        p=host.command(['/usr/bin/ss','-H','-lntu'])
        endpoints=sorted(set(' '.join([row.split()[0],row.split()[4]]) for row in p.stdout.decode().splitlines() if len(row.split())>=5))
        result['listeners']=dict(endpoints=endpoints[:500],truncated=len(endpoints)>500)
    except (OSError,ValueError,subprocess.SubprocessError) as error:result['listeners']={'reason':public_error(error)}
    return result

def preflight(store,host):
    require(os.uname().nodename=='server1.example.com','unexpected_host')
    require(Path('/run/systemd/system').is_dir(),'systemd_host_required')
    for relative,sha in CONTROLLERS.items():require(digest(read_private(Path('/')/relative,64*1024**2))==sha,'frozen_controller_drift')
    require(host.active(COLLECT_TIMER),'collector_timer_not_active')
    require(host.active('ustar-hdd-backup.timer'),'HDD_backup_timer_not_active')
    props=host.command(['/usr/bin/systemctl','show',COLLECTOR,'-p','User','-p','Group','-p','ExecStart']).stdout.decode()
    values=dict(line.split('=',1) for line in props.splitlines() if '=' in line)
    require(values.get('User')=='root' and values.get('Group')=='ustar-console'
            and 'path=/opt/ustar-console/venv/bin/ustar-console-collect' in values.get('ExecStart',''),'unexpected_collector_unit_identity')
    require(not Path('/var/lib/ustar-backup/moodle-was-running.json').exists(),'backup_recovery_required')
    for unit in (BACKUP_SERVICE,BACKUP_TIMER):
        # No unknown existing units are adopted, even if their file is elsewhere.
        value=host.command(['/usr/bin/systemctl','show',unit,'-p','LoadState','--value']).stdout.strip()
        require(value==b'not-found' or any(Path(v['path']).name==unit for v in (store.load() or {}).get('managed',[])),'backup_unit_already_exists')
    return export_plan()

def undo_batch(store,host):
    state=store.load();require(state is not None,'missing_batch_state')
    Exports(store.base).guard()
    manager=Managed(store)
    for row in state['managed']:manager.guard(Path(row['path']))
    state['phase']='restoring';store.save(state)
    if any(Path(v['path']).name==BACKUP_TIMER for v in state['managed']):
        host.systemctl('disable','--now',BACKUP_TIMER)
    manager.restore();host.systemctl('daemon-reload')
    if state.get('collector_timer_was_active'):host.systemctl('start',COLLECT_TIMER)
    Exports(store.base).undo()
    return dict(phase='metadata_restored',business_rows_restored=False,encrypted_archives_retained=True)

def host_main(args):
    store=Store(BASE);host=Host(store)
    try:
        require(os.geteuid()==0,'root_required')
        if args.internal_journal_probe:internal_probe();return 0
        own=Path(sys.argv[0]).absolute();raw=read_private(own,4*1024**2)
        def interrupted(*_):raise KeyboardInterrupt()
        for sig in (signal.SIGTERM,signal.SIGHUP,signal.SIGINT):signal.signal(sig,interrupted)
        signal.signal(signal.SIGALRM,lambda *_:(_ for _ in ()).throw(Refusal('operation_time_budget')))
        signal.alarm(270)
        with store.locked(create=not args.check):
            if args.undo or args.recover:result=undo_batch(store,host)
            elif args.backup_host_state:
                require((store.load() or {}).get('phase')=='active','batch_not_active')
                for row in store.load()['managed']:Managed(store).guard(Path(row['path']))
                result=backup_controls(store,host)
            elif args.check:
                plan=preflight(store,host)
                if store.load():
                    Exports(store.base).guard()
                    for row in store.load()['managed']:Managed(store).guard(Path(row['path']))
                result=dict(phase='ready_for_local_batch' if store.load() is None else store.load()['phase'],
                    planned_export_changes=len(plan['rows']),scan=plan|{'rows':None},
                    journal=host.snapshot(),maintenance=maintenance_metadata(host),
                    configuration_changes=False,provider_called=False,remaining=REMAINING)
            else:
                require(store.load() is None,'existing_batch_use_check_or_recover')
                plan=preflight(store,host);Exports(store.base).prepare(plan)
                manager=Managed(store)
                try:
                    manager.write(OPERATOR,raw)
                    backup_controls(store,host)
                    Exports(store.base).apply()
                    journal=repair_journal(store,host)
                    for path,content in service_files().items():manager.write(path,content,0o644)
                    host.command(['/usr/bin/systemd-analyze','verify',*map(str,service_files())])
                    host.systemctl('daemon-reload');host.systemctl('enable','--now',BACKUP_TIMER)
                    require(host.active(BACKUP_TIMER),'companion_timer_not_active')
                    state=store.load();state.update(phase='active',journal=journal,finished_utc=utc());store.save(state)
                    receipt=backup_controls(store,host)
                    result=dict(phase='local_batch_applied',exports_restricted=len(plan['rows']),journal=journal,
                        control_backup=receipt,companion_timer_active=True,maintenance=maintenance_metadata(host),
                        containers_unchanged=True,business_rows_restored=False,provider_called=False,
                        infrastructure_complete=False,remaining=REMAINING)
                except BaseException:
                    try:undo_batch(store,host)
                    except BaseException:raise Refusal('recovery_required_run_recover') from None
                    raise
        print(json.dumps(result,sort_keys=True));return 0
    except BaseException as error:
        print(json.dumps(dict(phase='refused',reason=public_error(error),business_rows_restored=False,provider_called=False)));return 2
    finally:signal.alarm(0)

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group()
    for name in ('check','apply','undo','recover','backup-host-state','internal-journal-probe'):group.add_argument('--'+name,action='store_true')
    args=parser.parse_args(argv)
    if not any(vars(args).values()):
        print(json.dumps(dict(phase='PLAN_ONLY',configuration_changes=False,provider_called=False)));return 0
    # Host integration is intentionally below the offline entrypoint.
    return host_main(args)

if __name__=='__main__':sys.exit(main())
