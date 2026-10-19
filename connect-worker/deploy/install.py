#!/usr/bin/env python3
"""USTAR Connect receiver installer/admin. Fixed host scope; Python standard library only."""
import argparse
import fcntl
import getpass
import hashlib
import io
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile

UNITS = ('receiver', 'probe', 'notify')
FILES = {'SOURCE_COMMIT', 'receiver.pyz', 'notifications.pyz', 'admin.pyz'} | {
    'units/ustar-connect-'+n+'.service' for n in UNITS}
PROXY = 'http://127.0.0.1:18081'
USERNAME = 'ustarcorp_bot'


def unique(pairs):
    result = {}
    for k,v in pairs:
        if k in result: raise ValueError('duplicate_json_field')
        result[k] = v
    return result


def validate_archive(path, digest):
    path = Path(path)
    if not re.fullmatch('[a-f0-9]{64}',digest) or path.stat().st_size > 16777216:
        raise ValueError('archive_invalid')
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != digest: raise ValueError('archive_hash_mismatch')
    values = {}
    total = 0
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz') as archive:
        for member in archive:
            if (member.name not in FILES | {'manifest.json'} or member.name in values
                    or not member.isfile() or member.mode != 0o644 or member.size > 4194304):
                raise ValueError('archive_member_invalid')
            total += member.size
            if total > 16777216: raise ValueError('archive_too_large')
            values[member.name] = archive.extractfile(member).read(member.size+1)
    if set(values) != FILES | {'manifest.json'}: raise ValueError('archive_incomplete')
    manifest = json.loads(values.pop('manifest.json'),object_pairs_hook=unique)
    if not isinstance(manifest,dict) or set(manifest) != {'schema','source_commit','files'} or manifest['schema'] != 1:
        raise ValueError('manifest_invalid')
    sha = manifest['source_commit']
    if not isinstance(sha,str) or not re.fullmatch('[a-f0-9]{40}',sha) or values['SOURCE_COMMIT'] != (sha+'\n').encode():
        raise ValueError('source_identity_mismatch')
    if manifest['files'] != {name:hashlib.sha256(value).hexdigest() for name,value in values.items()}:
        raise ValueError('manifest_hash_mismatch')
    return sha, values


def safe(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts: raise ValueError('unsafe_path')
    for part in (path,*path.parents):
        if part.is_symlink(): raise ValueError('symlink_refused')
        if part.exists():
            info=part.stat()
            if info.st_uid not in (0,os.geteuid()) or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                raise ValueError('unsafe_owner_or_permissions')
    return path


def mkdir(path,mode=0o755):
    safe(path)
    if path.exists():
        if not path.is_dir() or stat.S_IMODE(path.stat().st_mode) != mode:
            raise ValueError('directory_mode_mismatch')
    else:
        path.mkdir(mode=mode,parents=True)
        path.chmod(mode)


def write_file(path, value, mode=0o644):
    path=safe(path)
    if path.exists() and (not path.is_file() or path.stat().st_nlink != 1):
        raise ValueError('unsafe_existing_file')
    safe(path.parent)
    fd,name=tempfile.mkstemp(prefix='.ustar-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as stream:
            os.fchmod(stream.fileno(),mode);stream.write(value);stream.flush();os.fsync(stream.fileno())
        os.replace(name,path)
        descriptor=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(descriptor)
        finally: os.close(descriptor)
    finally:
        if os.path.exists(name): os.unlink(name)


class Host:
    def run(self,args):
        result=subprocess.run(args,stdin=subprocess.DEVNULL,capture_output=True,text=True,timeout=75,check=False)
        if result.returncode: raise RuntimeError('host_command_failed')
        return result.stdout

    def active(self,name):
        result=subprocess.run(['systemctl','is-active','--quiet',name],capture_output=True,timeout=15)
        return result.returncode == 0

    def ensure_user(self,name):
        try: account=pwd.getpwnam(name)
        except KeyError:
            self.run(['/usr/sbin/useradd','--system','--user-group','--home-dir','/nonexistent',
                      '--shell','/usr/sbin/nologin',name])
            account=pwd.getpwnam(name)
        if (account.pw_uid == 0 or account.pw_dir != '/nonexistent' or account.pw_shell != '/usr/sbin/nologin'
                or os.getgrouplist(name,account.pw_gid) != [account.pw_gid]):
            raise ValueError('service_account_conflict')


class Installation:
    def __init__(self,root=Path('/'),host=None):
        self.root=Path(root);self.host=host or Host()
        self.prefix=self.root/'opt/ustar-connect'
        self.config=self.root/'etc/ustar-connect'
        self.units=self.root/'etc/systemd/system'

    def current(self):
        pointer=self.prefix/'current'
        if not pointer.is_symlink():
            if pointer.exists(): raise ValueError('unmanaged_current_path')
            return None
        target=Path(os.readlink(pointer))
        if not target.is_absolute(): target=pointer.parent/target
        if target.parent != self.prefix/'releases' or not re.fullmatch('[a-f0-9]{40}',target.name):
            raise ValueError('unmanaged_current_target')
        safe(target)
        if not target.is_dir(): raise ValueError('missing_release')
        return target

    def switch(self,target):
        pointer=self.prefix/'current'
        temp=self.prefix/'.current-new'
        if temp.exists() or temp.is_symlink(): raise ValueError('pending_switch_exists')
        if target is None:
            if pointer.is_symlink(): pointer.unlink()
            return
        os.symlink(str(target),temp)
        os.replace(temp,pointer)

    def release_files(self,release):
        safe(release)
        manifest=json.loads((release/'manifest.json').read_text(),object_pairs_hook=unique)
        if (set(manifest) != {'schema','source_commit','files'} or manifest['schema'] != 1
                or manifest['source_commit'] != release.name or set(manifest['files']) != FILES):
            raise ValueError('installed_manifest_invalid')
        values={}
        for name,digest in manifest['files'].items():
            path=safe(release/name)
            if not path.is_file() or path.stat().st_nlink != 1 or path.stat().st_size > 4194304:
                raise ValueError('installed_file_invalid')
            values[name]=path.read_bytes()
            if hashlib.sha256(values[name]).hexdigest() != digest: raise ValueError('installed_hash_mismatch')
        return values

    def check_units(self,oldvalues):
        snapshots={}
        for name in UNITS:
            filename='ustar-connect-'+name+'.service';path=safe(self.units/filename)
            if path.exists():
                if path.stat().st_nlink != 1 or not path.is_file(): raise ValueError('unsafe_unit')
                value=path.read_bytes()
                if oldvalues is None or value != oldvalues['units/'+filename]: raise ValueError('unmanaged_unit')
                snapshots[filename]=value
            else: snapshots[filename]=None
            for base in ('run/systemd/system','usr/lib/systemd/system','lib/systemd/system'):
                candidate=self.root/base/filename
                if candidate.exists() or candidate.is_symlink() or candidate.with_name(filename+'.d').exists():
                    raise ValueError('unit_override_requires_review')
            # Unknown administrator drop-ins can override credentials/executable/privileges.
            if (self.units/(filename+'.d')).exists(): raise ValueError('unit_dropin_requires_review')
        return snapshots

    def install(self,archive,digest):
        sha,values=validate_archive(archive,digest)
        old=self.current();oldvalues=self.release_files(old) if old else None
        snapshots=self.check_units(oldvalues)
        if self.host.active('ustar-connect-notify.service'): raise ValueError('notification_update_requires_separate_plan')
        active=self.host.active('ustar-connect-receiver.service')
        mkdir(self.prefix);mkdir(self.prefix/'releases');mkdir(self.config,0o700)
        mkdir(self.units)
        for name in ('ustar-connect','ustar-connect-notify'):self.host.ensure_user(name)
        release=self.prefix/'releases'/sha
        manifest={'schema':1,'source_commit':sha,'files':{n:hashlib.sha256(v).hexdigest() for n,v in values.items()}}
        if release.exists():
            if self.release_files(release) != values: raise ValueError('release_collision')
        else:
            staging=Path(tempfile.mkdtemp(prefix='.release-',dir=self.prefix/'releases'))
            try:
                for name,value in values.items():
                    parent=(staging/name).parent
                    if parent != staging:mkdir(parent,0o755)
                    write_file(staging/name,value)
                write_file(staging/'manifest.json',(json.dumps(manifest,sort_keys=True)+'\n').encode())
                staging.chmod(0o755);os.replace(staging,release)
            except BaseException:
                shutil.rmtree(staging,ignore_errors=True);raise
        # Validate actual unit syntax before switching an existing runtime.
        self.host.run(['systemd-analyze','verify',*[str(release/'units'/('ustar-connect-'+n+'.service')) for n in UNITS]])
        try:
            if active:self.host.run(['systemctl','stop','ustar-connect-receiver.service'])
            for name in UNITS:
                filename='ustar-connect-'+name+'.service';write_file(self.units/filename,values['units/'+filename])
            self.switch(release)
            self.host.run(['systemctl','daemon-reload'])
            if active:self.host.run(['systemctl','start','ustar-connect-receiver.service'])
            if old is not None and old != release:
                write_file(self.prefix/'previous.json',(json.dumps({'release':old.name,'current':sha})+'\n').encode(),0o600)
        except BaseException:
            self.switch(old)
            for filename,value in snapshots.items():
                if value is None:
                    path=self.units/filename
                    if path.exists():path.unlink()
                else:write_file(self.units/filename,value)
            try:
                self.host.run(['systemctl','daemon-reload'])
                if active:self.host.run(['systemctl','start','ustar-connect-receiver.service'])
            except Exception:
                raise RuntimeError('update_failed_recovery_requires_operator') from None
            raise
        return sha

    def configure(self,token):
        current=self.current()
        if current is None:raise ValueError('not_installed')
        self.release_files(current)
        if self.host.active('ustar-connect-receiver.service'):raise ValueError('stop_receiver_before_configuration')
        if not isinstance(token,str) or not re.fullmatch(r'[1-9][0-9]{0,19}:[A-Za-z0-9_-]{20,256}',token):
            raise ValueError('invalid_bot_token')
        tokenfile=self.config/'telegram.token'
        if tokenfile.exists():
            previous=safe(tokenfile).read_text().strip()
            if previous.split(':',1)[0] != token.split(':',1)[0]: raise ValueError('different_bot_requires_new_journal_plan')
        config={'bot_token_file':'/run/credentials/ustar-connect-receiver.service/telegram.token',
            'bot_username':USERNAME,'state_directory':'/var/lib/ustar-connect-receiver',
            'telegram_proxy':PROXY,'onboarding_enabled':False,'bridge_endpoint':None,
            'bridge_key_id':None,'bridge_key_file':None,'bridge_ca_file':None}
        probe=dict(config,bot_token_file='/run/credentials/ustar-connect-probe.service/telegram.token')
        write_file(tokenfile,(token+'\n').encode(),0o600)
        write_file(self.config/'receiver.json',(json.dumps(config,sort_keys=True)+'\n').encode(),0o600)
        write_file(self.config/'probe.json',(json.dumps(probe,sort_keys=True)+'\n').encode(),0o600)

    def probe(self):
        if self.current() is None: raise ValueError('not_installed')
        for name in ('telegram.token','receiver.json','probe.json'):
            path=safe(self.config/name)
            if (not path.is_file() or path.stat().st_nlink != 1
                    or stat.S_IMODE(path.stat().st_mode) != 0o600 or path.stat().st_size > 16384):
                raise ValueError('private_configuration_required')
        self.host.run(['systemctl','start','ustar-connect-probe.service'])
        result=self.host.run(['systemctl','show','ustar-connect-probe.service',
                             '--property=Result,ExecMainStatus,ConditionResult','--no-pager'])
        if set(result.splitlines()) != {'Result=success','ExecMainStatus=0','ConditionResult=yes'}:
            raise ValueError('telegram_probe_not_accepted')

    def start(self):
        self.probe()
        self.host.run(['systemctl','enable','--now','ustar-connect-receiver.service'])
        if not self.host.active('ustar-connect-receiver.service'):raise ValueError('receiver_not_active')

    def stop(self):
        self.host.run(['systemctl','disable','--now','ustar-connect-receiver.service'])

    def rollback(self):
        pointer=safe(self.prefix/'previous.json')
        data=json.loads(pointer.read_text(),object_pairs_hook=unique)
        current=self.current()
        if (not isinstance(data,dict) or set(data) != {'release','current'} or current is None
                or data['current'] != current.name or not re.fullmatch('[a-f0-9]{40}',data['release'])):
            raise ValueError('no_verified_previous_release')
        target=self.prefix/'releases'/data['release'];values=self.release_files(target)
        currentvalues=self.release_files(current);self.check_units(currentvalues)
        if self.host.active('ustar-connect-notify.service'):raise ValueError('notification_update_requires_separate_plan')
        active=self.host.active('ustar-connect-receiver.service')
        try:
            if active:self.host.run(['systemctl','stop','ustar-connect-receiver.service'])
            for n in UNITS:
                filename='ustar-connect-'+n+'.service';write_file(self.units/filename,values['units/'+filename])
            self.switch(target);self.host.run(['systemctl','daemon-reload'])
            if active:self.host.run(['systemctl','start','ustar-connect-receiver.service'])
            write_file(pointer,(json.dumps({'release':current.name,'current':target.name})+'\n').encode(),0o600)
        except BaseException:
            self.switch(current)
            for n in UNITS:
                filename='ustar-connect-'+n+'.service';write_file(self.units/filename,currentvalues['units/'+filename])
            try:
                self.host.run(['systemctl','daemon-reload'])
                if active:self.host.run(['systemctl','start','ustar-connect-receiver.service'])
            except Exception:raise RuntimeError('rollback_failed_recovery_requires_operator') from None
            raise
        return target.name

    def status(self):
        current=self.current()
        result={'source_commit':current.name if current else None,'onboarding_enabled':False,'units':{}}
        for n in UNITS:
            name='ustar-connect-'+n+'.service'
            output=self.host.run(['systemctl','show',name,'--property=LoadState,ActiveState,SubState,Result,ExecMainStatus','--no-pager'])
            result['units'][name]=dict(line.split('=',1) for line in output.splitlines() if '=' in line)
        return result


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='action',required=True)
    install=sub.add_parser('install');install.add_argument('--archive',required=True);install.add_argument('--sha256',required=True)
    for n in ('configure','probe','start','stop','status','rollback'):sub.add_parser(n)
    args=parser.parse_args(argv)
    try:
        if os.geteuid()!=0 or sys.version_info < (3,12) or not Path('/run/systemd/system').is_dir():
            raise ValueError('requires_root_python312_systemd')
        instance=Installation()
        # Root-only lock spans release/configuration and all service transitions.
        lockpath=Path('/run/ustar-connect-install.lock');safe(lockpath)
        descriptor=os.open(lockpath,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        with os.fdopen(descriptor,'w') as lock:
            info=os.fstat(lock.fileno())
            if info.st_nlink != 1 or not stat.S_ISREG(info.st_mode):raise ValueError('unsafe_install_lock')
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            if args.action=='install':value={'status':'installed_services_not_started','source_commit':instance.install(args.archive,args.sha256)}
            elif args.action=='configure':
                with open('/dev/tty','r+') as tty:
                    token=getpass.getpass('Token @ustarcorp_bot (hidden): ',stream=tty)
                instance.configure(token);token=None
                value={'status':'configured','onboarding_enabled':False,'proxy':PROXY}
            elif args.action=='status':value=instance.status()
            elif args.action=='rollback':value={'status':'rolled_back','source_commit':instance.rollback(),'journal_preserved':True}
            else:
                getattr(instance,args.action)();value={'status':args.action+'_complete'}
            print(json.dumps(value,sort_keys=True));return 0
    except Exception as error:
        # Never render arbitrary filesystem/provider/subprocess input or credential-bearing arguments.
        reason=str(error) if isinstance(error,(ValueError,RuntimeError)) and re.fullmatch('[a-z_]{1,80}',str(error)) else 'operation_failed'
        print(json.dumps({'status':'failed','reason':reason}));return 1


if __name__=='__main__':raise SystemExit(main())
