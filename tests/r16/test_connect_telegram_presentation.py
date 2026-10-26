"""Version3 distinguishes events without exporting private Academy content or weakening v1/v2."""
import copy
import inspect
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'connect-worker'))
from ustar_connect.notification_worker import NotificationWorker
from ustar_connect.telegram_api import TelegramClient
from ustar_connect.bridge import BridgeClient

NOW=1800000000
LEASE={'delivery':7,'lease':'ud1_'+'A'*43,'expires':NOW+60}
PERMIT={'status':'sending','subject':'77777','category':'tasks','severity':'normal',
        'occurred_at':NOW-10,'send_before':NOW+10,'version':3,'ack':None,'event':'task_assigned'}

class Response(io.BytesIO):
    def __enter__(self): return self
    def __exit__(self,*args): self.close()

class TelegramPresentation(unittest.TestCase):
    def setUp(self):
        self.api=TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
        self.calls=[]
        def open_request(request,timeout):
            method=request.full_url.rsplit('/',1)[-1];self.calls.append((method,json.loads(request.data)))
            value={'id':123456,'is_bot':True,'username':'ustarcorp_bot'} if method=='getMe' else {'message_id':991,'chat':{'id':77777,'type':'private'}}
            return Response(json.dumps({'ok':True,'result':value}).encode())
        self.api._opener=Mock();self.api._opener.open.side_effect=open_request
        self.bridge=Mock()
    def run_worker(self,permit,version=3):
        self.bridge.request.side_effect=[{'ok':True,'result':v} for v in (LEASE,permit,{'status':'provider_accepted'})]
        try:
            worker=NotificationWorker(self.api,self.bridge,bot_id='123456',bot_username='ustarcorp_bot',
                notification_contract=version,clock=lambda:NOW,allowed_subjects=('77777',))
        except ValueError:
            self.fail('Explicit presentation contract3 is not implemented')
        return worker.step()
    def test_assignment_and_reminder_are_different_fixed_messages(self):
        texts=[]
        for event,label in [('task_assigned','Назначена задача'),('task_reminder','Напоминание о задаче')]:
            self.assertEqual('provider_accepted',self.run_worker({**PERMIT,'event':event})['status'])
            self.assertEqual('notification.begin_v3',self.bridge.request.call_args_list[-2].args[0])
            text=self.calls[-1][1]['text'];texts.append(text)
            self.assertIn(label,text);self.assertIn('UTC',text)
            for private in ('taskid','userid','https://','PRIVATE'):
                self.assertNotIn(private,text)
            self.assertEqual('https://ustar.local/local/ustar/tasks.php',
                self.calls[-1][1]['reply_markup']['inline_keyboard'][0][0]['url'])
        self.assertNotEqual(*texts)
    def test_invalid_event_category_or_version_never_sends(self):
        variants=[{**PERMIT,'event':v} for v in ('private title',None,True,[],{},'feed_reply')]
        variants += [{**PERMIT,'category':[]},{**PERMIT,'severity':{}},{**PERMIT,'version':2},
                     {**PERMIT,'version':True},{**PERMIT,'body':'private'},
                     {k:v for k,v in PERMIT.items() if k!='event'}]
        for permit in variants:
            with self.subTest(permit=permit):
                self.assertEqual('unavailable',self.run_worker(permit)['status'])
        self.assertFalse(any(m=='sendMessage' for m,_ in self.calls))
    def test_old_contracts_do_not_accept_v3_fields(self):
        for version in (1,2):
            permit={**PERMIT,'version':version}
            if version==1:permit={k:v for k,v in permit.items() if k not in ('version','ack')}
            self.assertEqual('unavailable',self.run_worker(permit,version)['status'])
        self.assertFalse(any(m=='sendMessage' for m,_ in self.calls))
    def test_v3_keeps_optional_ack_without_leaking_action_in_text(self):
        action={'action':'ua1_'+'A'*43,'expires':NOW+3600}
        self.assertEqual('provider_accepted',self.run_worker({**PERMIT,'ack':action})['status'])
        payload=self.calls[-1][1]
        self.assertNotIn(action['action'],payload['text'])
        self.assertEqual(action['action'],payload['reply_markup']['inline_keyboard'][0][0]['callback_data'])
        self.assertTrue(payload['protect_content'])
    def test_event_label_keeps_critical_urgency_visible(self):
        self.assertEqual('provider_accepted', self.run_worker({**PERMIT,'severity':'critical'})['status'])
        self.assertIn('Назначена задача', self.calls[-1][1]['text'])
        self.assertIn('Важное событие', self.calls[-1][1]['text'])
    def test_renderer_rejects_unbounded_or_wrong_category_event_before_transport(self):
        self.assertIn('event',inspect.signature(self.api.send_notification).parameters)
        for event in ('SECRET SUBJECT','feed_reply',[],True):
            with self.assertRaises(ValueError):
                self.api.send_notification(77777,'tasks','normal',NOW,event=event)
        self.assertEqual([],self.calls)
    def test_v3_probe_checks_old_begin_permissions_without_consuming_delivery(self):
        client=BridgeClient('https://localhost/local/ustar/connect_bridge.php','tg_v3','a'*64)
        seen=[]
        from urllib.error import HTTPError
        def denied(request,timeout):
            seen.append(json.loads(request.data)['operation'])
            raise HTTPError(request.full_url,403,'Forbidden',{},io.BytesIO(b'{"ok":false,"reason":"forbidden_operation"}'))
        client._opener=Mock();client._opener.open.side_effect=denied
        try:client.probe_notification_scope(3)
        except ValueError:self.fail('Explicit scope3 probe missing')
        self.assertEqual(['link.claim','notification.begin','notification.begin_v2'],seen)
    def test_bridge_rejects_duplicate_json_fields(self):
        client=BridgeClient('https://localhost/local/ustar/connect_bridge.php','tg_v3','a'*64)
        client._opener=Mock();client._opener.open.side_effect=lambda *a,**kw:Response(
            b'{"ok":true,"result":{"status":"cancelled","status":"sending"}}')
        with self.assertRaises(ValueError):client.request('notification.begin_v2',{'delivery':'7','lease':LEASE['lease']})

if __name__=='__main__':unittest.main()
