"""Independent recipient preferences must change actual durable alert evaluation."""
from datetime import datetime, timezone

from ustar_console.alerts import Queue, updates
from ustar_console.notifier_control import Store


def state(cpu=95,ram=10,stale=False,status='ok'):
    return dict(stale=stale,host={'cpu_percent':cpu,'memory':{'total_bytes':100,'available_bytes':ram}},
        containers=[],checks=[dict(id='host',label='Host',status='no_data' if stale else 'ok',reason_code='measured'),
                               dict(id='cron',label='Cron',status=status,reason_code='measured')])


def setup(tmp_path):
    now=[1000];queue=Queue(tmp_path/'outbox.sqlite3',[123],clock=lambda:now[0])
    store=Store(queue.path,clock=lambda:now[0]);queue.accounts=store
    return queue,store,now


def link(store,user,chat,**changes):
    prefs=store.get_account(user)['preferences'];prefs.update(changes)
    store.set_preferences(user,prefs)
    assert store.link(store.create_link(user)['code'],chat)


def pending(queue,chat=None):
    with queue.connect() as db:
        return [dict(row) for row in db.execute("SELECT * FROM outbox WHERE status='pending'"+(" AND chat=?" if chat else ''),
                                               (chat,) if chat else ())]


def test_recipients_have_independent_threshold_hold_and_durable_states(tmp_path):
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['cpu_sustained','ram_sustained'],cpu_percent=90,
         ram_available_percent=20,hold_seconds=30)
    link(store,'bob',345,check_ids=['cpu_sustained','ram_sustained'],cpu_percent=99,
         ram_available_percent=5,hold_seconds=30)
    for _ in range(3): queue.evaluate(state());now[0]+=15
    assert len(pending(queue,234))==2 and not pending(queue,345)
    restarted=Queue(queue.path,[123],clock=lambda:now[0]);restarted.accounts=Store(queue.path,clock=lambda:now[0])
    restarted.evaluate(state());assert len(pending(queue,234))==2


def test_stale_invalid_and_gapped_samples_reset_resource_hold(tmp_path):
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['cpu_sustained'],statuses=['warning'],hold_seconds=30)
    queue.evaluate(state());now[0]+=15;queue.evaluate(state(stale=True))
    now[0]+=15;queue.evaluate(state());now[0]+=15;queue.evaluate(state(cpu=float('nan')))
    now[0]+=15;queue.evaluate(state());now[0]+=60;queue.evaluate(state())
    assert not pending(queue,234)
    now[0]+=15;queue.evaluate(state());now[0]+=15;queue.evaluate(state())
    assert len(pending(queue,234))==1


def test_quiet_hours_drop_pending_recheck_current_state_and_cross_midnight(tmp_path):
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['cron'],hold_seconds=0,quiet_start='23:00',quiet_end='07:00',timezone='UTC')
    now[0]=datetime(2026,10,18,22,59,tzinfo=timezone.utc).timestamp();queue.evaluate(state(status='critical'))
    assert len(pending(queue,234))==1
    now[0]+=60;queue.evaluate(state(status='critical'));assert not pending(queue,234)
    now[0]=datetime(2026,10,19,6,59,tzinfo=timezone.utc).timestamp();queue.evaluate(state(status='ok'))
    now[0]+=60;queue.evaluate(state(status='ok'));assert not pending(queue,234)
    now[0]+=15;queue.evaluate(state(status='critical'));assert len(pending(queue,234))==1


def test_repeat_recovery_filters_disabling_unlink_and_bootstrap_preferences(tmp_path):
    queue,store,now=setup(tmp_path)
    link(store,'alice',123,check_ids=['cron'],statuses=['critical'],repeat_minutes=15,recovery=False)
    queue.evaluate(state(status='critical'));assert len(pending(queue,123))==1
    queue.deliver(lambda *args:None,limit=20)
    # Long gap resets candidate hold, but cron critical has no hold.
    now[0]+=900;queue.evaluate(state(status='critical'));assert len(pending(queue,123))==1
    queue.deliver(lambda *args:None,limit=20)
    queue.evaluate(state(status='ok'));now[0]+=30;queue.evaluate(state(status='ok'));assert not pending(queue,123)
    prefs=store.get_account('alice')['preferences'];prefs['enabled']=False;store.set_preferences('alice',prefs)
    queue.evaluate(state(status='critical'));assert not pending(queue,123)
    command=dict(update_id=1,message={'text':'/status','from':{'id':123},'chat':{'id':123,'type':'private'}})
    updates(queue,None,[123],[123],state(),'https://example.test',batch=[command]);assert len(pending(queue,123))==1
    store.unlink('alice');assert not pending(queue,123)


def test_recovery_and_status_selection_are_per_recipient(tmp_path):
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['cron'],statuses=['critical'],recovery=True)
    link(store,'bob',345,check_ids=['cron'],statuses=['warning'],recovery=True)
    queue.evaluate(state(status='critical'));assert len(pending(queue,234))==1 and not pending(queue,345)
    queue.deliver(lambda *args:None,limit=20)
    queue.evaluate(state(status='ok'));now[0]+=30;queue.evaluate(state(status='ok'))
    assert len(pending(queue,234))==1 and 'ВОССТАНОВЛЕНО' in pending(queue,234)[0]['text']
    assert not pending(queue,345)


def test_personal_pending_is_rechecked_after_quiet_worker_restart(tmp_path):
    from ustar_console.alerts import iteration
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['cron'],quiet_start='23:00',quiet_end='07:00',timezone='UTC')
    now[0]=datetime(2026,10,18,22,59,tzinfo=timezone.utc).timestamp();queue.evaluate(state(status='critical'))
    # The worker was stopped throughout quiet hours. Old incidents cannot escape on startup.
    now[0]=datetime(2026,10,19,7,1,tzinfo=timezone.utc).timestamp();sent=[]
    def api(method,payload):
        if method=='getUpdates': return []
        sent.append(payload)
    iteration(queue,api,[123],[123],None,'https://example.test',refresh=lambda:state(status='ok'))
    assert not [row for row in sent if row['chat_id']==234]


def test_revoke_waits_for_selected_delivery_and_prevents_any_later_send(tmp_path):
    import threading
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['cron'])
    queue.evaluate(state(status='critical'));entered=threading.Event();released=threading.Event();revoked=threading.Event()
    def api(*args):
        entered.set();assert released.wait(2)
    sender=threading.Thread(target=lambda:queue.deliver(api,limit=20));sender.start();assert entered.wait(2)
    revoker=threading.Thread(target=lambda:(store.revoke('alice'),revoked.set()));revoker.start()
    assert not revoked.wait(.05)
    released.set();sender.join(2);revoker.join(2)
    assert revoked.is_set() and not store.authorized(234)
    calls=[];queue.deliver(lambda *args:calls.append(args),limit=20);assert not calls


def test_source_disabled_cancels_personal_pending_without_recovery(tmp_path):
    queue,store,now=setup(tmp_path);link(store,'alice',234,check_ids=['cron'])
    queue.evaluate(state(status='critical'));assert pending(queue,234)
    data=state(status='no_data');data['checks'][1]['reason_code']='disabled'
    queue.evaluate(data);assert not pending(queue,234)


def test_pending_repeats_coalesce_until_actual_delivery(tmp_path):
    queue,store,now=setup(tmp_path);link(store,'alice',234,check_ids=['cron'],repeat_minutes=15)
    queue.evaluate(state(status='critical'));now[0]+=901;queue.evaluate(state(status='critical'))
    assert len(pending(queue,234))==1


def test_resource_pending_cannot_escape_after_measurement_gap(tmp_path):
    queue,store,now=setup(tmp_path);link(store,'alice',234,check_ids=['cpu_sustained'],hold_seconds=30)
    for _ in range(3): queue.evaluate(state());now[0]+=15
    assert pending(queue,234)
    now[0]+=90;queue.evaluate(state());assert not pending(queue,234)


def test_saving_preferences_or_relinking_does_not_reuse_sent_event_key(tmp_path):
    queue,store,now=setup(tmp_path);link(store,'alice',234,check_ids=['cron'])
    queue.evaluate(state(status='critical'));queue.deliver(lambda *args:None,limit=20)
    prefs=store.get_account('alice')['preferences'];store.set_preferences('alice',prefs)
    queue.evaluate(state(status='critical'));assert len(pending(queue,234))==1
    sent=[];queue.deliver(lambda method,payload:sent.append(payload),limit=20)
    assert len([row for row in sent if row['chat_id']==234])==1
    store.unlink('alice');store.link(store.create_link('alice')['code'],234)
    queue.evaluate(state(status='critical'));assert len(pending(queue,234))==1


def test_repeat_interval_starts_at_actual_successful_delivery(tmp_path):
    queue,store,now=setup(tmp_path);link(store,'alice',234,check_ids=['cron'],repeat_minutes=15)
    queue.evaluate(state(status='critical'));now[0]=1901;queue.deliver(lambda *args:None,limit=20)
    now[0]=1902;queue.evaluate(state(status='critical'));assert not pending(queue,234)
    now[0]=2801;queue.evaluate(state(status='critical'));assert len(pending(queue,234))==1


def test_maintenance_cancellation_does_not_suppress_current_unsent_incident(tmp_path):
    queue,store,now=setup(tmp_path);link(store,'alice',234,check_ids=['cron'])
    queue.evaluate(state(status='critical'));assert pending(queue,234)
    now[0]=1001;queue.evaluate(state(status='critical'),dict(action='manual_backup',started=1001,until=1020))
    assert not pending(queue,234)
    now[0]=1021;queue.evaluate(state(status='critical'));assert len(pending(queue,234))==1


def test_missing_snapshot_cancels_pending_rule_absent_from_fallback(tmp_path):
    from ustar_console.alerts import iteration
    from ustar_console.data import snapshot
    queue,store,now=setup(tmp_path)
    link(store,'alice',234,check_ids=['hdd'],quiet_start='23:00',quiet_end='07:00',timezone='UTC')
    data=state();data['checks'].append(dict(id='hdd',label='HDD',status='critical',reason_code='measured'))
    now[0]=datetime(2026,10,18,22,59,tzinfo=timezone.utc).timestamp();queue.evaluate(data);assert pending(queue,234)
    now[0]=datetime(2026,10,19,7,1,tzinfo=timezone.utc).timestamp();sent=[]
    def api(method,payload):
        if method=='getUpdates': return []
        sent.append(payload)
    iteration(queue,api,[123],[123],None,'https://example.test',refresh=lambda:snapshot(tmp_path/'missing.json'))
    assert not [row for row in sent if row['chat_id']==234]
    with queue.connect() as db:
        assert db.execute("SELECT state FROM personal_rules WHERE username='alice' AND key='hdd'").fetchone()[0]=='no_data'
