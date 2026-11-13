"""Personal checklist widgets use the existing scoped receiver and never call the real provider."""
from copy import deepcopy
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock
import zlib

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))

from ustar_connect.bot_handler import BotHandler, parse_update
from ustar_connect.telegram_api import TelegramClient, TelegramError


TOKEN = 'cb4_' + 'A' * 43
CHAT = {'id': 55, 'type': 'private'}
MESSAGE = {'message_id': 22, 'chat': CHAT, 'widget': {}}
VIEW = {'title': 'Контроль зала', 'text': '', 'metrics': [], 'rows': [],
        'buttons': [{'label': 'Пошаговая форма', 'callback': TOKEN}],
        'widget': {'action': TOKEN, 'items': ['Проверить кассу', 'Проверить полки']}}
QUERY = {'update_id': 12, 'widget_query': {'id': '9876', 'from': {'id': 55, 'is_bot': False},
    'chat': CHAT, 'message_id': 22, 'chat_instance': '10001', 'type': {'type': 'data'}, 'data': TOKEN + ':10'}}


class ChecklistWidgetTest(unittest.TestCase):
    def setUp(self):
        self.api = Mock()
        self.bridge = Mock()
        self.journal = Mock()
        self.handler = BotHandler(self.api, self.bridge, self.journal, tenant='123456',
                                  academy_origin='https://ustar.local', clock=lambda: 1000)
        self.event = parse_update({'update_id': 11, 'message': {'message_id': 21, 'date': 1000,
            'text': 'Чек-листы', 'chat': CHAT, 'from': {'id': 55, 'is_bot': False}}},
            bot_id='123456', username='ustarcorp_bot', now=1000)

    def test_widget_api_uses_private_markup_and_accepts_true_receipt(self):
        from ustar_connect.bot_widget import HTML
        api = TelegramClient('123456:' + 'x' * 30)
        responses = iter(([], MESSAGE, True, True))
        requests = []

        def opened(request, timeout):
            requests.append((request.full_url.rsplit('/', 1)[1], json.loads(request.data), timeout))
            return io.BytesIO(json.dumps({'ok': True, 'result': next(responses)}).encode())

        api._opener = Mock()
        api._opener.open.side_effect = opened
        api.get_updates(1, acknowledgements_enabled=True, widgets_enabled=True)
        api.send_checklist_widget(55, VIEW['title'], VIEW['widget'], {'inline_keyboard': []})
        self.assertTrue(api.answer_widget_query('9876', {'status': 'saved', 'done': 1, 'total': 2}))
        api.complete_checklist_widget(55, 22, {'status': 'saved', 'done': 1, 'total': 2})
        self.assertEqual(['message', 'callback_query', 'widget_query'], requests[0][1]['allowed_updates'])
        self.assertEqual('sendWidget', requests[1][0])
        self.assertTrue(requests[1][1]['allow_queries'])
        self.assertTrue(requests[1][1]['protect_content'])
        self.assertNotIn('public_data', requests[1][1])
        self.assertEqual(VIEW['widget'], json.loads(requests[1][1]['chat_data']))
        self.assertLessEqual(len(zlib.compress(HTML.encode(), 9)), 4096)
        self.assertEqual(('answerWidgetQuery', 'editMessageWidgetData'), (requests[2][0], requests[3][0]))
        self.assertLessEqual(requests[2][2], 3)
        self.assertEqual(22, requests[3][1]['message_id'])

    def test_only_private_data_queries_with_complete_transport_fields_are_parsed(self):
        from ustar_connect.bot_widget import parse_widget_query
        self.assertEqual('10', parse_widget_query(QUERY).answers)
        for change in ({'chat': {'id': 56, 'type': 'private'}}, {'chat': {'id': 55, 'type': 'group'}},
                       {'from': {'id': 55, 'is_bot': True}}, {'type': {'type': 'sign_data'}},
                       {'type': {'type': 'data', 'wallet_card': {}}}, {'widget_id': '1'},
                       {'inline_message_id': '1'}, {'data': TOKEN + ':101001'}, {'message_id': True}):
            update = deepcopy(QUERY)
            update['widget_query'].update(change)
            with self.subTest(change=change):
                self.assertIsNone(parse_widget_query(update))
        self.assertIsNone(parse_widget_query({'update_id': 12, 'shared_widget_query': QUERY['widget_query']}))

    def test_role_bot_sends_widget_and_binds_actual_provider_message(self):
        self.bridge.request.side_effect = [{'ok': True, 'result': VIEW}, {'ok': True, 'result': {'status': 'bound'}}]
        self.api.send_checklist_widget.return_value = MESSAGE
        self.handler.handle(self.event)
        self.api.send_checklist_widget.assert_called_once()
        operation, payload = self.bridge.request.call_args.args
        self.assertEqual('bot.update', operation)
        self.assertEqual(('bind', '22', TOKEN), (payload['widget'], payload['message'], payload['callback']))
        self.api.send_bot_view.assert_not_called()
        self.journal.finish.assert_called_once_with(11, 'sent', '', 22)

    def test_rejected_widget_uses_existing_view_and_uncertain_send_does_not_retry(self):
        for uncertain in (False, True):
            self.bridge.reset_mock(side_effect=True)
            self.api.reset_mock(side_effect=True)
            self.bridge.request.return_value = {'ok': True, 'result': VIEW}
            self.api.send_checklist_widget.side_effect = TelegramError(
                'transport_unavailable' if uncertain else 'api_rejected', uncertain=uncertain)
            self.api.send_bot_view.return_value = MESSAGE
            self.handler.handle(self.event)
            self.assertEqual(0 if uncertain else 1, self.api.send_bot_view.call_count)
            self.assertEqual(1, self.bridge.request.call_count)

    def test_standard_form_callback_from_a_widget_sends_a_regular_message(self):
        event = parse_update({'update_id': 11, 'callback_query': {
            'id': '123', 'data': TOKEN, 'from': {'id': 55, 'is_bot': False},
            'message': {**MESSAGE, 'from': {'id': 123456, 'is_bot': True}, 'date': 1000}}},
            bot_id='123456', username='ustarcorp_bot', now=1000)
        self.bridge.request.return_value = {'ok': True, 'result': {k: v for k, v in VIEW.items() if k != 'widget'}}
        self.api.send_bot_view.return_value = MESSAGE
        self.handler.handle(event)
        self.api.send_bot_view.assert_called_once()
        self.api.edit_bot_view.assert_not_called()

    def test_query_is_answered_before_updating_widget_and_transport_replay_is_ignored(self):
        from ustar_connect.bot_widget import parse_widget_query
        query = parse_widget_query(QUERY)
        self.bridge.request.return_value = {'ok': True, 'result': {'status': 'saved', 'done': 1, 'total': 2}}
        self.api.answer_widget_query.return_value = True
        self.api.complete_checklist_widget.return_value = True
        self.journal.begin.side_effect = [True, False]
        self.handler.handle_widget(query)
        self.handler.handle_widget(query)
        operation, payload = self.bridge.request.call_args.args
        self.assertEqual(('bot.update', '10', '22'), (operation, payload['widget'], payload['message']))
        self.assertEqual(5, self.bridge.request.call_args.kwargs['timeout'])
        self.bridge.request.assert_called_once()
        self.assertEqual(['answer_widget_query', 'complete_checklist_widget'],
                         [item[0] for item in self.api.method_calls])
        self.journal.finish.assert_called_once_with(12, 'sent', '', 22)

    def test_confirmed_save_survives_expired_provider_answer(self):
        from ustar_connect.bot_widget import parse_widget_query
        self.bridge.request.return_value = {'ok': True, 'result': {'status': 'saved', 'done': 1, 'total': 2}}
        self.api.answer_widget_query.side_effect = TelegramError('api_rejected')
        self.api.complete_checklist_widget.return_value = True
        self.handler.handle_widget(parse_widget_query(QUERY))
        self.api.complete_checklist_widget.assert_called_once()
        self.journal.finish.assert_called_once_with(12, 'sent', 'widget_answer_unconfirmed', 22)
