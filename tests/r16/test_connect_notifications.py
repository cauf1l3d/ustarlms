"""Notification send boundary: no live credentials, provider or Moodle traffic."""
import importlib
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'connect-worker'))
NOW = 1800000000


class NotificationTests(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('ustar_connect.notification_worker')
        self.api, self.bridge = Mock(), Mock()
        self.api.get_me.return_value = {'id':123456,'is_bot':True,'username':'synthetic_academy_bot'}
        self.api.send_notification.return_value = {'message_id':99,'chat':{'id':77777,'type':'private'}}
        self.lease = {'delivery':7,'lease':'ud1_'+'A'*43,'expires':NOW+60}
        self.permit = {'status':'sending','subject':'77777','category':'tasks','severity':'action',
                       'occurred_at':NOW-10,'send_before':NOW+10}
        self.replies(self.lease,self.permit,{'status':'provider_accepted'})
        self.worker = self.module.NotificationWorker(self.api,self.bridge,bot_id='123456',
            bot_username='synthetic_academy_bot',clock=lambda:NOW)

    def replies(self,*values):
        self.bridge.request.side_effect=[{'ok':True,'result':v} if isinstance(v,dict) else v for v in values]

    def test_send_requires_persisted_begin_and_receipt_is_separate(self):
        self.assertEqual({'status':'provider_accepted'},self.worker.step())
        calls = self.bridge.request.call_args_list
        self.assertEqual('notification.lease',calls[0].args[0])
        self.assertEqual({'limit':'1'},calls[0].args[1])
        self.assertEqual(('notification.begin',{'delivery':'7','lease':self.lease['lease']}),calls[1].args)
        self.assertEqual('notification.finish',calls[2].args[0])
        self.assertEqual('99',calls[2].args[1]['provider_ref'])
        self.assertEqual(1,self.api.send_notification.call_count)
        self.assertNotIn('acknowledge',str(calls))

    def test_cancelled_or_unknown_begin_never_sends(self):
        for reply in ({'status':'cancelled'},{'status':'retry_wait'},ValueError('private upstream')):
            self.replies(self.lease,reply)
            self.assertIn(self.worker.step()['status'],('cancelled','retry_wait','unavailable'))
        self.api.send_notification.assert_not_called()

    def test_transport_timeout_is_uncertain_not_retried(self):
        from ustar_connect.telegram_api import TelegramError
        self.api.send_notification.side_effect = TelegramError('transport_unavailable',uncertain=True)
        self.replies(self.lease,self.permit,{'status':'uncertain'})
        self.assertEqual('uncertain',self.worker.step()['status'])
        self.assertEqual('uncertain',self.bridge.request.call_args.args[1]['outcome'])
        self.assertEqual(1,self.api.send_notification.call_count)

    def test_definite_rate_limit_is_durable_and_paces_worker(self):
        from ustar_connect.telegram_api import TelegramError
        self.api.send_notification.side_effect = TelegramError('rate_limited',retry_after=120)
        self.replies(self.lease,self.permit,{'status':'retry_wait'})
        self.assertEqual({'status':'retry_wait','retry_after':120},self.worker.step())
        self.assertEqual('120',self.bridge.request.call_args.args[1]['retry_after'])
        self.assertEqual({'status':'backoff','retry_after':120},self.worker.step())
        self.assertEqual(3,self.bridge.request.call_count)

    def test_unauthorized_records_definite_retry_then_stops(self):
        from ustar_connect.telegram_api import TelegramError
        self.api.send_notification.side_effect = TelegramError('unauthorized')
        self.replies(self.lease,self.permit,{'status':'retry_wait'})
        self.assertEqual({'status':'stopped','reason':'unauthorized'},self.worker.step())
        self.assertEqual('300',self.bridge.request.call_args.args[1]['retry_after'])
        self.worker.step();self.assertEqual(3,self.bridge.request.call_count)

    def test_bad_subject_stale_permit_and_bad_receipt_never_repeat(self):
        for bad in ({**self.permit,'subject':'-77777'},{**self.permit,'send_before':NOW-1},
                    {**self.permit,'category':'private text'},{**self.permit,'userid':99}):
            self.replies(self.lease,bad,{'status':'uncertain'})
            self.worker.step()
        self.api.send_notification.assert_not_called()
        self.replies(self.lease,self.permit,ValueError('lost finish'))
        self.assertEqual('receipt_unconfirmed',self.worker.step()['status'])
        self.assertEqual(1,self.api.send_notification.call_count)

    def test_provider_response_must_be_real_private_message(self):
        for response in ({},{'message_id':True,'chat':{'id':77777,'type':'private'}},
                         {'message_id':99,'chat':{'id':77777,'type':'group'}},
                         {'message_id':99,'chat':{'id':88888,'type':'private'}}):
            w=self.module.NotificationWorker(self.api,self.bridge,bot_id='123456',
                bot_username='synthetic_academy_bot',clock=lambda:NOW)
            self.api.send_notification.return_value=response
            self.replies(self.lease,self.permit,{'status':'uncertain'})
            self.assertEqual('uncertain',w.step()['status'])

    def test_bot_identity_is_checked_without_polling(self):
        self.api.get_me.return_value={'id':999,'is_bot':True,'username':'synthetic_academy_bot'}
        self.assertEqual('stopped',self.worker.step()['status'])
        self.bridge.request.assert_not_called();self.api.get_updates.assert_not_called()

    def test_text_is_fixed_readable_and_contains_no_private_body_or_link(self):
        from ustar_connect.telegram_api import TelegramClient
        api=TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
        api._send=Mock(return_value={})
        api.send_notification(77777,'tasks','action',NOW)
        subject,payload=api._send.call_args.args
        self.assertEqual(77777,subject)
        self.assertIn('Задачи',payload['text']);self.assertIn('/start',payload['text'])
        self.assertIn('UTC',payload['text']);self.assertNotIn('http',payload['text'])
        self.assertNotIn('parse_mode',payload)
        with self.assertRaises(ValueError):api.send_notification(77777,'unknown','normal',NOW)

    def test_bridge_signs_only_fixed_notification_operations(self):
        from ustar_connect.bridge import sign_request
        for op,payload in [('notification.lease',{'limit':'1'}),('notification.begin',{'delivery':'7','lease':self.lease['lease']}),
                           ('notification.finish',{'delivery':'7','lease':self.lease['lease'],'outcome':'uncertain',
                            'provider_ref':'','reason':'interrupted','retry_after':'0'})]:
            signed=sign_request(op,payload,key_id='tg_delivery',secret_hex='a'*64,now=NOW)
            self.assertEqual(op,json.loads(signed.body)['operation'])

    def test_finish_failure_does_not_turn_into_a_second_send(self):
        self.replies(self.lease,self.permit,ValueError('private'),{'status':'empty'})
        self.assertEqual('receipt_unconfirmed',self.worker.step()['status'])
        self.assertEqual('backoff',self.worker.step()['status'])
        self.worker.clock=lambda:NOW+2
        self.assertEqual('waiting',self.worker.step()['status'])
        self.assertEqual(1,self.api.send_notification.call_count)

    def test_forbidden_is_terminal_not_rate_limit(self):
        from ustar_connect.telegram_api import TelegramError
        self.api.send_notification.side_effect=TelegramError('forbidden')
        self.replies(self.lease,self.permit,{'status':'failed'})
        self.assertEqual({'status':'failed'},self.worker.step())
        self.assertEqual('failed',self.bridge.request.call_args.args[1]['outcome'])

    def test_default_cli_never_uses_api_or_bridge(self):
        runtime=importlib.import_module('ustar_connect.notification_runtime')
        config=Mock();config.api=self.api;config.bridge=self.bridge
        with patch.object(runtime,'load_config',return_value=config),patch('sys.stdout',new_callable=io.StringIO) as out:
            self.assertEqual(0,runtime.main(['--config','/synthetic/private.json']))
            self.assertFalse(json.loads(out.getvalue())['network_used'])
        self.api.get_me.assert_not_called();self.bridge.request.assert_not_called()


if __name__=='__main__':unittest.main()
