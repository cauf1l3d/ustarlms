#!/usr/bin/env python3
"""Successor controller on disposable actual systemd/TLS and HTTPS-owner fixtures.

Application SQL is the separate native2200→2300 gate. Never imports a provider SDK.
"""
import ast
import json
import os
from pathlib import Path
import shutil
import sys

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
import connect_con02_contract as c
import connect_con02_state as state
import connect_telegram_update as t
from connect_con02_runtime import Runtime


def artifact_bundle(path):
    path=Path(path)
    c.require(c.sha(path.read_bytes())==path.with_name(path.name+'.sha256').read_text().split()[0],'fixture_artifact_digest')
    parsed=ast.parse(path.read_text())
    constants={n.targets[0].id:ast.literal_eval(n.value) for n in parsed.body if isinstance(n,ast.Assign)
        and isinstance(n.targets[0],ast.Name) and n.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')}
    bundle=t.load_embedded(*[json.loads(constants[n],object_pairs_hook=state.unique)
                            for n in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')])
    for name in t.MODULES:
        source=ROOT/('scripts' if name=='production_manifest' else 'scripts/infra')/(name+'.py')
        assert bundle.modules[name+'.py']==source.read_bytes(),'successor_artifact_differs_from_tested_controller'
    return bundle


def verify(bundle,previous,journal,locks,host,*,cursor=None,actual_services=False,role_bundle=None):
    if os.geteuid()!=0 or os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('USTAR_CONNECT_CON02_FIXTURE')!='1':
        raise RuntimeError('disposable_ci_only')
    target=previous.root/str(t.BASE).lstrip('/')
    assert not target.exists(),'successor_fixture_namespace_collision'
    old=Runtime(previous,journal,locks)
    assert old.enable()['phase']=='runtime_active'
    if actual_services:
        host.service('enable',c.SERVICES[0]);host.service('start',c.SERVICES[0])
    old_image=state.capture(journal.path)
    controls=host.controls(journal.load())
    ack=controls['files']['ack_key']
    first=cursor() if cursor else None
    owner=c.https_snapshot(previous)
    # The native SQL fixture proves the app migration separately; here the real
    # release files must match before permitting a worker pointer handoff.
    for name,raw in bundle.payload.items():
        path=previous.engine.web/name
        image=state.capture(path)
        assert image['kind']=='file'
        state.replace(path,raw,image['metadata'])
    ctx=t.make_context(bundle,root=previous.root,host=host);host.ctx=ctx
    new_journal=state.Journal(ctx);new_locks=state.Locks(ctx)
    try:
        new_journal.begin('app',{'files':{}},{'files':{}},generation='c'*32)
        new_journal.checkpoint('app','app_ready')
        op=Runtime(ctx,new_journal,new_locks)
        assert op.enable()['phase']=='runtime_active'
        paths=host.control_paths()
        assert json.loads(paths['notifications'].read_bytes())['notification_contract']==3
        assert state.capture(paths['ack_key'])==ack
        assert paths['include'].read_bytes().startswith(controls['files']['include']['raw'])
        assert c.https_snapshot(ctx)==owner
        assert state.capture(journal.path)==old_image
        assert paths['pointer'].resolve().name==t.APP_COMMIT
        if role_bundle is not None:
            from connect_role_bot_systemd import verify as role_verify
            role_verify(role_bundle,ctx,new_journal,new_locks,host,cursor=cursor,actual_services=actual_services)
        assert op.disable()['ack_enabled'] is True
        assert {n:state.capture(p) for n,p in paths.items()}==controls['files']
        if actual_services:
            host.fail_once=True
            failed=op.enable()
            assert failed['phase']=='runtime_disabled' and failed['enable_succeeded'] is False and failed['ack_enabled'] is True
            assert host.actual_restart_failure
            assert op.enable()['phase']=='runtime_active'
            assert op.disable()['phase']=='runtime_disabled'
        assert state.capture(journal.path)==old_image and c.https_snapshot(ctx)==owner
        assert state.capture(paths['ack_key'])==ack
        last=cursor() if cursor else None
        assert not cursor or last>first
        evidence={'result':'TELEGRAM_UPDATE_RUNTIME=PASS' if actual_services else 'TELEGRAM_UPDATE_HTTPS_OWNER=PASS',
            'app_commit':t.APP_COMMIT,'tool_commit':bundle.reference['tool_commit'],
            'actual_systemd_UID33_LoadCredential':actual_services,'v3_scoped_sender':True,
            'v2_handback_ACK_key_cutoff_fence_and_old_journal_preserved':True,
            'HTTPS_owned_bytes_unchanged':True,'actual_start_failure_recovers':actual_services,
            'cursor_before':first,'cursor_after':last,'application_SQL':'separate native lifecycle gate',
            'external_provider_requests':False,'production_access':False}
        filename='telegram-update-runtime.json' if actual_services else 'telegram-update-https-owner.json'
        (ROOT/'artifacts'/filename).write_text(json.dumps(evidence,indent=2)+'\n')
        print(evidence['result'])
    finally:
        host.ctx=previous
        shutil.rmtree(target,ignore_errors=True)
