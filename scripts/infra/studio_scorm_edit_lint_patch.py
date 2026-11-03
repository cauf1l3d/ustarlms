#!/usr/bin/env python3
"""Correct PHP-only syntax preflight for the qualified Studio SCORM overlay."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import types
from connect_con02_state import capture

BASELINE='4715383b8f6ec4b48fef855944be13a51c3cad61'
APPLICATION='5e61c0d2566e8103d01121968d56852e26da99fa'
WORKER='267e55874eb8f6c36259d748f28a2c636adc9810'
FILES=('local/ustar/classes/course_studio_material.php', 'local/ustar/course_studio.php', 'local/ustar/course_studio/app.js', 'local/ustar/course_studio/editor.html', 'local/ustar/course_studio/model.js', 'local/ustar/tests/course_studio_material_test.php')
LIBRARY_JOURNAL='var/lib/ustar-library-marketplace-'+BASELINE[:12]+'/state.json'
NAVIGATION_JOURNAL='var/lib/ustar-library-navigation-f89dde49cf22/state.json'
ORIGINAL_LIBRARY_JOURNAL='var/lib/ustar-library-update-26bd4fe05a7e/state.json'
MAINTENANCE='opt/ustar/data/moodle/moodledata/climaintenance.html'
ADAPTER_SHA='7d37e5adcbf4ebd53814807cb219ed04760e549b39350cf22c125d9e9777d579'
LEGACY_SHA='33e8b4fc09c7bd15e7b7c73e2c9e126e2dc50e96b6760e681675035001bb1761'
PRESERVED=('opt/ustar-connect/current','opt/ustar/data/moodle/public/config.php',
    'opt/ustar/data/moodle/public/.ustar-connect/bridge.php','etc/ustar-connect/receiver.json',
    'etc/ustar-connect/notifications.json','etc/ustar-connect/probe.json','etc/ustar-console-egress/xray.json',
    'etc/cron.d/ustar_moodle','usr/local/sbin/USTAR_CRON_SETUP_20261005.py','var/lib/ustar-cron/installation.json',
    LIBRARY_JOURNAL,NAVIGATION_JOURNAL,ORIGINAL_LIBRARY_JOURNAL)
ADAPTER_SOURCE=None
LEGACY_SOURCE=None


def digest(raw):return hashlib.sha256(raw).hexdigest()


def runtime():
    # Reuse qualified source/rollback mechanics in a private module; old operators stay frozen.
    folder=Path(__file__).parent
    adapter=ADAPTER_SOURCE if ADAPTER_SOURCE is not None else (folder/'scorm_tracking_patch.py').read_bytes()
    legacy=LEGACY_SOURCE if LEGACY_SOURCE is not None else (folder/'route_scorm_patch.py').read_bytes()
    if digest(adapter)!=ADAPTER_SHA or digest(legacy)!=LEGACY_SHA:raise ValueError('frozen_overlay_changed')
    isolated=types.ModuleType('_ustar_studio_scorm_edit_lint_adapter');isolated.__file__='<qualified-tracking-adapter>'
    exec(compile(adapter,isolated.__file__,'exec'),isolated.__dict__)
    isolated.BASELINE=BASELINE;isolated.APPLICATION=APPLICATION;isolated.FILES=FILES
    isolated.PRESERVED=PRESERVED;isolated.FROZEN_SOURCE=legacy
    m=isolated.runtime();original=m.Patch
    class SourceLintHost:
        """Route only manifest-qualified PHP bytes to the frozen PHP stdin linter."""
        def __init__(self,host,payload):
            self.host=host;self.names={raw:name for name,raw in payload.items()}
            m.require(len(self.names)==len(payload),'ambiguous_source_lint')
            m.require(all(Path(name).suffix in ('.php','.js','.html') for name in payload),'unsupported_source_lint_type')
        def __getattr__(self,name):return getattr(self.host,name)
        def lint(self,raw):
            name=self.names.get(raw)
            m.require(name is not None,'unknown_source_lint_payload')
            if name.endswith('.php'):
                try:self.host.lint(raw)
                except m.Refusal as error:
                    if str(error)!='host_command_failed':raise
                    raise m.Refusal('php_lint_failed:'+name) from None
    class Patch(original):
        def __init__(self,root,manifest,payload,host):
            super().__init__(root,manifest,payload,host)
            self.base=self.root/('var/lib/ustar-studio-scorm-edit-lint-'+APPLICATION[:12])
            self.host=SourceLintHost(host,payload)
        def identity(self,state=None):
            journal=self.root/LIBRARY_JOURNAL;image=capture(journal);parent=journal.parent.lstat()
            m.require(image['kind']=='file' and image['metadata']['uid']==os.geteuid()
                and image['metadata']['mode']==0o600 and parent.st_uid==os.geteuid()
                and stat.S_IMODE(parent.st_mode)==0o700,'private_previous_library_journal_required')
            m.require(json.loads(image['raw']).get('phase')=='active','previous_library_update_not_active')
            m.require(not os.path.lexists(self.root/MAINTENANCE),'existing_maintenance')
            return super().identity(state)
    m.Patch=Patch
    originalhost=m.Host
    class Host(originalhost):
        def __init__(self):super().__init__();self.cache_purged=False
        def start(self):
            super().start()
            self.run(['docker','exec','-u','33:33',self.container_id,'php',
                '-d','sendmail_path=/bin/false','/var/www/html/admin/cli/purge_caches.php'])
            self.cache_purged=True
    m.Host=Host
    return m


def main(argv=None,*,bundle=None):
    global ADAPTER_SOURCE,LEGACY_SOURCE
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
        manifest,payload,LEGACY_SOURCE,ADAPTER_SOURCE=bundle;m=runtime()
        host=m.Host();operation=m.Patch(Path('/'),manifest,payload,host)
        result=operation.check() if args.check else operation.recover() if args.recover else operation.apply()
        result.update(local_ustar='2026110200',theme_ustar='2026110200',schema_changed=False,
            database_changed=host.cache_purged,business_data_changed=False,cache_purged=host.cache_purged)
        print(json.dumps(result,sort_keys=True),flush=True);return 2 if result['phase']=='recovery_required' else 0
    except Exception as error:
        reason=str(error) if re.fullmatch('[a-zA-Z0-9_:/.-]+',str(error)) else 'studio_scorm_edit_lint_operation_failed'
        print(json.dumps({'phase':'refused','reason':reason},sort_keys=True),flush=True);return 2


if __name__=='__main__':raise SystemExit(main())
