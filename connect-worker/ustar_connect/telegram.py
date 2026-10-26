"""Verify Telegram Mini App HMAC on the private worker, never on public edge.

The caller must still consume a browser-bound nonce, resolve an Academy link,
and check current employment/capabilities. Fresh signed initData is replayable;
this module does NOT issue a login session or attest Academy membership.
Reference: https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
"""
from dataclasses import dataclass
import hashlib
import hmac
import json
import re
from urllib.parse import parse_qsl

from .identity import ProviderIdentity


@dataclass(frozen=True, slots=True)
class TelegramLaunch:
    identity: ProviderIdentity
    issued_at: int
    proof_id: str
    start_param: str | None = None


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('invalid_user')
        result[key] = value
    return result


def _invalid_constant(_value):
    raise ValueError('invalid_user')


def validate_init_data(raw: str, *, bot_token: str, now: int) -> TelegramLaunch:
    """Validate origin/freshness and return only the signed sender identity.

    bot_token/now are trusted server configuration/clock, never request fields.
    HMAC includes every decoded field except hash (including signature).
    Limits are policy constants: 16KiB, 32 fields, 300s age, 30s clock skew.
    """
    if (not isinstance(bot_token, str)
            or not re.fullmatch(r'[1-9][0-9]{0,19}:[A-Za-z0-9_-]{20,256}', bot_token)
            or type(now) is not int or now <= 0):
        raise ValueError('invalid_verifier_configuration')
    try:
        if not isinstance(raw, str) or not 0 < len(raw.encode('utf-8')) <= 16384:
            raise ValueError('invalid_launch')
        if re.search(r'%(?![0-9A-Fa-f]{2})', raw):
            raise ValueError('invalid_launch')
        pairs = parse_qsl(raw, keep_blank_values=True, strict_parsing=True,
                          encoding='utf-8', errors='strict', max_num_fields=32)
        fields = {}
        for key, value in pairs:
            if (key in fields or not re.fullmatch(r'[a-z_]{1,64}', key)
                    or any(ord(char) < 32 or ord(char) == 127 for char in value)):
                raise ValueError('invalid_launch')
            fields[key] = value
    except (ValueError, UnicodeError):
        raise ValueError('invalid_launch') from None

    signature = fields.pop('hash', '')
    if not re.fullmatch(r'[0-9a-f]{64}', signature):
        raise ValueError('invalid_signature')
    check = '\n'.join(f'{key}={value}' for key, value in sorted(fields.items()))
    secret = hmac.digest(b'WebAppData', bot_token.encode(), 'sha256')
    expected = hmac.new(secret, check.encode('utf-8'), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise ValueError('invalid_signature')

    date = fields.get('auth_date', '')
    if not re.fullmatch(r'[1-9][0-9]{0,11}', date):
        raise ValueError('invalid_auth_date')
    issued_at = int(date)
    if issued_at < now - 300 or issued_at > now + 30:
        raise ValueError('expired_launch')
    try:
        user = json.loads(fields.get('user', ''), object_pairs_hook=_unique_object,
                          parse_constant=_invalid_constant)
        if (not isinstance(user, dict) or type(user.get('id')) is not int
                or not 0 < user['id'] < 2**52 or user.get('is_bot', False) is not False):
            raise ValueError('invalid_user')
    except (ValueError, RecursionError):
        raise ValueError('invalid_user') from None
    identity = ProviderIdentity('telegram', bot_token.split(':', 1)[0], str(user['id']))
    # Same signed launch keeps its replay lookup key even if query order/encoding changes.
    proof_id = hashlib.sha256((identity.tenant + ':' + signature).encode()).hexdigest()
    start = fields.get('start_param')
    if start is not None and not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', start):
        raise ValueError('invalid_start_param')
    return TelegramLaunch(identity, issued_at, proof_id, start)
