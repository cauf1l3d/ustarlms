#!/usr/bin/env python3
"""Coordinated link-only bridge deployment. Default PLAN_ONLY; no application upgrade."""
import argparse
import base64
from contextlib import nullcontext
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import stat
import sys
import tempfile
import zipfile
import academy_https
import connect_private_bridge as bridge
import connect_install

WEB_GID=33
require=bridge.require
sha=academy_https.sha
SERVICE='ustar-connect-receiver.service'
PROBE='ustar-connect-probe.service'


class Deployment:
    def __init__(self,reader,https_source,archive,digest,progress=None):
        self.r=reader;self.m=reader.https;self.i=reader.inst;self.lib=reader.library()
        self.source=https_source;self.archive=Path(archive);self.digest=digest
        self.base=reader.root/'var/lib/ustar-connect-bridge';self.state=self.base/'state.json'
        self.progress=progress or (lambda phase:print(json.dumps({'phase':phase}),flush=True))
        self.paths={'tool':self.m.p.tool,'config':self.m.p.old.config,
            'receiver':self.i.config/'receiver.json','probe':self.i.config/'probe.json',
            'key':self.i.config/'bridge.key','ca':self.i.config/'bridge.ca',
            'include':self.m.p.old.config.parent/'.ustar-connect/bridge.php',
            'receiver_dropin':self.i.units/(SERVICE+'.d/40-ustar-link.conf'),
            'probe_dropin':self.i.units/(PROBE+'.d/40-ustar-link.conf')}
    def save(self,s):self.lib.atomic(self.state,(json.dumps(s,sort_keys=True)+'\n').encode())
    def load(self):
        s=json.loads(self.r.read_private(self.state,131072),object_pairs_hook=bridge.unique)
        require(s.get('schema')==1 and s.get('phase') in ('preparing','active','restoring','inactive')
            and s.get('source_sha')==sha(self.source) and set(s.get('files',{}))==set(self.paths),
            'invalid_activation_state')
        require(all(re.fullmatch('[a-f0-9]{40}',s.get(k,'')) for k in ('old_release','new_release')),
            'invalid_release_state')
        return s
    def metadata(self,path):
        st=path.lstat()
        require(stat.S_ISREG(st.st_mode) and st.st_nlink==1,'unsafe_activation_file')
        return {'uid':st.st_uid,'gid':st.st_gid,'mode':stat.S_IMODE(st.st_mode),
            'xattrs':{k:base64.b64encode(os.getxattr(path,k)).decode() for k in os.listxattr(path)}}
    def current(self,name):
        p=self.paths[name]
        if not os.path.lexists(p):return None
        self.r.secure_parent(p.parent,'unsafe_activation_parent')
        return {'sha':sha(self.lib.read_file(p)),**self.metadata(p)}
    def before(self,s,name):return s['files'][name]['before']
    def after(self,s,name):return s['files'][name]['after']
    def guard(self,s,active=False):
        for name in self.paths:
            allowed=[self.after(s,name)] if active else [self.before(s,name),self.after(s,name)]
            require(self.current(name) in allowed,'activation_'+name+'_drift')
        state=self.m.load()
        require(state['tool_sha'] in (self.before(s,'tool')['sha'],self.after(s,'tool')['sha'])
            and state['files']['config']['after'] in (self.before(s,'config')['sha'],self.after(s,'config')['sha']),
            'HTTPS_handoff_drift')
        state['tool_sha']=self.current('tool')['sha'];state['files']['config']['after']=self.current('config')['sha']
        for name in ('http','tls'):
            allowed={state['files'][name]['after']}
            if state['phase']=='profile_changing':allowed.add(state['pending_profile'][name]['after'])
            if state['phase']=='renewing' and name=='tls':allowed.add(state['pending']['site_sha'])
            actual=sha(self.lib.read_file(self.m.files()[name]))
            require(actual in allowed,'HTTPS_'+name+'_drift');state['files'][name]['after']=actual
        self.m.guards(state)
        token=self.r.read_private(self.i.config/'telegram.token',512)
        require(sha(token)==s['token_sha'],'bot_token_changed')
        current=self.i.current()
        require(current is not None and current.name in ((s['new_release'],) if active else (s['old_release'],s['new_release'])),'receiver_pointer_drift')
        self.i.release_files(current)
        for name,digest in s['unit_hashes'].items():
            require(sha(self.lib.read_file(self.i.units/name))==digest,'receiver_unit_drift')
        for unit in (SERVICE,PROBE,'ustar-connect-notify.service'):
            for base in ('run/systemd/system','usr/lib/systemd/system','lib/systemd/system'):
                p=self.r.root/base/unit
                require(not os.path.lexists(p) and not os.path.lexists(p.with_name(unit+'.d')),'receiver_override_drift')
            folder=self.i.units/(unit+'.d')
            if os.path.lexists(folder):
                require(not folder.is_symlink() and folder.is_dir(),'receiver_override_drift')
                allowed={'40-ustar-link.conf'} if unit in (SERVICE,PROBE) else set()
                require({p.name for p in folder.iterdir()}<=allowed,'receiver_override_drift')
        include=self.paths['include'].parent
        if os.path.lexists(include):
            require(not include.is_symlink() and include.is_dir()
                and {p.name for p in include.iterdir()}<= {'bridge.php'},'private_include_drift')
        require(not self.i.host.active('ustar-connect-notify.service'),'notification_activation_requires_separate_plan')
    def bytes(self,name,side,s):
        raw=self.r.read_private(self.base/(name+'.'+side),131072)
        record=s['files'][name][side]
        require(record is not None and sha(raw)==record['sha'],'activation_before_image_changed')
        return raw
    def put(self,s,name,side):
        self.guard(s)
        record=s['files'][name][side];p=self.paths[name]
        if record is None:
            if os.path.lexists(p):p.unlink();self.lib.sync_directory(p.parent)
        else:
            self.m.replace(p,self.bytes(name,side,s),record)
        require(self.current(name)==record,'activation_write_not_effective')
    def checkpoint(self,s,step):
        s['step']=step;self.save(s);self.progress(step)
    def handoff(self,s,name,side):
        state=self.m.load()
        key=state['tool_sha'] if name=='tool' else state['files']['config']['after']
        require(key in (self.before(s,name)['sha'],self.after(s,name)['sha']),'HTTPS_handoff_drift')
        self.put(s,name,side)
        if name=='tool':state['tool_sha']=s['files'][name][side]['sha']
        else:state['files']['config']['after']=s['files'][name][side]['sha']
        self.m.save(state)
    def stage(self):
        commit,values=connect_install.validate_archive(self.archive,self.digest)
        old=self.i.current();require(old is not None,'receiver_not_installed')
        oldvalues=self.i.release_files(old);self.i.check_units(oldvalues)
        for name in connect_install.UNITS:
            key='units/ustar-connect-'+name+'.service'
            require(values[key]==oldvalues[key],'receiver_unit_change_requires_review')
        release=self.i.prefix/'releases'/commit
        if release.exists():require(self.i.release_files(release)==values,'receiver_release_collision')
        else:
            folder=Path(tempfile.mkdtemp(prefix='.bridge-release-',dir=release.parent))
            try:
                for name,raw in values.items():
                    p=folder/name
                    if p.parent!=folder:connect_install.mkdir(p.parent)
                    connect_install.write_file(p,raw)
                manifest={'schema':1,'source_commit':commit,'files':{n:sha(v) for n,v in values.items()}}
                connect_install.write_file(folder/'manifest.json',(json.dumps(manifest,sort_keys=True)+'\n').encode())
                folder.chmod(0o755);os.replace(folder,release);self.lib.sync_directory(release.parent)
            finally:
                if folder.exists():shutil.rmtree(folder)
        self.i.host.run(['systemd-analyze','verify',*[str(release/'units'/('ustar-connect-'+n+'.service')) for n in connect_install.UNITS]])
        return old,release
    def prepare(self):
        report=self.r.check();require(report['include_parent_protected'],'include_parent_not_prepared')
        old,new=self.stage()
        token=self.r.read_private(self.i.config/'telegram.token',512);bot=token.decode().strip().split(':',1)[0]
        key=secrets.token_hex(32)
        desired={'tool':self.source,'config':bridge.patch_config(self.lib.read_file(self.paths['config'])),
            'key':(key+'\n').encode(),'ca':self.m.read(self.m.p.old.ca),
            'include':bridge.php_settings(key,bot),'receiver_dropin':bridge.DROPIN,'probe_dropin':bridge.DROPIN,
            **{n:bridge.receiver_settings(self.r.read_private(self.paths[n]),n) for n in ('receiver','probe')}}
        rt=self.m.host.runtime(self.paths['config'])
        self.m.host.lint(rt,desired['config']);self.m.host.lint(rt,desired['include'])
        self.lib.directory(self.base,0o700,self.r.uid,self.r.root)
        s={'schema':1,'phase':'preparing','step':'captured','source_sha':sha(self.source),
            'old_release':old.name,'new_release':new.name,'was_active':self.i.host.active(SERVICE),
            'token_sha':sha(token),'files':{},'unit_hashes':{('ustar-connect-'+n+'.service'):
                sha(self.lib.read_file(self.i.units/('ustar-connect-'+n+'.service'))) for n in connect_install.UNITS}}
        for name,raw in desired.items():
            before=self.current(name)
            if before is not None:self.lib.atomic(self.base/(name+'.before'),self.lib.read_file(self.paths[name]))
            if before is None:
                mode=0o640 if name=='include' else 0o644 if name.endswith('dropin') else 0o600
                meta={'uid':self.r.uid,'gid':WEB_GID if name=='include' else os.getegid(),'mode':mode,'xattrs':{}}
            else:meta={k:v for k,v in before.items() if k!='sha'}
            self.lib.atomic(self.base/(name+'.after'),raw)
            s['files'][name]={'before':before,'after':{'sha':sha(raw),**meta}}
        self.save(s);return s
    def directories(self):
        for name in ('include','receiver_dropin','probe_dropin'):
            p=self.paths[name].parent
            if not os.path.lexists(p):
                p.mkdir(mode=0o700)
                if name=='include':os.chown(p,self.r.uid,WEB_GID)
                p.chmod(0o750 if name=='include' else 0o755);self.lib.sync_directory(p.parent)
            st=p.lstat();require(stat.S_ISDIR(st.st_mode) and st.st_uid==self.r.uid
                and not st.st_mode&0o022,'unsafe_activation_directory')
    def stop(self):
        self.i.host.run(['systemctl','stop',SERVICE])
        require(not self.i.host.active(SERVICE),'receiver_did_not_stop')
    def activate(self):
        if self.state.exists():
            s=self.load()
            if s['phase']=='active':return self.status()
            if s['phase']!='inactive':
                self.deactivate();raise bridge.Refusal('interrupted_activation_recovered_repeat_activate')
        s=self.prepare()
        try:
            self.stop();self.checkpoint(s,'receiver_stopped')
            self.handoff(s,'tool','after');self.checkpoint(s,'tool_handoff')
            self.m.set_bridge_enabled(True);self.checkpoint(s,'private_TLS')
            self.directories()
            for name in ('key','ca','include'):self.put(s,name,'after')
            self.checkpoint(s,'private_files')
            self.handoff(s,'config','after');self.checkpoint(s,'config_handoff')
            for name in ('receiver','probe','receiver_dropin','probe_dropin'):self.put(s,name,'after')
            self.i.switch(self.i.prefix/'releases'/s['new_release']);self.lib.sync_directory(self.i.prefix)
            self.i.host.run(['systemctl','daemon-reload']);self.checkpoint(s,'receiver_switch')
            self.guard(s,True);self.m.verify(self.m.load())
            self.i.host.run(['systemctl','start',PROBE]);self.checkpoint(s,'scope_verified')
            if s['was_active']:
                self.i.host.run(['systemctl','start',SERVICE]);require(self.i.host.active(SERVICE),'receiver_not_active')
            s['phase']='active';self.save(s)
            return self.status()
        except BaseException:
            # Do not hide a failed recovery. No employee records/cursors are restored.
            self.deactivate();raise
    def deactivate(self):
        s=self.load()
        if s['phase']=='inactive':return self.status()
        self.guard(s);self.stop();s['phase']='restoring';self.save(s)
        # Normalize the tool/state handoff before using the managed profile recovery.
        self.handoff(s,'tool','after')
        state=self.m.load()
        if state['phase']=='profile_changing':self.m.recover_profile(state)
        if self.m.load()['phase']=='renewing':self.m.recover_renewal(self.m.load())
        self.handoff(s,'config','before')
        self.m.set_bridge_enabled(False)
        for name in ('receiver','probe','receiver_dropin','probe_dropin','include','key','ca'):self.put(s,name,'before')
        for name in ('include','receiver_dropin','probe_dropin'):
            p=self.paths[name].parent
            if p.exists():p.rmdir();self.lib.sync_directory(p.parent)
        self.i.switch(self.i.prefix/'releases'/s['old_release']);self.lib.sync_directory(self.i.prefix)
        self.handoff(s,'tool','before')
        self.i.host.run(['systemctl','daemon-reload']);self.m.verify(self.m.load())
        if s['was_active']:
            self.i.host.run(['systemctl','start',SERVICE]);require(self.i.host.active(SERVICE),'previous_receiver_not_active')
        s['phase']='inactive';self.save(s);return self.status()
    def status(self):
        if not self.state.exists():return {'phase':'bridge_not_installed'}
        s=self.load();self.guard(s,active=s['phase']=='active')
        if s['phase'] in ('active','inactive'):self.m.verify(self.m.load())
        return {'phase':{'active':'bridge_active','inactive':'bridge_inactive'}.get(s['phase'],'bridge_recovery_required'),
            'receiver_active':self.i.host.active(SERVICE),'linking_enabled':s['phase']=='active',
            'notifications_enabled':False,'application_upgraded':False,'business_data_restored':False}


def bundle(path):
    expected={'__main__.py','connect_bridge_activation.py','connect_private_bridge.py',
        'academy_https.py','connect_install.py','receiver.tar.gz','REFERENCE.json','MANIFEST.json'}
    with zipfile.ZipFile(path) as z:
        require(len(z.namelist())==len(expected) and set(z.namelist())==expected,'bundle_inventory_changed')
        require(all(i.file_size<=1048576 for i in z.infolist()),'bundle_too_large')
        manifest=json.loads(z.read('MANIFEST.json'),object_pairs_hook=bridge.unique)
        require(set(manifest)=={'schema','tool_commit','files'} and manifest['schema']==1
            and set(manifest['files'])==expected-{'MANIFEST.json'},'invalid_bundle_manifest')
        for name,digest in manifest['files'].items():require(sha(z.read(name))==digest,'bundle_digest_changed')
        ref=json.loads(z.read('REFERENCE.json'),object_pairs_hook=bridge.unique)
        require(ref['tool_commit']==manifest['tool_commit'],'bundle_source_mismatch')
        return ref,z.read('academy_https.py'),z.read('receiver.tar.gz')


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    modes=parser.add_mutually_exclusive_group()
    for name in ('activate','deactivate','status'):modes.add_argument('--'+name,action='store_true')
    args=parser.parse_args(argv)
    if not any(vars(args).values()):print('{"phase":"plan_only","host_access":false}');return 0
    operation=None
    try:
        require(os.geteuid()==0 and sys.version_info>=(3,12),'root_python312_required')
        os.umask(0o077)
        def stop(*_):raise KeyboardInterrupt()
        for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,stop)
        path=Path(sys.argv[0])
        require(not any(p.is_symlink() for p in (path,*path.parents)),'unsafe_bundle_path')
        st=path.stat();require(st.st_uid==0 and st.st_nlink==1 and stat.S_ISREG(st.st_mode)
            and stat.S_IMODE(st.st_mode)==0o600 and st.st_size<=2097152,'unsafe_activation_bundle')
        for p in path.parents:
            st=p.stat();require(st.st_uid==0 and not st.st_mode&0o022,'unsafe_bundle_parent')
        ref,source,archive=bundle(path)
        manager=academy_https.Manager();reader=bridge.Readiness(ref,manager,connect_install.Installation())
        with bridge.locks(reader):
            # The same HTTPS lock is already held in the global deployment order.
            manager.locked=nullcontext
            with tempfile.TemporaryDirectory(prefix='ustar-bridge-',dir='/root') as td:
                receiver=Path(td)/'receiver.tar.gz';receiver.write_bytes(archive)
                operation=Deployment(reader,source,receiver,sha(archive))
                result=getattr(operation,next(n for n in ('activate','deactivate','status') if getattr(args,n)))()
        print(json.dumps(result,sort_keys=True));return 0
    except BaseException as error:
        known=isinstance(error,(bridge.Refusal,ValueError,RuntimeError)) or error.__class__.__name__=='Refusal'
        reason=str(error) if known and re.fullmatch('[A-Za-z0-9_]{1,100}',str(error)) else 'bridge_operation_failed'
        result={'phase':'refused','reason':reason}
        if operation is not None and operation.state.exists():
            try:result['recovery_state']=operation.load()['phase']
            except Exception:result['recovery_state']='inspect_required'
        print(json.dumps(result));return 2

if __name__=='__main__':raise SystemExit(main())
