"""Notification send boundary: no live credentials, provider or Moodle traffic."""
import importlib
import io
import json
from pathlib import Path
import sys
import unittest
import tempfile
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
        self.assertIn('Задачи',payload['text']);self.assertNotIn('персонального входа',payload['text'])
        self.assertEqual('https://ustar.local/local/ustar/tasks.php',
            payload['reply_markup']['inline_keyboard'][0][0]['url'])
        self.assertIn('UTC',payload['text']);self.assertNotIn('http',payload['text'])
        self.assertNotIn('parse_mode',payload)
        with self.assertRaises(ValueError):api.send_notification(77777,'unknown','normal',NOW)

    def test_worker_pilot_fence_prevents_sends_to_other_subjects(self):
        self.assertIn('allowed_subjects',__import__('inspect').signature(self.module.NotificationWorker).parameters)
        worker=self.module.NotificationWorker(self.api,self.bridge,bot_id='123456',
            bot_username='synthetic_academy_bot',clock=lambda:NOW,allowed_subjects=('88888',))
        self.replies(self.lease,self.permit,{'status':'failed'})
        self.assertEqual({'status':'failed'},worker.step())
        self.api.send_notification.assert_not_called()
        self.assertEqual('forbidden',self.bridge.request.call_args.args[1]['reason'])

    def test_worker_pilot_allows_only_explicit_valid_private_subjects(self):
        self.assertIn('allowed_subjects',__import__('inspect').signature(self.module.NotificationWorker).parameters)
        for subjects in ([],['0'],[77777],[{}],['77777','77777'],'77777'):
            with self.assertRaises(ValueError):self.module.NotificationWorker(self.api,self.bridge,bot_id='123456',
                bot_username='synthetic_academy_bot',allowed_subjects=subjects)
        worker=self.module.NotificationWorker(self.api,self.bridge,bot_id='123456',
            bot_username='synthetic_academy_bot',clock=lambda:NOW,allowed_subjects=('77777',))
        self.assertEqual({'status':'provider_accepted'},worker.step())

    def test_plain_start_copy_does_not_claim_user_is_unlinked(self):
        from ustar_connect.telegram_api import TelegramClient
        api=TelegramClient('123456:synthetic-test-credential-not-a-real-bot');api._send=Mock(return_value={})
        api.send_link_notice(77777,'instructions')
        text=api._send.call_args.args[1]['text']
        self.assertIn('повторять привязку не нужно',text)
        self.assertIn('статус связи',text.lower())

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

    def test_startup_unauthorized_stops_and_rate_limit_waits(self):
        from ustar_connect.telegram_api import TelegramError
        self.api.get_me.side_effect=TelegramError('rate_limited',retry_after=120)
        self.assertEqual({'status':'backoff','retry_after':120},self.worker.step())
        self.worker.step();self.assertEqual(1,self.api.get_me.call_count)
        self.worker.clock=lambda:NOW+121
        self.api.get_me.side_effect=TelegramError('unauthorized')
        self.assertEqual({'status':'stopped','reason':'unauthorized'},self.worker.step())


class PilotConfiguration(unittest.TestCase):
    def test_explicit_notification_config_loads_bridge_with_mini_app_and_linking_off(self):
        runtime=importlib.import_module('ustar_connect.receiver_runtime')
        self.assertIn('notification',__import__('inspect').signature(runtime.load_config).parameters)
        with tempfile.TemporaryDirectory() as td:
            folder=Path(td);token=folder/'token';key=folder/'key';config=folder/'notifications.json'
            token.write_text('123456:synthetic-test-credential-not-a-real-bot');token.chmod(0o600)
            key.write_text('12'*32);key.chmod(0o600)
            value={'bot_token_file':str(token),'bot_username':'synthetic_academy_bot',
                'state_directory':td,'telegram_proxy':None,'onboarding_enabled':False,'linking_enabled':False,
                'bridge_endpoint':'https://localhost/local/ustar/connect_bridge.php','bridge_key_id':'tg_notify_pilot_v1',
                'bridge_key_file':str(key),'bridge_ca_file':None,'notification_subjects':['77777']}
            def save():config.write_text(json.dumps(value));config.chmod(0o600)
            save();parsed=runtime.load_config(config,notification=True)
            self.assertIsNotNone(parsed.bridge);self.assertEqual(('77777',),parsed.notification_subjects)
            with self.assertRaises(ValueError):runtime.load_config(config)
            for bad in (None,[],[77777],['0'],['77777','88888']):
                value['notification_subjects']=bad;save()
                with self.assertRaises(ValueError):runtime.load_config(config,notification=True)

    def test_probe_verifies_identity_and_scope_without_polling_leasing_or_sending(self):
        runtime=importlib.import_module('ustar_connect.notification_runtime')
        config=Mock();config.notification_subjects=('77777',);config.bot_id='123456';config.bot_username='synthetic_academy_bot'
        config.api.get_me.return_value={'id':123456,'is_bot':True,'username':'synthetic_academy_bot'}
        config.api.get_webhook_info.return_value={'url':''}
        with patch.object(runtime,'load_config',return_value=config),patch('sys.stdout',new_callable=io.StringIO):
            self.assertEqual(0,runtime.main(['--config','/synthetic/private.json','--probe']))
        config.bridge.probe_notification_scope.assert_called_once()
        config.bridge.request.assert_not_called();config.api.get_updates.assert_not_called()
        config.api.send_notification.assert_not_called()

    def test_scope_probe_waits_for_config_handoff_without_leasing_or_sending(self):
        runtime=importlib.import_module('ustar_connect.notification_runtime')
        config=Mock();config.notification_subjects=('77777',);config.bot_id='123456';config.bot_username='synthetic_academy_bot'
        config.api.get_me.return_value={'id':123456,'is_bot':True,'username':'synthetic_academy_bot'}
        config.api.get_webhook_info.return_value={'url':''}
        config.bridge.probe_notification_scope.side_effect=[ValueError('bridge_scope_unverified'),None]
        with patch.object(runtime,'load_config',return_value=config),patch('time.sleep'),patch('sys.stdout',new_callable=io.StringIO):
            self.assertEqual(0,runtime.main(['--config','/synthetic/private.json','--probe']))
        self.assertEqual(2,config.bridge.probe_notification_scope.call_count)
        config.bridge.request.assert_not_called();config.api.send_notification.assert_not_called()


if __name__=='__main__':unittest.main()

class VersionTwoNotifications(unittest.TestCase):
    replies = NotificationTests.replies

    def setUp(self):
        NotificationTests.setUp(self)
        self.action = {'action': 'ua1_'+'A'*43, 'expires': NOW+3600}
        self.v2 = {**self.permit, 'version': 2, 'ack': self.action}

    def v2_worker(self):
        import inspect
        self.assertIn('notification_contract', inspect.signature(self.module.NotificationWorker).parameters)
        return self.module.NotificationWorker(self.api, self.bridge, bot_id='123456',
            bot_username='synthetic_academy_bot', clock=lambda: NOW, notification_contract=2)

    def test_v2_requires_exact_permit_and_fresh_action(self):
        worker = self.v2_worker(); self.replies(self.lease, self.v2, {'status':'provider_accepted'})
        self.assertEqual('provider_accepted', worker.step()['status'])
        self.assertEqual('notification.begin_v2', self.bridge.request.call_args_list[1].args[0])
        self.assertEqual(self.action, self.api.send_notification.call_args.kwargs['ack'])
        self.assertEqual('notification.finish', self.bridge.request.call_args_list[2].args[0])

    def test_null_ack_keeps_url_and_never_synthesizes_token(self):
        worker = self.v2_worker(); self.replies(self.lease, {**self.v2,'ack':None}, {'status':'provider_accepted'})
        self.assertEqual('provider_accepted', worker.step()['status'])
        self.assertIsNone(self.api.send_notification.call_args.kwargs['ack'])

    def test_v2_bad_begin_never_downgrades_or_sends(self):
        bads = [self.permit, {**self.v2,'version':True}, {**self.v2,'userid':13},
                {**self.v2,'ack':{}}, {**self.v2,'ack':{**self.action,'expires':NOW}},
                {**self.v2,'ack':{**self.action,'expires':NOW+86401}},
                {**self.v2,'ack':{**self.action,'expires':True}},
                {**self.v2,'ack':{**self.action,'action':'ua1_'+'B'*43}},
                {**self.v2,'ack':{**self.action,'body':'private'}}]
        for bad in bads:
            with self.subTest(bad=bad):
                self.api.send_notification.reset_mock(); self.bridge.request.reset_mock()
                self.replies(self.lease,bad)
                self.assertEqual('unavailable',self.v2_worker().step()['status'])
                self.api.send_notification.assert_not_called()
                self.assertNotIn('notification.begin',[c.args[0] for c in self.bridge.request.call_args_list])

    def test_v2_timeout_is_uncertain_without_resend(self):
        from ustar_connect.telegram_api import TelegramError
        self.api.send_notification.side_effect=TelegramError('transport_unavailable',uncertain=True)
        worker=self.v2_worker();self.replies(self.lease,self.v2,{'status':'uncertain'})
        self.assertEqual('uncertain',worker.step()['status'])
        self.assertEqual(1,self.api.send_notification.call_count)
        self.assertEqual('uncertain',self.bridge.request.call_args.args[1]['outcome'])

    def test_v1_does_not_negotiate_v2_and_contract_is_strict(self):
        self.v2_worker()
        self.replies(self.lease,self.v2)
        self.assertEqual('unavailable',self.worker.step()['status'])
        self.api.send_notification.assert_not_called()
        for invalid in (True,False,0,4,'2',None):
            with self.assertRaises(ValueError):
                self.module.NotificationWorker(self.api,self.bridge,bot_id='123456',
                    bot_username='synthetic_academy_bot',notification_contract=invalid)
