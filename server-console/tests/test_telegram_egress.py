import copy
import http.client
import importlib.util
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import threading
from types import SimpleNamespace

import pytest

from ustar_console import alerts, setup, telegram_transport as transport

TOOLS = Path(__file__).parents[1]/'tools'
sys.path.insert(0, str(TOOLS))
try:
    spec = importlib.util.spec_from_file_location('telegram_egress', TOOLS/'telegram_egress.py')
    egress = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(egress)
finally:
    sys.path.remove(str(TOOLS))

TOKEN = '123456789:'+('x'*35)


def node(network='tcp', security='reality'):
    mode = dict(serverName='fixture.example', fingerprint='chrome')
    if security == 'reality':
        mode.update(publicKey='A'*43, shortId='0123456789abcdef')
    return dict(protocol='vless', settings=dict(vnext=[dict(address='1.1.1.1', port=443,
        users=[dict(id='5783a3e7-e373-51cd-8642-c83782b807c5', encryption='none')])]),
        streamSettings=dict(network=network, security=security, **{security+'Settings':mode}))


@pytest.mark.parametrize('network,security', [('tcp','reality'),('grpc','reality'),('tcp','tls'),('grpc','tls')])
def test_generated_proxy_discards_provider_listeners_and_blocks_everything_except_Telegram(network, security):
    source = dict(outbounds=[node(network, security), dict(protocol='freedom')],
                  inbounds=[dict(listen='0.0.0.0')], routing=dict(domainStrategy='IPOnDemand'),
                  log=dict(access='/etc/private-file'))
    choices, skipped = egress.candidates(json.dumps([source, source]).encode())
    assert len(choices) == 1 and skipped == 0
    value = egress.configuration(choices[0])
    assert value['inbounds'] == [dict(tag='telegram_http',listen='127.0.0.1',port=18081,
                                     protocol='http',settings=dict(allowTransparent=False))]
    assert value['outbounds'][0] == dict(tag='blocked',protocol='blackhole')
    assert value['routing']['rules'] == [dict(type='field',inboundTag=['telegram_http'],
        domain=['full:api.telegram.org'],port='443',network='tcp',outboundTag='telegram_vless')]
    assert '/etc/private-file' not in json.dumps(value) and 'freedom' not in json.dumps(value)


@pytest.mark.parametrize('change', [
    lambda x:x['streamSettings'].update(security='none'),
    lambda x:x['streamSettings']['realitySettings'].update(allowInsecure=True),
    lambda x:x['streamSettings']['realitySettings'].update(privateKey='private'),
    lambda x:x['streamSettings']['realitySettings'].update(certificates=[{'certificateFile':'/etc/shadow'}]),
    lambda x:x['streamSettings'].update(sockopt=dict(dialerProxy='provider-chain')),
    lambda x:x.update(proxySettings=dict(tag='provider-chain')),
    lambda x:x['settings']['vnext'][0].update(address='127.0.0.1'),
    lambda x:x['settings']['vnext'][0].update(address='192.168.1.60'),
    lambda x:x['settings']['vnext'][0].update(port=True),
    lambda x:x['settings']['vnext'][0]['users'][0].update(reverse=dict(tag='reverse')),
])
def test_unsafe_or_dependent_nodes_are_not_installed(change):
    value = node();change(value)
    with pytest.raises(egress.SetupError): egress.candidate(value)


def test_flat_VLESS_and_public_IPv4_pinning_preserve_TLS_name(monkeypatch):
    value = node(security='tls')
    source = value['settings']['vnext'][0]
    value['settings'] = dict(address='public.fixture',port=source['port'],**source['users'][0])
    value['streamSettings']['tlsSettings'].pop('serverName')
    cleaned = egress.candidate(value)
    monkeypatch.setattr(egress.socket,'getaddrinfo',lambda *a,**k:[(None,None,None,None,('1.1.1.1',443))])
    pinned = egress.pin_endpoint(cleaned)
    assert pinned['settings']['vnext'][0]['address'] == '1.1.1.1'
    assert pinned['streamSettings']['tlsSettings']['serverName'] == 'public.fixture'
    monkeypatch.setattr(egress.socket,'getaddrinfo',lambda *a,**k:[(None,None,None,None,('192.168.1.1',443))])
    with pytest.raises(egress.SetupError,match='node_not_public'): egress.pin_endpoint(cleaned)


@pytest.mark.parametrize('proxy', ['http://0.0.0.0:18081','http://localhost:18081','socks5://127.0.0.1:18081',
                                  'http://127.0.0.1:18081@other.test','http://127.0.0.1:18081/'])
def test_bot_refuses_unconfigured_proxy_destinations(proxy):
    with pytest.raises(ValueError): alerts.Telegram(TOKEN,proxy=proxy)


def test_bot_proxy_errors_redirects_rate_limit_and_malformed_bodies_are_sanitized(monkeypatch):
    api = alerts.Telegram(TOKEN,proxy=transport.PROXY)
    def fail(*_): raise http.client.BadStatusLine('/bot'+TOKEN+'/getMe')
    monkeypatch.setattr(alerts,'exchange',fail)
    with pytest.raises(alerts.DeliveryError,match='transport_unavailable') as error: api('getMe',{})
    assert TOKEN not in str(error.value)
    for status,raw,reason in [(302,b'{}','api_rejected'),(401,b'{}','api_rejected'),
                              (429,b'{"parameters":{"retry_after":12}}','rate_limited'),
                              (200,b'[]','transport_unavailable')]:
        monkeypatch.setattr(alerts,'exchange',lambda *a:(status,raw))
        with pytest.raises(alerts.DeliveryError,match=reason): api('getMe',{})


@pytest.mark.parametrize('proxy', [None,transport.PROXY])
def test_credentials_accept_optional_fixed_proxy_and_keep_private_allowlist(tmp_path, proxy):
    path = tmp_path/'telegram'
    value = dict(token=TOKEN,users=[123],chats=[123])
    if proxy: value['proxy']=proxy
    path.write_text(json.dumps(value));path.chmod(0o600)
    assert alerts.config(path) == value
    value['proxy']='http://outside.test';path.write_text(json.dumps(value))
    with pytest.raises(ValueError): alerts.config(path)


@pytest.fixture(scope='module')
def certificates(tmp_path_factory):
    folder = tmp_path_factory.mktemp('telegram-TLS')
    for name in ('api.telegram.org','other.example'):
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
            '-keyout',str(folder/(name+'.key')),'-out',str(folder/(name+'.crt')),
            '-subj','/CN='+name,'-addext','subjectAltName=DNS:'+name],
            check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    return folder


@pytest.mark.parametrize('trusted,name,success', [(True,'api.telegram.org',True),
    (False,'api.telegram.org',False),(True,'other.example',False)])
def test_actual_CONNECT_verifies_Telegram_TLS_even_with_NO_PROXY_star(monkeypatch,certificates,trusted,name,success):
    listener = socket.socket();listener.bind(('127.0.0.1',0));listener.listen(1);listener.settimeout(3)
    port = listener.getsockname()[1]
    observed = []
    server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    server_context.load_cert_chain(certificates/(name+'.crt'),certificates/(name+'.key'))
    def serve():
        try:
            connection,_ = listener.accept();connection.settimeout(3)
            with connection:
                header=b''
                while b'\r\n\r\n' not in header: header+=connection.recv(1024)
                observed.append(header)
                connection.sendall(b'HTTP/1.1 200 Connection established\r\n\r\n')
                try:
                    with server_context.wrap_socket(connection,server_side=True) as tls:
                        request=b''
                        while b'\r\n\r\n' not in request: request+=tls.recv(4096)
                        header,_,body=request.partition(b'\r\n\r\n')
                        length=int(next(line.split(b':',1)[1] for line in header.split(b'\r\n')
                                        if line.lower().startswith(b'content-length:')))
                        while len(body)<length:body+=tls.recv(4096)
                        payload=b'{"ok":true,"result":{"id":1}}'
                        tls.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: '+str(len(payload)).encode()+
                                    b'\r\nConnection: close\r\n\r\n'+payload)
                except (ssl.SSLError,OSError): pass
        finally: listener.close()
    worker=threading.Thread(target=serve,daemon=True);worker.start()
    real_connection=http.client.HTTPSConnection
    real_context=ssl.create_default_context
    context=real_context(cafile=str(certificates/(name+'.crt'))) if trusted else real_context()
    monkeypatch.setattr(transport.ssl,'create_default_context',lambda:context)
    def local_connection(host,configured_port,**kwargs):
        assert (host,configured_port)==('127.0.0.1',18081)
        assert kwargs['context'].verify_mode==ssl.CERT_REQUIRED and kwargs['context'].check_hostname
        return real_connection(host,port,**kwargs)
    monkeypatch.setattr(transport,'HTTPSConnection',local_connection)
    monkeypatch.setenv('NO_PROXY','*');monkeypatch.setenv('HTTPS_PROXY','http://outside.test')
    try:
        if success: assert alerts.Telegram(TOKEN,proxy=transport.PROXY)('getMe',{})=={'id':1}
        else:
            with pytest.raises(alerts.DeliveryError,match='transport_unavailable'):
                alerts.Telegram(TOKEN,proxy=transport.PROXY)('getMe',{})
    finally: worker.join(4)
    assert observed and observed[0].startswith(b'CONNECT api.telegram.org:443 HTTP/')
    assert TOKEN.encode() not in observed[0]


def test_installer_failed_permanent_probe_removes_only_its_new_service(tmp_path,monkeypatch):
    config=tmp_path/'etc'/'xray.json';unit=tmp_path/'new.service'
    monkeypatch.setattr(egress,'CONFIG',config);monkeypatch.setattr(egress,'UNIT_PATH',unit)
    monkeypatch.setattr(egress.os,'geteuid',lambda:0);monkeypatch.setattr(egress.os,'isatty',lambda _:True)
    monkeypatch.setattr(egress,'trusted_source',lambda:None);monkeypatch.setattr(egress,'binary',lambda:None)
    monkeypatch.setattr(egress.getpass,'getpass',lambda _: 'https://novavps.app/s/private-fixture')
    monkeypatch.setattr(egress.subscription,'fetch_subscription',lambda _:json.dumps({'outbounds':[node()]}).encode())
    monkeypatch.setattr(egress,'pin_endpoint',copy.deepcopy);monkeypatch.setattr(egress,'ready',lambda:None)
    real_temporary=egress.tempfile.TemporaryDirectory
    monkeypatch.setattr(egress.tempfile,'TemporaryDirectory',lambda **_:real_temporary(dir=tmp_path))
    calls=[]
    monkeypatch.setattr(egress,'run',lambda argv,**_: calls.append(argv) or SimpleNamespace(returncode=0))
    outcomes=iter([{'telegram_https':'verified'},egress.SetupError('post_install_failure')])
    def probe():
        value=next(outcomes)
        if isinstance(value,Exception):raise value
        return value
    monkeypatch.setattr(egress,'probe',probe)
    with pytest.raises(egress.SetupError,match='post_install_failure'):egress.install()
    assert not config.exists() and not unit.exists()
    assert ['systemctl','disable','--now',egress.UNIT] in calls
    assert not any(any('ustar-console.service' in arg or 'docker' in arg for arg in call) for call in calls)


def test_telegram_only_setup_restarts_to_reload_credentials(monkeypatch):
    monkeypatch.setattr(sys,'argv',['setup','--telegram-only','--telegram-proxy'])
    monkeypatch.setattr(setup.os,'geteuid',lambda:0);monkeypatch.setattr(setup.os,'isatty',lambda _:True)
    monkeypatch.setattr(setup,'trusted_config',lambda _:None)
    selected=[];calls=[]
    monkeypatch.setattr(setup,'telegram',lambda value:selected.append(value) or True)
    monkeypatch.setattr(setup,'run',calls.append)
    setup.main()
    assert selected == [transport.PROXY]
    assert calls == [['systemctl','enable','ustar-console-notify.service'],['systemctl','restart','ustar-console-notify.service']]
