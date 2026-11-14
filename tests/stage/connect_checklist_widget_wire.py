"""Native Moodle bridge with an isolated Bot API fixture and the real polling receiver."""
import io
from email import policy
from email.parser import BytesParser
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))
from connect_broadcast_http import Loopback
from ustar_connect.bridge import BridgeClient, PATH
from ustar_connect.receiver import TelegramReceiver
from ustar_connect.receiver_journal import Journal
from ustar_connect.telegram_api import TelegramClient


class TelegramFixture:
    chat = {'id': 88888, 'type': 'private'}
    employee = {'id': 88888, 'is_bot': False}
    bot = {'id': 123456, 'is_bot': True, 'username': 'ustarcorp_bot'}

    def __init__(self):
        self.next = [{'update_id': 900, 'message': {'message_id': 60, 'date': int(time.time()),
            'chat': self.chat, 'from': self.employee, 'text': 'Чек-листы'}}]
        self.sent = 0
        self.answers = []

    def open(self, request, timeout):
        method = request.full_url.rsplit('/', 1)[-1]
        if method == 'sendPhoto':
            header = ('Content-Type: ' + request.headers['Content-type'] + '\r\n\r\n').encode()
            parts = BytesParser(policy=policy.default).parsebytes(header + request.data)
            fields = {part.get_param('name', header='Content-Disposition'): part.get_content()
                      for part in parts.iter_parts() if not part.get_filename()}
            body = {key: json.loads(fields[key]) for key in ('chat_id', 'reply_markup')}
        else:
            body = json.loads(request.data)
        if method == 'getMe':
            result = self.bot
        elif method == 'getWebhookInfo':
            result = {'url': ''}
        elif method == 'getUpdates':
            assert 'widget_query' in body['allowed_updates']
            result, self.next = self.next, []
        elif method in ('sendMessage', 'sendPhoto'):
            # The UI can use its text or visual renderer for the checklist list.
            assert body['chat_id'] == self.chat['id']
            keyboard = body['reply_markup']['inline_keyboard']
            callback = keyboard[0][0]['callback_data']
            self.next.append({'update_id': 901, 'callback_query': {
                'id': '1', 'from': self.employee, 'data': callback,
                'message': {'message_id': 61, 'date': int(time.time()), 'chat': self.chat, 'from': self.bot},
            }})
            result = {'message_id': 61, 'chat': self.chat}
        elif method == 'answerCallbackQuery':
            result = True
        elif method == 'sendWidget':
            self.sent += 1
            model = json.loads(body['chat_data'])
            assert model['items'] == ['Проверить кассу', 'Проверить полки']
            assert body['protect_content'] and body['allow_queries'] and 'public_data' not in body
            query = {'id': '2', 'from': self.employee, 'chat': self.chat, 'message_id': 62, 'chat_instance': '10',
                     'type': {'type': 'data'}, 'data': model['action'] + ':10'}
            self.next.extend([{'update_id': 902, 'widget_query': {**query, 'id': '3', 'message_id': 63}},
                              {'update_id': 903, 'widget_query': query},
                              {'update_id': 904, 'widget_query': {**query, 'id': '4'}}])
            result = {'message_id': 62, 'chat': self.chat, 'widget': {}}
        elif method == 'answerWidgetQuery':
            self.answers.append(json.loads(body['data']))
            result = True
        elif method == 'editMessageWidgetData':
            assert body['chat_id'] == self.chat['id'] and body['message_id'] == 62
            assert json.loads(body['chat_data']) == {'status': 'saved', 'done': 1, 'total': 2}
            result = True
        else:
            raise AssertionError(method)
        return io.BytesIO(json.dumps({'ok': True, 'result': result}).encode())


def main():
    bridge = BridgeClient('https://localhost' + PATH, 'tg_widget_fixture', 'cd' * 32)
    bridge._opener = Loopback()
    api = TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
    api._opener = provider = TelegramFixture()
    with tempfile.TemporaryDirectory() as directory:
        journal = Journal(directory, '123456')
        try:
            receiver = TelegramReceiver(api, None, journal, bot_id='123456', bot_username='ustarcorp_bot',
                role_bot_enabled=True, bot_bridge=bridge, bot_academy_origin='https://ustar.local')
            for expected in (1, 1, 3):
                assert receiver.step() == {'status': 'polled', 'processed': expected}
            assert journal.health()['next_offset'] == 905
        finally:
            journal.close()
    saved = {'status': 'saved', 'done': 1, 'total': 2}
    assert provider.sent == 1 and provider.answers == [{'status': 'stale'}, saved, saved]
    subprocess.run(['php', '/source/tests/stage/connect_broadcast_http_fixture.php', 'verify-widget'], check=True)


if __name__ == '__main__':
    main()
