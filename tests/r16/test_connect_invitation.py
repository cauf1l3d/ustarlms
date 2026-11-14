from pathlib import Path
import sys
import unittest
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))
from ustar_connect.bot_handler import BotHandler, parse_update

CHAT = {'id': 55, 'type': 'private'}


class InvitationTransportTest(unittest.TestCase):
    def test_private_start_preserves_invitation_without_changing_normal_start(self):
        token = 'ui1_' + 'A' * 43
        message = {'message_id': 21, 'date': 1000, 'text': '/start ' + token,
                   'chat': CHAT, 'from': {'id': 55, 'is_bot': False}}
        parse = lambda msg: parse_update({'update_id': 11, 'message': msg},
                                         bot_id='123456', username='ustarcorp_bot', now=1000)
        self.assertEqual('/start ' + token, parse(message).payload['text'])
        self.assertEqual('/start', parse({**message, 'text': '/start'}).payload['text'])
        self.assertIsNone(parse({**message, 'forward_origin': {}}))
        self.assertIsNone(parse({**message, 'chat': {'id': 55, 'type': 'group'}}))
        self.assertIsNone(parse({**message, 'text': '/start ' + 'uc1_' + 'A' * 43}))

    def test_policy_is_rendered_as_full_text(self):
        api, bridge, journal = Mock(), Mock(), Mock()
        bridge.request.return_value = {'ok': True, 'result': {
            'title': 'Agreement', 'text': 'A full page of terms', 'buttons': [],
            'metrics': [], 'rows': [], 'plain': True}}
        api.send_bot_view.return_value = {'chat': CHAT, 'message_id': 22}
        message = {'message_id': 21, 'date': 1000, 'text': '/start',
                   'chat': CHAT, 'from': {'id': 55, 'is_bot': False}}
        event = parse_update({'update_id': 11, 'message': message},
                             bot_id='123456', username='ustarcorp_bot', now=1000)
        BotHandler(api, bridge, journal, tenant='123456', academy_origin='https://ustar.local').handle(event)
        sent = api.send_bot_view.call_args.args[1]
        self.assertIn('A full page of terms', sent['text'])
        self.assertNotIn('photo', sent)
