"""Open and start a personal task over the signed Moodle bridge and the polling receiver."""
import io
import json
import subprocess
import tempfile
import time

from connect_checklist_widget_wire import BridgeClient, Journal, Loopback, PATH, TelegramClient, TelegramFixture
from ustar_connect.receiver import TelegramReceiver


class TaskFixture(TelegramFixture):
    def __init__(self):
        super().__init__()
        self.next = [{'update_id': 920, 'message': {'message_id': 70, 'date': int(time.time()),
            'chat': self.chat, 'from': self.employee, 'text': 'Мои задачи'}}]
        self.state = None
        self.edited = 0

    def open(self, request, timeout):
        method = request.full_url.rsplit('/', 1)[-1]
        if method in ('sendMessage', 'sendPhoto'):
            response = super().open(request, timeout)
            self.next[-1]['update_id'] = 921
            return response
        if method == 'sendWidget':
            self.sent += 1
            body = json.loads(request.data)
            self.state = json.loads(body['chat_data'])
            assert self.state['kind'] == 'task' and self.state['status'] == 'assigned'
            assert self.state['description'] == 'Проверить позиции по накладной'
            assert '17:00' in self.state['due'] and body['allow_queries'] and body['protect_content']
            labels = [button['text'] for row in body['reply_markup']['inline_keyboard'] for button in row]
            assert 'Заполнить отчёт' in labels and 'Подробности задачи' in labels
            query = {'id': '10', 'from': self.employee, 'chat': self.chat, 'message_id': 72,
                     'chat_instance': '10', 'type': {'type': 'data'}, 'data': self.state['action'] + ':start'}
            self.next.extend([{'update_id': 922, 'widget_query': {**query, 'id': '11', 'message_id': 73}},
                              {'update_id': 923, 'widget_query': query},
                              {'update_id': 924, 'widget_query': {**query, 'id': '12'}}])
            result = {'message_id': 72, 'chat': self.chat, 'widget': {}}
        elif method == 'editMessageWidgetData':
            body = json.loads(request.data)
            assert body['chat_id'] == self.chat['id'] and body['message_id'] == 72
            assert json.loads(body['chat_data']) == self.receipt()
            self.edited += 1
            result = True
        else:
            return super().open(request, timeout)
        return io.BytesIO(json.dumps({'ok': True, 'result': result}).encode())

    def receipt(self):
        return {'status': 'started', 'description': self.state['description'], 'due': self.state['due']}


def main():
    bridge = BridgeClient('https://localhost' + PATH, 'tg_widget_fixture', 'cd' * 32)
    bridge._opener = Loopback()
    api = TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
    api._opener = provider = TaskFixture()
    with tempfile.TemporaryDirectory() as directory:
        journal = Journal(directory, '123456')
        try:
            receiver = TelegramReceiver(api, None, journal, bot_id='123456', bot_username='ustarcorp_bot',
                role_bot_enabled=True, bot_bridge=bridge, bot_academy_origin='https://ustar.local')
            for expected in (1, 1, 3):
                assert receiver.step() == {'status': 'polled', 'processed': expected}
            assert journal.health()['next_offset'] == 925
        finally:
            journal.close()
    assert provider.sent == 1 and provider.edited == 2
    assert provider.answers == [{'status': 'stale'}, provider.receipt(), provider.receipt()]
    subprocess.run(['php', '/source/tests/stage/connect_broadcast_http_fixture.php', 'verify-task-widget'], check=True)


if __name__ == '__main__':
    main()
