"""RFC 6238 SHA-1 TOTP; six digits, 30s, one-step clock tolerance."""
import base64
import hashlib
import hmac
import re
import secrets
import struct


def secret():
    return base64.b32encode(secrets.token_bytes(20)).decode().rstrip('=')


def code(key, counter):
    raw=base64.b32decode(key+'='*((-len(key))%8),casefold=False)
    value=hmac.new(raw,struct.pack('>Q',counter),hashlib.sha1).digest()
    offset=value[-1]&15
    number=(struct.unpack('>I',value[offset:offset+4])[0]&0x7fffffff)%1000000
    return str(number).zfill(6)


def verify(key, supplied, now, previous=-1):
    if not re.fullmatch(r'[0-9]{6}',supplied):
        return None
    counter=int(now//30)
    for candidate in (counter,counter-1,counter+1):
        if candidate>=0 and candidate>previous and hmac.compare_digest(code(key,candidate),supplied):
            return candidate
    return None
