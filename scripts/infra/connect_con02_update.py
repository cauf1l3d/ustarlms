#!/usr/bin/env python3
"""Strict operator CLI. No arguments always means offline PLAN_ONLY."""
import argparse
import json
import os
from pathlib import Path
import signal
import stat
import sys
import traceback
import uuid
from connect_con02_contract import (APP_COMMIT, BASELINE_COMMIT, LEGACY_COMMIT, MODULES,
    EmbeddedBundle, Refusal, check, decode_envelope, make_context, require, sha)
from connect_con02_state import unique


def load_embedded(manifest, sources, assets):
    modules, values = decode_envelope(manifest, sources, assets)
    require(set(modules) == {n+'.py' for n in MODULES}, 'embedded_module_inventory')
    require('reference.json' in values and 'worker.tar.gz' in values, 'embedded_reference_required')
    reference = json.loads(values['reference.json'], object_pairs_hook=unique)
    require(reference.get('schema') == 1 and reference.get('tool_commit') == manifest['tool_commit']
            and reference.get('app_commit') == reference.get('worker_commit') == APP_COMMIT
            and reference.get('baseline_commit') == BASELINE_COMMIT and reference.get('legacy_commit') == LEGACY_COMMIT,
            'embedded_reference_source')
    baseline, candidate = reference['baseline'], reference['candidate']
    require((len(baseline['files']), len(candidate['files']), len(baseline['tables']), len(candidate['tables']))
            == (552, 557, 99, 100) and set(candidate['tables']) - set(baseline['tables']) == {'local_ustar_conn_acktokens'}
            and baseline['versions'] == {'local_ustar': 2026102000, 'theme_ustar': 2026100202}
            and candidate['versions'] == {'local_ustar': 2026102200, 'theme_ustar': 2026100202}, 'embedded_release_profile')
    legacy_names = {'scripts/infra/'+n+'.py' for n in ('connect_notification_pilot', 'connect_bridge_activation',
                    'connect_private_bridge', 'academy_https')} | {'connect-worker/deploy/install.py'}
    expected = {'reference.json', 'worker.tar.gz'} | {'payload/'+n for n in candidate['files']} | {'legacy/'+n for n in legacy_names}
    require(set(values) == expected, 'embedded_asset_inventory')
    payload = {n: values['payload/'+n] for n in candidate['files']}
    require(all(n.startswith(('local/ustar/', 'theme/ustar/')) and sha(raw) == candidate['files'][n]
                for n, raw in payload.items()), 'embedded_payload_digest')
    bundle = EmbeddedBundle(reference, modules, payload, values['worker.tar.gz'],
                            {n: values['legacy/'+n] for n in legacy_names})
    from connect_con02_runtime import worker_files
    worker_files(bundle)
    return bundle


def artifact_guard(path):
    require(os.geteuid() == 0 and sys.version_info >= (3, 12), 'root_python312_required')
    path = Path(path).absolute()
    st = path.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1
            and stat.S_IMODE(st.st_mode) == 0o600 and st.st_size <= 128*1024**2, 'unsafe_controller_artifact')
    for p in path.parents:
        info = p.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022, 'unsafe_artifact_parent')


def parser():
    p = argparse.ArgumentParser(description=__doc__)
    group = p.add_mutually_exclusive_group()
    for name in ('check', 'upgrade', 'status', 'enable-ack', 'disable-ack', 'recover', 'restore'):
        group.add_argument('--'+name, action='store_true')
    p.add_argument('--acknowledge-outage', action='store_true')
    p.add_argument('--discard-writes-since-snapshot', action='store_true')
    return p


def status(ctx):
    from connect_con02_state import Journal, Locks
    from connect_con02_application import service_snapshot
    with Locks(ctx).check():
        result = Journal(ctx).public()
        db = ctx.host.database(ctx.host.prefix())
        return {**result, 'versions': {k: db['addons'].get(k) for k in ('local_ustar', 'theme_ustar')},
                'services': service_snapshot(ctx.host), 'host_changes': False}


def main(argv=None, *, bundle=None):
    p = parser(); args = p.parse_args(argv)
    if args.acknowledge_outage and not (args.upgrade or args.restore): p.error('outage flag requires upgrade or restore')
    if args.discard_writes_since_snapshot and not args.restore: p.error('discard flag requires restore')
    if (args.upgrade or args.restore) and not args.acknowledge_outage: p.error('explicit outage acknowledgement required')
    if not any((args.check, args.upgrade, args.status, args.enable_ack, args.disable_ack, args.recover, args.restore)):
        print(json.dumps({'phase': 'PLAN_ONLY', 'host_access': False, 'network_used': False})); return 0
    log = None
    try:
        artifact_guard(Path(sys.argv[0]))
        require(bundle is not None, 'embedded_bundle_required')
        ctx = make_context(bundle)
        mutating = not (args.check or args.status)
        if mutating:
            from connect_con02_state import private_directory
            private_directory(ctx.engine.base, ctx.uid)
            log = (ctx.engine.base / ('private-'+uuid.uuid4().hex+'.log')).open('xb')
            os.fchmod(log.fileno(), 0o600); ctx.host.log = log
            def interrupted(*_): raise KeyboardInterrupt()
            for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP): signal.signal(sig, interrupted)
            ctx.progress = lambda phase: print(json.dumps({'phase': phase}), flush=True)
        if args.check: result = check(ctx)
        elif args.status: result = status(ctx)
        else:
            from connect_con02_application import Application
            from connect_con02_runtime import Runtime
            from connect_con02_state import Journal, Locks
            journal, locks = Journal(ctx), Locks(ctx)
            app, runtime = Application(ctx, journal, locks), Runtime(ctx, journal, locks)
            if args.upgrade: result = app.upgrade()
            elif args.enable_ack: result = runtime.enable()
            elif args.disable_ack: result = runtime.disable()
            elif args.restore: result = app.restore(discard=args.discard_writes_since_snapshot, outage=args.acknowledge_outage)
            else:
                state = journal.load()
                require(state is not None and state.get('app') is not None, 'application_transaction_required')
                result = runtime.recover() if state['app']['phase'] == 'app_ready' and state.get('runtime') else app.recover()
        print(json.dumps(result, sort_keys=True), flush=True)
        return 2 if result.get('phase') == 'recovery_required' or result.get('upgrade_succeeded') is False or result.get('enable_succeeded') is False else 0
    except BaseException as error:
        if log is not None:
            log.write(traceback.format_exc().encode()); log.flush(); os.fsync(log.fileno())
        reason = str(error) if isinstance(error, Refusal) else 'controller_operation_failed'
        import re
        if not re.fullmatch('[a-zA-Z0-9_]{1,100}', reason): reason = 'controller_operation_failed'
        print(json.dumps({'phase': 'refused', 'reason': reason}), flush=True); return 2
    finally:
        if log is not None: log.close()


if __name__ == '__main__': raise SystemExit(main())
