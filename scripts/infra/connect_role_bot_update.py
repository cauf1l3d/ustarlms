#!/usr/bin/env python3
"""Fixed accepted2803 -> coordinated role bot2900. Default is offline PLAN_ONLY."""
from dataclasses import dataclass
import copy
import json
from pathlib import Path
import re
import connect_con02_contract as c
import feed_media_update as previous
from connect_con02_state import Journal, Locks, capture, descriptor, unique

APP_COMMIT='5cc02944e1059ba015afcc7c5e8068ca56c34a92'
WORKER_COMMIT=APP_COMMIT
BASELINE_COMMIT=previous.APP_COMMIT
PREDECESSOR_TOOL='36be3f108f2eac6a3db728f93b4456a729b97a3e'
BASE=Path('/var/lib/ustar-connect-role-bot-update')
MODULES=previous.MODULES+('connect_role_bot_update',)
VERSIONS={'local_ustar':'2026102900','theme_ustar':'2026102700'}
CRON_LINE=b"VERSIONS = {'local_ustar': '2026102803', 'theme_ustar': '2026102700'}"
BOT_ID='tg_role_bot_v4'
NEW_TABLES={'local_ustar_conn_dialogs','local_ustar_conn_actions','local_ustar_conn_updates'}
CHANGED=frozenset('local/ustar/'+n for n in (
    'classes/adaptation_service.php','classes/connect/actor_service.php','classes/connect/bridge_auth.php',
    'classes/connect/bridge_service.php','classes/connect/identity_service.php','classes/connect/notification_action.php','classes/forced_retraining.php',
    'classes/privacy/provider.php','classes/route_quiz_grading.php','classes/staffing_requests.php',
    'classes/task/prune_connect_identity.php','classes/task_files.php','connect_bridge.php',
    'db/install.xml','db/upgrade.php','lang/en/local_ustar.php','lang/ru/local_ustar.php',
    'tests/connect_notification_action_test.php','version.php'))
ADDED=frozenset(['local/ustar/classes/connect/bot_'+n+'.php' for n in (
    'actor','adaptation','confirmation','forms','guards','learning','people','registration','request','service','store','upload','work')]+
    ['local/ustar/classes/task_workspace/received_photo.php','local/ustar/connect_bot_confirm.php','local/ustar/tests/connect_bot_test.php'])


@dataclass(frozen=True)
class Bundle:
    reference:dict
    modules:dict
    payload:dict
    worker_archive:bytes
    legacy:dict
    predecessor:previous.Bundle


def validate_reference(ref):
    c.require(ref.get('schema')==1 and re.fullmatch('[a-f0-9]{40}',ref.get('tool_commit',''))
        and ref.get('app_commit')==APP_COMMIT and ref.get('worker_commit')==WORKER_COMMIT
        and ref.get('baseline_commit')==BASELINE_COMMIT and ref.get('legacy_commit')==c.LEGACY_COMMIT,
        'role_bot_fixed_source_required')
    old,new=ref['baseline'],ref['candidate']
    c.require(old['versions']=={'local_ustar':2026102803,'theme_ustar':2026102700}
        and new['versions']=={k:int(v) for k,v in VERSIONS.items()}
        and len(old['files'])==590 and len(new['files'])==606,'role_bot_release_profile')
    c.require(len(old['tables'])==100 and len(new['tables'])==103
        and set(new['tables'])==set(old['tables'])|NEW_TABLES,'role_bot_metadata_tables')
    for key in ('schema_fields','schema_indexes'):
        c.require({t:new[key][t] for t in old['tables']}==old[key],'role_bot_old_schema_changed')
    c.require(set(new['files'])==set(old['files'])|ADDED
        and {n for n in old['files'] if old['files'][n]!=new['files'][n]}==CHANGED,'role_bot_unrelated_source')
    c.require(all(n.startswith(('local/ustar/','theme/ustar/')) and '..' not in Path(n).parts
        and '\\' not in n and re.fullmatch('[a-f0-9]{64}',s) for n,s in new['files'].items()),'role_bot_payload_path')


def load_embedded(manifest,sources,assets):
    import base64
    modules,values=c.decode_envelope(manifest,sources,assets,app_commit=APP_COMMIT)
    c.require(set(modules)=={n+'.py' for n in MODULES},'embedded_module_inventory')
    ref=json.loads(values['reference.json'],object_pairs_hook=unique);validate_reference(ref)
    oldref=json.loads(values['feed.json'],object_pairs_hook=unique);previous.validate_reference(oldref)
    c.require(ref['tool_commit']==manifest['tool_commit'] and oldref['tool_commit']==PREDECESSOR_TOOL
        and ref['baseline']==oldref['candidate'],'role_bot_predecessor_identity')
    payload={n:values['payload/'+n] for n in ref['candidate']['files']}
    c.require(all(c.sha(raw)==ref['candidate']['files'][n] for n,raw in payload.items()),'embedded_payload_digest')
    oldvalues={n:raw for n,raw in values.items() if n not in ('reference.json','feed.json','baseline-worker.tar.gz')
        and not n.startswith(('payload/','baseline2803/'))}
    oldvalues['reference.json']=values['feed.json'];oldvalues['worker.tar.gz']=values['baseline-worker.tar.gz']
    for n in ref['baseline']['files']:oldvalues['payload/'+n]=values['baseline2803/'+n] if n in CHANGED else payload[n]
    c.require(set(values)==set(oldvalues)|{'feed.json','baseline-worker.tar.gz'}|
        {'baseline2803/'+n for n in CHANGED}|{'payload/'+n for n in ADDED},'embedded_asset_inventory')
    oldsources={n+'.py':sources[n+'.py'] for n in previous.MODULES}
    oldmanifest={'schema':1,'tool_commit':PREDECESSOR_TOOL,'app_commit':previous.APP_COMMIT,
        'worker_commit':previous.WORKER_COMMIT,
        'sources':{n:{'sha256':c.sha(raw.encode()),'bytes':len(raw.encode())} for n,raw in oldsources.items()},
        'assets':{n:{'sha256':c.sha(raw),'bytes':len(raw)} for n,raw in oldvalues.items()}}
    predecessor=previous.load_embedded(oldmanifest,oldsources,{n:base64.b64encode(raw).decode() for n,raw in oldvalues.items()})
    result=Bundle(ref,modules,payload,values['worker.tar.gz'],predecessor.legacy,predecessor)
    from connect_con02_runtime import worker_files
    worker_files(result);return result


def verify_database(current,baseline,reference,*,target):
    try:return c.verify_database(current,baseline,reference,target=target,historical_version=2026102803)
    except c.Refusal as error:
        if str(error)!='schema_indexes':raise
    alternate=copy.deepcopy(reference)
    for table,field in [('local_ustar_coin_accounts','userid'),('local_ustar_test_results','attemptid')]:
        indexes=alternate['baseline']['schema_indexes'][table]
        match=[i for i in indexes if i['fields']==[field] and i['unique'] is True]
        c.require(len(match)==1,'schema_indexes');indexes.remove(match[0])
    c.verify_database(current,baseline,alternate,target=target,historical_version=2026102803)
    return 'server1_legacy_indexes_20261023'


def patch_cron(raw,installation,*,accepted_sha):
    c.require(c.sha(raw)==accepted_sha and raw.count(CRON_LINE)==1,'cron_source_changed')
    c.require(installation.get('schema')==1 and installation.get('script_sha256')==accepted_sha
        and installation.get('versions')==previous.VERSIONS,'cron_installation_changed')
    after=raw.replace(CRON_LINE,b"VERSIONS = {'local_ustar': '2026102900', 'theme_ustar': '2026102700'}")
    compile(after,'<reviewed-role-bot-cron>','exec')
    return after,{**installation,'versions':VERSIONS,'script_sha256':c.sha(after)}


def profiles(ctx,before,*,generation,cutoff,notify_secret,ack_secret):
    """One explicit non-pilot profile; preserve link/ACK/v3 keys and all personal consent."""
    c.require(re.fullmatch('[a-f0-9]{32}',generation) and type(cutoff) is int and cutoff>0,'runtime_generation')
    raw={n:i['raw'] for n,i in before['files'].items()}
    c.require(all(raw[n] is None for n in ('bot_key','receiver_bot_dropin','probe_bot_dropin')),'role_bot_controls_exist')
    sender=json.loads(raw['notifications'],object_pairs_hook=unique)
    receivers={n:json.loads(raw[n],object_pairs_hook=unique) for n in ('receiver','probe')}
    from connect_con02_profiles import config as v1_config
    for name,cfg in [('notifications',sender),*receivers.items()]:
        extras={'notification_contract'} if name=='notifications' else {'acknowledgements_enabled','ack_bridge_key_id','ack_bridge_key_file'}
        normalized={k:v for k,v in cfg.items() if k not in extras}
        if name=='notifications':normalized['bridge_key_id']='tg_notify_pilot_v1'
        v1_config(json.dumps(normalized).encode(),name)
    c.require(sender.get('notification_contract')==3 and type(sender['notification_contract']) is int
        and sender.get('bridge_key_id')=='tg_notify_v3','accepted_v3_sender_required')
    for name,cfg in receivers.items():
        c.require(cfg.get('acknowledgements_enabled') is True and cfg.get('ack_bridge_key_id')=='tg_ack_v1'
            and cfg.get('ack_bridge_key_file')=='/run/credentials/ustar-connect-'+name+'.service/ack-bridge.key',
            'accepted_ACK_receiver_required')
    # v1_config validates its original key ID; normalize only the known v3 sender for that strict check.
    fence=c.read_fence(raw['include'],sender,receivers['receiver'])
    c.require(all(before['fence'].get(k)==v for k,v in fence.items()),'notification_fence_changed')
    c.require(raw['include'].count(b'$CFG->ustar_connect_ack_enabled=true;')==1
        and raw['include'].count(b'$CFG->ustar_connect_notification_presentation_enabled=true;')==1
        and b'ustar_connect_bot_enabled' not in raw['include'] and b'unset($CFG' not in raw['include'],'accepted_v3_include_required')
    keys=[raw[n].strip() for n in ('link_key','notify_key','ack_key')]+[notify_secret]
    c.require(all(isinstance(k,bytes) and re.fullmatch(b'[a-f0-9]{64}',k) for k in keys) and len(set(keys))==4,'runtime_keys_not_distinct')
    c.require(notify_secret not in re.findall(rb"'secret'=>'([a-f0-9]{64})'",raw['include']),'runtime_keys_not_distinct')
    tenants=re.findall(rb"'tenant'=>'([1-9][0-9]{0,15})'",raw['include'])
    c.require(tenants and len(set(tenants))==1,'include_tenant_changed')
    include=raw['include']+("\n// USTAR role bot generation "+generation+"; explicit linked/opt-in future delivery.\n"
        "$CFG->ustar_connect_bridge_keys['"+BOT_ID+"']=['secret'=>'"+notify_secret.decode()+
        "','provider'=>'telegram','tenant'=>'"+tenants[0].decode()+"','operations'=>['bot.update','bot.upload']];\n"
        "unset($CFG->ustar_connect_notify_userids);\n$CFG->ustar_connect_notify_since="+str(cutoff)+";\n"
        "$CFG->ustar_connect_role_delivery_profile='academy_linked_opt_in';\n$CFG->ustar_connect_bot_enabled=true;\n").encode()
    sender.update(role_bot_delivery=True,notification_subjects='academy_linked_opt_in')
    result={'include':include,'bot_key':notify_secret+b'\n','notifications':(json.dumps(sender,sort_keys=True)+'\n').encode()}
    for name,cfg in receivers.items():
        cfg.update(role_bot_enabled=True,bot_bridge_key_id=BOT_ID,
            bot_bridge_key_file='/run/credentials/ustar-connect-'+name+'.service/bot-bridge.key',bot_academy_origin='https://ustar.local')
        result[name]=(json.dumps(cfg,sort_keys=True)+'\n').encode()
        result[name+'_bot_dropin']=b'# USTAR managed role bot capability\n[Service]\nLoadCredential=bot-bridge.key:/etc/ustar-connect/bot-bridge.key\n'
    return result


class Host(c.Host):
    def previous_context(self):
        if '_previous' not in self.__dict__:
            self._previous=previous.make_context(self.ctx.bundle.predecessor)
            self._previous.host.log=self.log
        return self._previous
    def legacy(self):return self.previous_context().host.legacy()
    def control_paths(self):
        paths=super().control_paths();r=self.ctx.root
        paths['bot_key']=r/'etc/ustar-connect/bot-bridge.key'
        for name in ('receiver','probe'):
            paths[name+'_bot_dropin']=r/('etc/systemd/system/ustar-connect-'+name+'.service.d/70-ustar-role-bot.conf')
        return paths
    def predecessor(self):
        from connect_con02_application import Application
        p=self.previous_context();journal=Journal(p);saved=journal.load()
        c.require(saved and saved.get('runtime') is None and (saved.get('app') or {}).get('phase')=='app_ready','accepted_Feed2803_required')
        row=saved['app'];Application(p,journal,Locks(p)).bind(row)
        p.host.source(target=True);database=p.host.database(row['prefix'])
        previous.verify_database(database,{**row['database'],'tasks':database['tasks']},p.bundle.reference,target=True)
        controls=p.host.controls(saved);journal.guard('app')
        p.host.verify_runtime(p.host.previous_context())
        accepted=p.host.accepted_cron();self.accepted_cron_sha=c.sha(accepted)
        c.require(row['files']['cron_script']['after']['sha256']==self.accepted_cron_sha,'accepted_cron_journal_changed')
        for name in ('cron_script','cron_installation','cron_schedule'):
            image=row['files'][name]['after'];c.require(descriptor(capture(Path(image['path'])))==image,'accepted_cron_not_effective')
        for name in ('bot_key','receiver_bot_dropin','probe_bot_dropin'):
            image=capture(self.control_paths()[name]);c.require(image['kind']=='absent','role_bot_controls_exist');controls['files'][name]=image
        return controls
    def controls(self,journal):
        if journal is None:return self.predecessor()
        app=journal.get('app');c.require(app is not None,'application_transaction_required')
        paths=self.control_paths();runtime=journal.get('runtime')
        images={n:capture(path) for n,path in paths.items()}
        for name,image in images.items():
            allowed=[app['controls'][name]]
            if runtime and name in runtime['files']:allowed+=list(runtime['files'][name].values())
            c.require(descriptor(image) in allowed,'runtime_control_drift')
        from connect_con02_runtime import worker_files
        installation=self.legacy().installation;release=installation.current()
        c.require(release is not None and release.name in (previous.WORKER_COMMIT,WORKER_COMMIT),'unknown_worker_release')
        qualified=self.ctx.bundle if release.name==WORKER_COMMIT else self.ctx.bundle.predecessor
        c.require(installation.release_files(release)==worker_files(qualified),'accepted_worker_changed')
        import os
        for name in ('receiver','probe','notify'):
            unit='ustar-connect-'+name+'.service'
            for base in ('run/systemd/system','usr/lib/systemd/system','lib/systemd/system'):
                path=self.ctx.root/base/unit
                c.require(not os.path.lexists(path) and not os.path.lexists(path.with_name(unit+'.d')),'unknown_runtime_override')
            allowed={'50-ustar-notify-pilot.conf'} if name=='notify' else {'40-ustar-link.conf','60-ustar-con02-ack.conf'}
            if runtime and name!='notify':allowed.add('70-ustar-role-bot.conf')
            folder=self.ctx.root/'etc/systemd/system'/(unit+'.d')
            c.require(folder.is_dir() and not folder.is_symlink() and {p.name for p in folder.iterdir()}<=allowed,'unknown_runtime_override')
        # Every transitional byte image is fixed in the durable generation. Do not
        # reinterpret a half-written new profile as an old one-employee pilot.
        fence=copy.deepcopy(app['fence'])
        if runtime and descriptor(images['include'])==runtime['files']['include']['after']:
            fence.update(role_bot_enabled=True,notification_recipient_profile='academy_linked_opt_in',notify_since=runtime['cutoff'])
        return {'files':images,'fence':fence}
    def cron_patch(self,raw,installation):
        saved=Journal(self.ctx).load()
        digest=saved['app']['files']['cron_script']['before']['sha256'] if saved and saved.get('app') else getattr(self,'accepted_cron_sha',None)
        c.require(digest is not None,'accepted_cron_required')
        return patch_cron(raw,installation,accepted_sha=digest)


def make_context(bundle,*,root=Path('/'),uid=0,host=None):
    validate_reference(bundle.reference)
    c.require(bundle.predecessor.reference['tool_commit']==PREDECESSOR_TOOL,'fixed_predecessor_required')
    ctx=previous.make_context(bundle.predecessor,root=root,uid=uid,host=host)
    ctx.bundle=bundle;ctx.engine.reference=bundle.reference;ctx.engine.payload=bundle.payload
    ctx.engine.schema_fields=bundle.reference['candidate']['schema_fields']
    ctx.engine.base=Path(root)/str(BASE).lstrip('/');ctx.engine.marker=ctx.engine.base/'uninitialized-primitive.json'
    if host is None:ctx.host=Host(ctx)
    ctx.verify_database=verify_database;ctx.baseline_ack_enabled=True;ctx.profiles=profiles
    ctx.patch_cron=lambda raw,record:Host.cron_patch(ctx.host,raw,record)
    return ctx


def check(ctx):
    from connect_con02_application import Application
    from connect_con02_runtime import Runtime
    with Locks(ctx).check():
        journal=Journal(ctx);saved=journal.load();profile=None
        if saved is None:
            ctx.host.controls(None);ctx.host.preflight();database=ctx.host.database(ctx.host.prefix())
            profile=verify_database(database,database,ctx.bundle.reference,target=False);ctx.host.source(target=False)
            target=False;active=False
        else:
            c.require((saved.get('app') or {}).get('phase') in ('app_ready','baseline_restored'),'role_bot_recovery_required')
            target=saved['app']['phase']=='app_ready';Application(ctx,journal,Locks(ctx)).verify(saved['app'],target=target)
            active=(saved.get('runtime') or {}).get('phase')=='runtime_active'
            if saved.get('runtime'):
                c.require(saved['runtime']['phase'] in ('runtime_active','runtime_disabled'),'role_bot_recovery_required')
                Runtime(ctx,journal,Locks(ctx)).exact('after' if active else 'before')
        c.https_snapshot(ctx)
        return {'phase':'role_bot_ready' if active else ('application_ready' if target else 'ready_for_role_bot_update'),
            'local_ustar':'2026102900' if target else '2026102803','role_bot_enabled':active,
            'host_changes':False,'provider_called':False,**({'baseline_index_profile':profile} if profile else {})}


def main(argv=None,*,bundle=None):
    import argparse,os,signal,sys,traceback,uuid
    from connect_con02_update import artifact_guard,status
    p=argparse.ArgumentParser(description=__doc__);g=p.add_mutually_exclusive_group()
    for name in ('check','upgrade','status','recover','restore','enable-bot','disable-bot'):g.add_argument('--'+name,action='store_true')
    p.add_argument('--acknowledge-outage',action='store_true');p.add_argument('--discard-writes-since-snapshot',action='store_true')
    args=p.parse_args(argv)
    if args.acknowledge_outage and not(args.upgrade or args.restore):p.error('outage flag requires upgrade or restore')
    if args.discard_writes_since_snapshot and not args.restore:p.error('discard flag requires restore')
    if(args.upgrade or args.restore) and not args.acknowledge_outage:p.error('explicit outage acknowledgement required')
    if not any((args.check,args.upgrade,args.status,args.recover,args.restore,args.enable_bot,args.disable_bot)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    log=None
    try:
        artifact_guard(Path(sys.argv[0]));c.require(bundle is not None,'embedded_bundle_required');ctx=make_context(bundle)
        if not(args.check or args.status):
            from connect_con02_state import private_directory
            private_directory(ctx.engine.base,ctx.uid);log=(ctx.engine.base/('private-'+uuid.uuid4().hex+'.log')).open('xb')
            os.fchmod(log.fileno(),0o600);ctx.host.log=log
            def interrupted(*_):raise KeyboardInterrupt()
            for sig in(signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,interrupted)
            ctx.progress=lambda phase:print(json.dumps({'phase':phase}),flush=True)
        from connect_con02_application import Application
        from connect_con02_runtime import Runtime
        journal=Journal(ctx);locks=Locks(ctx);application=Application(ctx,journal,locks);runtime=Runtime(ctx,journal,locks)
        if args.check:result=check(ctx)
        elif args.status:result=status(ctx)
        elif args.enable_bot:result=runtime.enable()
        elif args.disable_bot:result=runtime.disable()
        elif args.restore:
            if (journal.load() or {}).get('runtime'):
                disabled=runtime.disable();c.require(disabled['phase']=='runtime_disabled','role_bot_disable_required')
            result=application.restore(outage=args.acknowledge_outage,discard=args.discard_writes_since_snapshot)
        elif args.recover:
            saved=journal.load()
            result=runtime.recover() if saved and saved.get('runtime') else application.recover()
        else:
            result=application.upgrade()
            if result['phase']=='app_ready':result=runtime.enable()
        if result.get('phase')=='runtime_active':result={**result,'role_bot_enabled':True,'local_ustar':'2026102900'}
        print(json.dumps(result,sort_keys=True),flush=True)
        return 2 if result.get('phase')=='recovery_required' or result.get('upgrade_succeeded') is False or result.get('enable_succeeded') is False else 0
    except BaseException as error:
        if log is not None:log.write(traceback.format_exc().encode());log.flush();os.fsync(log.fileno())
        reason=str(error) if isinstance(error,c.Refusal) else 'role_bot_operation_failed'
        if not re.fullmatch('[a-zA-Z0-9_]{1,100}',reason):reason='role_bot_operation_failed'
        print(json.dumps({'phase':'refused','reason':reason}),flush=True);return 2
    finally:
        if log is not None:log.close()
