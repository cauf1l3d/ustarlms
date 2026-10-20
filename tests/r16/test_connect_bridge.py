"""Bridge authentication contract: synthetic keys only, no server/bot traffic."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import unittest
from unittest.mock import Mock, MagicMock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'connect-worker'))
from ustar_connect.bridge import sign_request, BridgeClient, telegram_exchange_payload
from ustar_connect.telegram import validate_init_data
from test_connect_identity import signed, NOW, TOKEN

KEY = '12' * 32
NONCE = 'rn1_' + 'A' * 43
PAYLOAD = {'provider': 'telegram', 'tenant': '123456', 'subject': '8727627355'}


class BridgeWorkerTests(unittest.TestCase):
    def test_link_scope_probe_accepts_only_exact_authenticated_scope_denial(self):
        import io
        from urllib.error import HTTPError
        client=BridgeClient('https://localhost/local/ustar/connect_bridge.php','tg1',KEY)
        client._opener=Mock()
        for code,body,accepted in [(403,b'{"ok":false,"reason":"forbidden_operation"}',True),
            (409,b'{"ok":false,"reason":"forbidden_operation"}',False),
            (403,b'{"ok":false,"reason":"invalid_transport"}',False),
            (404,b'{"ok":false,"reason":"bridge_disabled"}',False),
            (403,b'<html>PRIVATE_CANARY</html>',False)]:
            client._opener.open.side_effect=HTTPError('https://localhost',code,'fixture',{},io.BytesIO(body))
            if accepted:self.assertIsNone(client.probe_link_scope())
            else:
                with self.assertRaisesRegex(ValueError,'^bridge_scope_unverified$'):client.probe_link_scope()
            request=client._opener.open.call_args.args[0]
            self.assertEqual({'operation':'registration.options','payload':{'probe':'link-scope-v1'}},json.loads(request.data))
        client._opener.open.side_effect=None
        client._opener.open.return_value=MagicMock()
        client._opener.open.return_value.__enter__.return_value.read.return_value=b'{"ok":true,"result":{}}'
        with self.assertRaisesRegex(ValueError,'^bridge_scope_unverified$'):client.probe_link_scope()

    def test_body_and_signature_are_stable_and_no_secret_in_repr(self):
        request = sign_request('launch.create', PAYLOAD, key_id='tg1', secret_hex=KEY, now=NOW, nonce=NONCE)
        self.assertEqual(json.loads(request.body), {'operation': 'launch.create', 'payload': PAYLOAD})
        self.assertEqual(request.headers['X-Ustar-Time'], str(NOW))
        self.assertEqual(len(request.headers['X-Ustar-Signature']), 64)
        self.assertNotIn(KEY, repr(request))

    def test_signed_telegram_start_param_is_required_for_exchange(self):
        nonce = 'ul1_' + 'A' * 43
        proof = validate_init_data(signed(start_param=nonce), bot_token=TOKEN, now=NOW)
        payload = telegram_exchange_payload(proof, 'ub1_' + 'A' * 43)
        self.assertEqual(payload['launch'], nonce)
        self.assertEqual(payload['subject'], PAYLOAD['subject'])
        self.assertNotIn('username', payload)
        with self.assertRaises(ValueError):
            telegram_exchange_payload(validate_init_data(signed(), bot_token=TOKEN, now=NOW), 'ub1_'+'A'*43)

    def test_client_requires_fixed_https_origin_and_keeps_secret_private(self):
        client = BridgeClient('https://localhost/local/ustar/connect_bridge.php', 'tg1', KEY)
        self.assertNotIn(KEY, repr(client))
        for endpoint in ('http://localhost/local/ustar/connect_bridge.php',
                         'https://user:pass@localhost/local/ustar/connect_bridge.php',
                         'https://localhost/other', 'https://localhost/local/ustar/connect_bridge.php?token=bad'):
            with self.subTest(endpoint=endpoint), self.assertRaises(ValueError):
                BridgeClient(endpoint, 'tg1', KEY)

    def test_client_denies_redirects_and_checks_tls_without_ambient_proxies(self):
        from urllib.request import HTTPSHandler
        import ssl
        client = BridgeClient('https://localhost/local/ustar/connect_bridge.php', 'tg1', KEY)
        tls = next(x for x in client._opener.handlers if isinstance(x, HTTPSHandler))._context
        self.assertTrue(tls.check_hostname)
        self.assertEqual(tls.verify_mode, ssl.CERT_REQUIRED)
        from ustar_connect.bridge import _NoRedirect
        self.assertIsNone(_NoRedirect().redirect_request(None, None, 302, '', {}, 'https://other.invalid'))
        # Explicit empty ProxyHandler prevents environment proxy credentials being used.
        self.assertFalse(any(hasattr(x, 'proxies') and x.proxies for x in client._opener.handlers))
        client._opener = Mock()
        client._opener.open.side_effect = OSError('synthetic private transport detail')
        with self.assertRaisesRegex(ValueError, '^bridge_unavailable$'):
            client.request('launch.create', PAYLOAD)

    def test_body_size_is_bounded_before_network_io(self):
        with self.assertRaisesRegex(ValueError, 'bridge_payload_too_large'):
            sign_request('launch.create', {'subject': 'x'*16384}, key_id='tg1', secret_hex=KEY, now=NOW)


@unittest.skipUnless(os.environ.get('USTAR_TEST_PHP') or shutil.which('php'), 'PHP runtime not installed')
class BridgeCrossLanguageTests(unittest.TestCase):
    def test_scope_probe_requires_auth_and_rejects_broad_key_before_any_database_use(self):
        from dataclasses import replace
        request=sign_request('registration.options',{'probe':'link-scope-v1'},key_id='tg1',secret_hex=KEY,now=NOW)
        self.assertEqual({'denied':'forbidden_operation'},self.php_verify(request,scopes=['link.claim']))
        altered=replace(request,headers={**request.headers,'X-Ustar-Signature':'0'*64})
        self.assertEqual({'denied':'invalid_transport'},self.php_verify(altered,scopes=['link.claim']))
        # Exercise actual dispatch with no Moodle or DB object. Overbroad keys
        # must stop at field validation, before remember_transport can access DB.
        script=('define("MOODLE_INTERNAL",true); require '+json.dumps(str(ROOT/'moodle/local/ustar/classes/connect/bridge_auth.php'))+'; '
            'require '+json.dumps(str(ROOT/'moodle/local/ustar/classes/connect/bridge_service.php'))+'; '
            '$x=json_decode(stream_get_contents(STDIN),true); try {'
            '\\local_ustar\\connect\\bridge_service::dispatch($x["body"],$x["headers"],$x["keys"]);'
            '}catch(\\InvalidArgumentException $e){echo $e->getMessage();}')
        request=sign_request('registration.options',{'probe':'link-scope-v1'},key_id='tg1',secret_hex=KEY,now=int(__import__('time').time()))
        data={'body':request.body.decode(),'headers':request.headers,'keys':{'tg1':{
            'secret':KEY,'provider':'telegram','tenant':'123456','operations':['registration.options','link.claim']}}}
        result=subprocess.run([os.environ.get('USTAR_TEST_PHP','php'),'-n','-r',script],input=json.dumps(data),text=True,capture_output=True,check=True)
        self.assertEqual('invalid_payload',result.stdout);self.assertEqual('',result.stderr)

    def php_verify(self, request, *, now=NOW, scopes=None):
        source = ROOT / 'moodle/local/ustar/classes/connect/bridge_auth.php'
        data = {'body': request.body.decode(), 'headers': request.headers, 'now': now,
                'keys': {'tg1': {'secret': KEY, 'provider': 'telegram', 'tenant': '123456',
                                 'operations': scopes or ['launch.create']}}}
        script = ('define("MOODLE_INTERNAL",true); require ' + json.dumps(str(source)) + '; '
                  '$x=json_decode(stream_get_contents(STDIN),true); try {'
                  '$r=\\local_ustar\\connect\\bridge_auth::verify($x["body"],$x["headers"],$x["keys"],$x["now"]);'
                  'echo json_encode(["operation"=>$r["operation"],"payload"=>$r["payload"]]);'
                  '}catch(\\InvalidArgumentException $e){echo json_encode(["denied"=>$e->getMessage()]);}')
        result = subprocess.run([os.environ.get('USTAR_TEST_PHP', 'php'), '-n', '-r', script],
                                input=json.dumps(data), text=True, capture_output=True, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_python_request_is_accepted_by_php(self):
        request = sign_request('launch.create', PAYLOAD, key_id='tg1', secret_hex=KEY, now=NOW, nonce=NONCE)
        self.assertEqual(self.php_verify(request), {'operation': 'launch.create', 'payload': PAYLOAD})

    def test_time_key_operation_and_body_tamper_are_denied(self):
        request = sign_request('launch.create', PAYLOAD, key_id='tg1', secret_hex=KEY, now=NOW, nonce=NONCE)
        self.assertIn('denied', self.php_verify(request, now=NOW+31))
        self.assertIn('denied', self.php_verify(request, scopes=['entry.status']))
        from dataclasses import replace
        altered = replace(request, body=request.body.replace(b'8727627355', b'8727627356'))
        self.assertIn('denied', self.php_verify(altered))
        headers = dict(request.headers, **{'X-Ustar-Key': 'unknown'})
        self.assertIn('denied', self.php_verify(replace(request, headers=headers)))

    def test_authenticated_duplicate_fields_and_noncanonical_json_are_denied(self):
        import hashlib
        import hmac
        from dataclasses import replace
        request = sign_request('launch.create', PAYLOAD, key_id='tg1', secret_hex=KEY, now=NOW, nonce=NONCE)
        for body in (request.body.replace(b'"subject":', b'"subject":"99","subject":'),
                     request.body.replace(b':', b': ', 1)):
            message = '\n'.join(['USTAR-CONNECT-BRIDGE-v1', 'POST', '/local/ustar/connect_bridge.php',
                                 'tg1', str(NOW), NONCE, hashlib.sha256(body).hexdigest()]).encode()
            headers = dict(request.headers)
            headers['X-Ustar-Signature'] = hmac.new(bytes.fromhex(KEY), message, hashlib.sha256).hexdigest()
            self.assertEqual(self.php_verify(replace(request, body=body, headers=headers)), {'denied': 'invalid_request'})


if __name__ == '__main__':
    unittest.main()
