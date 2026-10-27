#!/usr/bin/env python3
"""Successor to the accepted CON02 controller. Old journals remain read only."""
from dataclasses import dataclass
import json
from pathlib import Path
import re
from types import SimpleNamespace
import connect_con02_contract as c
from connect_con02_state import Journal, Locks, unique

APP_COMMIT = '6c56a5b6ab830b97b706a5d7028b49cfae31e7a4'
PREDECESSOR_TOOL = '4394b99475f469b061010a1ea4aa77b9d984c40f'
BASE = Path('/var/lib/ustar-connect-telegram-update')
MODULES = c.MODULES + ('connect_telegram_update',)
VERSIONS = {'local_ustar':'2026102300', 'theme_ustar':'2026100202'}
CRON_LINE = b"VERSIONS = {'local_ustar': '2026102200', 'theme_ustar': '2026100202'}"


@dataclass(frozen=True)
class Bundle:
    reference: dict
    modules: dict
    payload: dict
    worker_archive: bytes
    legacy: dict
    predecessor: c.EmbeddedBundle


def profiles(ctx, before, *, generation, cutoff, notify_secret, ack_secret):
    """Append a sender capability; outstanding v2 callback tokens keep their key/time."""
    c.require(isinstance(generation, str) and re.fullmatch('[a-f0-9]{32}', generation), 'runtime_generation')
    raw = {n:i['raw'] for n,i in before['files'].items()}
    cfg = json.loads(raw['notifications'], object_pairs_hook=unique)
    c.require(type(cfg.get('notification_contract')) is int and cfg['notification_contract'] == 2
              and cfg.get('bridge_key_id') == 'tg_notify_v2', 'accepted_v2_sender_required')
    for n in ('receiver', 'probe'):
        receiver = json.loads(raw[n], object_pairs_hook=unique)
        c.require(receiver.get('acknowledgements_enabled') is True
                  and receiver.get('ack_bridge_key_id') == 'tg_ack_v1', 'accepted_ACK_receiver_required')
    fence = c.read_fence(raw['include'], cfg, json.loads(raw['receiver'], object_pairs_hook=unique))
    c.require(all(before['fence'].get(k) == v for k,v in fence.items()), 'notification_fence_changed')
    c.require(raw['include'].count(b'$CFG->ustar_connect_ack_enabled=true;') == 1
              and len(re.findall(rb'(?m)^\$CFG->ustar_connect_ack_since=([1-9][0-9]*);$', raw['include'])) == 1
              and b'ustar_connect_notification_presentation_enabled' not in raw['include']
              and b"['tg_notify_v3']" not in raw['include'], 'accepted_v2_include_required')
    keys = [raw[n].strip() for n in ('link_key','notify_key','ack_key')] + [notify_secret]
    c.require(all(isinstance(k, bytes) and re.fullmatch(b'[a-f0-9]{64}',k) for k in keys)
              and len(set(keys)) == 4, 'runtime_keys_not_distinct')
    tenants = re.findall(rb"'tenant'=>'([1-9][0-9]{0,19})'", raw['include'])
    c.require(tenants and len(set(tenants)) == 1, 'include_tenant_changed')
    include = raw['include'] + ("\n// USTAR Telegram presentation generation " + generation + ".\n"
        "$CFG->ustar_connect_bridge_keys['tg_notify_v3']=['secret'=>'" + notify_secret.decode() +
        "','provider'=>'telegram','tenant'=>'" + tenants[0].decode() +
        "','operations'=>['notification.lease','notification.begin_v3','notification.finish']];\n"
        "$CFG->ustar_connect_notification_presentation_enabled=true;\n").encode()
    cfg.update(notification_contract=3, bridge_key_id='tg_notify_v3')
    return {'include':include, 'notifications':(json.dumps(cfg,sort_keys=True)+'\n').encode(),
            'notify_key':notify_secret+b'\n'}


def patch_cron(raw, installation, *, accepted_sha):
    c.require(c.sha(raw) == accepted_sha and raw.count(CRON_LINE) == 1, 'cron_source_changed')
    c.require(isinstance(installation,dict) and installation.get('schema') == 1
              and installation.get('script_sha256') == accepted_sha
              and installation.get('versions') == {'local_ustar':'2026102200','theme_ustar':'2026100202'},
              'cron_installation_changed')
    result = raw.replace(CRON_LINE, CRON_LINE.replace(b'2026102200',b'2026102300'))
    compile(result,'<reviewed-Telegram-cron>','exec')
    return result, {**installation,'versions':VERSIONS,'script_sha256':c.sha(result)}


def verify_database(current, baseline, reference, *, target):
    return c.verify_database(current, baseline, reference, target=target, historical_version=2026102200)


def load_embedded(manifest, sources, assets):
    modules, values = c.decode_envelope(manifest, sources, assets, app_commit=APP_COMMIT)
    c.require(set(modules) == {n+'.py' for n in MODULES}, 'embedded_module_inventory')
    ref = json.loads(values['reference.json'], object_pairs_hook=unique)
    pred = json.loads(values['predecessor.json'], object_pairs_hook=unique)
    c.require(ref.get('schema') == 1 and ref.get('tool_commit') == manifest['tool_commit']
              and ref.get('app_commit') == ref.get('worker_commit') == APP_COMMIT
              and ref.get('baseline_commit') == c.APP_COMMIT
              and ref.get('legacy_commit') == c.LEGACY_COMMIT
              and pred.get('tool_commit') == PREDECESSOR_TOOL
              and pred.get('app_commit') == pred.get('worker_commit') == c.APP_COMMIT
              and pred.get('baseline_commit') == c.BASELINE_COMMIT
              and pred.get('legacy_commit') == c.LEGACY_COMMIT
              and ref['baseline'] == pred['candidate'], 'embedded_successor_identity')
    b, t = ref['baseline'], ref['candidate']
    c.require(len(b['files']) == len(t['files']) == 557 and len(b['tables']) == len(t['tables']) == 100
              and b['tables'] == t['tables'] and b['schema_fields'] == t['schema_fields']
              and b['schema_indexes'] == t['schema_indexes']
              and b['versions'] == {'local_ustar':2026102200,'theme_ustar':2026100202}
              and t['versions'] == {'local_ustar':2026102300,'theme_ustar':2026100202}, 'embedded_successor_profile')
    legacy_names = {'scripts/infra/'+n+'.py' for n in ('connect_notification_pilot',
        'connect_bridge_activation','connect_private_bridge','academy_https')} | {'connect-worker/deploy/install.py'}
    expected = {'reference.json','predecessor.json','worker.tar.gz','predecessor-worker.tar.gz'} | {
        'payload/'+n for n in t['files']} | {'legacy/'+n for n in legacy_names}
    c.require(set(values) == expected,'embedded_asset_inventory')
    payload = {n:values['payload/'+n] for n in t['files']}
    c.require(all(n.startswith(('local/ustar/','theme/ustar/')) and c.sha(raw) == t['files'][n]
                  for n,raw in payload.items()), 'embedded_payload_digest')
    legacy = {n:values['legacy/'+n] for n in legacy_names}
    previous = c.EmbeddedBundle(pred,{}, {},values['predecessor-worker.tar.gz'],legacy)
    bundle = Bundle(ref,modules,payload,values['worker.tar.gz'],legacy,previous)
    from connect_con02_runtime import worker_files
    worker_files(previous); worker_files(bundle)
    return bundle


class Host(c.Host):
    def predecessor_context(self):
        return c.make_context(self.ctx.bundle.predecessor)

    def verify_predecessor_worker(self, previous):
        from connect_con02_runtime import worker_files
        installation=previous.host.legacy().installation
        release=installation.current()
        c.require(release is not None and release.name == c.APP_COMMIT
                  and installation.release_files(release) == worker_files(previous.bundle),
                  'accepted_worker_changed')

    def predecessor(self):
        """Called while shared deployment locks are held; never reacquire them."""
        from connect_con02_application import Application, patch_cron as old_patch
        from connect_con02_runtime import Runtime
        previous = self.predecessor_context()
        previous.host.log = self.log
        journal = Journal(previous); saved = journal.load()
        c.require(saved is not None and saved['app'] is not None and saved['app']['phase'] == 'app_ready'
                  and saved['runtime'] is not None and saved['runtime']['phase'] == 'runtime_active',
                  'accepted_CON02_active_required')
        Application(previous,journal,Locks(previous)).verify(saved['app'],target=True)
        Runtime(previous,journal,Locks(previous)).exact('after')
        self.verify_predecessor_worker(previous)
        c.https_snapshot(previous)
        # The accepted2200 cron is derived from its pinned2000 before-image,
        # not from arbitrary currently observed bytes or a journal digest alone.
        row = saved['app']; folder = journal.folder(row)
        old = journal.read_private(folder/'cron_script.before')
        installation = json.loads(journal.read_private(folder/'cron_installation.before'),object_pairs_hook=unique)
        accepted, _ = old_patch(old, installation)
        c.require(c.sha(accepted) == row['files']['cron_script']['after']['sha256'], 'accepted_cron_journal_changed')
        from connect_con02_state import capture, descriptor
        for name in ('cron_script','cron_installation','cron_schedule'):
            image=row['files'][name]['after']
            c.require(descriptor(capture(Path(image['path']))) == image,'accepted_cron_not_effective')
        self.accepted_cron_sha = c.sha(accepted)
        return previous.host.controls(saved)

    def controls(self, journal):
        if journal is None: return self.predecessor()
        return super().controls(journal)

    def cron_patch(self, raw, installation):
        # After a fresh process starts in recovery the saved app images provide
        # the exact accepted digest; predecessor verification is only pre-upgrade.
        saved = Journal(self.ctx).load()
        digest = (saved['app']['files']['cron_script']['before']['sha256']
                  if saved and saved.get('app') else getattr(self,'accepted_cron_sha',None))
        c.require(digest is not None,'accepted_cron_required')
        return patch_cron(raw,installation,accepted_sha=digest)


def make_context(bundle, *, root=Path('/'), uid=0, host=None):
    c.require(bundle.reference['app_commit'] == bundle.reference['worker_commit'] == APP_COMMIT
              and bundle.reference['baseline_commit'] == c.APP_COMMIT
              and bundle.predecessor.reference['tool_commit'] == PREDECESSOR_TOOL, 'fixed_successor_required')
    ctx = c.make_context(bundle.predecessor,root=root,uid=uid,host=host)
    ctx.bundle = bundle
    ctx.engine.reference, ctx.engine.payload = bundle.reference, bundle.payload
    ctx.engine.schema_fields = bundle.reference['candidate']['schema_fields']
    ctx.engine.base = Path(root)/str(BASE).lstrip('/')
    ctx.engine.marker = ctx.engine.base/'uninitialized-primitive.json'
    if host is None: ctx.host = Host(ctx)
    ctx.baseline_ack_enabled = True
    ctx.verify_database = verify_database
    ctx.profiles = profiles
    ctx.patch_cron = lambda raw, installation: Host.cron_patch(ctx.host, raw, installation)
    return ctx


def check(ctx):
    from connect_con02_application import Application
    with Locks(ctx).check():
        journal = Journal(ctx); saved = journal.load()
        if saved is None:
            ctx.host.controls(None)
            target = False
        else:
            row = saved.get('app')
            c.require(row is not None and row['phase'] in ('app_ready','baseline_restored'), 'application_recovery_required')
            target = row['phase'] == 'app_ready'
            Application(ctx,journal,Locks(ctx)).verify(row,target=target)
            if saved.get('runtime'):
                from connect_con02_runtime import Runtime
                c.require(saved['runtime']['phase'] in ('runtime_active','runtime_disabled'),'runtime_recovery_required')
                Runtime(ctx,journal,Locks(ctx)).exact('after' if saved['runtime']['phase']=='runtime_active' else 'before')
        c.https_snapshot(ctx)
        return {'phase':'application_ready' if target else 'baseline_ready',
                'local_ustar':'2026102300' if target else '2026102200',
                'ack_enabled':True, 'host_changes':False, 'provider_called':False}


def main(argv=None, *, bundle=None):
    from connect_con02_update import artifact_guard, status
    import argparse
    import os
    import signal
    import sys
    import traceback
    import uuid
    p=argparse.ArgumentParser(description=__doc__)
    g=p.add_mutually_exclusive_group()
    for n in ('check','status','upgrade','enable-presentation','disable-presentation','recover','restore'):
        g.add_argument('--'+n,action='store_true')
    p.add_argument('--acknowledge-outage',action='store_true')
    p.add_argument('--discard-writes-since-snapshot',action='store_true')
    args=p.parse_args(argv)
    if args.acknowledge_outage and not (args.upgrade or args.restore): p.error('outage flag requires upgrade or restore')
    if args.discard_writes_since_snapshot and not args.restore: p.error('discard flag requires restore')
    if (args.upgrade or args.restore) and not args.acknowledge_outage: p.error('explicit outage acknowledgement required')
    if not any((args.check,args.status,args.upgrade,args.enable_presentation,args.disable_presentation,args.recover,args.restore)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    log=None
    try:
        artifact_guard(Path(sys.argv[0]));c.require(bundle is not None,'embedded_bundle_required')
        ctx=make_context(bundle)
        if not (args.check or args.status):
            from connect_con02_state import private_directory
            private_directory(ctx.engine.base,ctx.uid)
            log=(ctx.engine.base/('private-'+uuid.uuid4().hex+'.log')).open('xb');os.fchmod(log.fileno(),0o600)
            ctx.host.log=log
            def interrupted(*_):raise KeyboardInterrupt()
            for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,interrupted)
            ctx.progress=lambda phase:print(json.dumps({'phase':phase}),flush=True)
        from connect_con02_application import Application
        from connect_con02_runtime import Runtime
        journal,locks=Journal(ctx),Locks(ctx)
        app,runtime=Application(ctx,journal,locks),Runtime(ctx,journal,locks)
        if args.check:result=check(ctx)
        elif args.status:result=status(ctx)
        elif args.upgrade:result=app.upgrade()
        elif args.enable_presentation:result=runtime.enable()
        elif args.disable_presentation:result=runtime.disable()
        elif args.restore:result=app.restore(discard=args.discard_writes_since_snapshot,outage=args.acknowledge_outage)
        else:
            saved=journal.load()
            c.require(saved is not None and saved.get('app') is not None,'application_transaction_required')
            result=runtime.recover() if saved['app']['phase']=='app_ready' and saved.get('runtime') else app.recover()
        if result.get('phase') in ('runtime_active','runtime_disabled'):
            result={**result,'ack_enabled':True,'presentation_enabled':result['phase']=='runtime_active'}
        print(json.dumps(result,sort_keys=True),flush=True)
        return 2 if result.get('phase')=='recovery_required' or result.get('upgrade_succeeded') is False or result.get('enable_succeeded') is False else 0
    except BaseException as error:
        if log is not None:log.write(traceback.format_exc().encode());log.flush();os.fsync(log.fileno())
        reason=str(error) if isinstance(error,c.Refusal) else 'controller_operation_failed'
        if not re.fullmatch('[a-zA-Z0-9_]{1,100}',reason):reason='controller_operation_failed'
        print(json.dumps({'phase':'refused','reason':reason}),flush=True);return 2
    finally:
        if log is not None:log.close()


if __name__=='__main__':raise SystemExit(main())
