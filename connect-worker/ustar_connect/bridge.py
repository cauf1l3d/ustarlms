"""Signed, scoped requests to one private Moodle endpoint. No admin credential."""
import base64
from dataclasses import dataclass
import hashlib
import hmac
import json
import re
import secrets
import ssl
import time
from urllib.error import URLError, HTTPError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPSHandler, ProxyHandler, HTTPRedirectHandler

PATH = '/local/ustar/connect_bridge.php'
OPERATIONS = {'launch.create', 'launch.exchange', 'entry.status', 'entry.revoke', 'link.claim',
              'registration.options', 'registration.submit', 'registration.status'}
REJECTIONS = {'validation_failed', 'invalid_entry', 'revoked_entry', 'restart_required',
              'expired_launch', 'launch_consumed', 'replayed_proof', 'launch_mismatch',
              'idempotency_conflict', 'already_registered', 'receipt_expired', 'rate_limited',
              'invalid_link_code', 'invalid_challenge', 'expired_challenge', 'claimed_challenge',
              'provider_mismatch', 'link_conflict'}
FORM_FIELDS = {'username', 'password', 'email', 'firstname', 'lastname', 'departmentid'}


class BridgeRejected(ValueError):
    """Only fixed reason codes/field names cross the HTTP boundary."""
    def __init__(self, reason: str, field: str | None = None):
        if reason not in REJECTIONS or (reason == 'validation_failed' and field not in FORM_FIELDS | {'form'}):
            raise ValueError('bridge_unavailable')
        super().__init__(reason)
        self.reason, self.field = reason, field if reason == 'validation_failed' else None


def _token(value: str, prefix: str) -> str:
    if not isinstance(value, str) or not re.fullmatch(re.escape(prefix) + r'_[A-Za-z0-9_-]{43}', value):
        raise ValueError('invalid_opaque_token')
    encoded = value[len(prefix)+1:]
    if base64.urlsafe_b64encode(base64.urlsafe_b64decode(encoded+'=')).decode().rstrip('=') != encoded:
        raise ValueError('invalid_opaque_token')
    return value


@dataclass(frozen=True, repr=False)
class SignedRequest:
    body: bytes
    headers: dict


def sign_request(operation: str, payload: dict, *, key_id: str, secret_hex: str,
                 now: int, nonce: str | None = None) -> SignedRequest:
    if operation not in OPERATIONS or not isinstance(payload, dict):
        raise ValueError('invalid_operation')
    if (not re.fullmatch(r'[A-Za-z0-9_-]{1,32}', key_id)
            or not re.fullmatch(r'[0-9a-f]{64}', secret_hex) or type(now) is not int or now <= 0):
        raise ValueError('invalid_bridge_configuration')
    nonce = _token(nonce or 'rn1_' + secrets.token_urlsafe(32), 'rn1')
    body = json.dumps({'operation': operation, 'payload': payload}, sort_keys=True,
                      separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode()
    if len(body) > 16384:
        raise ValueError('bridge_payload_too_large')
    message = '\n'.join(['USTAR-CONNECT-BRIDGE-v1', 'POST', PATH, key_id, str(now), nonce,
                         hashlib.sha256(body).hexdigest()]).encode()
    signature = hmac.new(bytes.fromhex(secret_hex), message, hashlib.sha256).hexdigest()
    return SignedRequest(body, {'Content-Type': 'application/json', 'X-Ustar-Key': key_id,
        'X-Ustar-Time': str(now), 'X-Ustar-Nonce': nonce, 'X-Ustar-Signature': signature})


def telegram_exchange_payload(proof, browser: str) -> dict:
    """Accept only the verifier's signed start_param; never tgWebAppStartParam alone."""
    launch = _token(proof.start_param, 'ul1')
    return {'provider': proof.identity.provider, 'tenant': proof.identity.tenant,
            'subject': proof.identity.subject, 'launch': launch, 'proof': proof.proof_id,
            'auth_date': proof.issued_at, 'browser': _token(browser, 'ub1')}


def telegram_link_payload(proof) -> dict:
    return {'provider': proof.identity.provider, 'tenant': proof.identity.tenant,
            'subject': proof.identity.subject, 'code': _token(proof.start_param, 'uc1'),
            'proof': proof.proof_id, 'auth_date': proof.issued_at}


class _NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class BridgeClient:
    """TLS validation stays enabled; no ambient proxy, redirect, logging or retries."""
    def __init__(self, endpoint: str, key_id: str, secret_hex: str, *, ca_file: str | None = None):
        parsed = urlsplit(endpoint)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
                or parsed.query or parsed.fragment or parsed.path != PATH):
            raise ValueError('invalid_bridge_endpoint')
        sign_request('launch.create', {}, key_id=key_id, secret_hex=secret_hex, now=1)
        self._endpoint, self._key_id, self._secret = endpoint, key_id, secret_hex
        context = ssl.create_default_context(cafile=ca_file)
        self._opener = build_opener(ProxyHandler({}), HTTPSHandler(context=context), _NoRedirect())

    def request(self, operation: str, payload: dict) -> dict:
        signed = sign_request(operation, payload, key_id=self._key_id, secret_hex=self._secret, now=int(time.time()))
        request = Request(self._endpoint, data=signed.body, headers=signed.headers, method='POST')
        try:
            with self._opener.open(request, timeout=10) as response:
                raw = response.read(16385)
            if len(raw) > 16384:
                raise ValueError('bridge_response_too_large')
            value = json.loads(raw)
            if not isinstance(value, dict):
                raise ValueError('invalid_bridge_response')
            return value
        except HTTPError as error:
            rejection = None
            try:
                raw = error.read(16385)
                value = json.loads(raw) if len(raw) <= 16384 else None
                if (error.code in (403, 409, 422, 429) and isinstance(value, dict)
                        and value.get('ok') is False):
                    rejection = BridgeRejected(value.get('reason'), value.get('field'))
            except (OSError, ValueError, TypeError, RecursionError):
                pass
            finally:
                error.close()
            if rejection is not None:
                raise rejection from None
            raise ValueError('bridge_unavailable') from None
        except (URLError, OSError, ValueError, RecursionError):
            raise ValueError('bridge_unavailable') from None
