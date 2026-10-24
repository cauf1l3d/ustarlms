"""Real narrow Telegram serialization; synthetic opener, no network/provider credentials."""
import io
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))
from ustar_connect.telegram_api import TelegramClient, TelegramError

NOW = 1800000000
ACTION = {'action': 'ua1_'+'A'*43, 'expires': NOW+3600}


class Response(io.BytesIO):
    def __enter__(self): return self
    def __exit__(self, *args): self.close()


class CallbackTransport(unittest.TestCase):
    def setUp(self):
        self.api = TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
        self.requests = []
        self.result = True
        def opening(request, timeout):
            self.requests.append((request.full_url.rsplit('/', 1)[-1], json.loads(request.data), timeout))
            return Response(json.dumps({'ok': True, 'result': self.result}).encode())
        self.api._opener = Mock(); self.api._opener.open.side_effect = opening

    def feature(self, name):
        self.assertTrue(hasattr(self.api, name), name+' is missing')

    def test_poll_filter_optin_and_original_defaults(self):
        import inspect
        self.assertIn('acknowledgements_enabled', inspect.signature(self.api.get_updates).parameters)
        self.result = []
        self.api.get_updates(50)
        self.assertEqual(['message'], self.requests[-1][1]['allowed_updates'])
        self.api.get_updates(51, acknowledgements_enabled=True)
        self.assertEqual(['message', 'callback_query'], self.requests[-1][1]['allowed_updates'])
        self.assertEqual(35, self.requests[-1][2])
        with self.assertRaises(ValueError): self.api.get_updates(1, acknowledgements_enabled=1)

    def test_callback_true_is_not_a_message_dict(self):
        self.feature('answer_notification_ack')
        self.assertIs(True, self.api.answer_notification_ack('query-1', 'acknowledged', NOW))
        method, payload, timeout = self.requests[-1]
        self.assertEqual('answerCallbackQuery', method); self.assertEqual(3, timeout)
        self.assertEqual(0, payload['cache_time']); self.assertIn('Подтверждено', payload['text'])
        self.assertIn('UTC', payload['text']); self.assertNotIn('url', payload)
        for invalid in ({}, [], 1, 'true', False, None):
            self.result = invalid
            with self.assertRaises(TelegramError): self.api.answer_notification_ack('q', 'acknowledged', NOW)

    def test_callback_outcomes_are_fixed_and_validate_query_time(self):
        self.feature('answer_notification_ack')
        self.api.answer_notification_ack('q', 'uncertain')
        self.assertIn('неизвестен', self.requests[-1][1]['text'])
        self.api.answer_notification_ack('q', 'unavailable')
        self.assertEqual('Не удалось подтвердить. Откройте Академию', self.requests[-1][1]['text'])
        for query in ('', 'a b', 'x\n', 'a'*257, 123, 'я'):
            with self.assertRaises(ValueError): self.api.answer_notification_ack(query, 'unavailable')
        for at in (True, 0, -1, 2**32, '1800000000'):
            with self.assertRaises(ValueError): self.api.answer_notification_ack('q', 'acknowledged', at)
        with self.assertRaises(ValueError): self.api.answer_notification_ack('q', 'untrusted text')

    def test_private_edit_requires_matching_message(self):
        self.feature('edit_notification_ack')
        self.result = {'message_id': 991, 'chat': {'type': 'private', 'id': 77777}}
        self.api.edit_notification_ack(77777, 991, 'tasks')
        method, payload, timeout = self.requests[-1]
        self.assertEqual('editMessageReplyMarkup', method); self.assertEqual(3, timeout)
        keyboard = payload['reply_markup']['inline_keyboard']
        self.assertEqual([[{'text':'Открыть в Академии','url':'https://ustar.local/local/ustar/tasks.php'}]], keyboard)
        for bad in (True, {}, {'message_id': True, 'chat': {'id':77777,'type':'private'}},
                    {'message_id':991,'chat':{'id':77778,'type':'private'}},
                    {'message_id':991,'chat':{'id':77777,'type':'group'}}):
            self.result=bad
            with self.assertRaises(TelegramError): self.api.edit_notification_ack(77777,991,'tasks')
        for uid, mid, category in ((True,991,'tasks'), (77777,True,'tasks'), (77777,991,'secret')):
            with self.assertRaises(ValueError): self.api.edit_notification_ack(uid,mid,category)

    def test_keyboard_keeps_fixed_url_and_hides_private_content(self):
        import inspect
        self.assertIn('ack', inspect.signature(self.api.send_notification).parameters)
        self.result={'message_id':991,'chat':{'id':77777,'type':'private'}}
        self.api.send_notification(77777,'tasks','action',NOW,ack=ACTION)
        method,payload,_=self.requests[-1]
        buttons=sum(payload['reply_markup']['inline_keyboard'],[])
        self.assertEqual('sendMessage',method)
        self.assertIn({'text':'Принял','callback_data':ACTION['action']},buttons)
        self.assertIn({'text':'Открыть в Академии','url':'https://ustar.local/local/ustar/tasks.php'},buttons)
        self.assertTrue(payload['protect_content']);self.assertNotIn(ACTION['action'],payload['text'])
        self.api.send_notification(77777,'tasks','action',NOW)
        self.assertNotIn('callback_data',json.dumps(self.requests[-1][1]))
        for bad in ({}, {**ACTION,'url':'https://evil.invalid'}, {**ACTION,'expires':True},
                    {**ACTION,'action':'ua1_'+'B'*43}, {'action':True,'expires':NOW+3600}):
            with self.assertRaises(ValueError): self.api.send_notification(77777,'tasks','action',NOW,ack=bad)

    def test_ui_methods_do_not_relax_existing_result_types(self):
        self.feature('answer_notification_ack')
        self.result=True
        for call in (self.api.get_me,self.api.get_webhook_info,lambda:self.api.get_updates(0)):
            with self.assertRaises(TelegramError): call()

    def test_success_keeps_visible_first_ack_time_with_only_fixed_Academy_links(self):
        # Losing the canonical timestamp or deleting all confirmation is the break.
        self.result = {'message_id': 991, 'chat': {'type': 'private', 'id': 77777}}
        self.assertIn('acknowledged_at', __import__('inspect').signature(self.api.edit_notification_ack).parameters)
        self.api.edit_notification_ack(77777, 991, 'tasks', acknowledged_at=NOW)
        method, payload, _ = self.requests[-1]
        self.assertEqual('editMessageReplyMarkup', method)
        buttons = sum(payload['reply_markup']['inline_keyboard'], [])
        self.assertTrue(any(b['text'].startswith('✓ Принято · ') and 'UTC' in b['text'] for b in buttons))
        self.assertEqual({'https://ustar.local/local/ustar/tasks.php'}, {b['url'] for b in buttons})
        self.assertFalse(any('callback_data' in b for b in buttons))
        first = buttons[0]['text']
        self.api.edit_notification_ack(77777, 991, 'tasks', acknowledged_at=NOW)
        self.assertEqual(first, self.requests[-1][1]['reply_markup']['inline_keyboard'][0][0]['text'])
        for bad in (True, 0, -1, '1800000000', 2**32):
            with self.assertRaises(ValueError):
                self.api.edit_notification_ack(77777, 991, 'tasks', acknowledged_at=bad)


if __name__=='__main__': unittest.main()
