#!/usr/bin/env python3
"""Worker-only presentation successor for accepted app2900 / role runtime. Offline by default."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import sys
from types import SimpleNamespace

# Replaced only by the immutable package builder.
PREDECESSOR_SHA='__PREDECESSOR_SHA__'
WORKER_SHA='__WORKER_SHA__'
WORKER_COMMIT='__WORKER_COMMIT__'
BASE=Path('/var/lib/ustar-connect-bot-visual-update')


def sha(raw):return hashlib.sha256(raw).hexdigest()


def bootstrap(parent):
    raw=(parent/'predecessor.py').read_bytes()
    if sha(raw)!=PREDECESSOR_SHA:raise ValueError('predecessor_changed')
    needle=b'sys.exit(main(bundle=bundle))'
    if raw.count(needle)!=1:raise ValueError('predecessor_bootstrap')
    # Keep every reviewed module and envelope byte. Suppress its CLI dispatch only.
    scope={'__file__':str(parent/'predecessor.py'),'__name__':'_visual_predecessor'}
    exec(compile(raw.replace(needle,b'_visual_bundle=bundle'),'<fixed-2900-predecessor>','exec'),scope)
    return scope['_visual_bundle']


class VisualUpdate:
    def __init__(self,ctx,candidate,*,base=None):
        from connect_con02_state import Journal,Locks
        self.ctx,self.host,self.candidate=ctx,ctx.host,candidate
        self.old=Journal(ctx);self.locks=Locks(ctx)
        self.base=base or ctx.root/str(BASE).lstrip('/')
        self.path=self.base/'state.json'

    def read(self):
        from connect_con02_contract import require
        from connect_con02_state import capture,unique
        if not os.path.lexists(self.path):return None
        require(self.base.stat().st_uid==self.ctx.uid and self.base.stat().st_mode&0o777==0o700,'unsafe_visual_state_directory')
        image=capture(self.path)
        require(image['kind']=='file' and image['metadata']['uid']==self.ctx.uid
            and image['metadata']['mode']==0o600 and len(image['raw'])<65536,'unsafe_visual_journal')
        state=json.loads(image['raw'],object_pairs_hook=unique)
        require(set(state)=={'schema','worker_commit','phase','services','pointer','predecessor_sha256'}
            and state['schema']==1 and state['worker_commit']==self.candidate.reference['worker_commit']
            and state['phase'] in ('prepared','workers_stopped','pointer_switched','visuals_active','rolled_back','recovery_required'),
            'visual_journal_identity')
        from connect_con02_application import SERVICES
        require(set(state['services'])==set(SERVICES) and all(set(v)=={'active','enabled'}
            and all(type(n) is bool for n in v.values()) for v in state['services'].values()),'visual_service_snapshot')
        return state

    def save(self,state,phase):
        from connect_con02_state import private_directory,replace
        private_directory(self.base,self.ctx.uid)
        state={**state,'phase':phase}
        replace(self.path,(json.dumps(state,sort_keys=True)+'\n').encode(),
            {'uid':self.ctx.uid,'gid':os.getegid(),'mode':0o600,'xattrs':{}})
        if hasattr(self.ctx,'progress'):self.ctx.progress(phase)
        return state

    def pointer(self):return self.host.control_paths()['pointer']

    def verify_application(self,saved):
        from connect_con02_application import Application
        import connect_role_bot_update as role
        Application(self.ctx,self.old,self.locks).bind(saved['app'])
        self.host.source(target=True)
        role.verify_database(self.host.database(saved['app']['prefix']),saved['app']['database'],self.ctx.bundle.reference,target=True)

    def verify(self,state=None,*,application=True):
        from connect_con02_contract import require,require_https_unchanged
        from connect_con02_runtime import worker_files
        from connect_con02_state import capture,descriptor
        saved=self.old.load()
        require(saved and (saved.get('app') or {}).get('phase')=='app_ready'
            and (saved.get('runtime') or {}).get('phase')=='runtime_active','accepted_active2900_required')
        if state:require(sha(self.old.path.read_bytes())==state['predecessor_sha256'],'predecessor_journal_changed')
        expected={**saved['app']['controls'],**{n:pair['after'] for n,pair in saved['app']['files'].items()},
            **{n:pair['after'] for n,pair in saved['runtime']['files'].items()}}
        for name,image in expected.items():
            if name=='pointer':continue
            require(descriptor(capture(Path(image['path'])))==image,'visual_control_drift')
        oldpointer=saved['runtime']['files']['pointer']['after']
        oldraw=self.old.read_private(self.old.folder(saved['runtime'])/'pointer.after')
        require(oldpointer['kind']=='symlink' and oldraw==str(self.ctx.root/'opt/ustar-connect/releases'/self.ctx.bundle.reference['worker_commit']).encode(),
            'accepted_pointer_identity')
        if state:require(state['pointer']==oldpointer,'visual_pointer_snapshot')
        actual=capture(self.pointer())
        require(actual['kind']=='symlink' and actual['metadata']==oldpointer['metadata'] and actual['raw'] in
            (oldraw,str(self.ctx.root/'opt/ustar-connect/releases'/self.candidate.reference['worker_commit']).encode()),'unknown_worker_pointer')
        if state is None:require(actual['raw']==oldraw,'active_visual_transaction_required')
        # No new drop-ins, generated secrets or alternate unit search paths.
        for name in ('receiver','probe','notify'):
            unit='ustar-connect-'+name+'.service'
            allowed={'50-ustar-notify-pilot.conf'} if name=='notify' else {'40-ustar-link.conf','60-ustar-con02-ack.conf','70-ustar-role-bot.conf'}
            folder=self.ctx.root/'etc/systemd/system'/(unit+'.d')
            require(folder.is_dir() and not folder.is_symlink() and {p.name for p in folder.iterdir()}<=allowed,'unknown_runtime_override')
            for base in ('run/systemd/system','usr/lib/systemd/system','lib/systemd/system'):
                path=self.ctx.root/base/unit
                require(not os.path.lexists(path) and not os.path.lexists(path.with_name(unit+'.d')),'unknown_runtime_override')
        release=self.host.legacy().installation.current()
        bundle=self.candidate if release and release.name==self.candidate.reference['worker_commit'] else self.ctx.bundle
        require(release is not None and self.host.legacy().installation.release_files(release)==worker_files(bundle),'visual_worker_changed')
        require_https_unchanged(self.ctx,saved['runtime']['https'])
        if application:self.verify_application(saved)
        return saved

    def switch(self,*,candidate):
        from connect_con02_contract import require
        from connect_con02_state import capture,sync
        # Reuse the reviewed installer's atomic pointer replacement and parent guards.
        installation=self.host.legacy().installation
        target=self.ctx.root/'opt/ustar-connect/releases'/(self.candidate.reference['worker_commit'] if candidate else self.ctx.bundle.reference['worker_commit'])
        require(target.is_dir() and not target.is_symlink(),'worker_release_required')
        installation.switch(target);sync(self.pointer().parent)
        require(capture(self.pointer())['raw']==str(target).encode(),'visual_pointer_not_effective')

    def restore(self,state):
        from connect_con02_application import stop_workers,restore_workers
        self.verify(state,application=False)
        stop_workers(self.host)
        self.switch(candidate=False)
        restore_workers(self.host,state['services'],profile='v2')
        self.verify(state,application=False)
        return self.save(state,'rolled_back')

    def check(self):
        from connect_con02_state import capture
        with self.locks.check():
            state=self.read();self.verify(state)
            active=capture(self.pointer())['raw']==str(self.ctx.root/'opt/ustar-connect/releases'/self.candidate.reference['worker_commit']).encode()
            return {'phase':'visuals_ready' if active else 'ready_for_visual_update','local_ustar':'2026102900',
                'host_changes':False,'provider_called':False,'worker_commit':self.candidate.reference['worker_commit'] if active else self.ctx.bundle.reference['worker_commit'],
                'recovery_required':bool(state and state['phase'] not in ('visuals_active','rolled_back'))}

    def failure_reason(self,error):
        from connect_con02_contract import Refusal
        if isinstance(error,Refusal) and re.fullmatch('[a-z][a-z0-9_]{1,99}',str(error)):return str(error)
        return 'visual_switch_failed'

    def run(self,*,recover=False):
        from connect_con02_application import stop_workers,restore_workers,service_snapshot
        from connect_con02_contract import require
        from connect_con02_runtime import stage_worker
        from connect_con02_state import capture
        with self.locks.prebackup(),self.locks.cold():
            state=self.read();saved=self.verify(state)
            if recover:
                require(state is not None,'visual_transaction_required')
                return self.restore(state)
            if state and state['phase']=='visuals_active':
                require(capture(self.pointer())['raw']==str(self.ctx.root/'opt/ustar-connect/releases'/self.candidate.reference['worker_commit']).encode(),'visual_active_pointer_drift')
                return {'phase':'visuals_active','verify_only':True}
            if state and state['phase'] not in ('rolled_back',):
                return {**self.restore(state),'update_succeeded':False}
            # Stage and unit verification are done before stopping services.
            stage_worker(SimpleNamespace(bundle=self.candidate,host=self.host,root=self.ctx.root,uid=self.ctx.uid))
            state={'schema':1,'worker_commit':self.candidate.reference['worker_commit'],'phase':'prepared',
                'services':service_snapshot(self.host),'pointer':saved['runtime']['files']['pointer']['after'],
                'predecessor_sha256':sha(self.old.path.read_bytes())}
            self.save(state,'prepared')
            try:
                stop_workers(self.host);self.save(state,'workers_stopped')
                self.switch(candidate=True);self.save(state,'pointer_switched')
                restore_workers(self.host,state['services'],profile='v2')
                self.verify(state)
                self.save(state,'visuals_active')
                return {'phase':'visuals_active','local_ustar':'2026102900','worker_commit':state['worker_commit'],'app_changed':False}
            except BaseException as error:
                reason=self.failure_reason(error)
                try:self.restore(state)
                except BaseException:
                    self.save(state,'recovery_required')
                    return {'phase':'recovery_required','update_succeeded':False,'reason':reason}
                return {'phase':'rolled_back','update_succeeded':False,'reason':reason}


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group()
    for name in ('check','upgrade','recover'):group.add_argument('--'+name,action='store_true')
    args=parser.parse_args(argv)
    if not any((args.check,args.upgrade,args.recover)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    try:
        if os.geteuid()!=0:raise ValueError('requires_root')
        from stat import S_ISREG
        parent=Path(__file__).absolute().parent
        for path in (Path(__file__),parent/'predecessor.py',parent/'worker.tar.gz'):
            for p in (path,*path.parents):
                st=p.lstat()
                if st.st_uid!=0 or st.st_mode&0o022 or p.is_symlink():raise ValueError('unsafe_operator_path')
            if not S_ISREG(path.stat().st_mode) or path.stat().st_nlink!=1:raise ValueError('unsafe_operator_file')
        bundle=bootstrap(parent)
        import connect_role_bot_update as role
        from connect_con02_runtime import worker_files
        from connect_con02_contract import require
        archive=(parent/'worker.tar.gz').read_bytes()
        require(len(archive)<16*1024**2 and sha(archive)==WORKER_SHA,'candidate_worker_changed')
        candidate=SimpleNamespace(reference={'worker_commit':WORKER_COMMIT},worker_archive=archive)
        worker_files(candidate)
        ctx=role.make_context(bundle)
        for sig in (signal.SIGTERM,signal.SIGINT,signal.SIGHUP):
            signal.signal(sig,lambda *_:(_ for _ in ()).throw(KeyboardInterrupt()))
        if not args.check:ctx.progress=lambda phase:print(json.dumps({'phase':phase}),flush=True)
        operation=VisualUpdate(ctx,candidate)
        result=operation.check() if args.check else operation.run(recover=args.recover)
        # Only a fixed allowlist leaves the host; journal/config/raw errors remain private.
        if args.recover:result={k:v for k,v in result.items() if k in ('phase','update_succeeded')}
        print(json.dumps(result,sort_keys=True),flush=True)
        return 2 if result.get('update_succeeded') is False or result.get('recovery_required') else 0
    except BaseException as error:
        reason=str(error)
        if not re.fullmatch('[a-z][a-z0-9_]{1,99}',reason):reason='visual_update_failed'
        print(json.dumps({'phase':'refused','reason':reason}),flush=True);return 2


if __name__=='__main__':raise SystemExit(main())
