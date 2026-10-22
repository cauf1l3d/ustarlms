"""Private callback processing with real durable journal; transport remains synthetic."""
import copy
import importlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'connect-worker'))
from ustar_connect.bridge import BridgeRejected
from ustar_connect.receiver import TelegramReceiver, ReceiverStopped
from ustar_connect.receiver_journal import Journal
from ustar_connect.telegram_api import TelegramError

NOW=1800000000
ACTION='ua1_'+'A'*43


def callback(uid=301):
    return {'update_id':uid,'callback_query':{'id':'query-'+str(uid),'chat_instance':'opaque',
        'from':{'id':77777,'is_bot':False},'data':ACTION,
        'message':{'message_id':991,'date':NOW-3600,'text':'Private content never journaled',
                   'from':{'id':123456,'is_bot':True},'chat':{'id':77777,'type':'private'}}}}


class NotificationCallback(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('ustar_connect.notification_callback'),'Callback module missing')
        self.module=importlib.import_module('ustar_connect.notification_callback')
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.now=NOW;self.directory=Path(self.temp.name)
        self.journal=Journal(self.directory,'123456',clock=lambda:self.now);self.addCleanup(self.journal.close)
        self.api=Mock();self.api.get_me.return_value={'id':123456,'is_bot':True,'username':'ustarcorp_bot'}
        self.api.get_webhook_info.return_value={'url':''};self.api.get_updates.return_value=[callback()]
        self.api.answer_notification_ack.return_value=True
        self.api.edit_notification_ack.return_value={'message_id':991,'chat':{'id':77777,'type':'private'}}
        self.bridge=Mock();self.ack_bridge=Mock()
        self.ack_bridge.request.return_value={'ok':True,'result':{'status':'acknowledged','acknowledged_at':NOW-60,'category':'tasks'}}

    def receiver(self,enabled=True):
        return TelegramReceiver(self.api,self.bridge,self.journal,bot_id='123456',bot_username='ustarcorp_bot',
            clock=lambda:self.now,acknowledgements_enabled=enabled,ack_bridge=self.ack_bridge if enabled else None)

    def states(self):return self.journal.health()['states']

    def test_old_message_is_not_an_old_click(self):
        self.assertIsNotNone(self.module.parse_callback(callback(),bot_id='123456',now=NOW))
        receiver=self.receiver();receiver.step()
        self.assertEqual(302,self.journal.health()['next_offset']);self.assertEqual({'sent':1},self.states())
        self.assertEqual(('notification.ack',{'action':ACTION,'message_id':'991','provider':'telegram',
            'subject':'77777','tenant':'123456'}),self.ack_bridge.request.call_args.args)
        self.assertEqual(5,self.ack_bridge.request.call_args.kwargs['timeout'])
        self.api.send_notification.assert_not_called();self.bridge.request.assert_not_called()
        self.api.get_updates.assert_called_once_with(0,acknowledgements_enabled=True)

    def test_callback_origin_denial_matrix(self):
        bad=[]
        def altered(path,value):
            x=callback();target=x
            for part in path[:-1]:target=target[part]
            target[path[-1]]=value;bad.append(x)
        for path,value in [(['callback_query','from','id'],True),(['callback_query','from','is_bot'],True),
                (['callback_query','message','chat','id'],99999),(['callback_query','message','chat','type'],'group'),
                (['callback_query','message','from','id'],654321),(['callback_query','message','from','is_bot'],False),
                (['callback_query','message','date'],0),(['callback_query','message','date'],NOW+31),
                (['callback_query','message','message_id'],True),(['callback_query','data'],'ua1_'+'B'*43),
                (['callback_query','id'],'a b'),(['callback_query','id'],123),(['callback_query','id'],'я')]:altered(path,value)
        for field in ('forward_origin','forward_from','forward_from_chat','via_bot','sender_chat',
                      'business_connection_id','guest_query_id','guest_bot_caller_user','guest_bot_caller_chat',
                      'message_thread_id','is_topic_message'):
            x=callback();x['callback_query']['message'][field]={};bad.append(x)
        for field in ('inline_message_id','game_short_name','business_connection_id','guest_query_id'):
            x=callback();x['callback_query'][field]='bad';bad.append(x)
        x=callback();x['message']={};bad.append(x)
        for x in bad:
            with self.subTest(x=x):self.assertIsNone(self.module.parse_callback(x,bot_id='123456',now=NOW))

    def test_disabled_receiver_ignores_already_queued_callbacks(self):
        self.receiver(False).step();self.assertEqual({'ignored':1},self.states())
        self.ack_bridge.request.assert_not_called();self.api.answer_notification_ack.assert_not_called()

    def test_duplicate_restart_do_not_repeat_bridge(self):
        self.receiver().step();self.receiver().step();self.assertEqual(1,self.ack_bridge.request.call_count)
        self.assertEqual(1,self.api.answer_notification_ack.call_count)
        self.assertEqual(302,self.journal.health()['next_offset'])

    def test_recovered_creating_is_uncertain_without_retry(self):
        self.journal.begin(301,'a'*64,'ack');self.journal.phase(301,'creating')
        self.receiver().step();self.assertEqual({'uncertain':1},self.states())
        self.ack_bridge.request.assert_not_called()

    def test_commit_then_lost_ui_keeps_receipt_and_new_click_gets_first_time(self):
        self.api.answer_notification_ack.side_effect=TelegramError('transport_unavailable')
        receiver=self.receiver();receiver.step()
        self.assertEqual({'uncertain':1},self.states());self.api.edit_notification_ack.assert_not_called()
        self.api.answer_notification_ack.side_effect=None
        self.api.get_updates.return_value=[callback(302)];receiver.step()
        self.assertEqual({'uncertain':1,'sent':1},self.states())
        self.assertEqual(('query-302','acknowledged',NOW-60),self.api.answer_notification_ack.call_args.args)
        self.assertEqual(2,self.ack_bridge.request.call_count)

    def test_edit_failure_is_ui_only_and_does_not_make_ack_retryable(self):
        self.api.edit_notification_ack.side_effect=TelegramError('api_rejected')
        self.receiver().step();self.assertEqual({'sent':1},self.states())
        row=self.journal._db.execute('SELECT reason FROM events').fetchone()
        self.assertEqual('ui_edit_unconfirmed',row[0]);self.assertEqual(1,self.ack_bridge.request.call_count)

    def test_backend_denial_and_unknown_result_keep_url(self):
        for i,error in enumerate((BridgeRejected('ack_unavailable'),ValueError('upstream private text')),301):
            self.api.get_updates.return_value=[callback(i)];self.ack_bridge.request.side_effect=error
            self.receiver().step()
            self.assertEqual('unavailable' if i==301 else 'uncertain',self.api.answer_notification_ack.call_args.args[1])
        self.api.edit_notification_ack.assert_not_called()

    def test_ui_429_persists_backoff_and_leaves_batch_suffix(self):
        self.api.get_updates.return_value=[callback(),callback(302)]
        self.api.answer_notification_ack.side_effect=TelegramError('rate_limited',retry_after=120)
        receiver=self.receiver();receiver.step()
        self.assertEqual(NOW+120,self.journal.health()['backoff_until'])
        self.assertEqual(302,self.journal.health()['next_offset'])
        receiver.step();self.assertEqual(1,self.ack_bridge.request.call_count)

    def test_ui_unauthorized_stops_after_recording_uncertainty(self):
        self.api.answer_notification_ack.side_effect=TelegramError('unauthorized')
        with self.assertRaises(ReceiverStopped):self.receiver().step()
        self.assertEqual({'uncertain':1},self.states())

    def test_exact_canonical_receipt_is_required_before_success(self):
        for i,bad in enumerate(({'ok':True,'result':{'status':'acknowledged','acknowledged_at':True,'category':'tasks'}},
                               {'ok':True,'result':{'status':'acknowledged','acknowledged_at':NOW,'category':'private'}},
                               {'ok':True,'result':{'status':'acknowledged','acknowledged_at':NOW,'category':'tasks','userid':130}}),301):
            self.api.get_updates.return_value=[callback(i)];self.ack_bridge.request.return_value=bad
            self.receiver().step();self.assertEqual('uncertain',self.api.answer_notification_ack.call_args.args[1])
        self.api.edit_notification_ack.assert_not_called()

    def test_rate_limit_and_no_token_or_private_body_in_journal(self):
        self.api.get_updates.return_value=[callback(i) for i in range(301,312)]
        self.receiver().step();self.assertEqual(10,self.ack_bridge.request.call_count)
        self.assertEqual({'sent':10,'ignored':1},self.states())
        self.assertEqual(1,self.journal._db.execute('PRAGMA user_version').fetchone()[0])
        for path in self.directory.glob('receiver.sqlite3*'):
            data=path.read_bytes()
            for secret in (ACTION,'Private content never journaled','query-301'):
                self.assertNotIn(secret.encode(),data)


if __name__=='__main__':unittest.main()
