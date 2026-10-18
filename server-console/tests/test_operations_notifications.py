import json
import os
from pathlib import Path
import sqlite3
from unittest.mock import Mock

import pytest

from conftest import PASSWORD
from ustar_console import operations as ops
from ustar_console.alerts import Queue,DeliveryError,updates,config,command_reply
from ustar_console.auth import Auth,Denied
from ustar_console.config import Settings
from ustar_console.history_store import record,read
from ustar_console import totp


@pytest.fixture
def jobs(tmp_path,monkeypatch):
    monkeypatch.setattr(ops,'current',lambda action:dict(action=action,target='fixed'))
    return ops.Operations(tmp_path/'jobs.sqlite3',clock=lambda:1000)


def test_plan_actor_expiry_and_exact_confirmation(jobs):
    plan=jobs.plan('owner','academy_restart')
    for actor,confirmation in [('someone','academy_restart'),('owner','yes')]:
        with pytest.raises(ops.Refused): jobs.confirm(actor,plan['id'],'a'*32,confirmation)
    jobs.clock=lambda:1091
    with pytest.raises(ops.Refused): jobs.confirm('owner',plan['id'],'a'*32,'academy_restart')
    assert not jobs.status()['jobs']


def test_job_persists_idempotently_and_changed_target_refused(jobs,monkeypatch):
    plan=jobs.plan('owner','manual_backup')
    first=jobs.confirm('owner',plan['id'],'a'*32,'manual_backup')
    second=jobs.confirm('owner',plan['id'],'a'*32,'manual_backup')
    assert first['id']==second['id']
    assert len(ops.Operations(jobs.path).status()['jobs'])==1
    with pytest.raises(ops.Refused): jobs.confirm('owner',plan['id'],'b'*32,'manual_backup')
    with jobs.connect() as db: db.execute("UPDATE jobs SET state='complete'")
    plan=jobs.plan('owner','academy_restart')
    monkeypatch.setattr(ops,'current',lambda action:dict(action=action,target='changed'))
    with pytest.raises(ops.Refused,match='target_changed'): jobs.confirm('owner',plan['id'],'c'*32,'academy_restart')


@pytest.mark.parametrize('action',['shell','docker','backup; reboot','restore','/bin/sh'])
def test_broker_has_no_generic_action(action):
    with pytest.raises(ops.Refused): ops.current(action)


def test_grant_session_archive_and_expiry_bound(tmp_path,monkeypatch):
    now=[1000]
    jobs=ops.Operations(tmp_path/'jobs.sqlite3',clock=lambda:now[0])
    name='ustar-recovery-20261017T003000Z-1234abcd.tar.gz.age'
    folder=tmp_path/'hdd/backups/managed';folder.mkdir(parents=True)
    path=folder/name;path.write_bytes(b'encrypted fixture');os.chmod(path,0o600)
    monkeypatch.setattr(ops,'HDD',tmp_path/'hdd')
    monkeypatch.setattr(ops,'mount',lambda:{'uuid':'fixed'})
    metadata=dict(file=name,sha256='a'*64,bytes=path.stat().st_size)
    monkeypatch.setattr(ops,'backup',lambda:metadata.copy())
    # Real descriptor with actual no-follow/regular-file checks (test owner may be non-root in CI).
    original=ops.open_regular
    monkeypatch.setattr(ops,'open_regular',lambda path,owner:original(path,owner=os.geteuid()))
    # tmp_path has /tmp as parent; use a descriptor opened by the test fixture after metadata validation.
    monkeypatch.setattr(ops,'open_regular',lambda path,owner:(os.open(path,os.O_RDONLY|os.O_NOFOLLOW),path.stat()))
    grant=jobs.grant('owner','a'*64)
    with pytest.raises(ops.Refused): jobs.archive('owner','b'*64,grant['token'])
    value,fd=jobs.archive('owner','a'*64,grant['token'])
    try: assert os.read(fd,99)==b'encrypted fixture'
    finally: os.close(fd)
    assert set(value)=={'file','sha256','bytes'}
    metadata['sha256']='b'*64
    with pytest.raises(ops.Refused,match='archive_changed'): jobs.archive('owner','a'*64,grant['token'])
    now[0]=1121
    with pytest.raises(ops.Refused): jobs.archive('owner','a'*64,grant['token'])


@pytest.mark.parametrize('path,body',[
    ('operations/plan',{'action':'academy_restart'}),
    ('operations/confirm',{'plan_id':'a'*32,'idempotency':'b'*32,'confirmation':'academy_restart','password':PASSWORD}),
    ('backup/download',{'password':PASSWORD})])
def test_viewer_denied_before_broker(logged,app,monkeypatch,path,body):
    with app.state.auth.connection() as db: db.execute("UPDATE users SET role='viewer'")
    broker=Mock(side_effect=AssertionError('must not call root broker'));monkeypatch.setattr(ops,'rpc',broker)
    assert logged.post('/api/'+path,json=body).status_code==403
    assert not broker.called


def test_write_needs_csrf_and_fresh_password(logged,monkeypatch):
    broker=Mock();monkeypatch.setattr(ops,'rpc',broker)
    assert logged.post('/api/backup/download',json={'password':'incorrect'}).status_code==403
    logged.headers.pop('X-CSRF-Token')
    assert logged.post('/api/backup/download',json={'password':PASSWORD}).status_code==403
    assert not broker.called


def test_archive_stream_range_and_close(logged,monkeypatch,tmp_path):
    path=tmp_path/'encrypted.age';path.write_bytes(b'0123456789')
    descriptors=[]
    def broker(method,**kwargs):
        fd=os.open(path,os.O_RDONLY);descriptors.append(fd)
        return dict(file='archive.age',bytes=10,sha256='a'*64,_fd=fd)
    monkeypatch.setattr(ops,'rpc',broker)
    response=logged.get('/api/backup/archive/'+'a'*43,headers={'Range':'bytes=3-6'})
    assert response.status_code==206 and response.content==b'3456'
    assert response.headers['content-range']=='bytes 3-6/10'
    assert 'attachment' in response.headers['content-disposition']
    with pytest.raises(OSError): os.fstat(descriptors[-1])
    assert logged.get('/api/backup/archive/'+'a'*43,headers={'Range':'bytes=1-2,4-5'}).status_code==416
    with pytest.raises(OSError): os.fstat(descriptors[-1])


def state(status='ok',key='cron'):
    return dict(stale=False,host={},containers=[],checks=[dict(id=key,label='Cron',status=status,reason_code='measured')])


def test_outbox_transitions_retry_and_restart(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    queue.evaluate(state('critical'))
    queue.evaluate(state('critical'))
    assert queue.status()['pending']==1
    def failure(*args): raise DeliveryError('rate_limited',120)
    queue.deliver(failure)
    assert queue.status()['delivery_failures']==1
    calls=[]
    queue=Queue(queue.path,[123],clock=lambda:now[0])
    queue.deliver(lambda *args:calls.append(args))
    assert not calls
    now[0]+=121
    queue.deliver(lambda *args:calls.append(args))
    assert len(calls)==1 and queue.status()['pending']==0
    queue.evaluate(state('ok'));now[0]+=30;queue.evaluate(state('ok'))
    assert queue.status()['pending']==1


def test_nodata_is_not_recovery_or_immediate_alarm(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    queue.evaluate(state('no_data'));assert queue.status()['pending']==0
    for _ in range(4): now[0]+=15;queue.evaluate(state('no_data'))
    assert queue.status()['pending']>=1
    with queue.connect() as db:
        texts=[r[0] for r in db.execute('SELECT text FROM outbox')]
    assert any('НЕТ ДАННЫХ' in text for text in texts)
    assert not any('ВОССТАНОВЛЕНО' in text for text in texts)


def test_sustained_rules_reset_after_missing_interval(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    data=state();data['host']={'cpu_percent':91}
    for _ in range(10): queue.evaluate(data);now[0]+=15
    now[0]+=600;queue.evaluate(data)
    with queue.connect() as db:
        assert not db.execute("SELECT 1 FROM outbox WHERE key LIKE 'rule:cpu_sustained:%'").fetchone()
    for _ in range(41): now[0]+=15;queue.evaluate(data)
    with queue.connect() as db:
        assert db.execute("SELECT 1 FROM outbox WHERE key LIKE 'rule:cpu_sustained:%'").fetchone()


def test_maintenance_bounded_and_oom_recovery_never_muted(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    window=dict(action='manual_backup',started=990,until=1100)
    queue.evaluate(state('critical'),window)
    assert queue.status()['pending']==0
    queue.evaluate(state('critical','recovery'),window)
    assert queue.status()['pending']==1
    data=state();data['containers']=[{'oom':True}]
    queue.evaluate(data,window)
    assert queue.status()['pending']==2
    now[0]=1101;queue.evaluate(state('critical'),window)
    assert queue.status()['pending']==3


def test_bot_numeric_user_and_chat_both_allowlisted_and_offset_durable(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    def message(uid,user,chat,kind='private',**extra):
        return dict(update_id=uid,message=dict(text='/status',**{'from':dict(id=user,is_bot=False),'chat':dict(id=chat,type=kind)},**extra))
    batch=[message(1,123,999),message(2,999,123),message(3,123,123,'group'),
           message(4,123,123,forward_origin={'type':'user'}),message(5,123,123)]
    data=state();data['domains']={'academy':'ok'}
    api=Mock(return_value=batch)
    updates(queue,api,[123],[123],data,'https://console.test')
    assert queue.status()['pending']==1
    updates(queue,api,[123],[123],data,'https://console.test')
    assert api.call_args.args[1]['offset']==6
    assert queue.status()['pending']==1


def test_backup_latest_is_metadata_link_not_new_capture():
    data=state();data['backup']=dict(file='encrypted.age',sha256='a'*64,bytes=123,completed_at='now')
    reply=command_reply('/backup latest',data,'https://console.test')
    assert '/#backups' in reply and 'a'*64 in reply
    assert 'key' not in reply and command_reply('/shell reboot',data,'https://console.test') is None


@pytest.mark.parametrize('polling_fails',[False,True])
def test_notification_worker_starts_and_publishes_status(tmp_path,monkeypatch,settings,polling_fails):
    from ustar_console import alerts
    from ustar_console import config as console_config
    credential=tmp_path/'credentials';credential.mkdir()
    token='123456789:'+('x'*35)
    (credential/'telegram').write_text(json.dumps(dict(token=token,users=[123],chats=[123])))
    (credential/'telegram').chmod(0o600)
    monkeypatch.setenv('CREDENTIALS_DIRECTORY',str(credential))
    monkeypatch.setattr(console_config,'load',lambda:settings)
    real_path=Path
    mapped={
        '/var/lib/ustar-console-notify/outbox.sqlite3':tmp_path/'outbox.sqlite3',
        '/run/ustar-console-ops/status.json':tmp_path/'missing-operations.json',
        '/run/ustar-console-notify/status.json':tmp_path/'status.json',
    }
    monkeypatch.setattr(alerts,'Path',lambda value:mapped.get(str(value),real_path(value)))
    calls=[]
    def request(self,method,data):
        calls.append((method,data))
        if method=='getWebhookInfo': return {'url':''}
        if method=='getUpdates':
            if polling_fails: raise DeliveryError('transport_unavailable')
            return []
        if method=='sendMessage': return {'message_id':1}
        raise AssertionError('Unexpected Bot API method')
    monkeypatch.setattr(alerts.Telegram,'__call__',request)
    class EndCycle(Exception): pass
    sleeps=[]
    def stop(seconds): sleeps.append(seconds);raise EndCycle
    monkeypatch.setattr(alerts.time,'sleep',stop)
    with pytest.raises(EndCycle): alerts.main()
    assert calls[0]==('getWebhookInfo',{})
    assert any(method=='getUpdates' for method,_ in calls)
    status=json.loads((tmp_path/'status.json').read_text())
    assert status['polling']==('no_data' if polling_fails else 'ok') and status['evaluation']=='ok'
    assert sleeps==[10 if polling_fails else .2]
    assert token not in (tmp_path/'status.json').read_text()


def test_totp_vectors_replay_and_auth_limit(settings):
    # RFC6238 SHA-1 vector at t=59, reduced to six digits.
    key='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
    assert totp.code(key,1)=='287082'
    assert totp.verify(key,'287082',59)==1
    assert totp.verify(key,'287082',59,1) is None
    auth=Auth(settings,clock=lambda:1000)
    auth.set_user('owner',PASSWORD,'owner')
    with auth.connection() as db: db.execute('INSERT INTO factors VALUES(?,?,?)',('owner',key,-1))
    with pytest.raises(Denied): auth.login('owner',PASSWORD,'peer')
    token=auth.login('owner',PASSWORD,'peer',totp.code(key,33))
    with pytest.raises(Denied): auth.authorize(auth.session(token),PASSWORD,totp.code(key,33))
    auth.clock=lambda:1030
    auth.authorize(auth.session(token),PASSWORD,totp.code(key,34))


@pytest.mark.parametrize('kwargs',[dict(listen='0.0.0.0'),dict(listen='192.168.1.60'),dict(listen='8.8.8.8',tls=True),dict(port=80),dict(tls=True)])
def test_lan_config_refuses_exposure_and_origin_mismatch(kwargs):
    with pytest.raises(ValueError): Settings('https://console.test',**kwargs)


def test_native_history_gaps_retention_and_freshness(tmp_path,monkeypatch):
    from ustar_console import history_store
    path=tmp_path/'history.sqlite3';export=tmp_path/'history.json'
    monkeypatch.setattr('ustar_console.collector.write_snapshot',lambda value,target:target.write_text(json.dumps(value)))
    data=state();data['host']={'cpu_percent':12,'memory':{'total_bytes':100,'available_bytes':60}}
    record(data,path,export,now=10000000)
    value=json.loads(export.read_text());series=value['series']['cpu:1h']
    assert len(series['points'])<=721 and sum(v is not None for _,v in series['points'])==1
    assert any(v is None for _,v in series['points'])
    record(data,path,export,now=10000000+1296000+3600)
    with sqlite3.connect(path) as db: assert db.execute('SELECT count(*) FROM samples').fetchone()[0]==1
    monkeypatch.setattr(history_store,'safe_json',None,raising=False)
    # Production read uses safe root-owned export. Substitute only file transport in this fixture.
    monkeypatch.setattr('ustar_console.data.safe_json',lambda *a,**k:json.loads(export.read_text()))
    assert read('cpu','1h',export,now=11299600)['status']=='ok'
    assert read('cpu','1h',export,now=11299699)['reason_code']=='stale'


def test_ntp_selected_peer_and_smart_history_bits():
    from ustar_console.hardware import parse_clock,parse_smart
    value=parse_clock('     remote refid st t when poll reach delay offset jitter\n*194.58.206.20 .GPS. 1 u 42 64 377 1.234 -0.2024 0.1690\n')
    assert value['status']=='ok' and value['offset_ms']==-.2024
    with pytest.raises(ValueError): parse_clock('no selected source')
    raw={'smartctl':{'exit_status':64},'smart_status':{'passed':True},
         'ata_smart_attributes':{'table':[{'id':key,'raw':{'value':0}} for key in (5,197,198,199)]}}
    smart=parse_smart(raw)
    assert smart['status']=='ok' and smart['historical_error_log'] is True
    raw['ata_smart_attributes']['table'][1]['raw']['value']=1
    assert parse_smart(raw)['status']=='critical'
    raw['smartctl']['exit_status']=2
    with pytest.raises(ValueError): parse_smart(raw)


def test_independent_observer_does_not_emit_fake_local_cpu_events(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    data=state('critical','observer');data['scope']='independent_observer'
    for _ in range(3): queue.evaluate(data);now[0]+=30
    assert queue.status()['pending']==1
    with queue.connect() as db:
        assert db.execute('SELECT key FROM rules').fetchall()[0][0]=='observer'


def test_outgoing_incidents_survive_failed_command_polling_and_full_queue(tmp_path,monkeypatch):
    from ustar_console.alerts import iteration
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    queue.evaluate(state('critical'))
    sent=[]
    def api(method,data):
        if method=='getUpdates': raise DeliveryError('polling_unavailable')
        sent.append(data);return {}
    monkeypatch.setattr(queue,'evaluate',Mock(side_effect=ValueError('capacity')))
    result=iteration(queue,api,[123],[123],state('critical'),'https://console.test')
    assert len(sent)==1 and queue.status()['pending']==0
    assert result['polling']=='no_data' and result['evaluation']=='queue_capacity'


def test_restart_rechecks_target_while_retaining_its_own_backup_lock(tmp_path,monkeypatch):
    import fcntl
    path=tmp_path/'backup.lock';path.write_text('')
    monkeypatch.setattr(ops,'BACKUP_LOCK',path)
    monkeypatch.setattr(ops,'BACKUP',tmp_path)
    identifier='a'*64
    monkeypatch.setattr(ops,'container_identity',lambda:dict(id=identifier,running=True,started='fixed'))
    monkeypatch.setattr(ops,'open_regular',lambda p,owner:(os.open(p,os.O_RDONLY|os.O_NOFOLLOW),p.stat()))
    jobs=ops.Operations(tmp_path/'jobs.sqlite3')
    plan=jobs.plan('owner','academy_restart')
    job=jobs.confirm('owner',plan['id'],'b'*32,'academy_restart')
    monkeypatch.setattr(ops,'Operations',lambda:jobs)
    monkeypatch.setattr(ops.os,'geteuid',lambda:0)
    calls=[]
    def process(argv,**kwargs):
        calls.append(argv)
        other=os.open(path,os.O_RDWR)
        try:
            with pytest.raises(BlockingIOError): fcntl.flock(other,fcntl.LOCK_EX|fcntl.LOCK_NB)
        finally: os.close(other)
        return Mock(wait=Mock(return_value=0))
    monkeypatch.setattr(ops.subprocess,'Popen',process)
    ops.run_job(job['id'])
    assert jobs.status()['jobs'][0]['state']=='complete'
    assert calls==[['/usr/bin/docker','restart','--time','30',identifier]]
    other=os.open(path,os.O_RDWR)
    try: fcntl.flock(other,fcntl.LOCK_EX|fcntl.LOCK_NB)
    finally: os.close(other)


def test_delivery_batch_is_bounded_to_keep_rule_sampling_alive(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    with queue.connect() as db:
        for i in range(5): queue.enqueue(db,'fixture:'+str(i),'fixture')
    sent=[]
    queue.deliver(lambda *args:sent.append(args))
    assert len(sent)==2 and queue.status()['pending']==3
