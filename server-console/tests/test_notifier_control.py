"""Private notifier state and bounded web-to-notifier control protocol."""
import importlib.util
import json
import os
import socket
import struct

import pytest

from ustar_console.alerts import Queue, updates


def control():
    assert importlib.util.find_spec('ustar_console.notifier_control'), 'notifier control is required'
    from ustar_console import notifier_control
    return notifier_control


def setup(tmp_path):
    now=[1000]
    queue=Queue(tmp_path/'outbox.sqlite3',[123],clock=lambda:now[0])
    store=control().Store(queue.path,clock=lambda:now[0])
    return queue,store,now


def message(code,uid=1,chat=234,**extra):
    return dict(update_id=uid,message=dict(text='/link '+code,
        **{'from':dict(id=chat,is_bot=False),'chat':dict(id=chat,type='private')},**extra))


def test_hash_only_link_persists_expires_and_single_use(tmp_path):
    queue,store,now=setup(tmp_path)
    result=store.create_link('alice')
    assert result['expires_at']==1600 and len(result['code'])>=32
    assert result['code'].encode() not in queue.path.read_bytes()
    store=control().Store(queue.path,clock=lambda:now[0])
    assert store.link(result['code'],234)
    assert store.get_account('alice')['telegram_id']==234
    assert not store.link(result['code'],345)
    code=store.create_link('bob')['code'];now[0]=1600
    assert not store.link(code,345)
    assert not store.get_account('bob')['linked']


def test_one_to_one_codes_rotate_and_revoke_removes_pending(tmp_path):
    queue,store,now=setup(tmp_path)
    first=store.create_link('alice')['code'];second=store.create_link('alice')['code']
    assert not store.link(first,234) and store.link(second,234)
    assert not store.link(store.create_link('bob')['code'],234)
    with queue.connect() as db: queue.enqueue(db,'personal:alice:cron:1','alert',[234])
    store.create_link('alice')
    store.revoke('alice')
    assert not store.authorized(234) and not store.get_account('alice')['linked']
    assert queue.status()['pending']==0


@pytest.mark.parametrize('extra',[
    {'forward_origin':{'type':'user'}},{'forward_from':{'id':1}},
    {'forward_date':1},{'sender_chat':{'id':234}},
    {'from':{'id':234,'is_bot':True}}, {'from':{'id':234}}, {'chat':{'id':234,'type':'group'}},
    {'from':{'id':999,'is_bot':False}},
])
def test_link_only_accepts_unforwarded_private_human(tmp_path,extra):
    queue,store,_=setup(tmp_path);queue.accounts=store
    code=store.create_link('alice')['code'];update=message(code)
    update['message'].update(extra)
    updates(queue,None,[123],[123],{'checks':[]},'https://example.test',batch=[update])
    assert not store.get_account('alice')['linked']


def test_link_before_allowlist_replay_and_dynamic_commands(tmp_path):
    queue,store,_=setup(tmp_path);queue.accounts=store
    code=store.create_link('alice')['code']
    update=message(code)
    updates(queue,None,[123],[123],{'checks':[]},'https://example.test',batch=[update])
    assert store.get_account('alice')['telegram_id']==234
    count=queue.status()['pending']
    updates(queue,None,[123],[123],{'checks':[]},'https://example.test',batch=[update])
    assert queue.status()['pending']==count
    update=message('',uid=2);update['message']['text']='/status'
    updates(queue,None,[123],[123],{'checks':[]},'https://example.test',batch=[update])
    assert queue.status()['pending']==count+1
    store.unlink('alice');assert not store.authorized(234)


def test_preferences_are_complete_strict_validated_and_disabled_cancel(tmp_path):
    queue,store,_=setup(tmp_path)
    defaults=store.get_account('alice')['preferences']
    assert set(defaults)=={'enabled','check_ids','statuses','repeat_minutes','quiet_start','quiet_end',
                          'timezone','recovery','cpu_percent','ram_available_percent','hold_seconds'}
    for field,value in [('enabled',1),('check_ids',['shell']),('repeat_minutes',14),
                        ('quiet_start','25:00'),('timezone','Mars/Olympus'),
                        ('cpu_percent',float('nan')),('ram_available_percent',100),('hold_seconds',3601)]:
        with pytest.raises(ValueError): store.set_preferences('alice',dict(defaults,**{field:value}))
    store.link(store.create_link('alice')['code'],234)
    with queue.connect() as db: queue.enqueue(db,'personal:alice:cron:1','alert',[234])
    store.set_preferences('alice',dict(defaults,enabled=False))
    assert queue.status()['pending']==0 and store.authorized(234)


def test_existing_queue_migrates_and_stays_private(tmp_path):
    queue,store,_=setup(tmp_path)
    with queue.connect() as db: queue.enqueue(db,'legacy','preserved')
    restarted=control().Store(queue.path)
    assert not restarted.get_account('alice')['linked'] and queue.status()['pending']==1
    assert queue.path.stat().st_mode&0o777==0o600


def test_socket_peer_and_bounded_protocol(tmp_path,monkeypatch):
    queue,store,_=setup(tmp_path);module=control()
    try:
        probe=socket.socket(socket.AF_UNIX);probe.close()
    except PermissionError:
        if os.environ.get('GITHUB_ACTIONS'): raise
        pytest.skip('AF_UNIX is denied by this execution environment; native CI runs this test')
    path=tmp_path/'run/control.sock'
    monkeypatch.setattr(module,'CONTROL_PATH',path)
    with module.ControlServer(store,path=path,web_uid=os.getuid(),group_gid=os.getgid()):
        assert path.stat().st_mode&0o777==0o660
        assert path.parent.stat().st_mode&0o777==0o750
        assert module.rpc('get_account',username='alice')['linked'] is False
        with socket.socket(socket.AF_UNIX) as client:
            client.connect(str(path));client.sendall(b'x'*17000+b'\n')
            assert not json.loads(client.recv(65536))['ok']
    assert not path.exists()
    if os.getuid()!=0:
        with module.ControlServer(store,path=path,web_uid=os.getuid()+1,group_gid=os.getgid()):
            with pytest.raises((OSError,ValueError)):
                module.rpc('get_account',username='alice')


def test_socket_peer_allowlist_without_native_socket():
    module=control()
    class Peer:
        def getsockopt(self,*args): return struct.pack('3i',77,88,99)
    assert not module.peer_allowed(Peer(),123)
    assert module.peer_allowed(Peer(),88)
    class Root:
        def getsockopt(self,*args): return struct.pack('3i',77,0,99)
    assert module.peer_allowed(Root(),88)


def test_rpc_transport_failure_and_remote_validation_are_distinct(monkeypatch):
    module=control()
    class Channel:
        def __enter__(self): return self
        def __exit__(self,*args): pass
        def settimeout(self,*args): pass
        def connect(self,*args): raise FileNotFoundError('no notifier')
    monkeypatch.setattr(module.socket,'socket',lambda *args:Channel())
    with pytest.raises(OSError): module.rpc('get_account',username='alice')
    class Rejected(Channel):
        def connect(self,*args): pass
        def sendall(self,*args): pass
        def recv(self,*args): return b'{"ok":false,"error":"invalid_preferences"}\n'
    monkeypatch.setattr(module.socket,'socket',lambda *args:Rejected())
    with pytest.raises(module.Refused,match='invalid_preferences'):
        module.rpc('set_preferences',username='alice',preferences={})


def test_protocol_rejects_size_duplicates_trailing_requests_and_slow_drip(monkeypatch):
    module=control()
    class Channel:
        def __init__(self,parts): self.parts=iter(parts)
        def recv(self,*args): return next(self.parts,b'')
        def settimeout(self,*args): pass
    for raw in (b'{"method":1,"method":2}\n',b'{}\n{}\n',b'{"value":NaN}\n',b'x'*17000):
        with pytest.raises(ValueError): module._read(Channel([raw]),16384)
    times=iter([0,0,3]);monkeypatch.setattr(module.time,'monotonic',lambda:next(times))
    with pytest.raises((TimeoutError,ValueError)): module._read(Channel([b'{',b'}\n']),16384)
