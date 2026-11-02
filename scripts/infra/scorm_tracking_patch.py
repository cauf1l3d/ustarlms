#!/usr/bin/env python3
"""Fixed1100 source overlay for native SCORM tracking. Default is offline."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
import types
from connect_con02_state import capture, descriptor, replace

BASELINE='b9f170d89cead30021b0acbfba6ee681cec024f8'
APPLICATION='2150affe7e2964a1056468cce0e438f9e2f91dcb'
WORKER='267e55874eb8f6c36259d748f28a2c636adc9810'
FROZEN_SHA='33e8b4fc09c7bd15e7b7c73e2c9e126e2dc50e96b6760e681675035001bb1761'
FINISH_SHA='38fa5e373a319e8bba4bb0cee3e69d9e5930432bee9e85b6c9dca9ff2a4aa76d'
FILES=('local/ustar/classes/scorm_route_launch.php','local/ustar/tests/scorm_route_launch_test.php')
PRESERVED=('opt/ustar-connect/current','opt/ustar/data/moodle/public/config.php',
    'opt/ustar/data/moodle/public/.ustar-connect/bridge.php','etc/ustar-connect/receiver.json',
    'etc/ustar-connect/notifications.json','etc/ustar-connect/probe.json','etc/ustar-console-egress/xray.json',
    'etc/cron.d/ustar_moodle','usr/local/sbin/USTAR_CRON_SETUP_20261005.py','var/lib/ustar-cron/installation.json')
FROZEN_SOURCE=None

def sha256(raw):return hashlib.sha256(raw).hexdigest()

def runtime():
    # Isolated reuse: no published legacy module, source or archive is modified.
    raw=FROZEN_SOURCE if FROZEN_SOURCE is not None else Path(__file__).with_name('route_scorm_patch.py').read_bytes()
    if sha256(raw)!=FROZEN_SHA:raise ValueError('frozen_overlay_changed')
    m=types.ModuleType('_ustar_tracking_overlay');m.__file__='<qualified-route-scorm-overlay>'
    exec(compile(raw,m.__file__,'exec'),m.__dict__)
    m.BASELINE=BASELINE;m.APPLICATION=APPLICATION;m.CHANGED=FILES;m.ADDED=();m.FILES=FILES
    def read(path):
        m.parents_safe(path);image=capture(path)
        m.require(image['kind']=='file','unsafe_regular_file')
        return image['raw'],image['metadata']
    def atomic(path,raw,metadata):
        m.parents_safe(path);replace(Path(path),raw,{'xattrs':{},**metadata})
    m.read=read;m.atomic=atomic
    original=m.Patch
    class Patch(original):
        def __init__(self,root,manifest,payload,host):
            super().__init__(root,manifest,payload,host)
            m.require(manifest.get('preserved')==list(PRESERVED),'fixed_preserved_controls_required')
            self.base=self.root/('var/lib/ustar-scorm-tracking-fix-'+APPLICATION[:12])
        def controls(self):return {p:descriptor(capture(self.root/p)) for p in PRESERVED}
        def record(self,state,phase):
            if 'controls' not in state:state['controls']=self.controls()
            m.require(self.controls()==state['controls'],'preserved_controls_changed')
            super().record(state,phase)
        def identity(self,state=None):
            if state and 'controls' in state:
                m.require(self.controls()==state['controls'],'preserved_controls_changed')
                for name,item in state['files'].items():
                    m.require(capture(self.web/name)['metadata']==item['before'],'source_metadata_changed')
            return super().identity(state)
    m.Patch=Patch
    originalhost=m.Host
    class Host(originalhost):
        def identity(self):
            row=json.loads(self.run(['docker','inspect','--format',
                '{"Id":{{json .Id}},"Image":{{json .Image}},"State":{"Running":{{json .State.Running}}},"Mounts":{{json .Mounts}}}',
                'ustar_moodle']))
            m.require(row['Image']=='sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b','web_image_changed')
            m.require(any(p['Source']=='/opt/ustar/data/moodle/public' and p['Destination']=='/var/www/html'
                for p in row['Mounts']),'web_bind_changed')
            m.require(self.container_id is None or self.container_id==row['Id'],'web_container_replaced')
            self.container_id=row['Id'];return row
        def start(self):
            super().start()
            for attempt in range(5):
                try:
                    status=self.run(['curl','--silent','--show-error','--noproxy','*','--connect-timeout','3',
                        '--max-time','10','--output','/dev/null','--write-out','%{http_code}',
                        '--header','Host: ustar.local','http://127.0.0.1:8082/login/index.php'],timeout=12).strip()
                except m.Refusal as error:
                    if str(error)!='host_command_failed':raise
                    status=b'000'
                if status==b'200':return
                if attempt<4:time.sleep(1)
            raise m.Refusal('moodle_backend_login_failed')
    m.Host=Host
    return m

def previous_finish(root,*,apply):
    """The qualified companion completes only committed f879, with all its native DB guards."""
    script=Path(sys.argv[0]).absolute().with_name('finish.py')
    image=capture(script)
    if image['kind']!='file' or sha256(image['raw'])!=FINISH_SHA:
        raise ValueError('qualified_finish_companion_required')
    meta=image['metadata']
    if meta['uid']!=0 or meta['mode']!=0o600:raise ValueError('private_root_artifact_required')
    journal=Path(root)/('var/lib/ustar-route-review-update-'+BASELINE[:12])/'state.json'
    before=capture(journal);folder=journal.parent.lstat()
    if (before['kind']!='file' or before['metadata']['uid']!=0 or before['metadata']['mode']!=0o600
            or folder.st_uid!=0 or stat.S_IMODE(folder.st_mode)!=0o700):
        raise ValueError('private_previous_journal_required')
    phase=json.loads(before['raw']).get('phase')
    # A completed receipt is historical: normal cron backoff and worker state can change.
    # The overlay independently verifies all614 source files and preserves current controls.
    if phase=='active':return {'phase':'active','host_changes':False}
    if phase!='publication_committed':raise ValueError('previous_update_finish_required')
    def run(options):
        result=subprocess.run([sys.executable,'-I','-B',str(script),*options],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=240)
        if result.returncode or len(result.stdout)>65536:raise ValueError('previous_update_finish_required')
        value=json.loads(result.stdout.splitlines()[-1])
        if value.get('phase') not in ('active','ready_to_finish'):raise ValueError('previous_update_finish_required')
        return value
    status=run(['--check'])
    if apply and status['phase']=='ready_to_finish':
        status=run(['--recover','--acknowledge-outage'])
        if status['phase']!='active':raise ValueError('previous_update_finish_required')
    return status

def main(argv=None,*,bundle=None):
    global FROZEN_SOURCE
    parser=argparse.ArgumentParser(description=__doc__);mode=parser.add_mutually_exclusive_group()
    for name in ('check','upgrade','recover'):mode.add_argument('--'+name,action='store_true')
    parser.add_argument('--acknowledge-outage',action='store_true');args=parser.parse_args(argv)
    if not any((args.check,args.upgrade,args.recover)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'source_only':True}));return 0
    if not args.check and not args.acknowledge_outage:parser.error('upgrade/recover requires --acknowledge-outage')
    try:
        path=Path(sys.argv[0]).absolute();image=capture(path);parent=path.parent.lstat()
        if (os.geteuid()!=0 or image['kind']!='file' or image['metadata']['uid']!=0
                or image['metadata']['mode']!=0o600 or not stat.S_ISDIR(parent.st_mode)
                or parent.st_uid!=0 or stat.S_IMODE(parent.st_mode)!=0o700):
            raise ValueError('private_root_artifact_required')
        if bundle is None:raise ValueError('use_built_verified_package')
        manifest,payload,FROZEN_SOURCE=bundle;m=runtime();operation=m.Patch(Path('/'),manifest,payload,m.Host())
        state=operation.saved()
        # Finish f879 before acquiring its shared managed locks; no migrations or source changes are repeated.
        if (not state or state['phase']=='rolled_back') and not args.recover:
            previous=previous_finish(operation.root,apply=args.upgrade)
        else:previous=None
        result=operation.check() if args.check else operation.recover() if args.recover else operation.apply()
        if previous:result['previous_update']=previous['phase']
        result['local_ustar']='2026110100';result['schema_changed']=False
        print(json.dumps(result,sort_keys=True),flush=True);return 2 if result['phase']=='recovery_required' else 0
    except Exception as error:
        reason=str(error) if re.fullmatch('[a-zA-Z0-9_:/.-]+',str(error)) else 'scorm_tracking_operation_failed'
        print(json.dumps({'phase':'refused','reason':reason},sort_keys=True),flush=True);return 2

if __name__=='__main__':raise SystemExit(main())
