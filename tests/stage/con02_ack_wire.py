"""Frozen a86 worker parses actual native PHP v1 packets; synthetic provider, no network."""
import json
from pathlib import Path
import sys

sys.path.insert(0, '/source/.stage-input/con02-old-worker/connect-worker')
from ustar_connect.notification_worker import NotificationWorker

packet = json.loads(Path('/stage/con02-old-wire.json').read_text())

class Bridge:
    def __init__(self):
        self.operations = []

    def request(self, operation, payload):
        expected = ['notification.lease', 'notification.begin', 'notification.finish']
        assert operation == expected[len(self.operations)]
        self.operations.append(operation)
        key = {'notification.lease': 'lease', 'notification.begin': 'permit', 'notification.finish': 'receipt'}[operation]
        return {'ok': True, 'result': packet[key]}

class Provider:
    sent = 0

    def get_me(self):
        return {'id': 123456, 'is_bot': True, 'username': 'ustarcorp_bot'}

    def send_notification(self, subject, category, severity, occurred_at):
        assert subject == 77788001 and category == 'tasks'
        self.sent += 1
        return {'message_id': 991, 'chat': {'type': 'private', 'id': subject}}

provider, bridge = Provider(), Bridge()
worker = NotificationWorker(provider, bridge, bot_id='123456', bot_username='ustarcorp_bot', allowed_subjects=['77788001'])
assert worker.step() == {'status': 'provider_accepted'}
assert provider.sent == 1
print('CON02_FROZEN_A86_NATIVE_PACKET_COMPAT=PASS; synthetic_provider=true; live_traffic=false')
