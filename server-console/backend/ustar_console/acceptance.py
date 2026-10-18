"""Non-destructive native installation receipt. No jobs, restarts or test alerts."""
import json
import os
from pathlib import Path
import ssl
import subprocess
import urllib.request

from .config import load
from .data import snapshot


def main():
    if os.geteuid()!=0: raise SystemExit('Run with sudo')
    settings=load()
    if not settings.tls: raise SystemExit('LAN native TLS is not configured')
    context=ssl.create_default_context(cafile='/etc/ustar-console/ustar-console-ca.crt')
    context.minimum_version=ssl.TLSVersion.TLSv1_2
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=context))
    with opener.open(settings.origin+'/healthz',timeout=5) as response:
        alive=json.load(response)
    assert alive.get('status')=='alive'
    units={}
    for name in ('ustar-console','ustar-console-ops','ustar-console-collect.timer','ustar-console-notify'):
        result=subprocess.run(['systemctl','is-active',name],capture_output=True,text=True,timeout=3)
        units[name]=result.stdout.strip()
    value=snapshot(settings.snapshot)
    checks=[dict(id=c['id'],status=c['status'],reason=c['reason_code']) for c in value['checks']]
    web=subprocess.check_output(['id','-Gn','ustar-console'],text=True,timeout=3).strip()
    assert web=='ustar-console'
    for path in ('/run/docker.sock','/srv/ustar-storage/backups/managed','/var/lib/ustar-backup',
                 '/etc/ustar-console/authority/ca.key','/etc/ustar-console/telegram.json','/var/lib/ustar-console-ops'):
        if Path(path).exists():
            denied=subprocess.run(['runuser','-u','ustar-console','--','test','-r',path],timeout=3).returncode!=0
            assert denied, 'Web identity unexpectedly reads '+path
    print(json.dumps(dict(console_tls='verified_with_local_ca',client_certificate_trust='not_measured',
        acceptance_scope='installation_gate_only_not_owner_acceptance',mini_app='not_measured',units=units,source_stale=value['stale'],checks=checks,
        web_groups='console_only',native_accepted=not value['stale'] and all(units[k]=='active' for k in units if k!='ustar-console-notify'),
        remaining=['browser_login_role_denial','download_hash','telegram_receiver_ack','independent_observer','restore_RTO']),
        ensure_ascii=False,indent=2))
