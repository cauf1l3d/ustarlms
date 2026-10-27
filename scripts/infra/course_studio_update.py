#!/usr/bin/env python3
"""Fixed accepted2300 -> qualified Studio2600; application/cron only."""
from dataclasses import dataclass
import json
from pathlib import Path
import re
import connect_con02_contract as c
import connect_telegram_update as t
from connect_con02_state import Journal, Locks, unique

APP_COMMIT='2d95137ac85a09ad4c513edae8a792152153c840'
BASELINE_COMMIT=WORKER_COMMIT=t.APP_COMMIT
PREDECESSOR_TOOL='3d1938ae0f2997e79ebbd3e4c3c1c6aa9cd7c134'
BASE=Path('/var/lib/ustar-course-studio-update')
MODULES=t.MODULES+('course_studio_update',)
VERSIONS={'local_ustar':'2026102600','theme_ustar':'2026100202'}
CRON_LINE=b"VERSIONS = {'local_ustar': '2026102300', 'theme_ustar': '2026100202'}"
CHANGED=frozenset('local/ustar/'+n for n in (
 'classes/material_studio.php','classes/studio_scorm_runtime.php','lang/en/local_ustar.php',
 'lang/ru/local_ustar.php','materials.php','materials_studio.php','templates/materials.mustache','version.php'))
NEW_PHP=frozenset('local/ustar/'+n for n in ('course_studio.php','course_studio_api.php',
 'classes/course_studio.php','classes/course_studio_conflict.php','classes/course_studio_package.php',
 'classes/course_studio_project.php','tests/course_studio_test.php','tests/course_studio_project_test.php'))

@dataclass(frozen=True)
class Bundle:
    reference:dict
    modules:dict
    payload:dict
    worker_archive:bytes
    legacy:dict
    predecessor:t.Bundle


def validate_reference(ref):
    c.require(ref.get('schema')==1 and re.fullmatch('[a-f0-9]{40}',ref.get('tool_commit',''))
        and ref.get('app_commit')==APP_COMMIT and ref.get('baseline_commit')==BASELINE_COMMIT
        and ref.get('worker_commit')==WORKER_COMMIT and ref.get('legacy_commit')==c.LEGACY_COMMIT,
        'studio_fixed_source_required')
    before,after=ref['baseline'],ref['candidate']
    c.require(len(before['files'])==557 and len(after['files'])==582
        and before['versions']=={'local_ustar':2026102300,'theme_ustar':2026100202}
        and after['versions']=={'local_ustar':2026102600,'theme_ustar':2026100202},'studio_release_profile')
    c.require(before['tables']==after['tables'] and len(after['tables'])==100
        and before['schema_fields']==after['schema_fields']
        and before['schema_indexes']==after['schema_indexes'],'studio_schema_must_be_unchanged')
    old,new=set(before['files']),set(after['files'])
    c.require(old<=new and {n for n in old if before['files'][n]!=after['files'][n]}==CHANGED,
        'studio_unrelated_source_changed')
    c.require(len(new-old)==25 and NEW_PHP<=new-old
        and all(n in NEW_PHP or n.startswith('local/ustar/course_studio/') for n in new-old),
        'studio_added_source_profile')
    c.require(all(n.startswith(('local/ustar/','theme/ustar/')) and '..' not in Path(n).parts
        and not '\\' in n and re.fullmatch('[a-f0-9]{64}',digest)
        for n,digest in after['files'].items()),'studio_payload_path')


def patch_cron(raw,installation,*,accepted_sha):
    c.require(c.sha(raw)==accepted_sha and raw.count(CRON_LINE)==1,'cron_source_changed')
    c.require(isinstance(installation,dict) and installation.get('schema')==1
        and installation.get('script_sha256')==accepted_sha and installation.get('versions')==t.VERSIONS,
        'cron_installation_changed')
    result=raw.replace(CRON_LINE,CRON_LINE.replace(b'2026102300',b'2026102600'))
    compile(result,'<reviewed-Studio-cron>','exec')
    return result,{**installation,'versions':VERSIONS,'script_sha256':c.sha(result)}


def verify_database(current,baseline,reference,*,target):
    return c.verify_database(current,baseline,reference,target=target,historical_version=2026102300)


def verify_predecessor_database(current,baseline,reference):
    # Task disabled/customised flags may have been legitimately changed since
    # the23Oct release. Freeze the current policy; never reset it to that receipt.
    observed={**baseline,'tasks':current['tasks']}
    return t.verify_database(current,observed,reference,target=True)


def decode_studio_envelope(manifest,sources,assets):
    c.require(isinstance(manifest,dict) and manifest.get('worker_commit')==WORKER_COMMIT,
        'studio_worker_identity')
    # Reuse the bounded, hash-checked decoder. The actual worker identity is the
    # explicitly checked2300 identity above; no worker payload is activated here.
    return c.decode_envelope({**manifest,'worker_commit':APP_COMMIT},sources,assets,app_commit=APP_COMMIT)


def load_embedded(manifest,sources,assets):
    modules,values=decode_studio_envelope(manifest,sources,assets)
    c.require(set(modules)=={n+'.py' for n in MODULES},'embedded_module_inventory')
    ref=json.loads(values['reference.json'],object_pairs_hook=unique);validate_reference(ref)
    c.require(ref['tool_commit']==manifest['tool_commit'],'studio_tool_identity')
    telegram=json.loads(values['telegram.json'],object_pairs_hook=unique)
    con02=json.loads(values['con02.json'],object_pairs_hook=unique)
    c.require(telegram['tool_commit']==PREDECESSOR_TOOL and telegram['app_commit']==WORKER_COMMIT
        and telegram['worker_commit']==WORKER_COMMIT and telegram['baseline_commit']==c.APP_COMMIT
        and con02['tool_commit']==t.PREDECESSOR_TOOL and con02['app_commit']==c.APP_COMMIT
        and con02['worker_commit']==c.APP_COMMIT and con02['baseline_commit']==c.BASELINE_COMMIT,
        'studio_predecessor_identity')
    c.require(ref['baseline']==telegram['candidate'] and telegram['baseline']==con02['candidate'],
        'studio_predecessor_profile')
    legacy_names={'scripts/infra/'+n+'.py' for n in ('connect_notification_pilot','connect_bridge_activation',
        'connect_private_bridge','academy_https')}|{'connect-worker/deploy/install.py'}
    expected={'reference.json','telegram.json','con02.json','worker.tar.gz','predecessor-worker.tar.gz'}\
        |{'payload/'+n for n in ref['candidate']['files']}|{'legacy/'+n for n in legacy_names}
    c.require(set(values)==expected,'embedded_asset_inventory')
    payload={n:values['payload/'+n] for n in ref['candidate']['files']}
    c.require(all(c.sha(raw)==ref['candidate']['files'][n] for n,raw in payload.items()),'embedded_payload_digest')
    legacy={n:values['legacy/'+n] for n in legacy_names}
    old=c.EmbeddedBundle(con02,{}, {},values['predecessor-worker.tar.gz'],legacy)
    previous=t.Bundle(telegram,{}, {},values['worker.tar.gz'],legacy,old)
    from connect_con02_runtime import worker_files
    worker_files(old);worker_files(previous)
    return Bundle(ref,modules,payload,previous.worker_archive,legacy,previous)


class Host(c.Host):
    def previous_context(self):
        if '_previous' not in self.__dict__:
            self._previous=t.make_context(self.ctx.bundle.predecessor)
            self._previous.host.log=self.log
        return self._previous

    def legacy(self):return self.previous_context().host.legacy()

    def predecessor(self):
        from connect_con02_application import Application,patch_cron as old_patch
        from connect_con02_runtime import Runtime,worker_files
        from connect_con02_state import capture,descriptor
        previous=self.previous_context();journal=Journal(previous);saved=journal.load()
        c.require(saved and (saved.get('app') or {}).get('phase')=='app_ready'
            and (saved.get('runtime') or {}).get('phase')=='runtime_active','accepted_Telegram2300_required')
        Application(previous,journal,Locks(previous)).bind(saved['app'])
        previous.host.source(target=True)
        verify_predecessor_database(previous.host.database(saved['app']['prefix']),
            saved['app']['database'],previous.bundle.reference)
        previous.host.controls(saved);journal.guard('app')
        Runtime(previous,journal,Locks(previous)).exact('after')
        installation=previous.host.legacy().installation;release=installation.current()
        c.require(release is not None and release.name==WORKER_COMMIT
            and installation.release_files(release)==worker_files(previous.bundle),'accepted_worker_changed')
        controls=previous.host.controls(saved)
        c.require(json.loads(controls['files']['notifications']['raw'])['notification_contract']==3
            and b'$CFG->ustar_connect_notification_presentation_enabled=true;' in controls['files']['include']['raw'],
            'accepted_presentation_required')
        # Derive the accepted2300 cron from the fixed2000 source and both pure
        # predecessor patches; never bless an arbitrary current cron digest.
        oldctx=c.make_context(previous.bundle.predecessor);oldjournal=Journal(oldctx);oldstate=oldjournal.load()
        c.require(oldstate and (oldstate.get('app') or {}).get('phase')=='app_ready','accepted_CON02_journal_required')
        folder=oldjournal.folder(oldstate['app'])
        raw=oldjournal.read_private(folder/'cron_script.before')
        record=json.loads(oldjournal.read_private(folder/'cron_installation.before'),object_pairs_hook=unique)
        cron2200,record2200=old_patch(raw,record)
        cron2300,_=t.patch_cron(cron2200,record2200,accepted_sha=c.sha(cron2200))
        row=saved['app'];self.accepted_cron_sha=c.sha(cron2300)
        c.require(row['files']['cron_script']['after']['sha256']==self.accepted_cron_sha,
            'accepted_cron_journal_changed')
        for name in ('cron_script','cron_installation','cron_schedule'):
            image=row['files'][name]['after']
            c.require(descriptor(capture(Path(image['path'])))==image,'accepted_cron_not_effective')
        return controls

    def controls(self,journal):
        return self.predecessor() if journal is None else super().controls(journal)

    def cron_patch(self,raw,installation):
        saved=Journal(self.ctx).load()
        digest=(saved['app']['files']['cron_script']['before']['sha256']
            if saved and saved.get('app') else getattr(self,'accepted_cron_sha',None))
        c.require(digest is not None,'accepted_cron_required')
        return patch_cron(raw,installation,accepted_sha=digest)


def make_context(bundle,*,root=Path('/'),uid=0,host=None):
    validate_reference(bundle.reference)
    c.require(bundle.predecessor.reference['tool_commit']==PREDECESSOR_TOOL,'fixed_predecessor_required')
    ctx=t.make_context(bundle.predecessor,root=root,uid=uid,host=host)
    ctx.bundle=bundle;ctx.engine.reference=bundle.reference;ctx.engine.payload=bundle.payload
    ctx.engine.schema_fields=bundle.reference['candidate']['schema_fields']
    ctx.engine.base=Path(root)/str(BASE).lstrip('/');ctx.engine.marker=ctx.engine.base/'uninitialized-primitive.json'
    if host is None:ctx.host=Host(ctx)
    ctx.verify_database=verify_database;ctx.baseline_ack_enabled=True
    ctx.patch_cron=lambda raw,record:Host.cron_patch(ctx.host,raw,record)
    return ctx


def check(ctx):
    from connect_con02_application import Application
    with Locks(ctx).check():
        journal=Journal(ctx);saved=journal.load()
        if saved is None:
            ctx.host.controls(None);ctx.host.preflight();target=False
            database=ctx.host.database(ctx.host.prefix());verify_database(database,database,ctx.bundle.reference,target=False)
            ctx.host.source(target=False)
        else:
            c.require(saved.get('runtime') is None and (saved.get('app') or {}).get('phase') in ('app_ready','baseline_restored'),
                'studio_recovery_required')
            target=saved['app']['phase']=='app_ready'
            Application(ctx,journal,Locks(ctx)).verify(saved['app'],target=target)
        c.https_snapshot(ctx)
        return {'phase':'application_ready' if target else 'ready_for_studio_update',
            'local_ustar':'2026102600' if target else '2026102300','host_changes':False,'provider_called':False}


def main(argv=None,*,bundle=None):
    import argparse,os,signal,sys,traceback,uuid
    from connect_con02_update import artifact_guard,status
    p=argparse.ArgumentParser(description=__doc__);g=p.add_mutually_exclusive_group()
    for name in ('check','upgrade','status','recover','restore'):g.add_argument('--'+name,action='store_true')
    p.add_argument('--acknowledge-outage',action='store_true');p.add_argument('--discard-writes-since-snapshot',action='store_true')
    args=p.parse_args(argv)
    if args.acknowledge_outage and not(args.upgrade or args.restore):p.error('outage flag requires upgrade or restore')
    if args.discard_writes_since_snapshot and not args.restore:p.error('discard flag requires restore')
    if (args.upgrade or args.restore) and not args.acknowledge_outage:p.error('explicit outage acknowledgement required')
    if not any((args.check,args.upgrade,args.status,args.recover,args.restore)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    log=None
    try:
        artifact_guard(Path(sys.argv[0]));c.require(bundle is not None,'embedded_bundle_required');ctx=make_context(bundle)
        if args.upgrade or args.recover or args.restore:
            from connect_con02_state import private_directory
            private_directory(ctx.engine.base,ctx.uid);log=(ctx.engine.base/('private-'+uuid.uuid4().hex+'.log')).open('xb')
            os.fchmod(log.fileno(),0o600);ctx.host.log=log
            def interrupted(*_):raise KeyboardInterrupt()
            for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,interrupted)
            ctx.progress=lambda phase:print(json.dumps({'phase':phase}),flush=True)
        if args.check:result=check(ctx)
        elif args.status:result=status(ctx)
        else:
            from connect_con02_application import Application
            operation=Application(ctx,Journal(ctx),Locks(ctx))
            if args.upgrade:result=operation.upgrade()
            elif args.recover:result=operation.recover()
            else:result=operation.restore(outage=args.acknowledge_outage,discard=args.discard_writes_since_snapshot)
        print(json.dumps(result,sort_keys=True),flush=True)
        return 2 if result.get('phase')=='recovery_required' or result.get('upgrade_succeeded') is False else 0
    except BaseException as error:
        if log is not None:log.write(traceback.format_exc().encode());log.flush();os.fsync(log.fileno())
        reason=str(error) if isinstance(error,c.Refusal) else 'studio_operation_failed'
        if not re.fullmatch('[a-zA-Z0-9_]{1,100}',reason):reason='studio_operation_failed'
        print(json.dumps({'phase':'refused','reason':reason}),flush=True);return 2
    finally:
        if log is not None:log.close()
