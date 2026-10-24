"""Private-worker WSGI application. No public listener, installer or relay here.

Instantiate only inside a trusted HTTPS/relay deployment. Forwarded headers are
not transport authentication. Bot/service secrets never belong on public edge.
"""
import hmac
from http import HTTPStatus
import json
from pathlib import Path
import re
import threading
import time
from urllib.parse import urlsplit

from .bridge import BridgeRejected, FORM_FIELDS, _token, telegram_exchange_payload, telegram_link_payload
from .telegram import validate_init_data
from .web_sessions import COOKIE, SessionStore, WebError
from . import actor_web

ASSETS = {'/': ('index.html', 'text/html; charset=utf-8'),
          '/onboarding.js': ('onboarding.js', 'text/javascript; charset=utf-8'),
          '/onboarding.css': ('onboarding.css', 'text/css; charset=utf-8')}
CSP = ("default-src 'none'; script-src 'self' https://telegram.org; style-src 'self'; "
       "connect-src 'self'; img-src 'self'; base-uri 'none'; object-src 'none'; "
       "form-action 'none'; frame-ancestors https://web.telegram.org")


def _unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate_field')
        result[key] = value
    return result


class OnboardingApp:
    def __init__(self, origin, bot_token, bridge, *, clock=time.time):
        parsed = urlsplit(origin)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
                or parsed.path or parsed.query or parsed.fragment or origin != 'https://'+parsed.netloc
                or re.search(r'[\s\\]', origin)):
            raise ValueError('invalid_browser_origin')
        if not isinstance(bot_token, str) or not re.fullmatch(r'[1-9][0-9]{0,19}:[A-Za-z0-9_-]{20,256}', bot_token):
            raise ValueError('invalid_verifier_configuration')
        self.origin, self.host = origin, parsed.netloc
        self._bot_token, self._bridge, self._clock = bot_token, bridge, clock
        self._sessions = SessionStore()
        self._upstream_slots = threading.BoundedSemaphore(4)
        self._assets = {route: ((Path(__file__).resolve().parent.parent/'web'/name).read_bytes(), kind)
                        for route, (name, kind) in ASSETS.items()}

    @staticmethod
    def _cookie(environ):
        raw = environ.get('HTTP_COOKIE', '')
        if len(raw) > 8192:
            raise WebError('invalid_cookie')
        values = [item.strip().partition('=')[2] for item in raw.split(';')
                  if item.strip().partition('=')[0] == COOKIE]
        if len(values) > 1 or (values and not re.fullmatch(r'[A-Za-z0-9_-]{43}', values[0])):
            raise WebError('invalid_cookie')
        return values[0] if values else ''

    @staticmethod
    def _set_cookie(cookie, ttl):
        return f'{COOKIE}={cookie}; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age={ttl}'

    @staticmethod
    def _json(environ):
        if environ.get('CONTENT_TYPE', '').lower() not in ('application/json', 'application/json; charset=utf-8'):
            raise WebError('invalid_content_type', 415)
        length = environ.get('CONTENT_LENGTH', '')
        if not re.fullmatch(r'[0-9]{1,8}', length):
            raise WebError('invalid_payload', 400)
        length = int(length)
        if length > 24576:
            raise WebError('payload_too_large', 413)
        raw = environ['wsgi.input'].read(length)
        try:
            if len(raw) != length:
                raise ValueError('length_mismatch')
            value = json.loads(raw.decode('utf-8'), object_pairs_hook=_unique,
                               parse_constant=lambda _: (_ for _ in ()).throw(ValueError()))
            if not isinstance(value, dict):
                raise ValueError('invalid_object')
            return value
        except (ValueError, UnicodeError, RecursionError):
            raise WebError('invalid_payload', 400) from None

    def _request(self, operation, payload):
        if not self._upstream_slots.acquire(blocking=False):
            raise WebError('busy', 429)
        try:
            envelope = self._bridge.request(operation, payload)
            if (not isinstance(envelope, dict) or envelope.get('ok') is not True
                    or not isinstance(envelope.get('result'), dict)):
                raise ValueError('invalid_bridge_response')
            return envelope['result']
        finally:
            self._upstream_slots.release()

    def __call__(self, environ, start_response):
        extra = {}
        try:
            status, result, kind = self._handle(environ, extra)
        except WebError as error:
            status, result, kind = error.status, {'ok': False, 'reason': error.reason}, 'application/json'
        except BridgeRejected as error:
            status = (422 if error.reason == 'validation_failed' else 429 if error.reason == 'rate_limited'
                      else 409 if error.reason in ('idempotency_conflict', 'already_registered', 'receipt_expired') else 403)
            result, kind = {'ok': False, 'reason': error.reason}, 'application/json'
            if error.field:
                result['field'] = error.field
        except Exception:
            # Never log/reflect request, traceback or upstream text: they can contain passwords.
            status, result, kind = 503, {'ok': False, 'reason': 'source_unavailable'}, 'application/json'
        raw = json.dumps(result, ensure_ascii=True, separators=(',', ':')).encode() if isinstance(result, dict) else result
        headers = {'Content-Type': kind, 'Content-Length': str(len(raw)), 'Cache-Control': 'no-store',
                   'Content-Security-Policy': CSP, 'Referrer-Policy': 'no-referrer',
                   'X-Content-Type-Options': 'nosniff',
                   'Permissions-Policy': 'camera=(), microphone=(), geolocation=()', **extra}
        start_response(f'{status} {HTTPStatus(status).phrase}', list(headers.items()))
        return [raw]

    def _handle(self, env, extra):
        if env.get('wsgi.url_scheme') != 'https' or env.get('HTTP_HOST') != self.host:
            raise WebError('invalid_origin')
        path, method = env.get('PATH_INFO', ''), env.get('REQUEST_METHOD')
        if path in self._assets:
            if method != 'GET':
                raise WebError('method_not_allowed', 405)
            raw, kind = self._assets[path]
            return 200, raw, kind
        routes = {'/api/bootstrap', '/api/launch', '/api/options', '/api/status', '/api/register', '/api/logout', '/api/activate'}
        if path not in routes:
            raise WebError('not_found', 404)
        if method != ('GET' if path == '/api/bootstrap' else 'POST'):
            raise WebError('method_not_allowed', 405)
        if env.get('QUERY_STRING'):
            raise WebError('invalid_payload', 400)
        if (env.get('HTTP_X_USTAR_CONNECT') != '1'
                or env.get('HTTP_SEC_FETCH_SITE', 'same-origin') not in ('same-origin', 'none')
                or (method == 'POST' and env.get('HTTP_ORIGIN') != self.origin)):
            raise WebError('invalid_origin')
        now = int(self._clock())
        cookie = self._cookie(env)
        if path == '/api/bootstrap':
            try:
                session = self._sessions.lookup(cookie, now)
            except WebError:
                cookie, session = self._sessions.create(now)
                extra['Set-Cookie'] = self._set_cookie(cookie, 120)
        else:
            session = self._sessions.lookup(cookie, now)
        if not session.lock.acquire(blocking=False):
            raise WebError('busy', 429)
        try:
            if not self._sessions.owns(cookie, session, now):
                raise WebError('session_expired')
            if path == '/api/bootstrap':
                self._sessions.limit(session, 'bootstrap', now)
                value = {'ok': True, 'csrf': session.csrf, 'authenticated': bool(session.entry or session.link_claim or session.actor)}
                if session.link_claim:
                    value['scope'] = 'link_confirmation'
                elif session.actor:
                    value['scope'] = 'actor_read'
                return 200, value, 'application/json'
            csrf = env.get('HTTP_X_CSRF_TOKEN', '')
            if not isinstance(csrf, str) or not re.fullmatch(r'[A-Za-z0-9_-]{43}', csrf) or not hmac.compare_digest(csrf, session.csrf):
                raise WebError('invalid_csrf')
            data = self._json(env)
            operation = path.rsplit('/', 1)[1]
            expected = {'init_data'} if operation == 'launch' else FORM_FIELDS if operation == 'register' else set()
            if set(data) != expected or any(not isinstance(value, str) or '\0' in value
                                           or len(value.encode('utf-8')) > (16384 if operation == 'launch' else 4096)
                                           for value in data.values()):
                raise WebError('invalid_payload', 400)
            self._sessions.limit(session, operation, now)
            if operation == 'launch':
                if session.link_claim == 'uncertain':
                    raise WebError('link_outcome_unknown', 503)
                if session.entry or session.link_claim or session.actor:
                    raise WebError('restart_required')
                try:
                    proof = validate_init_data(data['init_data'], bot_token=self._bot_token, now=now)
                    linking = isinstance(proof.start_param, str) and proof.start_param.startswith('uc1_')
                    payload = telegram_link_payload(proof) if linking else telegram_exchange_payload(proof, session.browser)
                except ValueError:
                    raise WebError('invalid_launch') from None
                if linking:
                    # Commit uncertainty before a remote mutation. Never auto-claim after timeout.
                    session.link_claim = 'uncertain'
                    try:
                        reply = self._request('link.claim', payload)
                        if reply.get('claimed') is not True or reply.get('academy_confirmation_required') is not True:
                            raise ValueError('invalid_bridge_response')
                    except BridgeRejected:
                        session.link_claim = None  # A definite rejection performed no claim.
                        raise
                    except Exception:
                        raise WebError('link_outcome_unknown', 503) from None
                    session.link_claim, session.expires = 'claimed', now+600
                    cookie = self._sessions.rotate(cookie, session)
                    extra['Set-Cookie'] = self._set_cookie(cookie, 600)
                    return 200, {'ok': True, 'csrf': session.csrf, 'scope': 'link_confirmation',
                                 'academy_confirmation_required': True,
                                 'telegram_id': proof.identity.subject}, 'application/json'
                reply = self._request('launch.exchange', payload)
                entry = _token(reply.get('session'), 'ue1')
                expiry = reply.get('expires_at')
                if reply.get('scope') != 'onboarding' or type(expiry) is not int or expiry <= now:
                    raise ValueError('invalid_bridge_response')
                session.entry, session.expires = entry, min(expiry, now+600)
                cookie = self._sessions.rotate(cookie, session)
                extra['Set-Cookie'] = self._set_cookie(cookie, session.expires-now)
                return 200, {'ok': True, 'csrf': session.csrf, 'scope': 'onboarding'}, 'application/json'
            if operation == 'logout':
                self._sessions.remove(cookie)
                extra['Set-Cookie'] = self._set_cookie('', 0)
                if session.actor:
                    try:
                        self._request('actor.revoke', {'session': session.actor, 'browser': session.browser})
                    except Exception:
                        pass
                elif session.entry:
                    try:
                        self._request('entry.revoke', {'session': session.entry, 'browser': session.browser})
                    except Exception:
                        pass  # Local secret gone; remote entry still expires within600s.
                return 200, {'ok': True, 'local_session_closed': True}, 'application/json'
            if session.actor:
                if operation != 'status':
                    raise WebError('actor_only_session')
                try:
                    reply = self._request('actor.status', {'session': session.actor, 'browser': session.browser})
                    observed = int(self._clock())
                    result = actor_web.profile(reply, observed, session.actor_absolute)
                except BridgeRejected as error:
                    if error.reason in ('invalid_actor', 'native_auth_required', 'actor_unavailable'):
                        self._sessions.remove(cookie)
                        extra['Set-Cookie'] = self._set_cookie('', 0)
                    raise
                session.expires = result['idle_expires_at']
                extra['Set-Cookie'] = self._set_cookie(cookie, session.expires-observed)
                return 200, {'ok': True, **result}, 'application/json'
            if session.activation_uncertain:
                raise WebError('activation_outcome_unknown', 503)
            if session.link_claim:
                if session.link_claim == 'uncertain':
                    raise WebError('link_outcome_unknown', 503)
                if operation != 'status':
                    raise WebError('link_only_session')
                return 200, {'ok': True, 'scope': 'link_confirmation',
                             'account_state': 'academy_confirmation_required'}, 'application/json'
            if not session.entry:
                raise WebError('launch_required')
            payload = {'session': session.entry, 'browser': session.browser}
            if operation == 'activate':
                # Record uncertainty before the authority-changing private request.
                session.activation_uncertain = True
                try:
                    reply = self._request('actor.open', payload)
                    observed = int(self._clock())
                    activated = actor_web.grant(reply, observed)
                except BridgeRejected:
                    session.activation_uncertain = False
                    raise
                except Exception:
                    raise WebError('activation_outcome_unknown', 503) from None
                session.actor, session.actor_absolute = activated['session'], activated['expires_at']
                session.entry = None
                session.activation_uncertain = False
                session.expires = activated['idle_expires_at']
                cookie = self._sessions.rotate(cookie, session)
                extra['Set-Cookie'] = self._set_cookie(cookie, session.expires-observed)
                return 200, {'ok': True, 'csrf': session.csrf, 'scope': 'actor_read'}, 'application/json'
            if operation == 'register':
                payload.update(data, command=session.command)
                if len(json.dumps(payload, ensure_ascii=True).encode()) > 15000:
                    raise WebError('payload_too_large', 413)
                result = self._request('registration.submit', payload)
                if result.get('outcome') != 'registered' or type(result.get('replayed')) is not bool:
                    raise ValueError('invalid_bridge_response')
                result = {'ok': True, 'outcome': 'registered', 'replayed': result['replayed'],
                          'registration': self._registration(result.get('registration'))}
            elif operation == 'status':
                value = self._request('registration.status', payload)
                if value.get('scope') != 'onboarding' or value.get('account_state') not in ('unlinked', 'pending_approval', 'linked_active'):
                    raise ValueError('invalid_bridge_response')
                result = {'ok': True, 'scope': 'onboarding', 'account_state': value['account_state'],
                          'registration': self._registration(value.get('registration'))}
            else:
                value = self._request('registration.options', payload)
                choices = value.get('departments')
                if not isinstance(choices, list) or len(choices) > 500:
                    raise ValueError('invalid_bridge_response')
                departments = []
                for choice in choices:
                    if not isinstance(choice, dict) or any(not isinstance(choice.get(key), str)
                            or len(choice[key]) > 256 for key in ('id', 'name')):
                        raise ValueError('invalid_bridge_response')
                    departments.append({'id': choice['id'], 'name': choice['name']})
                result = {'ok': True, 'departments': departments}
            return 200, result, 'application/json'
        finally:
            session.lock.release()

    @staticmethod
    def _registration(value):
        if value is None:
            return None
        if not isinstance(value, dict) or value.get('status') not in ('pending', 'approved', 'rejected'):
            raise ValueError('invalid_bridge_response')
        result = {'status': value['status']}
        for name in ('submitted_at', 'reviewed_at'):
            if value.get(name) is not None and type(value.get(name)) is not int:
                raise ValueError('invalid_bridge_response')
            result[name] = value.get(name)
        return result
