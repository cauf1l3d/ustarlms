#!/usr/bin/env python3
"""Read-only NOVAVPS format probe. Never print subscription URLs or node values."""
import base64
from collections import Counter
import getpass
import json
import signal
import sys
import urllib.error
import urllib.parse
import urllib.request


HOST = 'novavps.app'
LIMIT = 1024 * 1024
NODE_LIMIT = 512
PROTOCOLS = {'vless', 'vmess', 'trojan', 'shadowsocks', 'socks', 'http',
             'hysteria', 'hysteria2', 'wireguard', 'freedom', 'blackhole', 'dns', 'loopback'}
ALIASES = {'ss': 'shadowsocks', 'socks5': 'socks', 'hy2': 'hysteria2'}
NETWORKS = {'tcp', 'raw', 'ws', 'grpc', 'http', 'h2', 'httpupgrade', 'xhttp',
            'splithttp', 'kcp', 'mkcp', 'quic', 'hysteria'}
SECURITY = {'tls', 'reality', 'xtls', 'none'}


class ProbeError(Exception):
    def __init__(self, code, http_status=None):
        super().__init__(code)
        self.code, self.http_status = code, http_status


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        return None


def validate_url(value, initial=True):
    try:
        parsed = urllib.parse.urlsplit(value)
        valid = (isinstance(value, str) and len(value) <= 8192 and value.isascii()
                 and not any(ord(c) <= 32 or ord(c) == 127 for c in value)
                 and parsed.scheme == 'https' and parsed.hostname == HOST
                 and parsed.port in (None, 443) and not parsed.username
                 and not parsed.password and not parsed.fragment
                 and (not initial or parsed.path.startswith('/s/') and len(parsed.path) > 3))
    except (ValueError, TypeError, AttributeError):
        valid = False
    if not valid:
        raise ProbeError('invalid_subscription_URL')
    return value


def fetch_subscription(value, opener=None):
    value = validate_url(value)
    if opener is None:
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    for hop in range(3):
        request = urllib.request.Request(value, headers={
            'User-Agent': 'USTAR-Subscription-Probe/1.0',
            'Accept': 'text/plain, application/json;q=0.9, */*;q=0.1',
            'Accept-Encoding': 'identity',
        })
        try:
            with opener.open(request, timeout=8) as response:
                body = response.read(LIMIT + 1)
            if len(body) > LIMIT:
                raise ProbeError('response_too_large')
            return body
        except urllib.error.HTTPError as error:
            code, location = error.code, (error.headers or {}).get('Location', '')
            try:
                error.close()
            except OSError:
                pass
            if code in (301, 302, 303, 307, 308):
                if hop == 2:
                    raise ProbeError('too_many_redirects') from None
                try:
                    value = validate_url(urllib.parse.urljoin(value, location), initial=False)
                except (ProbeError, ValueError, TypeError):
                    raise ProbeError('redirect_not_allowed') from None
                if not location:
                    raise ProbeError('redirect_not_allowed')
                continue
            raise ProbeError('HTTP_error', http_status=code) from None
        except (OSError, ValueError, urllib.error.URLError):
            # Exceptions can contain the complete secret-bearing URL.
            raise ProbeError('connection_or_TLS_failed') from None
    raise ProbeError('too_many_redirects')


def bucket(value, allowed):
    if value is None or value == '':
        return 'unspecified'
    return value if isinstance(value, str) and value in allowed else 'other'


def decode_base64(value):
    compact = b''.join(value.split())
    return base64.b64decode(compact + b'=' * (-len(compact) % 4), altchars=b'-_', validate=True)


def summarize(body):
    if len(body) > LIMIT:
        raise ProbeError('response_too_large')
    text = body.decode('utf-8-sig', errors='replace').strip()
    encoded = False
    if text and text[0] not in '{[<' and '://' not in text:
        try:
            text = decode_base64(text.encode('ascii')).decode('utf-8-sig').strip()
            encoded = True
        except (ValueError, UnicodeError):
            pass
    summary = dict(result='inspected', format='unrecognized', base64_wrapped=encoded,
                   nodes_examined=0, protocols={}, networks={}, transport_security={},
                   nodes_with_insecure_TLS=0, output_contains_node_values=False,
                   node_values_validated=False, configuration_installed=False)
    protocols, networks, security = Counter(), Counter(), Counter()

    def node(protocol, network=None, tls=None, insecure=False):
        if summary['nodes_examined'] >= NODE_LIMIT:
            return
        summary['nodes_examined'] += 1
        protocol = ALIASES.get(protocol, protocol) if isinstance(protocol, str) else None
        protocols[bucket(protocol, PROTOCOLS)] += 1
        networks[bucket(network, NETWORKS)] += 1
        security[bucket(tls, SECURITY)] += 1
        summary['nodes_with_insecure_TLS'] += int(insecure is True or insecure in ('1', 'true'))

    if text.lower().startswith(('<!doctype', '<html', '<head', '<body')):
        summary['format'] = 'HTML_page'
    elif text.startswith('happ://crypto'):
        summary['format'] = 'Happ_encrypted'
    elif text.startswith(('{', '[')):
        try:
            data = json.loads(text)
        except (ValueError, RecursionError):
            summary['format'] = 'invalid_JSON'
        else:
            summary['format'] = 'JSON_other'
            profiles = data[:NODE_LIMIT] if isinstance(data, list) else [data]
            for profile in profiles:
                if not isinstance(profile, dict) or not isinstance(profile.get('outbounds'), list):
                    continue
                summary['format'] = 'JSON_outbounds'
                for item in profile['outbounds'][:NODE_LIMIT]:
                    if not isinstance(item, dict):
                        continue
                    stream = item.get('streamSettings', {})
                    stream = stream if isinstance(stream, dict) else {}
                    tls = item.get('tls', {})
                    tls = tls if isinstance(tls, dict) else {}
                    tls_settings = stream.get('tlsSettings', {})
                    tls_settings = tls_settings if isinstance(tls_settings, dict) else {}
                    reality = tls.get('reality', {})
                    reality = reality if isinstance(reality, dict) else {}
                    mode = stream.get('security') or ('reality' if reality.get('enabled') is True else
                            'tls' if tls.get('enabled') is True else None)
                    node(item.get('protocol', item.get('type')), stream.get('network'), mode,
                         tls_settings.get('allowInsecure', tls.get('insecure', False)))
    else:
        for line in text.splitlines()[:NODE_LIMIT]:
            line = line.strip()
            if not line or line.startswith('#') or '://' not in line:
                continue
            protocol = ALIASES.get(line.partition('://')[0].lower(), line.partition('://')[0].lower())
            if protocol not in PROTOCOLS:
                continue
            try:
                parsed = urllib.parse.urlsplit(line)
                params = urllib.parse.parse_qs(parsed.query, max_num_fields=128)
                network = params.get('type', [None])[0]
                tls = params.get('security', [None])[0]
                insecure = params.get('allowInsecure', params.get('insecure', [False]))[0]
                if protocol == 'vmess':
                    payload = json.loads(decode_base64(line.partition('://')[2].encode('ascii')))
                    if isinstance(payload, dict):
                        network, tls = payload.get('net'), payload.get('tls')
                        insecure = payload.get('allowInsecure', False)
                node(protocol, network, tls, insecure)
            except (ValueError, UnicodeError, RecursionError):
                node(protocol)
        if summary['nodes_examined']:
            summary['format'] = 'URI_list'
    summary.update(protocols=dict(protocols), networks=dict(networks), transport_security=dict(security))
    return summary


def main():
    if not sys.stdin.isatty():
        print(json.dumps(dict(result='unavailable', reason='interactive_TTY_required')))
        return 1
    try:
        value = getpass.getpass('Subscription URL (hidden): ').strip()
    except (EOFError, KeyboardInterrupt):
        print(json.dumps(dict(result='unavailable', reason='cancelled')))
        return 1
    def timed_out(*_):
        raise ProbeError('deadline_exceeded')
    previous = signal.signal(signal.SIGALRM, timed_out)
    signal.alarm(30)
    try:
        result = summarize(fetch_subscription(value))
    except ProbeError as error:
        result = dict(result='unavailable', reason=error.code)
        if error.http_status is not None:
            result['HTTP_status'] = error.http_status
    except (EOFError, KeyboardInterrupt):
        result = dict(result='unavailable', reason='cancelled')
    except Exception:
        # Keep the privacy boundary even if an unanticipated response triggers a bug.
        result = dict(result='unavailable', reason='inspection_failed')
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous)
    print(json.dumps(result, ensure_ascii=True, indent=2))
    return int(result['result'] != 'inspected')


if __name__ == '__main__':
    sys.exit(main())
