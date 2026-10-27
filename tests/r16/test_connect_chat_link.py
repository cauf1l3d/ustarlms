"""Private chat challenge claims: real SQLite, synthetic Telegram and bridge."""
import hashlib
import importlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'connect-worker'))
import test_connect_receiver as fixtures
from ustar_connect.bridge import BridgeRejected

CODE = 'uc1_' + 'A' * 43


class ChatReceiver(unittest.TestCase):
    def setUp(self):
        self.receiver_module = importlib.import_module('ustar_connect.receiver')
        self.journal_module = importlib.import_module('ustar_connect.receiver_journal')
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.journal = self.journal_module.Journal(self.directory, '123456', clock=lambda: fixtures.NOW)
        self.addCleanup(lambda: self.journal.close())
        self.api, self.bridge = fixtures.FakeApi(), Mock()
        self.api.me['has_main_web_app'] = False
        self.notices = []
        def notice(subject, outcome):
            self.notices.append((subject, outcome))
            return {'message_id': 700, 'chat': {'id': subject, 'type': 'private'}}
        self.api.send_link_notice = notice
        self.bridge.request.return_value = {'ok': True, 'result': {
            'claimed': True, 'academy_confirmation_required': True}}

    def receiver(self, enabled=True):
        return self.receiver_module.TelegramReceiver(self.api, self.bridge, self.journal,
            bot_id='123456', bot_username=fixtures.USERNAME, onboarding_enabled=False,
            linking_enabled=enabled, clock=lambda: fixtures.NOW)

    def test_private_start_code_claims_only_then_requires_native_confirmation(self):
        self.api.updates = [fixtures.update(text='/start ' + CODE)]
        result = self.receiver().step()
        self.assertEqual('polled', result['status'])
        operation, payload = self.bridge.request.call_args.args
        self.assertEqual('link.claim', operation)
        self.assertEqual({'provider', 'tenant', 'subject', 'code', 'proof', 'auth_date'}, set(payload))
        self.assertEqual(('telegram', '123456', str(fixtures.USER), CODE, fixtures.NOW),
            tuple(payload[k] for k in ('provider', 'tenant', 'subject', 'code', 'auth_date')))
        proof = hashlib.sha256(('ustar-telegram-message-link-v1\0' + '123456:10:10:' + str(fixtures.USER)).encode()).hexdigest()
        self.assertEqual(proof, payload['proof'])
        self.assertEqual([(fixtures.USER, 'claimed')], self.notices)
        self.assertEqual([], self.api.sent)
        self.assertEqual(11, self.journal.health()['next_offset'])

    def test_replay_restart_and_journal_do_not_retain_or_repeat_code(self):
        self.api.updates = [fixtures.update(text='/start ' + CODE)]
        self.receiver().step()
        self.journal.close()
        self.journal = self.journal_module.Journal(self.directory, '123456', clock=lambda: fixtures.NOW)
        self.receiver().step()
        self.assertEqual(1, self.bridge.request.call_count)
        self.assertEqual(1, len(self.notices))
        for path in self.directory.iterdir():
            if path.is_file():
                self.assertNotIn(CODE.encode(), path.read_bytes())

    def test_forward_group_stale_other_bot_and_malformed_codes_do_not_claim(self):
        variants = [fixtures.update(10, '/start ' + CODE, forward_origin={}),
            fixtures.update(11, '/start ' + CODE, chat={'id': fixtures.USER, 'type': 'group'}),
            fixtures.update(12, '/start ' + CODE, date=fixtures.NOW - 121),
            fixtures.update(13, '/start@other_bot ' + CODE),
            fixtures.update(14, '/start uc1_' + 'B' * 43),
            fixtures.update(15, '/start ' + CODE + ' extra'),
            fixtures.update(16, '/academy ' + CODE),
            fixtures.update(17, '/start ' + CODE, message_id=True),
            fixtures.update(18, '/start ' + CODE, entities=[])]
        self.api.updates = variants
        self.receiver().step()
        self.bridge.request.assert_not_called()
        self.assertEqual([], self.notices)

    def test_addressed_start_and_plain_help_do_not_require_main_app(self):
        self.api.updates = [fixtures.update(10, '/start@' + fixtures.USERNAME + ' ' + CODE),
                            fixtures.update(11, '/help')]
        self.receiver().step()
        self.assertEqual(1, self.bridge.request.call_count)
        self.assertEqual([(fixtures.USER, 'claimed'), (fixtures.USER, 'instructions')], self.notices)

    def test_disabled_link_flag_never_loads_claim_even_with_valid_code(self):
        self.api.updates = [fixtures.update(text='/start ' + CODE)]
        self.receiver(False).step()
        self.bridge.request.assert_not_called()
        self.assertEqual([], self.notices)

    def test_bridge_timeout_is_uncertain_and_never_automatically_claims_again(self):
        self.bridge.request.side_effect = ValueError('PRIVATE_SENTINEL ' + CODE)
        self.api.updates = [fixtures.update(text='/start ' + CODE)]
        receiver = self.receiver()
        receiver.step(); receiver.step()
        self.assertEqual(1, self.bridge.request.call_count)
        self.assertEqual({'uncertain': 1}, self.journal.health()['states'])
        self.assertEqual([(fixtures.USER, 'uncertain')], self.notices)

    def test_fixed_bridge_rejection_is_readable_without_exposing_reason_or_identity(self):
        self.bridge.request.side_effect = BridgeRejected('claimed_challenge')
        self.api.updates = [fixtures.update(text='/start ' + CODE)]
        self.receiver().step()
        self.assertEqual({'rejected': 1}, self.journal.health()['states'])
        self.assertEqual([(fixtures.USER, 'rejected')], self.notices)

    def test_malformed_bridge_success_never_says_claimed(self):
        self.bridge.request.return_value = {'ok': True, 'result': {'claimed': True}}
        self.api.updates = [fixtures.update(text='/start ' + CODE)]
        self.receiver().step()
        self.assertEqual([(fixtures.USER, 'uncertain')], self.notices)
        self.assertEqual({'uncertain': 1}, self.journal.health()['states'])

    def test_link_rate_limit_is_durable_and_separate_from_help(self):
        self.api.updates = [fixtures.update(i, '/start ' + CODE) for i in range(10, 14)]
        self.receiver().step()
        self.assertEqual(3, self.bridge.request.call_count)
        self.assertEqual(1, self.journal.health()['states']['ignored'])


class ChatTransport(unittest.TestCase):
    def test_claim_notice_contains_real_numeric_subject_and_no_code_or_login_grant(self):
        module = importlib.import_module('ustar_connect.telegram_api')
        api = module.TelegramClient(fixtures.TOKEN)
        api._call = Mock(return_value={})
        api.send_link_notice(fixtures.USER, 'claimed')
        method, value = api._call.call_args.args
        self.assertEqual('sendMessage', method)
        self.assertEqual(fixtures.USER, value['chat_id'])
        self.assertTrue(value['protect_content'])
        self.assertIn(str(fixtures.USER), value['text'])
        self.assertIn('ещё не завершена', value['text'])
        self.assertNotIn(CODE, value['text'])
        self.assertNotIn('reply_markup', value)

    def test_link_notice_outcome_is_allowlisted(self):
        api = importlib.import_module('ustar_connect.telegram_api').TelegramClient(fixtures.TOKEN)
        api._call = Mock(return_value={})
        for outcome in ('instructions', 'rejected', 'uncertain'):
            api.send_link_notice(fixtures.USER, outcome)
        with self.assertRaises(ValueError):
            api.send_link_notice(fixtures.USER, 'PRIVATE_SENTINEL')
        self.assertEqual(3, api._call.call_count)


class ChatRuntime(unittest.TestCase):
    def setUp(self):
        self.fixture = fixtures.RuntimeConfigTests()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)

    def test_link_only_configuration_loads_bridge_without_enabling_mini_app(self):
        f = self.fixture
        f.config['linking_enabled'] = True; f.save()
        with patch.object(f.module, 'BridgeClient') as factory:
            config = f.module.load_config(f.path)
        self.assertTrue(config.linking_enabled)
        self.assertFalse(config.onboarding_enabled)
        factory.assert_called_once()

    def test_legacy_config_defaults_link_off_and_non_boolean_flags_fail(self):
        f = self.fixture
        self.assertFalse(f.module.load_config(f.path).linking_enabled)
        for flag in ('true', 1, None):
            f.config['linking_enabled'] = flag; f.save()
            with self.assertRaises(ValueError):
                f.module.load_config(f.path)

    def test_linking_still_requires_https_bridge(self):
        f = self.fixture
        f.config.update(linking_enabled=True, bridge_endpoint='http://localhost/local/ustar/connect_bridge.php'); f.save()
        with self.assertRaises(ValueError):
            f.module.load_config(f.path)

    def test_link_only_probe_has_no_main_app_requirement_or_journal_writes(self):
        f = self.fixture
        f.config['linking_enabled'] = True; f.save()
        api = fixtures.FakeApi(); api.me['has_main_web_app'] = False
        with patch.object(f.module, 'TelegramClient', return_value=api), patch.object(f.module, 'BridgeClient') as bridge, \
                patch.object(f.module, 'Journal') as journal, patch('sys.stdout', new_callable=io.StringIO) as output:
            self.assertEqual(0, f.module.main(['--config', str(f.path), '--probe']))
        self.assertTrue(json.loads(output.getvalue())['linking_enabled'])
        self.assertFalse(json.loads(output.getvalue())['onboarding_enabled'])
        self.assertEqual([('getMe',), ('getWebhookInfo',)], api.calls)
        journal.assert_not_called(); bridge.return_value.request.assert_not_called()

    def test_link_flag_appears_in_local_only_CLI_validation(self):
        f = self.fixture
        f.config['linking_enabled'] = True; f.save()
        with patch.object(f.module, 'TelegramClient') as api, patch.object(f.module, 'BridgeClient'), \
                patch('sys.stdout', new_callable=io.StringIO) as output:
            self.assertEqual(0, f.module.main(['--config', str(f.path)]))
        self.assertTrue(json.loads(output.getvalue())['linking_enabled'])
        api.return_value.get_me.assert_not_called()
