"""Real private-worker WSGI boundary; synthetic remote Moodle is not DB evidence."""
import importlib
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'connect-worker'))
from ustar_connect import bridge
from test_connect_identity import signed, NOW, TOKEN

ORIGIN = 'https://connect.example.invalid'
FORM = {'username': 'synthetic', 'password': 'Fixture-Only-Qx9!2026',
        'email': 'synthetic@example.invalid', 'firstname': 'Тест',
        'lastname': 'Connect', 'departmentid': 'retail'}


class SafeBridgeErrors(unittest.TestCase):
    def request(self, status, payload):
        client = bridge.BridgeClient('https://localhost'+bridge.PATH, 'tg1', '12'*32)
        client._opener = Mock()
        raw = payload if isinstance(payload, bytes) else json.dumps(payload).encode()
        client._opener.open.side_effect = HTTPError('https://localhost', status, 'PRIVATE', {}, io.BytesIO(raw))
        try:
            client.request('registration.status', {})
        except ValueError as error:
            return error
        self.fail('Expected bridge rejection')

    def test_validation_field_is_preserved_without_private_message(self):
        error = self.request(422, {'ok': False, 'reason': 'validation_failed', 'field': 'email',
                                  'message': FORM['password']})
        self.assertEqual('validation_failed', str(error))
        self.assertEqual('email', error.field)
        self.assertNotIn(FORM['password'], repr(error))
        self.assertIsNone(error.__cause__)

    def test_known_conflict_and_expiry_remain_typed(self):
        for reason in ('invalid_entry', 'revoked_entry', 'idempotency_conflict', 'already_registered', 'rate_limited'):
            self.assertEqual(reason, str(self.request(403, {'ok': False, 'reason': reason})))

    def test_unknown_malformed_oversized_or_wrong_status_are_unavailable(self):
        for code, payload in [(503, {'ok': False, 'reason': 'invalid_entry'}),
                              (403, {'ok': False, 'reason': FORM['password']}),
                              (422, {'ok': False, 'reason': 'validation_failed', 'field': 'private'}),
                              (422, b'not json'), (403, b' '*16385)]:
            self.assertEqual('bridge_unavailable', str(self.request(code, payload)))


class RemoteBoundary:
    """Only remote side effect boundary is synthetic; all web guards/verifier are real."""
    def __init__(self):
        self.calls = []
        self.state = 'unlinked'
        self.registration = None
        self.failure = None

    def request(self, operation, payload):
        self.calls.append((operation, dict(payload)))
        if self.failure:
            raise self.failure
        if operation == 'launch.exchange':
            return {'ok': True, 'result': {'session': 'ue1_'+'A'*43, 'scope': 'onboarding', 'expires_at': NOW+600}}
        if operation == 'registration.options':
            result = {'departments': [{'id': 'retail', 'name': '<img src=x onerror=alert(1)>'}]}
        elif operation == 'registration.submit':
            self.state, self.registration = 'pending_approval', {'status': 'pending', 'submitted_at': NOW, 'reviewed_at': None}
            result = {'outcome': 'registered', 'replayed': False, 'registration': self.registration}
        elif operation == 'entry.revoke':
            result = {'revoked': True}
        else:
            result = {'scope': 'onboarding', 'account_state': self.state,
                      'expires_at': NOW+600, 'registration': self.registration}
        return {'ok': True, 'result': result}


class BrowserBoundary(unittest.TestCase):
    def setUp(self):
        path = ROOT/'connect-worker/ustar_connect/onboarding.py'
        self.assertTrue(path.is_file(), 'private onboarding WSGI application is missing')
        self.module = importlib.import_module('ustar_connect.onboarding')
        self.now = NOW
        self.remote = RemoteBoundary()
        self.app = self.module.OnboardingApp(ORIGIN, TOKEN, self.remote, clock=lambda: self.now)
        self.cookie, self.csrf = '', ''

    def call(self, path, data=None, *, method=None, headers=None, raw=None):
        method = method or ('GET' if data is None and raw is None else 'POST')
        body = raw if raw is not None else (json.dumps(data).encode() if data is not None else b'')
        env = {'REQUEST_METHOD': method, 'PATH_INFO': path, 'QUERY_STRING': '',
               'wsgi.url_scheme': 'https', 'HTTP_HOST': 'connect.example.invalid',
               'CONTENT_TYPE': 'application/json', 'CONTENT_LENGTH': str(len(body)),
               'wsgi.input': io.BytesIO(body), 'HTTP_COOKIE': self.cookie,
               'HTTP_X_USTAR_CONNECT': '1', 'HTTP_X_CSRF_TOKEN': self.csrf,
               'HTTP_ORIGIN': ORIGIN, 'HTTP_SEC_FETCH_SITE': 'same-origin'}
        env.update(headers or {})
        captured = {}
        def start(status, values):
            captured.update(status=int(status.split()[0]), headers=dict(values))
        value = b''.join(self.app(env, start))
        captured['raw'] = value
        if 'Set-Cookie' in captured['headers']:
            self.cookie = captured['headers']['Set-Cookie'].split(';')[0]
        captured['body'] = json.loads(value) if 'application/json' in captured['headers'].get('Content-Type', '') else value.decode()
        return captured

    def bootstrap(self):
        result = self.call('/api/bootstrap')
        self.assertEqual(200, result['status'])
        self.csrf = result['body']['csrf']
        return result

    def login(self):
        self.bootstrap()
        result = self.call('/api/launch', {'init_data': signed(start_param='ul1_'+'A'*43)})
        self.assertEqual(200, result['status'], result['body'])
        self.csrf = result['body']['csrf']
        return result

    def test_bootstrap_cookie_security_and_no_business_session_in_browser(self):
        result = self.bootstrap()
        for flag in ('Secure', 'HttpOnly', 'SameSite=Strict', 'Path=/'):
            self.assertIn(flag, result['headers']['Set-Cookie'])
        self.assertTrue(self.cookie.startswith('__Host-ustar_connect='))
        self.assertEqual([], self.remote.calls)
        self.assertNotIn('ue1_', result['raw'].decode())
        self.assertIn('no-store', result['headers']['Cache-Control'])

    def test_bootstrap_global_rate_limit_and_session_store_capacity(self):
        from ustar_connect.web_sessions import SessionStore, WebError
        small = SessionStore(capacity=1)
        small.create(NOW)
        with self.assertRaises(WebError):
            small.create(NOW)
        small.create(NOW+121)  # Expired records do not permanently occupy capacity.
        for _ in range(30):
            self.cookie = ''
            self.assertEqual(200, self.call('/api/bootstrap')['status'])
        self.cookie = ''
        self.assertEqual(429, self.call('/api/bootstrap')['status'])
        self.now += 61
        self.assertEqual(200, self.call('/api/bootstrap')['status'])

    def test_app_rejects_non_https_origin_and_credentials_in_origin(self):
        for origin in ('http://connect.example.invalid', ORIGIN+'/', ORIGIN+'?x=1',
                       'https://user:pass@connect.example.invalid'):
            with self.assertRaises(ValueError):
                self.module.OnboardingApp(origin, TOKEN, self.remote)

    def test_launch_fails_closed_without_fresh_signature_and_token_never_enters_store(self):
        self.bootstrap()
        raw = signed(start_param='ul1_'+'A'*43, auth_date=str(NOW-301))
        self.assertEqual(403, self.call('/api/launch', {'init_data': raw})['status'])
        self.assertEqual([], self.remote.calls)
        record = next(iter(self.app._sessions._items.values()))
        self.assertIsNone(record.entry)
        self.assertNotIn('init_data', vars(record))

    def test_https_host_forwarded_origin_and_csrf_guards_before_bridge(self):
        self.bootstrap()
        data = {'init_data': signed(start_param='ul1_'+'A'*43)}
        for headers in ({'wsgi.url_scheme': 'http', 'HTTP_X_FORWARDED_PROTO': 'https'},
                        {'HTTP_HOST': 'attacker.invalid'}, {'HTTP_ORIGIN': 'https://attacker.invalid'},
                        {'HTTP_X_CSRF_TOKEN': 'bad'}, {'HTTP_X_USTAR_CONNECT': ''},
                        {'HTTP_SEC_FETCH_SITE': 'cross-site'}):
            result = self.call('/api/launch', data, headers=headers)
            self.assertIn(result['status'], (400, 403))
        self.assertEqual([], self.remote.calls)

    def test_cross_site_bootstrap_and_duplicate_cookie_fail_closed(self):
        self.assertEqual(403, self.call('/api/bootstrap', headers={'HTTP_SEC_FETCH_SITE': 'cross-site'})['status'])
        self.bootstrap()
        self.assertEqual(403, self.call('/api/bootstrap', headers={'HTTP_COOKIE': self.cookie+'; '+self.cookie})['status'])

    def test_only_signed_launch_is_exchanged_and_cookie_rotates(self):
        self.bootstrap()
        old_cookie, old_csrf = self.cookie, self.csrf
        self.assertEqual(403, self.call('/api/launch', {'init_data': 'forged'})['status'])
        self.assertEqual([], self.remote.calls)
        result = self.call('/api/launch', {'init_data': signed(start_param='ul1_'+'A'*43)})
        self.assertEqual(200, result['status'])
        self.assertNotEqual(old_cookie, self.cookie)
        self.assertNotEqual(old_csrf, result['body']['csrf'])
        self.assertEqual('launch.exchange', self.remote.calls[0][0])
        self.assertEqual('8727627355', self.remote.calls[0][1]['subject'])
        self.assertNotIn('ue1_', result['raw'].decode())
        self.assertEqual(403, self.call('/api/status', {}, headers={'HTTP_COOKIE': old_cookie, 'HTTP_X_CSRF_TOKEN': old_csrf})['status'])

    def test_register_uses_only_fixed_fields_server_command_and_stable_retry(self):
        self.login()
        result = self.call('/api/register', FORM)
        self.assertEqual(200, result['status'])
        first = self.remote.calls[-1][1]
        self.assertTrue(first['command'].startswith('ur1_'))
        self.assertEqual(set(FORM)|{'browser', 'session', 'command'}, set(first))
        self.call('/api/register', FORM)
        self.assertEqual(first['command'], self.remote.calls[-1][1]['command'])
        self.assertEqual(400, self.call('/api/register', dict(FORM, userid='2'))['status'])
        self.assertEqual('pending_approval', self.call('/api/status', {})['body']['account_state'])

    def test_bad_json_query_unknown_route_and_wrong_method_do_not_dispatch(self):
        self.login()
        before = len(self.remote.calls)
        for path, raw, headers in [('/api/status', b'{"x":1,"x":2}', {}),
                                   ('/api/status', b'{}', {'QUERY_STRING': 'token=secret'}),
                                   ('/api/status', b'{}', {'CONTENT_TYPE': 'text/plain'}),
                                   ('/api/status', b'{}', {'CONTENT_LENGTH': '3'}),
                                   ('/api/status', b'x'*24577, {})]:
            self.assertIn(self.call(path, raw=raw, headers=headers)['status'], (400, 413, 415))
        self.assertEqual(404, self.call('/api/launch.create', {})['status'])
        self.assertEqual(405, self.call('/api/register', method='GET')['status'])
        self.assertEqual(before, len(self.remote.calls))

    def test_expired_or_restarted_worker_loses_only_local_access(self):
        self.login()
        self.now += 601
        self.assertEqual(403, self.call('/api/status', {})['status'])
        self.app = self.module.OnboardingApp(ORIGIN, TOKEN, self.remote, clock=lambda: NOW)
        self.assertEqual(403, self.call('/api/status', {})['status'])

    def test_fresh_bot_launch_can_bootstrap_after_worker_restart_without_waiting_cookie_ttl(self):
        self.login()
        old_cookie = self.cookie
        self.app = self.module.OnboardingApp(ORIGIN, TOKEN, self.remote, clock=lambda: NOW)
        self.bootstrap()
        self.assertNotEqual(old_cookie, self.cookie)

    def test_busy_session_and_global_upstream_slots_deny_without_new_remote_calls(self):
        self.login()
        cookie = self.cookie.split('=', 1)[1]
        session = self.app._sessions.lookup(cookie, NOW)
        session.lock.acquire()
        try:
            before = len(self.remote.calls)
            self.assertEqual(429, self.call('/api/status', {})['status'])
            self.assertEqual(before, len(self.remote.calls))
        finally:
            session.lock.release()
        for _ in range(4):
            self.app._upstream_slots.acquire()
        try:
            self.assertEqual(429, self.call('/api/status', {})['status'])
            self.assertEqual(before, len(self.remote.calls))
        finally:
            for _ in range(4):
                self.app._upstream_slots.release()

    def test_session_store_does_not_retain_password_form_or_cookie_plaintext(self):
        self.login()
        self.call('/api/register', FORM)
        cookie = self.cookie.split('=', 1)[1]
        items = self.app._sessions._items
        self.assertNotIn(cookie, items)
        record = next(iter(items.values()))
        self.assertNotIn('password', vars(record))
        self.assertNotIn('form', vars(record))
        self.assertNotIn(FORM['password'], repr(record))

    def test_unknown_remote_fields_are_not_passed_to_browser(self):
        self.login()
        original = self.remote.request
        def request(operation, payload):
            result = original(operation, payload)
            result['result'].update(private_comment=FORM['password'], userid=2)
            return result
        self.remote.request = request
        result = self.call('/api/status', {})
        self.assertEqual(200, result['status'])
        self.assertNotIn(FORM['password'], result['raw'].decode())
        self.assertNotIn('userid', result['raw'].decode())

    def test_forged_subject_unsigned_start_and_unexpected_identity_fields_never_dispatch(self):
        self.bootstrap()
        for data in ({'init_data': signed(), 'start_param': 'ul1_'+'A'*43},
                     {'init_data': signed(start_param='ul1_'+'A'*43), 'subject': '99999'}):
            self.assertEqual(400, self.call('/api/launch', data)['status'])
        self.assertEqual(403, self.call('/api/launch', {'init_data': signed()})['status'])
        self.assertEqual([], self.remote.calls)

    def test_safe_errors_unknown_failure_and_logout_always_clears_local_access(self):
        self.login()
        self.remote.failure = ValueError(FORM['password'])
        response = self.call('/api/register', FORM)
        self.assertEqual(503, response['status'])
        self.assertNotIn(FORM['password'], response['raw'].decode())
        result = self.call('/api/logout', {})
        self.assertEqual(200, result['status'])
        self.assertIn('Max-Age=0', result['headers']['Set-Cookie'])
        self.assertEqual(403, self.call('/api/status', {})['status'])

    def test_authenticated_validation_is_usable_and_not_a_transport_failure(self):
        self.login()
        self.remote.failure = bridge.BridgeRejected('validation_failed', 'email')
        result = self.call('/api/register', FORM)
        self.assertEqual(422, result['status'])
        self.assertEqual('email', result['body']['field'])

    def test_session_attempt_limit_never_turns_into_unbounded_bridge_calls(self):
        self.login()
        for _ in range(5):
            self.call('/api/register', FORM)
        before = len(self.remote.calls)
        self.assertEqual(429, self.call('/api/register', FORM)['status'])
        self.assertEqual(before, len(self.remote.calls))

    def test_unicode_csrf_is_denied_not_classified_as_upstream_outage(self):
        self.login()
        before = len(self.remote.calls)
        self.assertEqual(403, self.call('/api/status', {}, headers={'HTTP_X_CSRF_TOKEN': 'секрет'})['status'])
        self.assertEqual(before, len(self.remote.calls))

    def test_static_assets_are_allowlisted_and_security_headers_allow_telegram_web(self):
        response = self.call('/')
        self.assertEqual(200, response['status'])
        self.assertIn('https://web.telegram.org', response['headers']['Content-Security-Policy'])
        self.assertNotIn("'unsafe-inline'", response['headers']['Content-Security-Policy'])
        self.assertEqual(404, self.call('/../ustar_connect/bridge.py')['status'])
        for path in ('/onboarding.js', '/onboarding.css'):
            self.assertEqual(200, self.call(path)['status'])


if __name__ == '__main__':
    unittest.main()
