"""Real PHP signup values. No Moodle database, bot token or network traffic."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest
import sys

ROOT = Path(__file__).resolve().parents[2]
FORM = {'username': 'synthetic', 'password': 'Fixture-Only-Qx9!2026',
        'email': 'synthetic@example.invalid', 'firstname': 'Тест',
        'lastname': 'Connect', 'departmentid': 'retail'}
KEY = '12' * 32
sys.path.insert(0, str(ROOT/'connect-worker'))


class SignupBridgeSigning(unittest.TestCase):
    def test_scoped_registration_operations_can_be_signed_without_secret_repr(self):
        from ustar_connect.bridge import sign_request
        payload = dict(FORM, browser='ub1_'+'A'*43, session='ue1_'+'A'*43, command='ur1_'+'A'*43)
        for operation, values in [('registration.submit', payload),
                                  ('registration.status', {'browser': payload['browser'], 'session': payload['session']}),
                                  ('registration.options', {'browser': payload['browser'], 'session': payload['session']})]:
            signed = sign_request(operation, values, key_id='tg1', secret_hex=KEY, now=1700000000)
            self.assertEqual(operation, json.loads(signed.body)['operation'])
            self.assertNotIn(FORM['password'], repr(signed))


@unittest.skipUnless(os.environ.get('USTAR_TEST_PHP') or shutil.which('php'), 'PHP runtime not installed')
class SignupValues(unittest.TestCase):
    def php(self, expression, data):
        folder = ROOT / 'moodle/local/ustar/classes/connect'
        self.assertTrue((folder/'signup_request.php').is_file(), 'signup_request is not implemented')
        script = 'define("MOODLE_INTERNAL", true); '
        script += 'require ' + json.dumps(str(folder.parent/'registration_validation_exception.php')) + '; '
        for file in ('identity.php', 'opaque_token.php', 'signup_request.php'):
            script += 'require ' + json.dumps(str(folder/file)) + '; '
        script += ('$x=json_decode(stream_get_contents(STDIN),true); try {echo json_encode(' + expression + ');'
                   '} catch (\\InvalidArgumentException $e) {echo json_encode(["denied"=>$e->getMessage()]);}')
        p = subprocess.run([os.environ.get('USTAR_TEST_PHP', 'php'), '-n', '-r', script],
                           input=json.dumps(data), capture_output=True, text=True, check=True)
        self.assertEqual('', p.stderr)
        return json.loads(p.stdout)

    def fingerprint(self, form=FORM, key=KEY):
        return self.php('\\local_ustar\\connect\\signup_request::fingerprint($x["form"],$x["key"])',
                        {'form': form, 'key': key})

    def test_fingerprint_is_keyed_order_stable_and_sensitive_to_password(self):
        first = self.fingerprint()
        self.assertEqual(64, len(first))
        self.assertEqual(first, self.fingerprint(dict(reversed(list(FORM.items())))))
        self.assertNotEqual(first, self.fingerprint(dict(FORM, password='Different-Only-Qx9!2026')))
        self.assertNotEqual(first, self.fingerprint(key='34'*32))
        self.assertNotEqual(first, self.fingerprint(dict(FORM, firstname='Другой')))
        self.assertNotIn(FORM['password'], first)

    def test_unknown_or_nonstring_fields_are_denied(self):
        for changes in ({'role': 'hrd'}, {'userid': '2'}, {'username': 5}, {'password': None}, {'email': 'x'*4097}):
            self.assertEqual({'denied': 'invalid_payload'}, self.fingerprint(dict(FORM, **changes)))
        self.assertEqual({'denied': 'invalid_payload'}, self.fingerprint({'username': 'incomplete'}))

    def test_command_hash_is_canonical_and_identity_namespaced(self):
        expression = ('\\local_ustar\\connect\\signup_request::command_hash('
                      'new \\local_ustar\\connect\\identity("telegram","123456",$x["subject"]),$x["command"])')
        one = self.php(expression, {'subject': '77777', 'command': 'ur1_'+'A'*43})
        self.assertEqual(64, len(one))
        self.assertNotEqual(one, self.php(expression, {'subject': '99999', 'command': 'ur1_'+'A'*43}))
        self.assertEqual({'denied': 'invalid_opaque_token'},
                         self.php(expression, {'subject': '77777', 'command': 'ur1_'+'A'*42+'B'}))

    def test_public_field_error_never_returns_exception_message_or_unknown_field(self):
        expression = ('\\local_ustar\\connect\\signup_request::validation_error('
                      'new \\local_ustar\\registration_validation_exception($x["field"],$x["message"]))')
        for field in ('email', 'private_internal_field'):
            result = self.php(expression, {'field': field, 'message': FORM['password']})
            self.assertEqual({'ok': False, 'reason': 'validation_failed',
                              'field': 'email' if field == 'email' else 'form'}, result)
            self.assertNotIn(FORM['password'], json.dumps(result))


if __name__ == '__main__':
    unittest.main()
