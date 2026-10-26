"""Fixed Telegram HTTPS endpoint, optionally over a loopback CONNECT proxy."""
from http.client import HTTPSConnection
import json
import secrets
import ssl

PROXY = 'http://127.0.0.1:18081'
PHOTO_LIMIT = 262144


def photo_body(data):
    """Only an in-memory generated PNG; never a URL, file ID or local path."""
    if (not isinstance(data,dict) or set(data)-{'chat_id','photo','caption','protect_content','reply_markup'}
            or type(data.get('chat_id')) is not int or not 0<data['chat_id']<2**52):
        raise ValueError('Invalid photo payload')
    photo=data.get('photo')
    if type(photo) is not bytes or not photo.startswith(b'\x89PNG\r\n\x1a\n') or not 8<len(photo)<=PHOTO_LIMIT:
        raise ValueError('Bounded generated PNG required')
    if (not isinstance(data.get('caption',''),str) or len(data.get('caption',''))>1024
            or data.get('protect_content') is not True):
        raise ValueError('Invalid photo options')
    boundary='ustar-'+secrets.token_hex(16)
    parts=[]
    for key in ('chat_id','caption','protect_content','reply_markup'):
        if key not in data: continue
        value=data[key] if isinstance(data[key],str) else json.dumps(data[key],ensure_ascii=False)
        raw=value.encode('utf-8')
        if len(raw)>8192: raise ValueError('Photo options too large')
        parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n').encode()+raw+b'\r\n')
    parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="photo"; filename="ustar.png"\r\n'
                  'Content-Type: image/png\r\n\r\n').encode()+photo+b'\r\n')
    parts.append(('--'+boundary+'--\r\n').encode())
    return b''.join(parts),'multipart/form-data; boundary='+boundary


def validate_proxy(value):
    if value is not None and value != PROXY:
        raise ValueError('Only the fixed loopback Telegram proxy is supported')
    return value


def exchange(path, body=None, proxy=None, content_type='application/json'):
    """No redirects, environment proxies, bypass rules, or unverified TLS."""
    validate_proxy(proxy)
    context = ssl.create_default_context()
    context.set_alpn_protocols(['http/1.1'])
    connection = HTTPSConnection(
        '127.0.0.1' if proxy else 'api.telegram.org',
        18081 if proxy else 443, timeout=8, context=context)
    try:
        if proxy:
            connection.set_tunnel('api.telegram.org', 443)
        connection.request('GET' if body is None else 'POST', path, body=body,
                           headers={'Content-Type': content_type, 'Host': 'api.telegram.org'})
        response = connection.getresponse()
        raw = response.read(262145)
        if len(raw) > 262144:
            raise ValueError('Response too large')
        return response.status, raw
    finally:
        connection.close()
