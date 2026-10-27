"""Real outbox/multipart behavior; synthetic PNG, no Telegram credentials."""
import io
import json
import sqlite3

from PIL import Image
import pytest

from ustar_console import alerts, telegram_transport
from test_telegram_views import sample, ORIGIN


def png():
    stream=io.BytesIO();Image.new('RGB',(16,16),'#157f8f').save(stream,format='PNG')
    return stream.getvalue()


def command(uid=1,actor=123,chat=123,text='/metrics'):
    return dict(update_id=uid,message={'text':text,'from':{'id':actor},'chat':{'id':chat,'type':'private'}})


def test_allowed_command_keeps_photo_and_complete_text_in_one_durable_reply(tmp_path,monkeypatch):
    image=png();seen=[]
    monkeypatch.setattr(alerts,'card_image',lambda *a,**k:image,raising=False)
    queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    alerts.updates(queue,None,[123],[123],sample(),ORIGIN,batch=[command()])
    assert queue.status()['pending']==1
    queue=alerts.Queue(queue.path,[123],clock=lambda:1000)
    queue.deliver(lambda method,payload:seen.append((method,payload)))
    assert [method for method,_ in seen]==['sendPhoto','sendMessage']
    assert seen[0][1]['photo']==image and seen[0][1]['protect_content'] is True
    assert '1,0 ТиБ' in seen[1][1]['text'] and queue.status()['pending']==0
    with queue.connect() as db:
        assert db.execute('SELECT photo FROM outbox').fetchone()[0] is None


def test_restart_after_photo_does_not_resend_accepted_image(tmp_path):
    now=[1000];queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0]);seen=[]
    with queue.connect() as db: queue.enqueue(db,'fixture','full text',photo=png())
    def first(method,payload):
        seen.append(method)
        if method=='sendMessage': raise alerts.DeliveryError('transport_unavailable')
    queue.deliver(first)
    assert seen==['sendPhoto','sendMessage'] and queue.status()['pending']==1
    now[0]+=30;queue=alerts.Queue(queue.path,[123],clock=lambda:now[0])
    queue.deliver(lambda method,payload:seen.append(method))
    assert seen==['sendPhoto','sendMessage','sendMessage'] and queue.status()['pending']==0


@pytest.mark.parametrize('reason,complete',[('api_rejected',True),('transport_unavailable',False),('rate_limited',False)])
def test_photo_rejection_falls_back_but_ambiguous_failure_retries(tmp_path,reason,complete):
    queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000);seen=[]
    with queue.connect() as db: queue.enqueue(db,'fixture','complete fallback text',photo=png())
    def api(method,payload):
        seen.append(method)
        if method=='sendPhoto': raise alerts.DeliveryError(reason,60)
    queue.deliver(api)
    assert seen==(['sendPhoto','sendMessage'] if complete else ['sendPhoto'])
    assert queue.status()['pending']==(0 if complete else 1)


def test_images_are_bounded_and_old_queue_migrates_without_losing_pending_text(tmp_path):
    path=tmp_path/'queue.sqlite3'
    with sqlite3.connect(path) as db:
        db.execute("CREATE TABLE outbox(id INTEGER PRIMARY KEY,key TEXT UNIQUE,chat INTEGER,text TEXT,attempts INTEGER DEFAULT 0,next REAL,status TEXT DEFAULT 'pending',error TEXT)")
        db.execute("INSERT INTO outbox(key,chat,text,next) VALUES('old',123,'previous reply',1)")
    path.chmod(0o600);queue=alerts.Queue(path,[123],clock=lambda:1000);sent=[]
    with queue.connect() as db:
        with pytest.raises(ValueError): queue.enqueue(db,'huge','text',photo=b'\x89PNG\r\n\x1a\n'+b'x'*262144)
        # Existing queued images may consume the budget; the new reply still retains text.
        db.execute('UPDATE outbox SET photo=?',(b'x'*(16*2**20),))
        queue.enqueue(db,'new','new reply',photo=png())
        assert db.execute("SELECT photo FROM outbox WHERE key='new:123'").fetchone()[0] is None
        db.execute('UPDATE outbox SET photo=NULL')
    queue.deliver(lambda method,payload:sent.append(payload['text']))
    assert sent==['previous reply','new reply']


def test_unauthorized_and_burst_limited_messages_never_render(tmp_path,monkeypatch):
    rendered=[]
    monkeypatch.setattr(alerts,'card_image',lambda *a,**k:rendered.append(1),raising=False)
    queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    batch=[command(1,actor=999),command(2,chat=999)]+[command(i) for i in range(3,9)]
    alerts.updates(queue,None,[123],[123],sample(),ORIGIN,batch=batch)
    assert len(rendered)==4 and queue.status()['pending']==4
    alerts.updates(queue,None,[123],[123],sample(),ORIGIN,batch=batch)
    assert len(rendered)==4


def test_send_photo_uses_bounded_multipart_and_fixed_proxy(monkeypatch):
    seen=[]
    def exchange(*args): seen.append(args);return 200,b'{"ok":true,"result":{}}'
    monkeypatch.setattr(alerts,'exchange',exchange)
    api=alerts.Telegram('123456789:'+('x'*35),proxy=telegram_transport.PROXY)
    image=png();api('sendPhoto',dict(chat_id=123,photo=image,caption='Ресурсы',protect_content=True))
    path,body,proxy,content_type=seen[0]
    assert path.endswith('/sendPhoto') and proxy==telegram_transport.PROXY
    assert content_type.startswith('multipart/form-data; boundary=') and image in body
    assert b'name="photo"; filename="ustar.png"' in body
    assert b'Content-Type: image/png' in body and 'Ресурсы'.encode() in body
    assert b'name="protect_content"\r\n\r\ntrue' in body
    for bad in ('/etc/shadow','https://other.invalid/image.png',b'bad',b'\x89PNG\r\n\x1a\n'+b'x'*262144):
        with pytest.raises(ValueError): api('sendPhoto',dict(chat_id=123,photo=bad))
    assert len(seen)==1


def test_photo_rate_limit_is_sanitized_and_does_not_expose_body(monkeypatch):
    monkeypatch.setattr(alerts,'exchange',lambda *args:(429,b'{"parameters":{"retry_after":42},"description":"private"}'))
    api=alerts.Telegram('123456789:'+('x'*35))
    with pytest.raises(alerts.DeliveryError) as error: api('sendPhoto',dict(chat_id=123,photo=png(),protect_content=True))
    assert error.value.code=='rate_limited' and error.value.retry_after==42
    assert 'private' not in str(error.value)


@pytest.mark.parametrize('status',[500,502,503,504,408])
def test_transient_http_error_retains_photo_until_adapter_accepts_it(tmp_path,monkeypatch,status):
    now=[1000];queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:now[0]);seen=[]
    with queue.connect() as db: queue.enqueue(db,'fixture','full text',photo=png())
    def temporary_failure(path,*args):
        seen.append(path.rsplit('/',1)[-1])
        return (status,b'{}') if path.endswith('/sendPhoto') else (200,b'{"ok":true,"result":{}}')
    monkeypatch.setattr(alerts,'exchange',temporary_failure)
    api=alerts.Telegram('123456789:'+('x'*35))
    queue.deliver(api)
    assert seen==['sendPhoto'] and queue.status()['pending']==1
    with queue.connect() as db:
        row=db.execute('SELECT photo,photo_sent,attempts FROM outbox').fetchone()
        assert row['photo']==png() and row['photo_sent']==0 and row['attempts']==1
    monkeypatch.setattr(alerts,'exchange',lambda path,*args:(seen.append(path.rsplit('/',1)[-1]) or 200,b'{"ok":true,"result":{}}'))
    now[0]+=30;queue.deliver(api)
    assert seen==['sendPhoto','sendPhoto','sendMessage'] and queue.status()['pending']==0


@pytest.mark.parametrize('raw',[b'{}',b'{"ok":null}',b'{"ok":false}',
    b'{"ok":false,"error_code":500}',b'{"ok":false,"error_code":429,"parameters":{"retry_after":10}}'])
def test_ambiguous_success_response_cannot_discard_photo(tmp_path,monkeypatch,raw):
    queue=alerts.Queue(tmp_path/'queue.sqlite3',[123],clock=lambda:1000)
    with queue.connect() as db: queue.enqueue(db,'fixture','full text',photo=png())
    monkeypatch.setattr(alerts,'exchange',lambda *args:(200,raw))
    queue.deliver(alerts.Telegram('123456789:'+('x'*35)))
    with queue.connect() as db:
        row=db.execute('SELECT photo,photo_sent,status FROM outbox').fetchone()
        assert row['status']=='pending' and row['photo']==png() and row['photo_sent']==0
