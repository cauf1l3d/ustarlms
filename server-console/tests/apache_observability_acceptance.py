"""Disposable Ubuntu CI only: actual Apache writes into a root-private log directory."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
if os.geteuid()!=0 or not os.environ.get('GITHUB_ACTIONS') or not Path('/run/systemd/system').is_dir():
    raise SystemExit('Requires disposable root Ubuntu GitHub runner; never run on server1')


def run(*args):
    return subprocess.run(args,check=True,capture_output=True,text=True,timeout=30)


site=Path('/etc/apache2/sites-available/ustar-console-fixture.conf')
enabled=Path('/etc/apache2/sites-enabled')/site.name
listen=Path('/etc/apache2/conf-enabled/ustar-console-fixture.conf')
content=Path('/var/www/ustar-console-fixture')
if any(p.exists() for p in (site,enabled,listen,content)):
    raise SystemExit('Refusing existing fixture paths')
content.mkdir(mode=0o755);(content/'index.html').write_text('USTAR fixture\n')
state=Path('/var/lib/ustar-console-collector');state.mkdir(mode=0o700,exist_ok=True)
assert state.stat().st_uid==0 and not state.stat().st_mode&0o077
listen.write_text('Listen 127.0.0.1:18082\n')
site.write_text('<VirtualHost 127.0.0.1:18082>\n    ServerName ustar.local\n'
    '    DocumentRoot /var/www/ustar-console-fixture\n'
    '    CustomLog /var/log/apache2/ustar-console-fixture-original.log combined\n'
    '</VirtualHost>\n')
enabled.symlink_to(site)
spec=importlib.util.spec_from_file_location('configure_observability',ROOT/'tools/configure_observability.py')
helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)
try:
    run('/usr/sbin/apache2ctl','configtest')
    run('/usr/bin/systemctl','start','apache2')
    result=helper.install();assert result['configured'] is True
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for agent in ('USTAR-Acceptance/1','USTAR-Acceptance/1','USTAR-Console-Probe/1'):
        request=urllib.request.Request('http://127.0.0.1:18082/',headers={'Host':'ustar.local','User-Agent':agent})
        with opener.open(request,timeout=5) as response:assert response.status==200
    sys.path.insert(0,str(ROOT/'backend'))
    from ustar_console.web_observability import collect,ACCESS_LOG
    for _ in range(20):
        value=collect()
        if value['requests']==2:break
        time.sleep(.1)
    assert value['requests']==2 and value['p95_ms'] is not None,value
    assert value['reason_code']=='insufficient_coverage' and value['errors_5xx']==0,value
    assert ACCESS_LOG.parent==state and not ACCESS_LOG.stat().st_mode&0o022
    assert helper.check()['configured'] is True
    print('APACHE_OBSERVABILITY=PASS; actual private log; two real requests; synthetic probe excluded; numeric p95/counts; partial window explicit')
finally:
    enabled.unlink(missing_ok=True);site.unlink(missing_ok=True);listen.unlink(missing_ok=True)
    helper.INCLUDE.unlink(missing_ok=True);helper.ROTATE.unlink(missing_ok=True)
    run('/usr/sbin/apache2ctl','configtest');run('/usr/bin/systemctl','reload','apache2')
