import base64
import importlib.util
import io
import json
from pathlib import Path
import urllib.error

import pytest


spec = importlib.util.spec_from_file_location('subscription_probe', Path(__file__).parents[1]/'tools/subscription_probe.py')
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)
URL = 'https://novavps.app/s/fixture-secret-subscription'
SECRET = 'fixture-private-value-must-not-appear'


@pytest.mark.parametrize('url', [
    'http://novavps.app/s/private', 'https://other.test/s/private',
    'https://novavps.app.other.test/s/private', 'https://user:password@novavps.app/s/private',
    'https://novavps.app/', 'https://novavps.app/s/',
    'https://novavps.app:8443/s/private', 'https://novavps.app/s/private#fragment',
    'https://novavps.app/s/private\nInjected: value',
])
def test_secret_input_cannot_select_another_origin_or_inject_headers(url):
    with pytest.raises(probe.ProbeError, match='invalid_subscription_URL'):
        probe.validate_url(url)


@pytest.mark.parametrize('encoded', [False, True])
def test_subscription_outputs_only_fixed_metadata_and_never_node_values(encoded):
    text = f'vless://{SECRET}@private-host.example:443?type=tcp&security=reality&pbk={SECRET}#{SECRET}'
    body = base64.b64encode(text.encode()) if encoded else text.encode()
    value = probe.summarize(body)
    assert value['format'] == 'URI_list' and value['protocols'] == {'vless': 1}
    assert value['networks'] == {'tcp': 1} and value['transport_security'] == {'reality': 1}
    assert SECRET not in json.dumps(value) and 'private-host' not in json.dumps(value)


def test_unknown_transport_values_are_never_copied_to_output():
    value = probe.summarize(f'vless://{SECRET}@example.test:443?type={SECRET}&security={SECRET}'.encode())
    assert value['networks'] == {'other': 1} and value['transport_security'] == {'other': 1}
    assert SECRET not in json.dumps(value)


def test_vmess_and_JSON_config_metadata_exclude_user_and_server_fields():
    payload = dict(id=SECRET, add=SECRET, net='ws', tls='tls', allowInsecure=True)
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    value = probe.summarize(('vmess://'+encoded).encode())
    assert value['protocols'] == {'vmess': 1} and value['nodes_with_insecure_TLS'] == 1
    data = dict(outbounds=[dict(protocol='vless', settings={'secret': SECRET},
                streamSettings=dict(network='grpc', security='tls', tlsSettings={'allowInsecure': True}))])
    value = probe.summarize(json.dumps(data).encode())
    assert value['format'] == 'JSON_outbounds' and value['networks'] == {'grpc': 1}
    assert value['nodes_with_insecure_TLS'] == 1 and SECRET not in json.dumps(value)


@pytest.mark.parametrize('body,expected', [
    (b'<html>fixture-private-value-must-not-appear</html>', 'HTML_page'),
    (b'happ://crypto-fixture-private-value-must-not-appear', 'Happ_encrypted'),
    (b'{"token":"fixture-private-value-must-not-appear"}', 'JSON_other'),
    (b'{"unterminated":', 'invalid_JSON'),
], ids=['HTML', 'Happ_encrypted', 'JSON_other', 'malformed_JSON'])
def test_non_configuration_responses_are_classified_without_contents(body, expected):
    value = probe.summarize(body)
    assert value['format'] == expected and value['nodes_examined'] == 0
    assert SECRET not in json.dumps(value)


class Opener:
    def __init__(self, responses): self.responses, self.calls = iter(responses), []
    def open(self, request, timeout):
        self.calls.append(request.full_url)
        result = next(self.responses)
        if isinstance(result, Exception): raise result
        return io.BytesIO(result)


def redirect(location):
    return urllib.error.HTTPError(URL, 302, 'private response', {'Location': location}, io.BytesIO(SECRET.encode()))


def test_same_origin_redirect_is_bounded_but_foreign_origin_never_receives_request():
    opener = Opener([redirect('/export/private'), b'fixture'])
    assert probe.fetch_subscription(URL, opener) == b'fixture' and len(opener.calls) == 2
    for location in ('https://other.test/steal', 'http://novavps.app/private', 'happ://crypto-private'):
        opener = Opener([redirect(location)])
        with pytest.raises(probe.ProbeError, match='redirect_not_allowed'):
            probe.fetch_subscription(URL, opener)
        assert opener.calls == [URL]
    with pytest.raises(probe.ProbeError, match='too_many_redirects'):
        probe.fetch_subscription(URL, Opener([redirect('/x')]*3))


def test_error_bodies_and_secret_URL_exceptions_are_not_exposed():
    error = urllib.error.HTTPError(URL, 403, SECRET, {}, io.BytesIO(SECRET.encode()))
    with pytest.raises(probe.ProbeError) as caught:
        probe.fetch_subscription(URL, Opener([error]))
    assert caught.value.http_status == 403 and SECRET not in str(caught.value) and error.fp.closed
    with pytest.raises(probe.ProbeError, match='connection_or_TLS_failed') as caught:
        probe.fetch_subscription(URL, Opener([urllib.error.URLError(URL+SECRET)]))
    assert SECRET not in str(caught.value)


def test_subscription_size_and_node_count_are_bounded():
    with pytest.raises(probe.ProbeError, match='response_too_large'):
        probe.fetch_subscription(URL, Opener([b'x'*(probe.LIMIT+1)]))
    value = probe.summarize(b'vless://private@example.test:443\n'*(probe.NODE_LIMIT+1))
    assert value['nodes_examined'] == probe.NODE_LIMIT


def test_malformed_URI_does_not_reuse_previous_protocol():
    value = probe.summarize(b'vless://private@example.test:443\nvmess://[malformed\n')
    assert value['protocols'] == {'vless': 1, 'vmess': 1}
    assert value['node_values_validated'] is False


def test_terminal_probe_never_prints_secret_exceptions(monkeypatch, capsys):
    monkeypatch.setattr(probe.sys.stdin, 'isatty', lambda: True)
    monkeypatch.setattr(probe.getpass, 'getpass', lambda *_: URL)
    def fail(_): raise RuntimeError(URL+SECRET)
    monkeypatch.setattr(probe, 'fetch_subscription', fail)
    assert probe.main() == 1
    captured = capsys.readouterr()
    assert json.loads(captured.out)['reason'] == 'inspection_failed'
    assert SECRET not in captured.out+captured.err and URL not in captured.out+captured.err
