#!/usr/bin/env python3
"""Worker-only successor accepting a verified, completed visual-v1 rollback."""
import importlib.util
import json
import os
from pathlib import Path
import sys

FROZEN_VISUAL_SHA='__FROZEN_VISUAL_SHA__'
LEGACY_WORKERS=('cb7128faa07629182aa5f1ada3a11299ac36cf24',
    '5646e2de78b798056168169249c2ed764062d756')


def load_visual(parent):
    import hashlib
    path=parent/'visual_v1.py'
    if hashlib.sha256(path.read_bytes()).hexdigest()!=FROZEN_VISUAL_SHA:
        raise ValueError('frozen_visual_changed')
    spec=importlib.util.spec_from_file_location('_fixed_visual_v1',path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    return module


if FROZEN_VISUAL_SHA.startswith('__'):
    import connect_bot_visual_update as v1  # Repository tests; packaged code uses hash-bound module.
else:v1=load_visual(Path(__file__).absolute().parent)
VisualBase=v1.VisualUpdate
bootstrap=v1.bootstrap


class VisualSuccessor(VisualBase):
    def __init__(self,ctx,candidate,*,base=None):
        name='var/lib/ustar-connect-visual-successor-'+candidate.reference['worker_commit'][:12]
        super().__init__(ctx,candidate,base=base or ctx.root/name)
        self.legacy_base=ctx.root/str(v1.BASE).lstrip('/')

    def legacy_raw(self):
        from connect_con02_contract import require
        from connect_con02_state import capture,unique
        from types import SimpleNamespace
        path=self.legacy_base/'state.json'
        if not os.path.lexists(path):return b'null\n'
        image=capture(path)
        require(image['kind']=='file' and image['metadata']['uid']==self.ctx.uid
            and image['metadata']['mode']==0o600 and len(image['raw'])<65536,'unsafe_visual_journal')
        data=json.loads(image['raw'],object_pairs_hook=unique)
        require(isinstance(data,dict) and data.get('worker_commit') in LEGACY_WORKERS,'unknown_legacy_visual')
        old=VisualBase(self.ctx,SimpleNamespace(reference={'worker_commit':data['worker_commit']}),base=self.legacy_base)
        state=old.read()
        require(state['phase']=='rolled_back','legacy_visual_recovery_required')
        require(v1.sha(self.old.path.read_bytes())==state['predecessor_sha256'],'predecessor_journal_changed')
        saved=self.old.load()
        require(saved and state['pointer']==saved['runtime']['files']['pointer']['after'],'visual_pointer_snapshot')
        return image['raw']

    def snapshot(self):
        from connect_con02_contract import require
        from connect_con02_state import capture
        path=self.base/'legacy-state.before.json'
        if not os.path.lexists(path):return None
        require(self.base.stat().st_uid==self.ctx.uid and self.base.stat().st_mode&0o777==0o700,'unsafe_visual_state_directory')
        image=capture(path)
        require(image['kind']=='file' and image['metadata']['uid']==self.ctx.uid
            and image['metadata']['mode']==0o600 and len(image['raw'])<65536,'unsafe_visual_journal')
        return image['raw']

    def read(self):
        from connect_con02_contract import require
        raw=self.legacy_raw();before=self.snapshot();state=super().read()
        require(before is None or before==raw,'legacy_visual_journal_changed')
        require(state is None or before is not None,'legacy_visual_snapshot_required')
        return state

    def save(self,state,phase):
        from connect_con02_contract import require
        from connect_con02_state import private_directory,replace
        raw=self.legacy_raw();before=self.snapshot()
        require(before is None or before==raw,'legacy_visual_journal_changed')
        if before is None:
            private_directory(self.base,self.ctx.uid)
            replace(self.base/'legacy-state.before.json',raw,
                {'uid':self.ctx.uid,'gid':os.getegid(),'mode':0o600,'xattrs':{}})
        return super().save(state,phase)

    def run(self,*,recover=False):
        if not recover:
            # Read-only identity/scope/TLS probe before staging/stopping workers.
            # The frozen transaction verifies controls again under deployment locks.
            self.check();self.host.probe('v2')
        return super().run(recover=recover)

    def restore(self,state):
        from connect_con02_application import stop_workers,SERVICES
        from connect_con02_contract import require
        # Restore only the hash-verified accepted baseline and unchanged controls.
        # Its normal startup checks identity and retries transport failures itself.
        # Recovery must not leave both consumers stopped behind a failed TLS probe.
        self.verify(state,application=False);stop_workers(self.host)
        self.switch(candidate=False)
        for unit in reversed(SERVICES):
            previous=state['services'][unit]
            if self.host.service_enabled(unit)!=previous['enabled']:
                self.host.service('enable' if previous['enabled'] else 'disable',unit)
            if previous['active']:self.host.service('start',unit)
            elif self.host.service_active(unit):self.host.service('stop',unit)
            require(self.host.service_active(unit)==previous['active']
                and self.host.service_enabled(unit)==previous['enabled'],'worker_restore_not_confirmed')
        self.verify(state,application=False)
        return self.save(state,'rolled_back')


def main(argv=None):
    if not any(a in ('--check','--upgrade','--recover') for a in (sys.argv[1:] if argv is None else argv)):
        return v1.main(argv)
    try:
        from stat import S_ISREG
        for path in (Path(__file__).absolute(),Path(__file__).absolute().parent/'visual_v1.py'):
            for item in (path,*path.parents):
                st=item.lstat()
                if st.st_uid!=0 or st.st_mode&0o022 or item.is_symlink():raise ValueError('unsafe_operator_path')
            if not S_ISREG(path.stat().st_mode) or path.stat().st_nlink!=1:raise ValueError('unsafe_operator_file')
        v1.VisualUpdate=VisualSuccessor
        return v1.main(argv)
    except BaseException:
        print(json.dumps({'phase':'refused','reason':'unsafe_successor_operator'}));return 2


if __name__=='__main__':raise SystemExit(main())
