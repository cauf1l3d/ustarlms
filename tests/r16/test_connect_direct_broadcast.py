"""Exercise the direct Telegram send with synthetic files and no network."""

from hashlib import sha1
from io import BytesIO
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))
from ustar_connect.notification_worker import NotificationWorker
from ustar_connect.telegram_api import TelegramClient
from ustar_connect.bot_cards import render_view
from ustar_connect.bridge import BridgeClient


NOW = 1800000000
TEXT = 'Склад РЦ\nГрафик на завтра <обновлён>'
FILE = b'%PDF-1.4 synthetic schedule'
FILE_META = {'id': 29, 'name': 'График.pdf', 'size': len(FILE), 'hash': sha1(FILE).hexdigest()}
STAMP = 'a' * 64
CHAT = {'id': 77777, 'type': 'private'}


class Response(BytesIO):
    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()


class DirectBroadcastTest(unittest.TestCase):
    def setUp(self):
        self.client = TelegramClient('123456:synthetic-test-credential-not-a-real-bot')
        self.api_requests = []

        def open_request(request, timeout):
            method = request.full_url.rsplit('/', 1)[-1]
            body = request.data if isinstance(request.data, bytes) else b''.join(request.data)
            self.api_requests.append((method, body, request.headers))
            result = {'id': 123456, 'is_bot': True, 'username': 'ustarcorp_bot'} if method == 'getMe' else {
                'message_id': 991, 'chat': CHAT,
            }
            return Response(json.dumps({'ok': True, 'result': result}).encode())

        self.client._opener = Mock()
        self.client._opener.open.side_effect = open_request
        self.bridge = Mock()
        self.lease = {'delivery': 7, 'lease': 'ud1_' + 'A' * 43, 'expires': NOW + 60,
                      'broadcast': {'text': TEXT, 'files': [FILE_META], 'stamp': STAMP, 'subject': '77777'}}
        self.permit = {'status': 'sending', 'subject': '77777', 'category': 'feed', 'severity': 'normal',
                       'occurred_at': NOW - 10, 'send_before': NOW + 10, 'version': 3, 'ack': None,
                       'event': 'feed_broadcast', 'stamp': STAMP}
        self.bridge.download_broadcast_file.side_effect = lambda _, descriptor, target: (
            target.write(FILE), target.seek(0)
        )

    def worker(self):
        return NotificationWorker(self.client, self.bridge, bot_id='123456', bot_username='ustarcorp_bot',
                                  notification_contract=3, clock=lambda: NOW, allowed_subjects=('77777',))

    def replies(self, *values):
        self.bridge.request.side_effect = [{'ok': True, 'result': value} for value in values]

    def test_confirmed_text_and_file_are_in_one_private_provider_request(self):
        self.replies(self.lease, self.permit, {'status': 'provider_accepted'})
        self.assertEqual({'status': 'provider_accepted'}, self.worker().step())
        method, body, headers = self.api_requests[-1]
        self.assertEqual('sendDocument', method)
        self.assertIn(TEXT.encode(), body)
        self.assertIn(FILE, body)
        self.assertIn(FILE_META['name'].encode(), body)
        self.assertEqual(str(len(body)), headers['Content-length'])
        self.assertEqual('991', self.bridge.request.call_args.args[1]['provider_ref'])

    def test_changed_revision_after_file_read_cannot_send(self):
        self.replies(self.lease, {'status': 'cancelled'})
        self.assertEqual({'status': 'cancelled'}, self.worker().step())
        self.bridge.download_broadcast_file.assert_called_once()
        self.assertEqual(['getMe'], [method for method, _, _ in self.api_requests])

    def test_failed_file_staging_is_recorded_without_begin_or_provider_send(self):
        self.bridge.download_broadcast_file.side_effect = OSError('synthetic private detail')
        self.replies(self.lease, {'status': 'retry_wait'})
        self.assertEqual({'status': 'retry_wait', 'reason': 'attachment_unavailable'}, self.worker().step())
        calls = self.bridge.request.call_args_list
        self.assertEqual(['notification.lease', 'notification.finish'], [c.args[0] for c in calls])
        self.assertEqual('attachment_unavailable', calls[-1].args[1]['reason'])
        self.assertEqual('0', calls[-1].args[1]['retry_after'])
        self.assertEqual(['getMe'], [method for method, _, _ in self.api_requests])

    def test_permit_generated_on_next_second_is_accepted(self):
        worker = self.worker()
        worker.clock = Mock(side_effect=[NOW, NOW, NOW, NOW, NOW + 1])
        self.replies(self.lease, {**self.permit, 'send_before': NOW + 11}, {'status': 'provider_accepted'})
        self.assertEqual({'status': 'provider_accepted'}, worker.step())
        self.assertEqual('sendDocument', self.api_requests[-1][0])

    def test_expired_permit_is_reported_without_sending(self):
        self.replies(self.lease, {**self.permit, 'send_before': NOW}, {'status': 'retry_wait'})
        self.assertEqual({'status': 'retry_wait', 'reason': 'permit_unavailable'}, self.worker().step())
        operation, payload = self.bridge.request.call_args.args
        self.assertEqual('notification.finish', operation)
        self.assertEqual('permit_unavailable', payload['reason'])
        self.assertEqual(['getMe'], [method for method, _, _ in self.api_requests])

    def test_permit_cannot_send_with_a_different_content_stamp(self):
        self.replies(self.lease, {**self.permit, 'stamp': 'b' * 64}, {'status': 'retry_wait'})
        self.assertEqual({'status': 'retry_wait', 'reason': 'permit_unavailable'}, self.worker().step())
        self.assertEqual(['getMe'], [method for method, _, _ in self.api_requests])

    def test_attachment_read_is_signed_and_verifies_the_exact_content(self):
        client = BridgeClient('https://localhost/local/ustar/connect_bridge.php', 'tg_v3', 'a' * 64)
        lease = {'delivery': '7', 'lease': self.lease['lease']}
        response = Response(FILE)
        response.headers = {'Content-Type': 'application/octet-stream'}
        client._opener = Mock()
        client._opener.open.return_value = response
        target = BytesIO()
        client.download_broadcast_file(lease, FILE_META, target)
        request = client._opener.open.call_args.args[0]
        self.assertEqual({'operation': 'notification.begin_v3', 'payload': {**lease, 'file': '29'}},
                         json.loads(request.data))
        self.assertIn('X-ustar-signature', request.headers)
        self.assertEqual(FILE, target.read())
        response = Response(FILE[:-1] + b'X')
        response.headers = {'Content-Type': 'application/octet-stream'}
        client._opener.open.return_value = response
        with self.assertRaisesRegex(ValueError, 'invalid_broadcast_file_content'):
            client.download_broadcast_file(lease, FILE_META, BytesIO())

    def test_bot_review_links_only_to_a_specific_academy_publication(self):
        view = {'title': 'Проверка рассылки', 'text': TEXT, 'buttons': [], 'metrics': [], 'rows': []}
        path = 'https://ustar.local/local/ustar/feed.php?postid=29'
        view['buttons'] = [{'label': 'Открыть объявление', 'url': path}]
        result = render_view(view, academy_origin='https://ustar.local')
        self.assertEqual(path, result['reply_markup']['inline_keyboard'][0][0]['url'])
        for bad in (path + '&postid=3', path.replace('ustar.local', 'untrusted.invalid'), path.replace('feed.php', 'pluginfile.php')):
            view['buttons'][0]['url'] = bad
            with self.assertRaises(ValueError):
                render_view(view, academy_origin='https://ustar.local')
