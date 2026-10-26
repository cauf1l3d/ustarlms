#!/usr/bin/env python3
"""Paired 1905-to-2000 addon/cron update. Default plan never reads the host.

Reuse accepted copy/control primitives; the original RC installer and its
immutable release contract are not changed. No bridge, key or bot activation.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import importlib
import io
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys
import tempfile
import time
import traceback
from types import SimpleNamespace
import uuid
import zipfile

import academy_https as https
import backup_https_prepare as backup_tls
import connect_academy_installer as engine
import connect_delivery_readiness as delivery
import cron_release_update as cron

require = engine.require
CURRENT = {'local_ustar':2026101905, 'theme_ustar':2026100202}
TARGET = {'local_ustar':2026102000, 'theme_ustar':2026100202}
BASELINE = '830d3703e2ffa2f9864900c0d0c58f60049cb0c2'
PAYLOAD = '1e8a8819f50785ebb4fb2640eeb9386c969d59d1'
CURRENT_CRON_SHA = 'c5eabdceaa2f137eccd35087b03d473f61257aaeb77624f4855583ebaf353638'
TARGET_CRON_SHA = 'ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751'
BASE = Path('/var/lib/ustar-connect-chat-link-update')
DELTA = frozenset(('local/ustar/classes/connect/identity_service.php',
    'local/ustar/classes/connect/link_management.php','local/ustar/templates/connect.mustache',
    'local/ustar/tests/connect_bridge_test.php','local/ustar/tests/connect_link_management_test.php',
    'local/ustar/version.php'))


def sha(raw): return hashlib.sha256(raw).hexdigest()


def bundle(path):
    with zipfile.ZipFile(path) as archive:
        names=archive.namelist()
        require(len(names)==len(set(names)) and len(names)<1000, 'bundle_inventory')
        require(sum(i.file_size for i in archive.infolist())<=256*1024**2, 'bundle_size')
        manifest=json.loads(archive.read('INSTALL_MANIFEST.json'))
        require(manifest['schema']==1 and manifest['payload_commit']==PAYLOAD
                and manifest['baseline_commit']==BASELINE, 'bundle_source')
        require(set(names)==set(manifest['files'])|{'INSTALL_MANIFEST.json'}, 'bundle_inventory')
        for name,digest in manifest['files'].items():
            require(not Path(name).is_absolute() and '..' not in Path(name).parts
                    and '\\' not in name and not name.endswith('/'), 'bundle_path')
            require(sha(archive.read(name))==digest, 'bundle_hash')
        reference=json.loads(archive.read('reference.json'))
        import connect_readiness
        connect_readiness.validate_reference(reference)
        require(reference['baseline_commit']==BASELINE and reference['source_commit']==PAYLOAD
                and reference['baseline']['versions']==CURRENT
                and reference['candidate']['versions']==TARGET, 'release_reference')
        before=reference['baseline'];after=reference['candidate']
        require(set(before['files'])==set(after['files']) and len(after['files'])==552
                and before['tables']==after['tables'] and len(after['tables'])==99, 'release_inventory')
        require({p for p in before['files'] if before['files'][p]!=after['files'][p]}==DELTA,
                'release_delta')
        payload={name:archive.read('payload/'+name) for name in after['files']}
        engine.verify_payload(reference,payload)
        return reference,payload,manifest


def dependencies(path):
    modules={key:importlib.import_module(name) for key,name in (
        ('p','production_patch_readiness'),('cold','lab_cold_checkpoint'),
        ('u','lab_patch_upgrade'),('c','lab_patch_candidate'),
        ('tx','production_patch_upgrade'),('w','production_patch_switch_preflight'),
        ('probe','connect_readiness'))}
    d=SimpleNamespace(**modules)
    d.reference,d.payload,d.manifest=bundle(path)
    with zipfile.ZipFile(path) as archive:
        d.backup_tls=backup_tls.Manager({k:archive.read('backup_tls_sources/'+k+'.py') for k in backup_tls.AFTER})
    # New release profile, without rewriting the historical helper manifest.
    d.w=SimpleNamespace(**vars(d.w))
    d.w.HELPERS={**d.w.HELPERS,'hdd_backup':(d.w.HELPERS['hdd_backup'][0],backup_tls.AFTER['producer'])}
    d.base,d.marker=BASE,BASE/'active.json'
    d.web=d.p.CODE/'public'
    d.trees={'code':d.p.CODE,'moodledata':d.p.DATA,'postgres':d.p.PGDATA}
    d.cold.reserve=lambda: d.tx.reserve(d.p)
    d.c.reserve=lambda: d.tx.reserve(d.p)
    d.controls=Controls()
    return d


class Controls:
    def __init__(self,root=Path('/'),uid=0):
        self.root,self.uid=Path(root),uid
        self.p=cron.Paths(self.root)
        self.writer=cron.Manager(self.p,uid=uid)
        self.https_lock=self.root/'var/lib/ustar-academy-https/lock'

    @contextmanager
    def locked_file(self,path,wait_seconds=0):
        before=self.writer.secure(path,private=True)
        fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
        try:
            require((os.fstat(fd).st_dev,os.fstat(fd).st_ino)==(before.st_dev,before.st_ino), 'lock_replaced')
            deadline=time.monotonic()+wait_seconds
            while True:
                try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);break
                except BlockingIOError:
                    require(time.monotonic()<deadline, 'managed_lock_busy')
                    time.sleep(0.1)
            yield
            now=path.lstat()
            require((now.st_dev,now.st_ino)==(before.st_dev,before.st_ino), 'lock_replaced')
        finally: os.close(fd)

    def inputs(self):
        raw=delivery.secure_read(self.root,str(self.p.script.relative_to(self.root)),self.uid)
        require(sha(raw)==CURRENT_CRON_SHA, 'cron_source_changed')
        old=b"VERSIONS = {'local_ustar': '2026101905', 'theme_ustar': '2026100202'}"
        require(raw.count(old)==1, 'cron_version_line')
        target=raw.replace(old,old.replace(b'2026101905',b'2026102000'))
        require(sha(target)==TARGET_CRON_SHA, 'target_cron_digest')
        compile(target,'reviewed_cron_v3','exec')
        state=delivery.control_json(self.root,str(self.p.installation.relative_to(self.root)),self.uid)
        require(state.get('schema')==1 and state.get('versions')=={k:str(v) for k,v in CURRENT.items()}
                and state.get('script_sha256')==CURRENT_CRON_SHA, 'cron_installation_changed')
        require(self.writer.read(self.p.cron)==cron.CRON_TEXT, 'cron_schedule_changed')
        return raw,target,state

    def capture(self,run):
        raw,target,state=self.inputs()
        desired=(json.dumps({**state,'versions':{k:str(v) for k,v in TARGET.items()},
                            'script_sha256':TARGET_CRON_SHA},ensure_ascii=False,indent=2)+'\n').encode()
        result={'schedule':sha(cron.CRON_TEXT)}
        for name,path,before,after in (('script',self.p.script,raw,target),
                ('installation',self.p.installation,self.writer.read(self.p.installation,True),desired)):
            result[name]={'before':sha(before),'after':sha(after),'meta':self.writer.metadata(path)}
            self.writer.private_write(run/(name+'.before'),before)
            self.writer.private_write(run/(name+'.after'),after)
        return result

    def validate_pair(self,record,run):
        require(sha(self.writer.read(self.p.cron))==record['schedule'], 'cron_schedule_changed')
        for name,path in (('script',self.p.script),('installation',self.p.installation)):
            info=record[name]
            require(sha(self.writer.read(path,name=='installation')) in (info['before'],info['after']),
                    'cron_administrator_drift')
            require(self.writer.metadata(path)==info['meta'], 'cron_metadata_changed')
            for suffix in ('before','after'):
                require(sha(self.writer.read(run/(name+'.'+suffix),True))==info[suffix], 'cron_image_changed')

    def apply_pair(self,record,run,target=True):
        self.validate_pair(record,run)
        suffix='after' if target else 'before'
        for name,path in (('script',self.p.script),('installation',self.p.installation)):
            self.writer.replace(path,self.writer.read(run/(name+'.'+suffix),True),record[name]['meta'])
        self.validate_pair(record,run)
        require(all(sha(self.writer.read(path,name=='installation'))==record[name][suffix]
                for name,path in (('script',self.p.script),('installation',self.p.installation))),
                'cron_pair_readback')


def https_snapshot(d):
    delivery.https_contract(d.controls.root,d.controls.uid)
    raw=delivery.secure_read(d.controls.root,'var/lib/ustar-academy-https/state.json',d.controls.uid,True)
    return {'state_sha256':sha(raw)}


class LoginProbe(https.Host):
    def __init__(self):
        # request/app_check use only the fixed ports and public CA. No key or
        # installed-helper definition is loaded for this HTTPS HTTP request.
        self.lib=None


class Host(engine.Host):
    def login(self):
        state=delivery.control_json(self.d.controls.root,'var/lib/ustar-academy-https/state.json',0)
        ca=self.d.controls.root/'etc/ustar-console/authority/ca.crt'
        LoginProbe().app_check(ca,state['leaf'])
        return 200


def verify_versions(d,current,baseline,candidate=False):
    wanted=dict(baseline['addons'])
    if candidate: wanted.update({k:str(v) for k,v in TARGET.items()})
    require(current['addons']==wanted and current['core']==baseline['core'], 'database_versions')
    require(current['tables']==baseline['tables'] and current['schema']==baseline['schema']
            and current['indexes']==baseline['indexes'], 'schema_changed')
    require(current['tasks']==baseline['tasks'], 'scheduled_task_flags')


def preflight(d):
    require(d.probe.webroot(d.p.CODE)==d.web, 'production_layout')
    require(not os.path.lexists(d.p.DATA/'climaintenance.html')
            and not os.path.lexists(d.p.STATE/'moodle-was-running.json'), 'recovery_or_maintenance_pending')
    d.p.mount()
    for name,(path,digest) in d.w.HELPERS.items():
        if name=='cron': continue  # Accepted c5eabdce is checked as a coordinated pair below.
        d.p.root_input(path)
        require(d.p.read_file(path,1024**2,True)==digest, 'installed_helper_changed')
    d.identities=d.p.containers()
    policy=d.w.docker_conditions(d.p,d.identities)
    require(len({r[0] for r in d.tx.roots(d).values()})==1, 'production_filesystems')
    engine.source(d)
    d.controls.inputs()
    delivery.services_contract(d.p.command)
    require(d.backup_tls.check()['phase']=='already_applied', 'backup_HTTPS_readiness_update_required')
    snapshot=https_snapshot(d)
    config=d.p.read_file(d.p.CODE/'config.php',128*1024);engine.config_guard(config)
    prefix=d.p.parse_prefix(config.decode())
    with io.BytesIO() as log: current=Host(d,d.identities,log).database(prefix)
    require({k:current['addons'].get(k) for k in CURRENT}=={k:str(v) for k,v in CURRENT.items()}, 'baseline_versions')
    require(current['tables']==d.reference['baseline']['tables'], 'baseline_tables')
    require(current['core'] in ('2025100608','2025100608.00'), 'core_changed')
    return {'status':'ready_for_chat_link_update','capacity':engine.capacity(d),
            'restart_policies':policy['restart_policies'],'https':snapshot,
            'current':CURRENT,'target':TARGET,'activation':False}


def validate_marker(d):
    marker=d.p.read_json(d.marker);run=Path(marker.get('run_directory',''))
    require(run.parent==d.base and re.fullmatch('update-[a-z0-9_]+',run.name), 'transaction_directory')
    d.p.root_input(d.marker);d.p.no_links(run)
    st=run.lstat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid==engine.ROOT_UID and not st.st_mode&0o077,
            'transaction_directory')
    require(marker.get('kind')=='connect_chat_link_update' and marker.get('payload_commit')==PAYLOAD
            and marker.get('tool_commit')==d.manifest['tool_commit'], 'transaction_identity')
    require(marker.get('roots')==d.tx.roots(d) and marker.get('web_root')==str(d.web), 'transaction_roots')
    require(set(marker.get('identities',{}))==set(d.p.IMAGES), 'transaction_containers')
    for name,row in marker['identities'].items():
        require(row.get('Image')==d.p.IMAGES[name] and re.fullmatch('[a-f0-9]{64}',row.get('Id','')),
                'transaction_container_identity')
    require(re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}',marker.get('prefix','')), 'transaction_prefix')
    return marker


def recover_locked(d,marker,host):
    host.policy('no');host.stop()
    require(not any(row['State']['Running'] for row in host.inspect().values()), 'stop_not_confirmed')
    require(https_snapshot(d)==marker['https'], 'https_state_changed')
    if marker.get('live_trees_modified'): d.tx.read_pair(d,marker)
    if marker.get('cron_pair'):
        d.controls.apply_pair(marker['cron_pair'],Path(marker['run_directory']),False)
    # Baseline1905 already contains the cleanup task; candidate=False in this
    # accepted recovery function preserves the entire exact baseline task map.
    engine.recover(d,marker,host)
    require(https_snapshot(d)==marker['https'], 'https_state_changed')


def perform(d,marker,host,baseline,policies):
    require(not os.path.lexists(d.p.STATE/'moodle-was-running.json'), 'backup_resume_marker')
    engine.source(d);d.controls.inputs()
    require(https_snapshot(d)==marker['https'], 'https_state_changed')
    engine.record(d,marker,phase='stopping_production',switch_started=True)
    host.policy('no');host.stop_web(clean=True)
    current=host.database(marker['prefix']);verify_versions(d,current,baseline)
    require(current['other_clients']==0, 'other_database_clients')
    host.stop(clean=True);engine.capacity(d,True)
    require(d.tx.roots(d)==marker['roots'], 'snapshot_roots')
    digest=d.tx.paired_copy(d,marker)
    engine.record(d,marker,phase='paired_baseline_complete',baseline_manifest_sha256=digest)
    images=d.controls.capture(Path(marker['run_directory']))
    engine.record(d,marker,phase='control_baseline_complete',cron_pair=images)
    before=d.tx.inventory(d,d.p.CODE)
    engine.activate(d,marker);expected=d.tx.inventory(d,d.p.CODE)
    scopes=[str((d.web/s).relative_to(d.p.CODE)) for s in engine.SCOPES]
    outside=lambda k: not any(k==s or k.startswith(s+'/') for s in scopes)
    require({k:v for k,v in before.items() if outside(k)}=={k:v for k,v in expected.items() if outside(k)},
            'unrelated_code_changed')
    d.tx.add_maintenance(d);host.start()
    for number in (1,2):
        engine.record(d,marker,phase='upgrade_'+str(number));host.cli_upgrade()
        verify_versions(d,host.database(marker['prefix']),baseline,True)
        require(d.p.read_file(d.tx.maintenance_path(d),4096)==d.tx.MAINTENANCE, 'maintenance_changed')
    d.tx.verify_tree(d,d.p.CODE,expected)
    engine.record(d,marker,phase='updating_cron_pair')
    d.controls.apply_pair(images,Path(marker['run_directory']))
    count=engine.references(d,host,marker['prefix'])
    require(https_snapshot(d)==marker['https'] and d.tx.roots(d)==marker['roots'], 'release_contract_changed')
    engine.record(d,marker,phase='public_release_committed',public_released=True)
    d.tx.remove_maintenance(d)
    require(host.login()==200, 'updated_https_login')
    host.restore_policies(policies)
    engine.record(d,marker,phase='upgrade_complete',file_references_verified=count)


def upgrade(d):
    engine.verify_payload(d.reference,d.payload);engine.source(d);d.controls.inputs()
    if os.path.lexists(d.marker):
        old=validate_marker(d)
        require(old['phase']=='baseline_restored', 'existing_transaction_use_restore')
        d.marker.rename(d.base/('history-'+uuid.uuid4().hex+'.json'));d.cold.sync_directory(d.base)
    with d.controls.locked_file(d.controls.https_lock):
        report=preflight(d);engine.capacity(d)
        if report is not None: d.policies=report['restart_policies']
        run=Path(tempfile.mkdtemp(prefix='update-',dir=d.base))
        with (run/'commands-private.log').open('xb') as log:
            os.chmod(run/'commands-private.log',0o600);host=Host(d,d.identities,log)
            prefix=d.p.parse_prefix(d.p.read_file(d.p.CODE/'config.php',128*1024).decode())
            baseline=host.database(prefix)
            policies=getattr(d,'policies',{})
            require(all(row['State']['Running'] for row in host.inspect().values()), 'production_not_running')
            marker={'kind':'connect_chat_link_update','payload_commit':PAYLOAD,
                'tool_commit':d.manifest['tool_commit'],'run_directory':str(run),
                'identities':d.identities,'roots':d.tx.roots(d),'web_root':str(d.web),'prefix':prefix,
                'baseline_versions':baseline,'restart_policies':policies,'https':https_snapshot(d),
                'live_trees_modified':False,'switch_started':False,'public_released':False}
            engine.record(d,marker,phase='prepared')
            try:
                archive=engine.fresh_hdd(d,marker,host)
                engine.record(d,marker,phase='fresh_hdd_complete',fresh_hdd_backup=archive)
                with d.controls.locked_file(d.controls.p.cron_lock,15),d.tx.exclusive_backup_lock(d.p):
                    try: perform(d,marker,host,baseline,policies)
                    except BaseException:
                        log.write(traceback.format_exc().encode());log.flush()
                        for number in engine.SIGNALS:signal.signal(number,signal.SIG_IGN)
                        if marker['public_released']:
                            host.restore_policies(policies)
                            engine.record(d,marker,phase='post_release_verification_required')
                        else: recover_locked(d,marker,host)
                        return 1
                engine.notice('CHAT_LINK_UPDATE=PASS; CRON_TARGET=2000; BRIDGE_AND_BOT_ACTIVATION=PENDING')
                return 0
            except BaseException:
                log.write(traceback.format_exc().encode());log.flush()
                for number in engine.SIGNALS:signal.signal(number,signal.SIG_IGN)
                if marker['switch_started']:
                    if not marker['public_released']:
                        try:host.policy('no');host.stop()
                        except BaseException:pass
                    engine.record(d,marker,phase='recovery_required')
                else:
                    if os.path.lexists(d.p.STATE/'moodle-was-running.json'):
                        helper,_=d.w.HELPERS['hdd_backup']
                        host.action(['python3','-I','-B','-u',str(helper),'--recover'],timeout=120)
                    require(not os.path.lexists(d.p.STATE/'moodle-was-running.json'), 'hdd_recovery_pending')
                    require(all(row['State']['Running'] for row in host.inspect().values()), 'startup_incomplete')
                    engine.source(d);verify_versions(d,host.database(prefix),baseline)
                    engine.record(d,marker,phase='baseline_restored')
                engine.notice('CHAT_LINK_UPDATE=STOPPED; USE_SAME_PACKAGE_STATUS_OR_RESTORE')
                return 1


def restore(d,discard=False):
    marker=validate_marker(d)
    require(not marker['public_released'] or discard, 'late_restore_requires_discard_consent')
    require(not os.path.lexists(d.p.STATE/'moodle-was-running.json'), 'hdd_resume_marker')
    with d.controls.locked_file(d.controls.https_lock),d.controls.locked_file(d.controls.p.cron_lock),d.tx.exclusive_backup_lock(d.p):
        with (Path(marker['run_directory'])/'commands-private.log').open('ab') as log:
            host=Host(d,marker['identities'],log)
            try:recover_locked(d,marker,host)
            except BaseException:
                try:host.policy('no');host.stop()
                finally:engine.record(d,marker,phase='recovery_required')
                raise
    return 0


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    modes=parser.add_mutually_exclusive_group()
    for name in ('check','upgrade','restore','status'):modes.add_argument('--'+name,action='store_true')
    modes.add_argument('--check-backup-https',action='store_true')
    modes.add_argument('--prepare-backup-https',action='store_true')
    parser.add_argument('--acknowledge-outage',action='store_true')
    parser.add_argument('--discard-writes-since-snapshot',action='store_true')
    args=parser.parse_args(argv)
    if not any((args.check,args.upgrade,args.restore,args.status,args.check_backup_https,args.prepare_backup_https)):
        engine.notice('PLAN_ONLY; fixed1905-to-2000; paired application/cron; HTTPS preserved; no host access');return 0
    if (args.upgrade or args.restore) and not args.acknowledge_outage:parser.error('--acknowledge-outage required')
    try:
        require(os.geteuid()==0 and sys.version_info>=(3,12), 'root_python312_required')
        d=dependencies(Path(sys.argv[0]));d.p.root_input(Path(sys.argv[0]).absolute())
        if args.check_backup_https or args.prepare_backup_https:
            with d.controls.locked_file(d.controls.https_lock),d.controls.locked_file(d.p.LOCK,15):
                https_snapshot(d)
                require(not os.path.lexists(d.p.STATE/'moodle-was-running.json'), 'backup_recovery_pending')
                for unit in ('ustar-hdd-backup.service','ustar-hdd-backup-recover.service'):
                    require(d.p.command(['systemctl','show','--value','-p','ActiveState',unit],timeout=10).strip()=='inactive', 'backup_service_active')
                result=d.backup_tls.apply() if args.prepare_backup_https else d.backup_tls.check()
            engine.notice(json.dumps(result,indent=2));return 0
        if args.check:
            with d.controls.locked_file(d.controls.https_lock),d.w.existing_shared_lock(d.p):result=preflight(d)
            engine.notice(json.dumps(result,indent=2));return 0
        if args.status:
            m=validate_marker(d);engine.notice(json.dumps({k:m[k] for k in ('phase','public_released','live_trees_modified','run_directory')}));return 0
        os.umask(0o077)
        def interrupted(*_):raise InterruptedError()
        for n in engine.SIGNALS:signal.signal(n,interrupted)
        with engine.transaction_lock(d):
            if args.upgrade:
                with d.controls.locked_file(d.controls.https_lock),d.w.existing_shared_lock(d.p):r=preflight(d)
                d.policies=r['restart_policies'];return upgrade(d)
            return restore(d,args.discard_writes_since_snapshot)
    except Exception as error:
        known=isinstance(error,(ValueError,cron.Refusal,delivery.Refusal))
        reason=str(error) if known and re.fullmatch('[a-z_]{1,80}',str(error)) else 'guard_refused'
        engine.notice(json.dumps({'status':'refused','reason':reason}));return 1


if __name__=='__main__':raise SystemExit(main())
