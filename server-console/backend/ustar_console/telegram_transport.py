"""Fixed Telegram HTTPS endpoint, optionally over a loopback CONNECT proxy."""
from http.client import HTTPSConnection
import ssl

PROXY = 'http://127.0.0.1:18081'


def validate_proxy(value):
    if value is not None and value != PROXY:
        raise ValueError('Only the fixed loopback Telegram proxy is supported')
    return value


def exchange(path, body=None, proxy=None):
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
                           headers={'Content-Type': 'application/json', 'Host': 'api.telegram.org'})
        response = connection.getresponse()
        raw = response.read(262145)
        if len(raw) > 262144:
            raise ValueError('Response too large')
        return response.status, raw
    finally:
        connection.close()
