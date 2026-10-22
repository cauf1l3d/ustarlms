"""Opt-in credentials are private and separate; CLI validation cannot poll/send/write ACK."""
import importlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch,Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'connect-worker'))


class AckConfiguration(unittest.TestCase):
    def setUp(self):
        self.module=importlib.import_module('ustar_connect.receiver_runtime')
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.config=self.root/'receiver.json'
        for name,text in [('token','123456:synthetic-test-credential-not-a-real-bot'),('link','a'*64),('ack','b'*64)]:
            path=self.root/name;path.write_text(text);path.chmod(0o600)
        self.value={'bot_token_file':str(self.root/'token'),'bot_username':'ustarcorp_bot',
            'state_directory':str(self.root),'telegram_proxy':None,'onboarding_enabled':False,
            'bridge_endpoint':'https://127.0.0.1:18443/local/ustar/connect_bridge.php',
            'bridge_key_id':'tg_link','bridge_key_file':str(self.root/'link'),'bridge_ca_file':None,'linking_enabled':True}

    def load(self,value=None,notification=False):
        self.config.write_text(json.dumps(self.value if value is None else value));self.config.chmod(0o600)
        return self.module.load_config(self.config,notification=notification)

    def enabled(self):
        return {**self.value,'acknowledgements_enabled':True,'ack_bridge_key_id':'tg_ack','ack_bridge_key_file':str(self.root/'ack')}

    def test_old_profiles_load_with_network_unused(self):
        parsed=self.load();self.assertTrue(hasattr(parsed,'acknowledgements_enabled'),'ACK configuration flag missing')
        self.assertFalse(parsed.acknowledgements_enabled);self.assertIsNone(parsed.ack_bridge)
        with patch.object(self.module,'TelegramClient') as api,patch('sys.stdout',new_callable=io.StringIO):
            self.assertEqual(0,self.module.main(['--config',str(self.config)]))
            api.return_value.get_updates.assert_not_called();api.return_value.get_me.assert_not_called()

    def test_ack_requires_distinct_private_key_pair(self):
        value=self.enabled();parsed=self.load(value)
        self.assertTrue(parsed.acknowledgements_enabled);self.assertIsNotNone(parsed.ack_bridge)
        self.assertIsNot(parsed.bridge,parsed.ack_bridge)
        for bad in ({**value,'ack_bridge_key_id':'tg_link'},
                    {k:v for k,v in value.items() if k!='ack_bridge_key_file'},
                    {**value,'ack_bridge_key_file':str(self.root/'link')},
                    {**value,'acknowledgements_enabled':1},
                    {**value,'acknowledgements_enabled':False},
                    {**value,'unknown':'x'}):
            with self.assertRaises(ValueError):self.load(bad)
        (self.root/'ack').chmod(0o644)
        with self.assertRaises(ValueError):self.load(value)

    def test_sender_never_loads_ack_key_and_contract_is_explicit(self):
        value={**self.value,'notification_subjects':['77777'],'linking_enabled':False,'notification_contract':2}
        parsed=self.load(value,notification=True)
        self.assertEqual(2,parsed.notification_contract);self.assertIsNone(parsed.ack_bridge)
        for bad in ({**value,'notification_contract':True},{**value,'notification_contract':3},
                    {**value,'acknowledgements_enabled':False},{**value,'ack_bridge_key_file':str(self.root/'ack')}):
            with self.assertRaises(ValueError):self.load(bad,notification=True)

    def test_ack_only_profile_does_not_require_unused_link_secret(self):
        value={**self.enabled(),'linking_enabled':False,'bridge_key_file':'/synthetic/unused-link-key'}
        parsed=self.load(value);self.assertIsNone(parsed.bridge);self.assertIsNotNone(parsed.ack_bridge)

    def test_probe_does_not_poll_lease_send_or_ack(self):
        parsed=self.load(self.enabled());parsed.api=Mock();parsed.bridge=Mock();parsed.ack_bridge=Mock()
        parsed.api.get_me.return_value={'id':123456,'is_bot':True,'username':'ustarcorp_bot'}
        parsed.api.get_webhook_info.return_value={'url':''}
        with patch.object(self.module,'load_config',return_value=parsed),patch('sys.stdout',new_callable=io.StringIO):
            self.assertEqual(0,self.module.main(['--config','/synthetic/config','--probe']))
        parsed.ack_bridge.probe_ack_scope.assert_called_once();parsed.ack_bridge.request.assert_not_called()
        parsed.api.get_updates.assert_not_called();parsed.api.send_notification.assert_not_called()


if __name__=='__main__':unittest.main()
