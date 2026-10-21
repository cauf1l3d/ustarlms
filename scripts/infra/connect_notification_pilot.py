#!/usr/bin/env python3
"""One-employee opt-in notification pilot; default PLAN_ONLY, no history replay."""
import argparse,copy,hashlib,json,os,re,secrets,signal,stat,subprocess,sys,tempfile,zipfile
from pathlib import Path
from contextlib import nullcontext
import academy_https
import connect_bridge_activation as activation
import connect_private_bridge as bridge
import connect_install

require=bridge.require
sha=academy_https.sha
SERVICE='ustar-connect-notify.service'
POLICY='opt/ustar/data/moodle/public/public/local/ustar/classes/connect/notification_delivery.php'
KEY_ID='tg_notify_pilot_v1'
DROPIN=(b'# USTAR managed one-employee notification pilot\n[Service]\n'
    b'ExecStartPre=/usr/bin/python3 -I -u /opt/ustar-connect/current/notifications.pyz --config %d/notifications.json --probe\n')


class Host:
    def __init__(self,link):self.link=link
    def enabled(self):
        result=subprocess.run(['systemctl','is-enabled',SERVICE],capture_output=True,text=True,timeout=10)
        value=result.stdout.strip()
        require(value in ('enabled','disabled'),'unexpected_notification_unit_state')
        return value=='enabled'
    def lookup(self,userid):
        # CLI bootstrap has no login/session impersonation. libpq enforces a
        # read-only connection; this collects no names, email or other users.
        l=self.link;runtime=l.m.host.runtime(l.m.p.old.config)
        script=r'''define('CLI_SCRIPT', true);define('NO_MOODLE_COOKIES', true);
require $argv[1];$uid=(int)$argv[2];$tenant=$argv[3];
$u=$DB->get_record('user',['id'=>$uid,'deleted'=>0,'suspended'=>0,'confirmed'=>1]);
if(!$u||!\local_ustar\accounts::is_business_account($uid)
 ||\local_ustar\employment::resolve($uid)['status']!==\local_ustar\employment::ACTIVE){exit(3);}
$links=$DB->get_records('local_ustar_conn_links',['userid'=>$uid,'provider'=>'telegram','tenant'=>$tenant,'state'=>'linked']);
if(count($links)!==1){exit(3);}$row=reset($links);
echo json_encode(['userid'=>$uid,'subject'=>(string)$row->subject]);'''
        token=l.r.read_private(l.i.config/'telegram.token',512).decode().strip();tenant=token.split(':',1)[0]
        try:
            raw=l.lib.command(['docker','exec','--user','www-data','-e',
                'PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=5000 -c lock_timeout=500',
                runtime['id'],'php','-r',script,runtime['config'],str(userid),tenant])
            require(len(raw)<=4096,'pilot_identity_unavailable')
            value=json.loads(raw)
        except Exception:raise bridge.Refusal('pilot_identity_unavailable') from None
        require(isinstance(value,dict) and set(value)=={'userid','subject'} and value['userid']==userid
            and isinstance(value['subject'],str) and re.fullmatch('[1-9][0-9]{0,15}',value['subject'])
            and int(value['subject'])<2**52,'pilot_identity_unavailable')
        return value


class Pilot:
    def __init__(self,link,reference,archive,digest,policy_bytes,host=None,progress=None):
        self.link,self.r,self.m,self.i,self.lib=link,link.r,link.m,link.i,link.lib
        self.ref,self.archive,self.digest,self.policy_bytes=reference,Path(archive),digest,policy_bytes
        self.host=host or Host(link)
        self.progress=progress or (lambda phase:print(json.dumps({'phase':phase}),flush=True))
        self.base=self.r.root/'var/lib/ustar-connect-notify-pilot';self.state=self.base/'state.json'
        self.paths={'policy':self.r.root/POLICY,'include':link.paths['include'],
            'include_image':link.base/'include.after','link_state':link.state,
            'notifications':self.i.config/'notifications.json','key':self.i.config/'notifications-bridge.key',
            'ca':self.i.config/'notifications-bridge-ca.crt',
            'dropin':self.i.units/(SERVICE+'.d/50-ustar-notify-pilot.conf')}
    def save(self,s):self.lib.atomic(self.state,(json.dumps(s,sort_keys=True)+'\n').encode())
    def load(self):
        s=json.loads(self.r.read_private(self.state,131072),object_pairs_hook=bridge.unique)
        require(isinstance(s,dict) and s.get('schema')==1 and s.get('tool_commit')==self.ref['tool_commit']
            and s.get('phase') in ('preparing','active','restoring','inactive')
            and set(s.get('files',{}))==set(self.paths)
            and all(re.fullmatch('[a-f0-9]{40}',s.get(k,'')) for k in ('old_release','new_release'))
            and type(s.get('userid')) is int and s['userid']>0,'invalid_notification_pilot_state')
        return s
    def current(self,name):
        p=self.paths[name]
        if not os.path.lexists(p):return None
        # The accepted plugin tree is runtime-owned. Only this exact source file
        # may retain that ownership; all secret/control paths require root parents.
        if name!='policy':self.r.secure_parent(p.parent,'unsafe_notification_parent')
        raw=self.lib.read_file(p)
        return {'sha':sha(raw),**self.link.metadata(p)}
    def guard(self,s,active=False):
        for name in self.paths:
            item=s['files'][name]
            require(self.current(name) in ([item['after']] if active else [item['before'],item['after']]),
                'notification_'+name+'_drift')
        release=self.i.current()
        require(release is not None and release.name in ((s['new_release'],) if active else (s['old_release'],s['new_release'])),
            'notification_receiver_drift')
        # An interrupted file/state/pointer handoff is verified against the two
        # recorded states, then normalized in memory only for the link guard.
        linked=copy.deepcopy(self.link.load())
        require(linked['phase']=='active','active_link_bridge_required')
        linked['files']['include']['after']=self.link.current('include')
        linked['new_release']=release.name
        self.link.guard(linked,active=True,notification_pilot=True)
        folder=self.paths['dropin'].parent
        if os.path.lexists(folder):
            self.r.secure_parent(folder,'unsafe_notification_parent')
            require({p.name for p in folder.iterdir()}<= {'50-ustar-notify-pilot.conf'},'unknown_notification_override')
    def stage(self,oldstate):
        commit,values=connect_install.validate_archive(self.archive,self.digest)
        require(commit==self.ref['tool_commit'],'notification_release_source_mismatch')
        for name in connect_install.UNITS:
            require(sha(values['units/ustar-connect-'+name+'.service'])==oldstate['unit_hashes']['ustar-connect-'+name+'.service'],
                'notification_unit_change_requires_review')
        target=self.i.prefix/'releases'/commit
        if target.exists():require(self.i.release_files(target)==values,'notification_release_collision')
        else:
            with tempfile.TemporaryDirectory(prefix='.notify-stage-',dir=target.parent) as td:
                folder=Path(td)/'release';connect_install.mkdir(folder)
                for name,raw in values.items():
                    p=folder/name
                    if p.parent!=folder:connect_install.mkdir(p.parent)
                    connect_install.write_file(p,raw)
                manifest={'schema':1,'source_commit':commit,'files':{n:sha(v) for n,v in values.items()}}
                connect_install.write_file(folder/'manifest.json',(json.dumps(manifest,sort_keys=True)+'\n').encode())
                os.replace(folder,target);self.lib.sync_directory(target.parent)
        return target
    def prepare(self,userid):
        require(type(userid) is int and 0<userid<2**31,'invalid_pilot_userid')
        old=self.link.load();require(old['phase']=='active','active_link_bridge_required')
        self.link.guard(old,active=True);self.m.verify(self.m.load())
        require(old['new_release']==self.ref.get('bridge_release'),'accepted_link_release_required')
        reference=self.r.ref
        require(reference.get('payload_commit')==bridge.PAYLOAD and set(reference.get('files',{}))==set(bridge.SECURITY_FILES),
            'invalid_transport_reference')
        web=self.m.p.old.config.parent/'public'
        for name,digest in reference['files'].items():
            require(isinstance(digest,str) and re.fullmatch('[a-f0-9]{64}',digest)
                and sha(self.lib.read_file(web/name))==digest,'transport_source_drift')
        # Fail on a fresh egress/scope outage before stopping the working receiver.
        self.i.host.run(['systemctl','start',activation.PROBE])
        require(self.i.host.active(activation.SERVICE),'link_receiver_not_active')
        require(not self.host.enabled(),'notification_service_must_be_disabled')
        require(self.ref.get('schema')==1 and self.ref.get('policy',{}).get('path')==POLICY
            and self.ref['policy']['after']==sha(self.policy_bytes),'invalid_notification_reference')
        require(self.current('policy')['sha']==self.ref['policy']['before'],'notification_policy_baseline_drift')
        for name in ('notifications','key','ca','dropin'):
            require(not os.path.lexists(self.paths[name]),'existing_notification_pilot_path')
        require(not os.path.lexists(self.paths['dropin'].parent),'existing_notification_override')
        identity=self.host.lookup(userid)
        require(identity.get('userid')==userid and isinstance(identity.get('subject'),str)
            and re.fullmatch('[1-9][0-9]{0,15}',identity['subject']) and int(identity['subject'])<2**52,
            'pilot_identity_unavailable')
        release=self.stage(old)
        token=self.r.read_private(self.i.config/'telegram.token',512).decode().strip();tenant=token.split(':',1)[0]
        key=secrets.token_hex(32)
        import time
        cutoff=int(time.time())+1
        before_include=self.lib.read_file(self.paths['include'])
        require(b'ustar_connect_notifications_enabled' not in before_include,'existing_notification_settings')
        include=before_include+("\n// USTAR one-employee notification pilot; separate key and new events only.\n"
            "$CFG->ustar_connect_bridge_keys['"+KEY_ID+"']=['secret'=>'"+key+"','provider'=>'telegram','tenant'=>'"+tenant+
            "','operations'=>['notification.lease','notification.begin','notification.finish']];\n"
            "$CFG->ustar_connect_notify_userids=["+str(userid)+"];\n"
            "$CFG->ustar_connect_notify_since="+str(cutoff)+";\n"
            "$CFG->ustar_connect_notifications_enabled=true;\n").encode()
        cred='/run/credentials/'+SERVICE+'/'
        cfg={'bot_token_file':cred+'telegram.token','bot_username':'ustarcorp_bot',
            'state_directory':'/var/lib/ustar-connect-notify','telegram_proxy':'http://127.0.0.1:18081',
            'onboarding_enabled':False,'linking_enabled':False,'notification_subjects':[identity['subject']],
            'bridge_endpoint':'https://127.0.0.1:18443/local/ustar/connect_bridge.php','bridge_key_id':KEY_ID,
            'bridge_key_file':cred+'bridge.key','bridge_ca_file':cred+'bridge-ca.crt'}
        newlinked=copy.deepcopy(old);newlinked['new_release']=release.name
        newlinked['files']['include']['after']={**self.current('include'),'sha':sha(include)}
        desired={'policy':self.policy_bytes,'include':include,'include_image':include,
            'link_state':(json.dumps(newlinked,sort_keys=True)+'\n').encode(),
            'notifications':(json.dumps(cfg,sort_keys=True)+'\n').encode(),'key':(key+'\n').encode(),
            'ca':self.m.read(self.m.p.old.ca),'dropin':DROPIN}
        rt=self.m.host.runtime(self.m.p.old.config)
        require(self.r.host.gateway(rt)==bridge.PEER,'backend_peer_changed')
        self.m.host.lint(rt,self.policy_bytes);self.m.host.lint(rt,include)
        self.lib.directory(self.base,0o700,self.r.uid,self.r.root)
        s={'schema':1,'phase':'preparing','step':'captured','tool_commit':self.ref['tool_commit'],
            'userid':userid,'cutoff':cutoff,'old_release':old['new_release'],'new_release':release.name,'files':{}}
        for name,raw in desired.items():
            before=self.current(name)
            if before is not None:self.lib.atomic(self.base/(name+'.before'),self.lib.read_file(self.paths[name]))
            meta={k:v for k,v in before.items() if k!='sha'} if before else {
                'uid':self.r.uid,'gid':os.getegid(),'mode':0o644 if name=='dropin' else 0o600,'xattrs':{}}
            self.lib.atomic(self.base/(name+'.after'),raw)
            s['files'][name]={'before':before,'after':{'sha':sha(raw),**meta}}
        self.save(s);return s
    def put(self,s,name,side):
        self.guard(s)
        record=s['files'][name][side];p=self.paths[name]
        if record is None:
            if os.path.lexists(p):p.unlink();self.lib.sync_directory(p.parent)
        else:
            raw=self.r.read_private(self.base/(name+'.'+side),131072)
            require(sha(raw)==record['sha'],'notification_before_image_changed')
            self.m.replace(p,raw,record)
        require(self.current(name)==record,'notification_write_not_effective')
    def checkpoint(self,s,step):s['step']=step;self.save(s);self.progress(step)
    def activate(self,userid):
        if self.state.exists():
            s=self.load()
            if s['phase']=='active':
                require(s['userid']==userid,'different_pilot_requires_deactivation');return self.status()
            if s['phase']!='inactive':
                self.deactivate();raise bridge.Refusal('interrupted_pilot_recovered_repeat_activate')
        s=self.prepare(userid)
        try:
            self.link.stop();self.checkpoint(s,'receiver_stopped')
            folder=self.paths['dropin'].parent;folder.mkdir(mode=0o755);self.lib.sync_directory(folder.parent)
            self.put(s,'policy','after');self.checkpoint(s,'policy')
            for name in ('key','ca','notifications','dropin'):self.put(s,name,'after')
            self.checkpoint(s,'private_files')
            for name in ('include','include_image','link_state'):self.put(s,name,'after')
            self.checkpoint(s,'link_handoff')
            self.i.switch(self.i.prefix/'releases'/s['new_release']);self.lib.sync_directory(self.i.prefix)
            self.i.host.run(['systemctl','daemon-reload']);self.checkpoint(s,'receiver_switch')
            self.i.host.run(['systemctl','start',activation.PROBE])
            self.i.host.run(['systemctl','start',activation.SERVICE])
            self.i.host.run(['systemctl','enable',SERVICE]);self.i.host.run(['systemctl','start',SERVICE])
            require(self.i.host.active(SERVICE),'notification_sender_not_active')
            self.checkpoint(s,'sender_started');self.guard(s,active=True);self.m.verify(self.m.load())
            s['phase']='active';self.save(s);return self.status()
        except Exception:
            self.deactivate();raise
    def deactivate(self):
        s=self.load()
        if s['phase']=='inactive':return self.status()
        self.guard(s);s['phase']='restoring';self.save(s)
        self.i.host.run(['systemctl','stop',SERVICE]);self.i.host.run(['systemctl','disable',SERVICE])
        self.link.stop()
        # Disable the global sending flag before restoring the old source fence.
        for name in ('include','policy','notifications','key','ca','dropin','include_image','link_state'):
            self.put(s,name,'before')
        folder=self.paths['dropin'].parent
        if folder.exists():folder.rmdir();self.lib.sync_directory(folder.parent)
        self.i.switch(self.i.prefix/'releases'/s['old_release']);self.lib.sync_directory(self.i.prefix)
        # The prior receiver rechecks Telegram identity in its own startup. A
        # concurrent egress outage must not prevent restoring its retrying process.
        self.i.host.run(['systemctl','daemon-reload']);self.i.host.run(['systemctl','start',activation.SERVICE])
        self.m.verify(self.m.load())
        s['phase']='inactive';self.save(s);return self.status()
    def status(self):
        if not self.state.exists():return {'phase':'notification_pilot_not_installed'}
        s=self.load();self.guard(s,active=s['phase']=='active')
        return {'phase':{'active':'notification_pilot_active','inactive':'notification_pilot_inactive'}.get(s['phase'],'notification_pilot_recovery_required'),
            'pilot_userids':[s['userid']],'notify_since':s['cutoff'],'historical_replay':False,
            'sender_active':self.i.host.active(SERVICE),'linking_preserved':True,'schema_upgraded':False,'business_data_restored':False}


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__);modes=parser.add_mutually_exclusive_group()
    for name in ('activate','deactivate','status'):modes.add_argument('--'+name,action='store_true')
    parser.add_argument('--pilot-userid',type=int)
    args=parser.parse_args(argv)
    if not any(getattr(args,n) for n in ('activate','deactivate','status')):
        print('{"phase":"plan_only","host_access":false}');return 0
    op=None
    try:
        require(os.geteuid()==0 and sys.version_info>=(3,12),'root_python312_required');os.umask(0o077)
        def stop(*_):raise KeyboardInterrupt()
        for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,stop)
        path=Path(sys.argv[0]);st=path.lstat()
        require(not any(p.is_symlink() for p in (path,*path.parents)) and stat.S_ISREG(st.st_mode)
            and st.st_uid==0 and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==0o600 and st.st_size<=2097152,'unsafe_pilot_bundle')
        for p in path.parents:
            st=p.stat();require(st.st_uid==0 and not st.st_mode&0o022,'unsafe_bundle_parent')
        ref,https_source,archive,policy=bundle(path)
        uid=args.pilot_userid
        # Never hold cron/backup/HTTPS locks while waiting for a human to find an ID.
        if args.activate and uid is None:
            with open('/dev/tty','r+',encoding='utf-8') as tty:
                tty.write('Числовой ID тестового сотрудника в Академии: ');tty.flush()
                raw=tty.readline().strip();require(re.fullmatch('[1-9][0-9]{0,9}',raw),'invalid_pilot_userid');uid=int(raw)
        manager=academy_https.Manager();reader=bridge.Readiness(ref['bridge'],manager,connect_install.Installation())
        with bridge.locks(reader):
            manager.locked=nullcontext
            with tempfile.TemporaryDirectory(prefix='ustar-notify-',dir='/root') as td:
                packed=Path(td)/'receiver.tar.gz';packed.write_bytes(archive)
                link=activation.Deployment(reader,https_source,packed,sha(archive))
                op=Pilot(link,ref,packed,sha(archive),policy)
                result=op.activate(uid) if args.activate else op.deactivate() if args.deactivate else op.status()
        print(json.dumps(result,sort_keys=True));return 0
    except BaseException as error:
        known=isinstance(error,(bridge.Refusal,ValueError,RuntimeError)) or error.__class__.__name__=='Refusal'
        reason=str(error) if known and re.fullmatch('[A-Za-z0-9_]{1,100}',str(error)) else 'notification_pilot_failed'
        result={'phase':'refused','reason':reason}
        if op is not None and op.state.exists():
            try:result['recovery_state']=op.load()['phase']
            except Exception:result['recovery_state']='inspect_required'
        print(json.dumps(result));return 2


def bundle(path):
    expected={'__main__.py','connect_notification_pilot.py','connect_bridge_activation.py','connect_private_bridge.py',
        'academy_https.py','connect_install.py','receiver.tar.gz','notification_delivery.php','REFERENCE.json','MANIFEST.json'}
    with zipfile.ZipFile(path) as z:
        require(len(z.namelist())==len(expected) and set(z.namelist())==expected,'bundle_inventory_changed')
        require(all(not i.is_dir() and i.file_size<=1048576 for i in z.infolist()),'bundle_too_large')
        manifest=json.loads(z.read('MANIFEST.json'),object_pairs_hook=bridge.unique)
        require(set(manifest)=={'schema','tool_commit','files'} and manifest['schema']==1
            and set(manifest['files'])==expected-{'MANIFEST.json'},'invalid_bundle_manifest')
        for name,digest in manifest['files'].items():require(sha(z.read(name))==digest,'bundle_digest_changed')
        ref=json.loads(z.read('REFERENCE.json'),object_pairs_hook=bridge.unique)
        require(ref['tool_commit']==manifest['tool_commit'] and ref['bridge']['tool_commit']==ref['tool_commit']
            and ref['policy']['path']==POLICY and ref['policy']['after']==sha(z.read('notification_delivery.php')),
            'bundle_source_mismatch')
        return ref,z.read('academy_https.py'),z.read('receiver.tar.gz'),z.read('notification_delivery.php')


if __name__=='__main__':raise SystemExit(main())
