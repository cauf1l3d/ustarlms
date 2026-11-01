#!/usr/bin/env python3
"""Role worker on real UID33/LoadCredential/TLS. Bot API and Academy store are synthetic."""
import ast
import json
import os
from pathlib import Path
import shutil
from types import MethodType
import sys

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
import connect_con02_contract as c
import connect_con02_state as state
import connect_role_bot_update as role
from connect_con02_runtime import Runtime


def artifact_bundle(path):
    path=Path(path)
    c.require(c.sha(path.read_bytes())==path.with_name(path.name+'.sha256').read_text().split()[0],'fixture_artifact_digest')
    tree=ast.parse(path.read_text())
    values={n.targets[0].id:ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign)
        and isinstance(n.targets[0],ast.Name) and n.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')}
    bundle=role.load_embedded(*[json.loads(values[n],object_pairs_hook=state.unique) for n in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')])
    for name in role.MODULES:
        source=ROOT/('scripts' if name=='production_manifest' else 'scripts/infra')/(name+'.py')
        assert bundle.modules[name+'.py']==source.read_bytes(),'role_artifact_differs_from_tested_controller'
    return bundle


def replace_source(web,payload):
    # Caller proved the dedicated CI fixture; no production-shaped invocation is accepted.
    for scope in ('local/ustar','theme/ustar'):shutil.rmtree(web/scope)
    for name,raw in payload.items():
        path=web/name;path.parent.mkdir(parents=True,exist_ok=True);path.parent.chmod(0o755)
        state.replace(path,raw,{'uid':0,'gid':33,'mode':0o644,'xattrs':{}})


def verify(bundle,previous,journal,locks,host,*,cursor=None,actual_services=True):
    if(os.geteuid()!=0 or os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('USTAR_CONNECT_CON02_FIXTURE')!='1'
            or (actual_services and not Path('/usr/local/lib/ustar-con02-controller-fixture/worker.py').is_file())
            or (not actual_services and previous.root==Path('/'))):
        raise RuntimeError('disposable_ci_only')
    target=previous.root/str(role.BASE).lstrip('/');assert not target.exists()
    before=host.controls(journal.load());old_journal=state.capture(journal.path);owner=c.https_snapshot(previous)
    old_paths=host.control_paths;old_controls=host.controls;first=cursor() if cursor else None
    paths=old_paths();paths['bot_key']=previous.root/'etc/ustar-connect/bot-bridge.key'
    for name in ('receiver','probe'):
        paths[name+'_bot_dropin']=previous.root/('etc/systemd/system/ustar-connect-'+name+'.service.d/70-ustar-role-bot.conf')
    extra={n:state.capture(paths[n]) for n in ('bot_key','receiver_bot_dropin','probe_bot_dropin')}
    assert all(i['kind']=='absent' for i in extra.values())
    host.control_paths=MethodType(lambda self:paths,host)
    # Store/SQL are synthetic here. Actual profile files, systemd/UID/credentials,
    # HMAC, private TLS, Python config/renderer/journal and failure handback are real.
    host.controls=MethodType(lambda self,saved:{'files':{n:state.capture(p) for n,p in paths.items()},'fence':before['fence']},host)
    replace_source(previous.engine.web,bundle.payload)
    ctx=role.make_context(bundle,root=previous.root,host=host);host.ctx=ctx
    new=state.Journal(ctx);newlocks=state.Locks(ctx)
    try:
        new.begin('app',{'files':{}},{'files':{}},generation='e'*32);new.checkpoint('app','app_ready')
        op=Runtime(ctx,new,newlocks)
        assert op.enable()['phase']=='runtime_active'
        assert paths['pointer'].resolve().name==role.WORKER_COMMIT
        assert json.loads(paths['receiver'].read_bytes())['role_bot_enabled'] is True
        assert json.loads(paths['notifications'].read_bytes())['notification_subjects']=='academy_linked_opt_in'
        assert not cursor or cursor()>first
        assert op.disable()['phase']=='runtime_disabled'
        if actual_services:
            host.fail_once=True;failed=op.enable()
            assert failed['phase']=='runtime_disabled' and failed['enable_succeeded'] is False and host.actual_restart_failure
            assert op.enable()['phase']=='runtime_active';assert op.disable()['phase']=='runtime_disabled'
        assert state.capture(journal.path)==old_journal and c.https_snapshot(ctx)==owner
        assert {n:state.capture(p) for n,p in paths.items()}=={**before['files'],**extra}
        evidence={'result':'PASS','app_commit':role.APP_COMMIT,'worker_commit':role.WORKER_COMMIT,
            'tool_commit':bundle.reference['tool_commit'],'actual_UID33_LoadCredential_private_TLS':actual_services,
            'separate_bot_HMAC_scope_probed':actual_services,'role_menu_roundtrip_and_real_SQLite_receipt':actual_services,
            'actual_exit77_full_v3_handback':actual_services,'actual_Apache_OpenSSL_PHP_owner':not actual_services,
            'existing_link_ACK_keys_cursor_and_HTTPS_preserved':True,
            'external_provider_requests':False,'Academy_SQL_and_Bot_API':'synthetic here; actual Moodle/PG/HTTP in separate gate'}
        name='connect-role-bot-systemd.json' if actual_services else 'connect-role-bot-https-owner.json'
        (ROOT/'artifacts'/name).write_text(json.dumps(evidence,sort_keys=True,indent=2)+'\n')
        print('CONNECT_ROLE_BOT_ACTUAL_SYSTEMD_UID33_CREDENTIALS_TLS_AND_MENU=PASS' if actual_services else 'CONNECT_ROLE_BOT_HTTPS_OWNER=PASS')
    finally:
        for name,image in extra.items():
            path=paths[name]
            if image['kind']=='absent':path.unlink(missing_ok=True)
            else:state.replace(path,image['raw'],image['metadata'])
        host.control_paths=old_paths;host.controls=old_controls;host.ctx=previous
        replace_source(previous.engine.web,previous.bundle.payload)
        shutil.rmtree(target,ignore_errors=True)
