"""Shared code format; durable binding/expiry/atomic consumption belongs to Moodle.

These helpers do not activate links. Never accept possession alone as login.
"""
import base64
import hashlib
import re
import secrets


def issue_code() -> str:
    """256-bit opaque secret, suitable for a code field in either channel."""
    return 'uc1_' + secrets.token_urlsafe(32)


def code_digest(code: str) -> str:
    """Return domain-separated hash for storage; reject noncanonical aliases."""
    if not isinstance(code, str) or not re.fullmatch(r'uc1_[A-Za-z0-9_-]{43}', code):
        raise ValueError('invalid_link_code')
    encoded = code[4:]
    decoded = base64.urlsafe_b64decode(encoded + '=')
    if base64.urlsafe_b64encode(decoded).decode().rstrip('=') != encoded:
        raise ValueError('invalid_link_code')
    return hashlib.sha256(b'ustar-connect-link-v1\0' + decoded).hexdigest()
