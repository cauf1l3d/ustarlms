"""Owner-facing cards and delivery boundaries, using real snapshot-shaped data."""
import json

import pytest

from ustar_console.alerts import Queue, command_reply, iteration, updates, config


ORIGIN = 'https://192.168.1.60:8443'


def sample():
    def row(key, domain='server', status='ok', reason='measured'):
        return dict(id=key, label=key, domain=domain, status=status, reason_code=reason)
    return dict(stale=False, age_seconds=5, observed_at='2026-10-18T01:10:00+00:00',
        checks=[row('host'), row('containers','academy'), row('http_local','academy'),
                row('database','academy'), row('cron','academy'), row('hdd','data'), row('backup','data'),
                row('collector','monitoring'), row('http_external','academy','no_data','not_configured')],
        domains={'academy':'no_data','server':'ok','data':'ok','monitoring':'ok'},
        host=dict(cpu_percent=12.5, cores=4, memory=dict(total_bytes=8*2**30, available_bytes=3*2**30,
             swap_used_bytes=128*2**20), uptime_seconds=90000,
             disk=dict(total_bytes=80*2**30,available_bytes=20*2**30,free_inode_percent=92)),
        hdd=dict(total_bytes=2*2**40,available_bytes=2**40,free_inode_percent=99),
        containers=[dict(name='ustar_moodle',state='running',running=True,oom=False,restarts=0)],
        database=dict(connections=8,max_connections=100,active=1,waiting=0,idle_in_transaction=0,
            oldest_transaction_seconds=2,size_bytes=300*2**20),
        cron=dict(last_success_at='2026-10-18T01:09:50+00:00',success_age_seconds=15,
            worker_exit_code=0,new_task_failures=0,ustar_error_lines=0,scheduled_failed=0,
            scheduled_overdue_10m=0,adhoc_queued=2),
        http_local=dict(code=200,total_ms=38),
        backup=dict(file='encrypted.age',bytes=2**30,sha256='a'*64,completed_at='2026-10-18T00:00:00Z',
            age_seconds=4200,status='ok',verification='creation_receipt_only',restore='not_accepted'),
        lab=dict(phase='stopped',ready=False,unlimited=False,expires_at=None))


def test_metrics_show_memory_both_disks_and_units():
    text = command_reply('/metrics',sample(),ORIGIN)
    for part in ('12,5%', 'RAM', '3,0 ГиБ', '8,0 ГиБ', 'SSD', '20,0 ГиБ', 'HDD', '1,0 ТиБ', 'inode'):
        assert part in text
    assert '04:10' in text and 'МСК' in text
    assert 'None' not in text and 'available_bytes' not in text


def test_status_uses_health_wording_and_separates_unconfigured_sources():
    text=command_reply('/status',sample(),ORIGIN)
    assert 'Академия' in text and 'В норме' in text
    assert 'ВОССТАНОВЛЕНО' not in text and 'academy:' not in text
    assert 'Не подключено' in text and 'внешний HTTP' in text


def test_academy_contains_pg_cron_and_http_measurements():
    text=command_reply('/academy',sample(),ORIGIN)
    for part in ('PostgreSQL','8 / 100','300,0 МиБ','HTTP 200','38 мс','Очередь разовых задач: 2'):
        assert part in text


@pytest.mark.parametrize('stale_probe', [False,True])
def test_stale_values_are_never_presented_as_current(stale_probe):
    data=sample()
    if stale_probe:
        data['checks'][0].update(status='no_data',reason_code='stale')
    else:
        data['stale']=True
    text=command_reply('/metrics',data,ORIGIN)
    assert '12,5%' not in text and '20,0 ГиБ' not in text
    assert 'устарел' in text.lower() or 'нет данных' in text.lower()


def test_missing_pg_does_not_print_fabricated_zero():
    data=sample();data['database']={}
    text=command_reply('/academy',data,ORIGIN)
    assert 'PostgreSQL' in text and 'нет данных' in text.lower()
    assert '0 / 100' not in text and 'None' not in text


def test_backup_mentions_receipt_and_unaccepted_restore():
    text=command_reply('/backup latest',sample(),ORIGIN)
    assert '1,0 ГиБ' in text and 'a'*64 in text and '/#backups' in text
    assert 'при создании' in text and 'Восстановление: ещё не принято' in text


def test_stopped_lab_does_not_claim_missing_expiry_is_a_fault():
    text=command_reply('/labs',sample(),ORIGIN)
    assert 'Остановлен' in text and 'не действует' in text
    assert 'not_measured' not in text and 'None' not in text
    data=sample();data['lab'].update(phase='ready',ready=True,unlimited=True)
    text=command_reply('/labs',data,ORIGIN)
    assert 'Без автоостановки' in text and 'контроллера' in text


def test_buttons_and_bot_mentions_resolve_to_the_same_cards():
    for button,command in [('📊 Сводка','/status'),('💻 Ресурсы','/metrics'),('🎓 Академия','/academy'),
                           ('💾 Бэкапы','/backup latest'),('🧪 Стенды','/labs'),('🔔 Алерты','/alerts'),('🖥 Пульт','/panel')]:
        assert command_reply(button,sample(),ORIGIN)==command_reply(command,sample(),ORIGIN)
    assert command_reply('/backup@ustar_bot latest',sample(),ORIGIN)==command_reply('/backup latest',sample(),ORIGIN)
    assert command_reply('/shell reboot',sample(),ORIGIN) is None


def test_alert_text_includes_value_threshold_action_and_true_recovery(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    data=sample();data['host']['cpu_percent']=91.5
    for _ in range(41):
        queue.evaluate(data);now[0]+=15
    with queue.connect() as db:
        text=db.execute("SELECT text FROM outbox WHERE key LIKE 'rule:cpu_sustained:%'").fetchone()[0]
    assert '91,5%' in text and '85%' in text and '10 мин' in text and 'Что делать:' in text
    data['host']['cpu_percent']=10
    for _ in range(3): queue.evaluate(data);now[0]+=15
    with queue.connect() as db:
        texts=[r[0] for r in db.execute("SELECT text FROM outbox WHERE key LIKE 'rule:cpu_sustained:%'")]
    assert len(texts)==2 and 'ВОССТАНОВЛЕНО' in texts[-1] and '10,0%' in texts[-1]


def test_four_button_taps_are_durable_and_replay_safe(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    batch=[dict(update_id=i,message={'text':text,'from':{'id':123,'is_bot':False},
            'chat':{'id':123,'type':'private'}}) for i,text in enumerate(
                ['📊 Сводка','💻 Ресурсы','🎓 Академия','🔔 Алерты'],1)]
    def api(method,payload): return batch
    updates(queue,api,[123],[123],sample(),ORIGIN)
    assert queue.status()['pending']==4
    updates(queue,api,[123],[123],sample(),ORIGIN)
    assert queue.status()['pending']==4


def test_command_is_delivered_with_native_keyboard_in_first_cycle(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);sent=[]
    def api(method,payload):
        if method=='getUpdates':
            return [dict(update_id=1,message={'text':'/start','from':{'id':123},
                                            'chat':{'id':123,'type':'private'}})]
        sent.append(payload);return {}
    iteration(queue,api,[123],[123],sample(),ORIGIN)
    assert len(sent)==1
    assert sent[0]['reply_markup']['resize_keyboard'] is True
    assert sent[0]['reply_markup']['is_persistent'] is True
    assert 'parse_mode' not in sent[0]
    assert not any('web_app' in button for row in sent[0]['reply_markup']['keyboard'] for button in row)


def test_alert_help_explains_thresholds_and_independent_observer_limit():
    text=command_reply('/alerts',sample(),ORIGIN)
    assert '85%' in text and '10 мин' in text and '26 ч' in text and '48 ч' in text
    assert 'независимый' in text.lower() and 'сервер' in text.lower()


@pytest.mark.parametrize('flag',[True,False])
def test_mini_app_is_a_strict_optional_boolean_in_private_credentials(tmp_path,flag):
    path=tmp_path/'telegram.json'
    path.write_text(json.dumps(dict(token='123456789:'+('x'*35),users=[123],chats=[123],mini_app=flag)))
    path.chmod(0o600)
    assert config(path)['mini_app'] is flag


@pytest.mark.parametrize('flag',['true',1,None,{}])
def test_malformed_mini_app_opt_in_is_rejected(tmp_path,flag):
    path=tmp_path/'telegram.json'
    path.write_text(json.dumps(dict(token='123456789:'+('x'*35),users=[123],chats=[123],mini_app=flag)))
    path.chmod(0o600)
    with pytest.raises(ValueError): config(path)


def test_opted_in_native_button_opens_only_the_existing_origin(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);sent=[]
    def api(method,payload):
        if method=='getUpdates':
            return [dict(update_id=1,message={'text':'/panel','from':{'id':123},
                'chat':{'id':123,'type':'private'}})]
        sent.append(payload);return {}
    iteration(queue,api,[123],[123],sample(),ORIGIN,mini_app=True)
    button=sent[0]['reply_markup']['keyboard'][-1][0]
    assert button['web_app']=={'url':ORIGIN}
    assert 'учётной записью пульта' in sent[0]['text']


@pytest.mark.parametrize('enabled',['on','off'])
def test_setup_mini_app_preserves_secrets_and_restarts_only_notifier(tmp_path,monkeypatch,enabled):
    from ustar_console import setup
    directory=tmp_path/'config';directory.mkdir()
    credential=dict(token='123456789:'+('x'*35),users=[123],chats=[123],proxy='http://127.0.0.1:18081')
    (directory/'telegram.json').write_text(json.dumps(credential));(directory/'telegram.json').chmod(0o600)
    monkeypatch.setattr(setup,'CONFIG',directory)
    monkeypatch.setattr(setup,'trusted_config',lambda path:{})
    monkeypatch.setattr(setup.os,'isatty',lambda fd:True)
    monkeypatch.setattr(setup.os,'geteuid',lambda:0)
    monkeypatch.setattr(setup,'save',lambda path,data: path.write_text(json.dumps(data)))
    calls=[];monkeypatch.setattr(setup,'run',calls.append)
    monkeypatch.setattr('sys.argv',['ustar-console-setup','--telegram-mini-app',enabled])
    setup.main()
    value=json.loads((directory/'telegram.json').read_text())
    assert value.pop('mini_app') is (enabled=='on')
    assert value==credential
    assert calls==[['systemctl','restart','ustar-console-notify.service']]


def test_burst_limit_resets_without_accepting_unknown_users(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    def batch(start,actor=123,count=6):
        return [dict(update_id=i,message={'text':'/status','from':{'id':actor},
            'chat':{'id':actor,'type':'private'}}) for i in range(start,start+count)]
    updates(queue,lambda *args:batch(1),[123],[123],sample(),ORIGIN)
    assert queue.status()['pending']==4
    updates(queue,lambda *args:batch(7,999),[123],[123],sample(),ORIGIN)
    assert queue.status()['pending']==4
    queue.clock=lambda:1011
    updates(queue,lambda *args:batch(13,count=1),[123],[123],sample(),ORIGIN)
    assert queue.status()['pending']==5


def test_per_probe_stale_lab_is_not_shown_ready():
    data=sample();data['lab'].update(phase='ready',ready=True,unlimited=True)
    data['checks'].append(dict(id='lab',domain='labs',label='lab',status='no_data',reason_code='stale'))
    text=command_reply('/labs',data,ORIGIN)
    assert 'Готов по данным' not in text and 'Нет свежих данных' in text


def test_unavailable_numbers_do_not_carry_fake_units():
    data=sample();data['host']={}
    text=command_reply('/metrics',data,ORIGIN)
    assert 'нет данных%' not in text and 'нет данных мс' not in text


def test_cpu_rule_does_not_use_a_stale_host_probe(tmp_path):
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    data=sample();data['host']['cpu_percent']=99
    data['checks'][0].update(status='no_data',reason_code='stale')
    for _ in range(42): queue.evaluate(data);now[0]+=15
    with queue.connect() as db:
        row=db.execute("SELECT state FROM rules WHERE key='cpu_sustained'").fetchone()
    assert row[0]=='no_data'


def test_empty_container_result_does_not_report_oom_recovery(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    data=sample();data['containers']=[]
    queue.evaluate(data)
    with queue.connect() as db:
        assert db.execute("SELECT state FROM rules WHERE key='container_oom'").fetchone()[0]=='no_data'


def test_failed_native_button_does_not_hold_critical_alert_delivery(tmp_path):
    from ustar_console.alerts import DeliveryError
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    with queue.connect() as db: queue.enqueue(db,'legacy','old message: < 10% & unknown >')
    calls=[]
    def api(method,payload):
        calls.append(payload)
        if 'reply_markup' in payload: raise DeliveryError('api_rejected')
        return {}
    queue.deliver(api,reply_markup={'keyboard':[[{'text':'Пульт','web_app':{'url':ORIGIN}}]]})
    assert queue.status()['pending']==0
    assert calls[-1]['text']=='old message: < 10% & unknown >' and 'parse_mode' not in calls[-1]
    assert 'reply_markup' not in calls[-1]


def test_alert_card_includes_sustained_cpu_rule_state(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);data=sample();sent=[]
    data['host']['cpu_percent']=99
    def api(method,payload):
        if method=='getUpdates': return [dict(update_id=1,message={'text':'/alerts','from':{'id':123},
            'chat':{'id':123,'type':'private'}})]
        sent.append(payload);return {}
    iteration(queue,api,[123],[123],data,ORIGIN)
    assert 'Длительная нагрузка CPU' in sent[0]['text']
    assert 'отклонений нет' not in sent[0]['text']


def test_confirmed_oom_is_alerted_despite_other_container_probe_failure(tmp_path):
    queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);data=sample()
    data['containers']=[dict(name='ustar_moodle',state='exited',running=False,oom=True,restarts=0),
                        dict(name='ustar_postgres',state='no_data')]
    find_row=next(c for c in data['checks'] if c['id']=='containers')
    find_row.update(status='no_data',reason_code='measured')
    queue.evaluate(data)
    with queue.connect() as db:
        assert db.execute("SELECT state FROM rules WHERE key='container_oom'").fetchone()[0]=='critical'
        assert db.execute("SELECT text FROM outbox WHERE key LIKE 'rule:container_oom:%'").fetchone()


def test_stale_unit_probe_does_not_print_old_active_state(tmp_path):
    data=sample();data['units']=[dict(name='ustar-backup.timer',state='active')]
    data['checks'].append(dict(id='units',label='units',domain='server',status='no_data',reason_code='stale'))
    now=[1000];queue=Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0])
    for _ in range(5): queue.evaluate(data);now[0]+=15
    with queue.connect() as db:
        text=db.execute("SELECT text FROM outbox WHERE key LIKE 'rule:units:%'").fetchone()[0]
    assert 'активна' not in text and 'устарели' in text

