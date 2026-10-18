"""Prompt replies without stale cards or blocked incident delivery."""
from ustar_console.alerts import DeliveryError, Queue, iteration, updates

ORIGIN='https://192.168.1.60:8443'


def data(cpu=12.5,stale=False):
    return dict(stale=stale,age_seconds=5,observed_at='2026-10-18T08:50:00+00:00',
        host={'cpu_percent':cpu},containers=[],backup={},lab={},checks=[dict(
            id='host',label='host',domain='server',status='no_data' if stale else 'ok',
            reason_code='stale' if stale else 'measured')])


def message():
    return dict(update_id=1,message={'text':'/metrics','from':{'id':123},
                                  'chat':{'id':123,'type':'private'}})


def test_poll_waits_for_commands_on_telegram_instead_of_short_polling(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);requests=[]
    def api(method,payload): requests.append((method,payload));return []
    updates(queue,api,[123],[123],data(),ORIGIN)
    assert requests==[('getUpdates',dict(offset=0,limit=20,timeout=5,allowed_updates=['message']))]


def test_command_and_rules_use_snapshot_refreshed_after_polling(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);polled=[];sent=[]
    def api(method,payload):
        if method=='getUpdates': polled.append(True);return [message()]
        if method=='sendMessage': sent.append(payload)
        return {}
    def refresh():
        assert polled, 'Snapshot must be read after the long poll returns'
        return data(cpu=99)
    iteration(queue,api,[123],[123],data(),ORIGIN,refresh=refresh)
    assert len(sent)==1 and '99,0%' in sent[0]['text'] and '12,5%' not in sent[0]['text']
    with queue.connect() as db:
        assert db.execute("SELECT state FROM rules WHERE key='cpu_sustained'").fetchone()[0]=='warning'


def test_snapshot_that_expires_during_polling_is_not_presented_as_current(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);sent=[]
    def api(method,payload):
        if method=='getUpdates': return [message()]
        if method=='sendMessage': sent.append(payload)
        return {}
    iteration(queue,api,[123],[123],data(),ORIGIN,refresh=lambda:data(cpu=99,stale=True))
    assert '99,0%' not in sent[0]['text'] and '12,5%' not in sent[0]['text']
    assert 'устарели' in sent[0]['text']


def test_pending_incident_is_sent_before_entering_long_poll(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);sent=[]
    with queue.connect() as db: queue.enqueue(db,'incident','Существующая авария')
    def api(method,payload):
        if method=='getUpdates':
            assert sent and sent[0]['text']=='Существующая авария'
            return []
        sent.append(payload);return {}
    iteration(queue,api,[123],[123],data(),ORIGIN)
    assert queue.status()['pending']==0


def test_new_incident_still_evaluates_and_delivers_after_poll_failure(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);sent=[];fresh=data()
    fresh['checks'].append(dict(id='recovery',label='recovery',domain='data',status='critical',reason_code='recovery_required'))
    fresh['recovery']={'marker_present':True}
    def api(method,payload):
        if method=='getUpdates': raise DeliveryError('transport_unavailable')
        sent.append(payload);return {}
    result=iteration(queue,api,[123],[123],data(),ORIGIN,refresh=lambda:fresh)
    assert result['polling']=='no_data' and result['evaluation']=='ok'
    assert len(sent)==1 and 'АВАРИЯ' in sent[0]['text']


def test_maintenance_starting_during_poll_mutes_new_cron_incident(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);sent=[];operations={};fresh=data()
    fresh['checks'].append(dict(id='cron',label='cron',domain='academy',status='critical',reason_code='cron_worker_failed'))
    def api(method,payload):
        if method=='getUpdates':
            operations['maintenance']=dict(action='manual_backup',started=1000,until=1100)
            return []
        sent.append(payload);return {}
    def maintenance():
        assert 'maintenance' in operations
        return operations['maintenance']
    iteration(queue,api,[123],[123],data(),ORIGIN,refresh=lambda:fresh,refresh_maintenance=maintenance)
    assert sent==[] and queue.status()['pending']==0
