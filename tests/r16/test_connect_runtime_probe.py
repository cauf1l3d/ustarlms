import importlib, io, json, sys, unittest
from pathlib import Path
from unittest.mock import patch, Mock
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'connect-worker'))

class RuntimeProbeTests(unittest.TestCase):
    def test_disabled_notice_is_explicit_and_has_no_launch_promise(self):
        api=importlib.import_module('ustar_connect.telegram_api').TelegramClient('123456:'+'a'*32)
        api._send=Mock(return_value={})
        api.send_notice(42,ready=False)
        text=api._send.call_args.args[1]['text']
        self.assertIn('Mini App пока не подключён',text)
        self.assertNotIn('Для персонального входа отправьте /start',text)
        self.assertNotIn('reply_markup',api._send.call_args.args[1])

    def test_probe_does_not_poll_send_open_journal_or_bridge(self):
        runtime=importlib.import_module('ustar_connect.receiver_runtime')
        config=Mock(bot_id='123456',bot_username='ustarcorp_bot',onboarding_enabled=False,linking_enabled=False)
        config.api.get_me.return_value={'id':123456,'username':'ustarcorp_bot','is_bot':True}
        config.api.get_webhook_info.return_value={'url':''}
        with patch.object(runtime,'load_config',return_value=config), patch.object(runtime,'Journal') as journal, patch('sys.stdout',new_callable=io.StringIO) as out:
            self.assertEqual(0,runtime.main(['--config','/synthetic','--probe']))
            value=json.loads(out.getvalue())
        self.assertEqual('telegram_verified',value['status'])
        self.assertFalse(value['updates_consumed'])
        journal.assert_not_called();config.bridge.request.assert_not_called()
        config.api.get_updates.assert_not_called();config.api.send_notice.assert_not_called()

    def test_probe_identity_mismatch_and_webhook_refuse_without_raw_response(self):
        runtime=importlib.import_module('ustar_connect.receiver_runtime')
        config=Mock(bot_id='123456',bot_username='ustarcorp_bot',onboarding_enabled=False,linking_enabled=False)
        for me,webhook,reason in [({'id':9,'username':'ustarcorp_bot','is_bot':True},{'url':''},'bot_identity_mismatch'),
            ({'id':123456,'username':'ustarcorp_bot','is_bot':True},{'url':'https://PRIVATE_CANARY.invalid'},'webhook_present')]:
            config.api.get_me.return_value=me;config.api.get_webhook_info.return_value=webhook
            with patch.object(runtime,'load_config',return_value=config),patch('sys.stdout',new_callable=io.StringIO) as out:
                self.assertEqual(3,runtime.main(['--config','/synthetic','--probe']))
                self.assertIn(reason,out.getvalue());self.assertNotIn('PRIVATE_CANARY',out.getvalue())
