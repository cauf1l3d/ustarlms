#!/usr/bin/env python3
"""Guarded INF-03 code ownership and private legacy archive."""
import base64
import copy
import hashlib
import os
from pathlib import Path, PurePosixPath
import stat
import sys
import postgres_app_handoff as h

Refusal=h.Refusal
require=h.require
EXTRAS=(
 'bx_grade_tokens.txt','config.php.backup-2026-08-10',
 'config.php.backup-before-wwwroot-fix-2026-08-10','config.php.backup_debug',
 'config.php.before_ip_change_20260830T203841Z','config.php.pre-cookie2726',
 'gradehelper.php','gradehelper_submit.php','public/bitrix_bot_handler.php',
 'public/config.php.before_ip_change_20260830T203238Z','public/course_dashboard.php',
 'public/gradehelper.php','public/gradehelper_submit.php','public/index.php.backup_ustar',
 'public/reports/course_10_005c7d20069c.pdf','public/reports/course_10_72536505b05e.pdf',
 'public/reports/course_10_879d66073583.pdf','public/reports/course_10_9e253332c84d.pdf',
 'public/reports/course_10_b661337a5988.pdf','public/reports/course_10_c959c810a6b9.pdf',
 'public/reports/course_10_d332481cd5be.pdf','wwwroot')


def snapshot(root):
    root=Path(root);h.parents(root)
    require(root.is_dir() and not root.is_symlink(),'unsafe_code_root')
    device=root.stat().st_dev;result={}
    def error(_):raise Refusal('tree_walk_failed')
    for directory,dirs,files in os.walk(root,followlinks=False,onerror=error):
        for p in [Path(directory)]+[Path(directory)/n for n in files]:
            st=p.lstat();name=p.relative_to(root).as_posix()
            require(st.st_dev==device,'nested_mount_refused')
            directory_entry=stat.S_ISDIR(st.st_mode)
            require(directory_entry or stat.S_ISREG(st.st_mode),'unsupported_tree_entry')
            require(directory_entry or st.st_nlink==1,'hardlink_refused')
            require(st.st_uid in (0,33) and st.st_gid in (0,33),'unknown_code_owner')
            require(not stat.S_IMODE(st.st_mode)&0o7000,'special_permission_bits')
            attrs=os.listxattr(p,follow_symlinks=False)
            require(not attrs,'unreviewed_extended_attributes')
            row={'kind':'directory' if directory_entry else 'file','uid':st.st_uid,'gid':st.st_gid,
                 'mode':stat.S_IMODE(st.st_mode)}
            if not directory_entry:
                digest=hashlib.sha256()
                fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
                with os.fdopen(fd,'rb') as stream:
                    def ident(s):return (s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns,s.st_mode,s.st_uid,s.st_gid)
                    require(ident(st)==ident(os.fstat(stream.fileno())),'file_replaced_during_scan')
                    for chunk in iter(lambda:stream.read(1024*1024),b''):digest.update(chunk)
                    require(ident(st)==ident(os.fstat(stream.fileno()))==ident(p.lstat()),'file_changed_during_scan')
                row.update(sha256=digest.hexdigest(),size=st.st_size,mtime_ns=st.st_mtime_ns)
            result[name]=row
            require(len(result)<=200000,'tree_entry_limit')
        for name in dirs:
            p=Path(directory)/name
            require(not p.is_symlink() and p.lstat().st_dev==device,'symlink_or_mount_refused')
    return result


def desired(before):
    require(all(n=='public/reports' or not n.startswith('public/reports/') or n in EXTRAS for n in before),
            'unknown_report_entry')
    after={}
    for name,row in before.items():
        if name in EXTRAS:
            require(row['kind']=='file','legacy_extra_not_regular');continue
        after[name]={**row,'uid':0,'mode':row['mode']&~0o022}
    return after


class Transaction:
    def __init__(self,root,base,progress=None):
        self.root=Path(root);self.store=h.Store(base);self.progress=progress or (lambda _:None)

    def load(self):
        value=self.store.load();require(value is not None,'hardening_state_required')
        require(value.get('root')==str(self.root) and value.get('operation')=='code_hardening_v1','hardening_state_identity')
        before=value['before'];require(value['after']==desired(before),'saved_policy_drift')
        for name in before:
            p=PurePosixPath(name)
            require(name=='.' or not p.is_absolute() and '..' not in p.parts and p.as_posix()==name,'unsafe_saved_path')
        require(set(value['extras'])==set(before)&set(EXTRAS),'saved_archive_inventory')
        for name,image in value['extras'].items():
            row=before[name]
            require(image['path']==str(self.root/name) and image['sha256']==row['sha256']
                    and image['metadata']=={'uid':row['uid'],'gid':row['gid'],'mode':row['mode'],'xattrs':{}},
                    'saved_archive_descriptor')
            h.image_raw(image)
        return value

    def prepare(self, runtime=None):
        require(self.store.load() is None,'hardening_already_prepared')
        before=snapshot(self.root);after=desired(before)
        extras={name:h.capture(self.root/name) for name in sorted(set(before)&set(EXTRAS))}
        state={'schema':1,'operation':'code_hardening_v1','root':str(self.root),'phase':'prepared',
               'before':before,'after':after,'extras':extras,'runtime':runtime}
        require(snapshot(self.root)==before,'tree_changed_during_preparation')
        self.store.save(state);self.load();return state

    def save_phase(self,phase):
        state=self.load();state['phase']=phase;self.store.save(state)

    def archive_path(self,name):return self.store.base/'archive'/name

    def verify_archive(self,state,required=False):
        for name,image in state['extras'].items():
            p=self.archive_path(name)
            if not os.path.lexists(p):
                require(not required,'archive_missing');continue
            actual=h.capture(p)
            require(actual['sha256']==image['sha256'] and actual['metadata']=={'uid':0,'gid':0,'mode':0o600,'xattrs':{}},
                    'archive_drift')
            for parent in p.parents:
                if parent==self.store.base.parent:break
                st=parent.lstat()
                require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and stat.S_IMODE(st.st_mode)==0o700,
                        'unsafe_archive_parent')

    def guard(self):
        state=self.load();actual=snapshot(self.root)
        require(set(actual)<=set(state['before']) and set(state['after'])<=set(actual),'tree_path_drift')
        for name,row in actual.items():
            b=state['before'][name];a=state['after'].get(name,b)
            # chown/chmod are separate syscalls; either known combination is recoverable.
            expected={**b,'uid':row['uid'],'mode':row['mode']}
            if name in state['extras']:expected['mtime_ns']=row['mtime_ns']
            require(row==expected and row['uid'] in (b['uid'],a['uid']) and row['mode'] in (b['mode'],a['mode']),
                    'tree_content_or_metadata_drift')
        self.verify_archive(state)
        for name in set(state['before'])-set(actual):
            require(self.archive_path(name).is_file(),'removed_extra_without_archive')
        return state,actual

    def archive(self,state):
        self.verify_archive(state)
        for name,image in state['extras'].items():
            p=self.archive_path(name)
            for parent in reversed(p.parents):
                if parent==self.store.base or self.store.base not in parent.parents:continue
                if not parent.exists():parent.mkdir(mode=0o700);h.sync(parent.parent)
                require(parent.is_dir() and not parent.is_symlink() and parent.stat().st_uid==0
                        and stat.S_IMODE(parent.stat().st_mode)==0o700,'unsafe_archive_parent')
            if not p.exists():
                h.replace_image({**image,'path':str(p),'metadata':{'uid':0,'gid':0,'mode':0o600,'xattrs':{}}})
        self.verify_archive(state,required=True);self.progress('archive_ready')

    def permissions(self,rows):
        # Root/parents first removes UID33 rename rights before changing children.
        for name,row in sorted(rows.items(),key=lambda item:(len(PurePosixPath(item[0]).parts),item[0])):
            p=self.root/name;st=p.lstat()
            if (st.st_uid,st.st_gid,stat.S_IMODE(st.st_mode))==(row['uid'],row['gid'],row['mode']):continue
            flags=os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK
            if row['kind']=='directory':flags|=os.O_DIRECTORY
            fd=os.open(p,flags)
            try:
                require((st.st_dev,st.st_ino)==(os.fstat(fd).st_dev,os.fstat(fd).st_ino),'entry_replaced')
                if st.st_uid!=row['uid'] or st.st_gid!=row['gid']:
                    os.fchown(fd,row['uid'],row['gid']);os.fsync(fd);self.progress('ownership')
                if stat.S_IMODE(os.fstat(fd).st_mode)!=row['mode']:os.fchmod(fd,row['mode'])
                os.fsync(fd)
            finally:os.close(fd)
            self.progress('permissions')

    def apply(self):
        state,actual=self.guard()
        require(state['phase'] in ('prepared','applying','hardened'),'invalid_apply_phase')
        self.archive(state);self.save_phase('applying')
        self.guard()
        for name in state['extras']:
            p=self.root/name
            if p.exists():p.unlink();h.sync(p.parent)
            self.progress('removed')
        self.permissions(state['after'])
        require(snapshot(self.root)==state['after'],'hardened_readback_failed')
        self.save_phase('hardened')
        return {'phase':'hardened','archived_files':len(state['extras'])}

    def undo(self):
        state,actual=self.guard()
        self.save_phase('restoring')
        for name,image in state['extras'].items():
            p=self.root/name
            if not p.exists():
                h.replace_image(image)
            os.utime(p,ns=(state['before'][name]['mtime_ns'],state['before'][name]['mtime_ns']))
        self.permissions(state['before'])
        require(snapshot(self.root)==state['before'],'restored_readback_failed')
        self.save_phase('baseline_restored')
        return {'phase':'baseline_restored','business_rows_restored':False}


class Coordinator:
    """Service boundary supplied by the accepted DB handoff controller."""
    def __init__(self,tx,host,dbstore):
        self.tx,self.host,self.dbstore=tx,host,dbstore
        self.db=h.Session(host,dbstore)

    def controls(self,state=None,recovery=False):
        saved=self.db.load();require(saved and saved['phase']=='app_role_active','accepted_app_role_required')
        self.db.guard_files()
        self.host.config_metadata(h.capture(h.CONFIG))
        if state is not None:
            runtime=state['runtime'];require(isinstance(runtime,dict),'missing_service_snapshot')
            require(h.capture(self.dbstore.path)['sha256']==runtime['db_state_sha'],'DB_handoff_journal_drift')
            require(self.host.legacy_images()==runtime['legacy_images'],'legacy_journal_drift')
        if recovery:self.host.ensure_pg()
        self.db.guard();self.host.verify_live('after',saved)
        return saved

    def verify(self):
        saved=self.controls(self.tx.load())
        self.host.runtime_validation=True
        self.host.verify_live('after',saved)
        self.host.runtime_validation=False

    def apply(self):
        current=self.tx.store.load()
        if current:
            require(current['phase']=='active','existing_operation_requires_recovery_or_new_review')
            self.tx.guard();self.verify();return {'phase':'code_hardened','verify_only':True}
        self.controls()
        runtime={'services':self.host.services(),'policies':self.host.policies(),
                 'db_state_sha':h.capture(self.dbstore.path)['sha256'],'legacy_images':self.host.legacy_images()}
        state=self.tx.prepare(runtime)
        try:
            run=self.tx.store.base/'backup';run.mkdir(mode=0o700);h.sync(run.parent)
            receipt=self.host.backup(run)
            state=self.tx.load();state['fresh_backup']=receipt;self.tx.store.save(state)
            with self.host.cold():
                self.tx.guard();self.controls(self.tx.load())
                self.host.stop();self.tx.save_phase('applying')
                self.tx.apply()
                self.host.start_web();self.verify()
                self.host.resume(runtime['services'],runtime['policies'])
                self.tx.guard();self.verify();self.tx.save_phase('active')
            return {'phase':'code_hardened','archived_files':len(state['extras']),
                    'business_rows_restored':False,'app_role':'ustar_app'}
        except (Exception,KeyboardInterrupt):
            self.host.runtime_validation=False
            try:return {**self.recover(), 'apply_succeeded':False}
            except BaseException:
                self.tx.save_phase('recovery_required')
                return {'phase':'recovery_required','business_rows_restored':False}

    def recover(self,undo=False):
        state,_=self.tx.guard()
        if state['phase']=='active' and not undo:
            self.verify();return {'phase':'code_hardened','verify_only':True}
        # Code and private control guards precede any restart after a reboot.
        self.controls(state,recovery=True)
        self.host.recover_backup()
        runtime=state['runtime']
        if undo and state['phase']=='active':
            runtime={**runtime,'services':self.host.services(),'policies':self.host.policies()}
            state['runtime']=runtime;self.tx.store.save(state)
        with self.host.cold():
            self.host.stop();result=self.tx.undo()
            self.host.start_web();self.verify()
            self.host.resume(runtime['services'],runtime['policies'])
            self.tx.guard();self.verify()
        return result


def main(argv=None):
    import argparse,io,json,signal,uuid
    parser=argparse.ArgumentParser(description='Guarded code hardening and private legacy archive')
    group=parser.add_mutually_exclusive_group()
    for name in ('check','status','apply','recover','undo'):group.add_argument('--'+name,action='store_true')
    parser.add_argument('--acknowledge-outage',action='store_true');args=parser.parse_args(argv)
    write=args.apply or args.recover or args.undo
    if write and not args.acknowledge_outage:parser.error('outage acknowledgement required')
    if not any((args.check,args.status,write)):
        print(json.dumps({'phase':'PLAN_ONLY','host_changes':False}));return 0
    log=None
    try:
        require(os.geteuid()==0,'root_required')
        tx=Transaction('/opt/ustar/data/moodle/public','/var/lib/ustar-code-hardening')
        if args.status:
            state=tx.store.load();print(json.dumps({'phase':state['phase'] if state else 'uninitialized','observation':'journal_only'}));return 0
        if write:
            own=h.capture(Path(sys.argv[0]));require(own['metadata']=={'uid':0,'gid':0,'mode':0o600,'xattrs':{}},'unsafe_operator_file')
            tx.store.directory(create=True);log=(tx.store.base/('private-'+uuid.uuid4().hex+'.log')).open('xb')
            os.fchmod(log.fileno(),0o600)
        else:log=io.BytesIO()
        dbstore=h.Store();host=h.ProductionHost(h.load_controller(),dbstore,log)
        coordinator=Coordinator(tx,host,dbstore)
        def interrupted(*_):raise KeyboardInterrupt()
        for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,interrupted)
        def progress(phase):
            if phase not in ('permissions','ownership'):print(json.dumps({'phase':phase}),flush=True)
        tx.progress=progress
        with tx.store.locked(write=write),dbstore.locked(write=False):
            with host.locks.prebackup() if write else host.locks.check():
                if args.check:
                    state=tx.store.load()
                    if state:
                        tx.guard();coordinator.controls(state)
                        result={'phase':'code_hardened' if state['phase']=='active' else 'recovery_required', 'saved_phase':state['phase']}
                    else:
                        coordinator.controls();before=snapshot(tx.root);after=desired(before)
                        result={'phase':'ready_for_code_hardening','entries':len(before),
                                'archived_files':len(before)-len(after),
                                'changed_permissions':sum(after[n]!=before[n] for n in after)}
                    result.update(host_changes=False,provider_called=False)
                elif args.apply:result=coordinator.apply()
                else:result=coordinator.recover(undo=args.undo)
        print(json.dumps(result,sort_keys=True));return 2 if result.get('apply_succeeded') is False or result['phase']=='recovery_required' else 0
    except BaseException as error:
        if log is not None:
            import traceback
            log.write(traceback.format_exc().encode());log.flush()
        import re
        reason=str(error) if isinstance(error,Refusal) and re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,100}',str(error)) else 'private_diagnostic_required'
        print(json.dumps({'phase':'refused','reason':reason}));return 2
    finally:
        if log is not None:log.close()

if __name__=='__main__':sys.exit(main())
