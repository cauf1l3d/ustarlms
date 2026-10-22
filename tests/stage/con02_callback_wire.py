#!/usr/bin/env python3
"""Actual native PHP packets + HMAC dispatch, synthetic Telegram only. No TLS claim here."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

if Path.cwd()!=Path('/stage/moodle') or Path('/opt/ustar').exists():raise RuntimeError('isolated_fixture_required')
mode=sys.argv[1]
if mode not in ('v1','v2'):raise RuntimeError('invalid_wire_mode')
source=Path('/source/.stage-input/con02-old-worker/connect-worker') if mode=='v1' else Path('/source/connect-worker')
sys.path.insert(0,str(source))
from ustar_connect.notification_worker import NotificationWorker
from ustar_connect.bridge import sign_request,BridgeRejected

wire=json.loads(Path('/stage/con02-callback-wire.json').read_text())[mode]
def dispatch(operation,payload,*,key='tg_ack'):
    secret={'tg_v1':'11','tg_v2':'22','tg_ack':'33'}[key]*32
    import time
    signed=sign_request(operation,payload,key_id=key,secret_hex=secret,now=int(time.time()))
    run=subprocess.run(['php','/source/tests/stage/con02_callback_probe.php','dispatch'],
        input=json.dumps({'body':signed.body.decode(),'headers':signed.headers}),capture_output=True,text=True,timeout=15)
    if run.returncode:raise RuntimeError('native_dispatch_failed')
    value=json.loads(run.stdout)
    if value.get('ok') is not True:raise BridgeRejected(value.get('reason'))
    return value
class SenderBridge:
    def request(self,op,payload):
        if op=='notification.lease':return {'ok':True,'result':wire['lease']}
        if op==('notification.begin' if mode=='v1' else 'notification.begin_v2'):return {'ok':True,'result':wire['permit']}
        if op=='notification.finish':return dispatch(op,payload,key='tg_'+mode)
        raise RuntimeError('unexpected_sender_operation')
class API:
    sent=0
    def get_me(self):return {'id':123456,'is_bot':True,'username':'ustarcorp_bot'}
    def get_webhook_info(self):return {'url':''}
    def send_notification(self,subject,category,severity,occurred_at,**kwargs):
        if mode=='v1' and kwargs:raise RuntimeError('frozen_contract_changed')
        if mode=='v2' and kwargs!={'ack':wire['permit']['ack']}:raise RuntimeError('v2_action_changed')
        self.sent+=1
        return {'message_id':991 if mode=='v1' else 992,'chat':{'id':subject,'type':'private'}}
api=API();kwargs={} if mode=='v1' else {'notification_contract':2}
worker=NotificationWorker(api,SenderBridge(),bot_id='123456',bot_username='ustarcorp_bot',clock=lambda:wire['now'],**kwargs)
if worker.step()!={'status':'provider_accepted'} or api.sent!=1:raise RuntimeError('native_packet_not_accepted')
if mode=='v2':
    from ustar_connect.receiver import TelegramReceiver
    from ustar_connect.receiver_journal import Journal
    import time
    class CallbackAPI(API):
        receipts=[]
        uid=301
        def get_updates(self,offset,**kw):
            return [{'update_id':self.uid,'callback_query':{'id':'native-query-'+str(self.uid),'chat_instance':'opaque',
                'data':wire['permit']['ack']['action'],'from':{'id':77777,'is_bot':False},
                'message':{'message_id':992,'date':int(time.time())-3600,'from':{'id':123456,'is_bot':True},
                           'chat':{'id':77777,'type':'private'}}}}]
        def answer_notification_ack(self,query,outcome,at=0):
            if outcome!='acknowledged':raise RuntimeError('native_callback_not_acknowledged')
            self.receipts.append(at);return True
        def edit_notification_ack(self,subject,mid,category):
            if (subject,mid,category)!=(77777,992,'tasks'):raise RuntimeError('unsafe_markup_target')
    class AckBridge:
        def request(self,op,payload,*,timeout=10):
            if op!='notification.ack' or timeout!=5:raise RuntimeError('wrong_callback_scope')
            return dispatch(op,payload)
    with tempfile.TemporaryDirectory(prefix='callback-wire-',dir='/stage') as folder:
        journal=Journal(folder,'123456');callbacks=CallbackAPI()
        try:
            receiver=TelegramReceiver(callbacks,None,journal,bot_id='123456',bot_username='ustarcorp_bot',
                acknowledgements_enabled=True,ack_bridge=AckBridge())
            receiver.step();callbacks.uid+=1;receiver.step()
            if len(callbacks.receipts)!=2 or callbacks.receipts[0]!=callbacks.receipts[1]:raise RuntimeError('first_receipt_not_stable')
        finally:journal.close()
    status=json.loads(subprocess.check_output(['php','/source/tests/stage/con02_callback_probe.php','status']))
    if status['notice']['status']!='acknowledged' or status['delivery']!={'status':'provider_accepted','providerref':'992'}:
        raise RuntimeError('canonical_transport_fact_changed')
print('CON02_CALLBACK_WIRE_'+mode.upper()+'=PASS')
