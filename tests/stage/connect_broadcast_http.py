"""Drive the signed native bridge, byte transfer and Python worker into a fake Telegram API."""
import io
import json
from pathlib import Path
import subprocess
import sys
import time
from urllib.request import Request, urlopen

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))
from ustar_connect.bridge import BridgeClient, PATH
from ustar_connect.notification_worker import NotificationWorker
from ustar_connect.telegram_api import TelegramClient


class Loopback:
    def open(self, request, timeout):
        if request.full_url != 'https://localhost' + PATH:
            raise ValueError('fixture_loopback_only')
        return urlopen(Request('http://127.0.0.1:18087' + PATH, request.data, dict(request.headers),
                               method='POST'), timeout=timeout)


class TelegramFixture:
    def __init__(self):
        self.methods = []

    def open(self, request, timeout):
        method = request.full_url.rsplit('/', 1)[-1]
        self.methods.append(method)
        if method == 'getMe':
            result = {'id': 123456, 'is_bot': True, 'username': 'ustarcorp_bot'}
        elif method == 'sendDocument':
            body = b''.join(request.data)
            assert 'Склад РЦ · сообщение'.encode() in body and 'График РЦ.txt'.encode() in body
            assert b'Synthetic attachment' in body and int(request.headers['Content-length']) == len(body)
            result = {'message_id': 991, 'chat': {'id': 77777, 'type': 'private'}}
        else:
            raise AssertionError(method)
        return io.BytesIO(json.dumps({'ok': True, 'result': result}).encode())


def main():
    bridge = BridgeClient('https://localhost' + PATH, 'tg_broadcast_fixture', 'ab' * 32)
    bridge._opener = Loopback()
    api = TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
    api._opener = fixture = TelegramFixture()
    calls = 0

    def clock():
        nonlocal calls
        calls += 1
        return time.time() - (1 if calls == 4 else 0)

    worker = NotificationWorker(api, bridge, bot_id='123456', bot_username='ustarcorp_bot',
                                notification_contract=3, clock=clock)
    assert worker.step() == {'status': 'provider_accepted'}
    assert fixture.methods == ['getMe', 'sendDocument']
    subprocess.run(['php', '/source/tests/stage/connect_broadcast_http_fixture.php', 'verify'], check=True)


if __name__ == '__main__':
    main()
