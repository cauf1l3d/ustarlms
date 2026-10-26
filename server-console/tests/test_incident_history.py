import json
import time

from ustar_console import alerts, server
from ustar_console.data import stamp
from test_telegram_views import sample


def test_transitions_persist_once_with_recovery_and_without_raw_details(tmp_path):
    now=[1000];queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0]);data=sample()
    check=next(c for c in data['checks'] if c['id']=='backup')
    check.update(status='critical',reason_code='backup_failed',label='private raw text')
    queue.evaluate(data);queue.evaluate(data)
    check.update(status='ok',reason_code='backup_receipt')
    for _ in range(3): now[0]+=15;queue.evaluate(data)
    queue=alerts.Queue(queue.path,[123],clock=lambda:now[0])
    rows=queue.status().get('incidents',[])
    assert len(rows)==2 and [r['status'] for r in rows]==['ok','critical']
    assert {r['check_id'] for r in rows}=={'backup'} and rows[0]['at']>rows[1]['at']
    assert 'private' not in json.dumps(rows) and 'text' not in rows[0]
    rule=next(r for r in queue.status()['rules'] if r['key']=='backup')
    assert rule['emitted']=='ok' and rule['since']==1015 and rule['last']==1045


def test_incident_retention_is_bounded_even_if_delivery_is_down(tmp_path):
    now=[1000];queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0]);data=sample()
    check=next(c for c in data['checks'] if c['id']=='backup')
    for i in range(130):
        check.update(status='critical' if i%2 else 'warning')
        queue.evaluate(data);now[0]+=15
    assert len(queue.status().get('incidents',[]))==100


def write_status(path,age=0):
    now=time.time()
    path.write_text(json.dumps(dict(observed_at=stamp(now-age),status='ok',polling='ok',evaluation='ok',
        pending=2,delivery_failures=0,rules=[dict(key='cpu_sustained',state='warning',emitted='ok',since=now-60,last=now,sent=0)],
        incidents=[dict(id=1,at=now-30,check_id='backup',status='critical',reason_code='backup_failed')],
        token='must-never-reach-api')))
    path.chmod(0o640)


def test_authenticated_events_include_incidents_but_never_private_status_fields(logged,tmp_path,monkeypatch):
    path=tmp_path/'status.json';write_status(path)
    from ustar_console import notification_state
    monkeypatch.setattr(notification_state,'STATUS',path)
    value=logged.get('/api/events').json()
    assert value['scope']=='console_security_and_monitoring' and value['host_logs']=='not_connected'
    assert value['incidents'][0]['check_id']=='backup' and value['incidents_status']=='ok'
    assert 'must-never-reach-api' not in json.dumps(value)
    assert any(e['event']=='login_succeeded' for e in value['events'])


def test_stale_notifier_keeps_dated_history_but_no_live_rule_state(logged,tmp_path,monkeypatch):
    path=tmp_path/'status.json';write_status(path,age=90)
    from ustar_console import notification_state
    monkeypatch.setattr(notification_state,'STATUS',path)
    value=logged.get('/api/events').json()
    assert value['incidents_status']=='stale' and len(value['incidents'])==1
    overview=logged.get('/api/overview').json()
    assert 'notifications' not in overview
    assert next(c for c in overview['checks'] if c['id']=='rules')['status']=='no_data'


def test_missing_or_invalid_event_export_cannot_break_security_events(logged,tmp_path,monkeypatch):
    from ustar_console import notification_state
    path=tmp_path/'status.json';monkeypatch.setattr(notification_state,'STATUS',path)
    assert logged.get('/api/events').json().get('incidents_status')=='no_data'
    write_status(path);value=json.loads(path.read_text());value['incidents'][0]['at']=float('inf')
    path.write_text(json.dumps(value))
    response=logged.get('/api/events')
    assert response.status_code==200 and response.json()['incidents']==[]


def test_failed_evaluation_cannot_promote_previous_cpu_rule_to_current_health(logged,tmp_path,monkeypatch):
    from ustar_console import notification_state
    path=tmp_path/'status.json';write_status(path)
    value=json.loads(path.read_text());value['evaluation']='queue_capacity'
    path.write_text(json.dumps(value));monkeypatch.setattr(notification_state,'STATUS',path)
    overview=logged.get('/api/overview').json()
    assert next(c for c in overview['checks'] if c['id']=='cpu_sustained')['status']=='no_data'
