"""Real Xray/LoadCredential/routing fixture; disposable Ubuntu CI only, never server1."""
import copy
import hashlib
import http.client
import json
import os
from pathlib import Path
import select
import socket
import subprocess
import sys
import tempfile
import time

if os.geteuid()!=0 or not Path('/run/systemd/system').is_dir() or not os.environ.get('GITHUB_ACTIONS'):
    raise SystemExit('Requires disposable Ubuntu root GitHub Actions runner; no skip fallback')
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
import telegram_egress as egress

# The hosted runner may delegate /opt to its regular user. Keep the production
# ancestor-ownership guard intact and use a root-owned fixture tree for this binary.
print('XRAY_NATIVE_OPT_METADATA='+json.dumps(dict(uid=Path('/opt').stat().st_uid,
    mode=oct(Path('/opt').stat().st_mode & 0o777))),flush=True)
egress.BINARY=Path('/var/lib/ustar-egress-native')/egress.VERSION/'xray'
egress.binary()
assert hashlib.sha256(egress.BINARY.read_bytes()).hexdigest()==egress.BINARY_SHA256
with tempfile.TemporaryDirectory(prefix='ustar-egress-native-',dir='/run') as directory:
    source=Path(directory)/'xray.json'
    outbound=dict(protocol='vless',settings=dict(vnext=[dict(address='1.1.1.1',port=443,
        users=[dict(id='5783a3e7-e373-51cd-8642-c83782b807c5',encryption='none')])]),
        streamSettings=dict(network='tcp',security='reality',realitySettings=dict(
            serverName='fixture.example',fingerprint='chrome',publicKey='A'*43,shortId='0123456789abcdef')))
    for network in ('tcp','grpc'):
        for security in ('reality','tls'):
            item=copy.deepcopy(outbound)
            item['streamSettings'].update(network=network,security=security)
            if security=='tls':item['streamSettings']['tlsSettings']=dict(serverName='fixture.example',allowInsecure=False)
            source.write_text(json.dumps(egress.configuration(egress.candidate(item))))
            source.chmod(0o600)
            subprocess.run([str(egress.BINARY),'run','-test','-config',str(source)],check=True,
                           stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=10)
    print('XRAY_TCP_GRPC_REALITY_TLS_CONFIG=PASS',flush=True)
    with socket.socket() as trap:
        trap.bind(('127.0.0.1',0));trap.listen(8)
        # An operator-created loopback trap substitutes for a provider solely inside this fixture.
        item=egress.candidate(outbound)
        item['settings']['vnext'][0].update(address='127.0.0.1',port=trap.getsockname()[1])
        source.write_text(json.dumps(egress.configuration(item)))
        name='ustar-egress-native-fixture'
        try:
            egress.run(egress.transient_argv(source,name))
            egress.ready()
            info=subprocess.check_output(['systemctl','show',name,'-p','MainPID','-p','DynamicUser',
                '-p','NoNewPrivileges','-p','ProtectSystem','-p','CapabilityBoundingSet'],text=True)
            values=dict(line.split('=',1) for line in info.splitlines())
            assert values['DynamicUser']=='yes' and values['NoNewPrivileges']=='yes'
            assert values['ProtectSystem']=='strict' and values['CapabilityBoundingSet']==''
            pid=int(values['MainPID']);assert pid>0
            uid=next(line for line in Path('/proc/'+str(pid)+'/status').read_text().splitlines() if line.startswith('Uid:'))
            assert all(int(value)!=0 for value in uid.split()[1:])
            for host,port in [('example.com',443),('api.telegram.org',80)]:
                connection=http.client.HTTPConnection('127.0.0.1',18081,timeout=2)
                connection.set_tunnel(host,port)
                try:
                    connection.connect()
                    connection.request('GET','/')
                    connection.getresponse()
                except (OSError,http.client.HTTPException):pass
                finally:connection.close()
                assert not select.select([trap],[],[],.3)[0], 'Disallowed target reached outbound'
            connection=http.client.HTTPConnection('127.0.0.1',18081,timeout=2)
            connection.set_tunnel('api.telegram.org',443)
            try:
                connection.connect();connection.request('GET','/')
                assert select.select([trap],[],[],3)[0], 'Allowed Telegram target did not reach outbound'
                accepted,_=trap.accept();accepted.close()
            finally:connection.close()
            print('XRAY_LOADCREDENTIAL_DYNAMIC_UID_DEFAULT_DENY_TELEGRAM_ONLY=PASS',flush=True)
        finally:
            egress.run(['systemctl','stop',name],check=False)
