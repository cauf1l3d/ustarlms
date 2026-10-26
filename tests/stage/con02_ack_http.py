"""Actual native Moodle login/GET/POST on the disposable fixture; never a live Academy."""
import http.cookiejar
from html.parser import HTMLParser
import json
from pathlib import Path
import subprocess
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, ProxyHandler

assert Path('/stage/moodle/config.php').is_file() and Path(__file__).parent == Path('/source/tests/stage')
state = json.loads(Path('/stage/con02-ack-state.json').read_text())
origin = 'http://127.0.0.1:8087'
client = build_opener(ProxyHandler({}), HTTPCookieProcessor(http.cookiejar.CookieJar()))

class Inputs(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.values = {}
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name'):
            self.values[attrs['name']] = attrs.get('value', '')

def request(path, form=None):
    data = None if form is None else urlencode(form).encode()
    try:
        response = client.open(origin+path, data, timeout=15)
    except HTTPError as error:
        response = error
    with response:
        return response.read(2097153).decode('utf-8')

def status():
    return json.loads(subprocess.check_output(['php', '/source/tests/stage/con02_ack_probe.php', 'status']))

token = Inputs(request('/login/index.php')).values['logintoken']
request('/login/index.php', {'username': 'con02_ack_employee', 'password': 'Ack-Fixture-Only-Password1!', 'logintoken': token})
body = request('/local/ustar/notifications.php')
csrf = Inputs(body).values['sesskey']
assert 'name="action" value="ack"' in body, 'Native inbox lacks explicit acknowledgement form'
assert status()['ackat'] is None
form = {'action': 'ack', 'id': str(state['own']), 'source': 'local', 'sesskey': csrf}
request('/local/ustar/notifications.php?'+urlencode(form))
assert status()['ackat'] is None, 'GET acknowledged a notice'
for extra in ({'sesskey': 'invalid'}, {'source': 'moodle'}, {'id': str(state['foreign'])}):
    request('/local/ustar/notifications.php', form | extra)
    assert status()['ackat'] is None, 'Invalid/native foreign request acknowledged a notice'
request('/local/ustar/notifications.php', form)
first = status()
assert first['status'] == 'acknowledged' and isinstance(first['ackat'], int)
request('/local/ustar/notifications.php', form)
request('/local/ustar/notifications.php', {'action': 'all', 'sesskey': csrf})
last = status()
assert last == first and last['foreign'] == 'unread' and last['task_unchanged'] and last['delivery_unchanged']
print('CON02_ACK_NATIVE_HTTP_POST_CSRF_SOURCE_RECIPIENT_REPEAT=PASS')
