"""Offline boundary tests: no bot calls, Moodle login or production data."""
import hashlib
import hmac
import json
from pathlib import Path
import sys
import unittest
from urllib.parse import urlencode

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'connect-worker'))
from ustar_connect.identity import ProviderIdentity
from ustar_connect.telegram import validate_init_data
from ustar_connect.link_code import issue_code, code_digest

TOKEN = '123456:' + 'synthetic-test-credential-not-a-real-bot'
NOW = 1800000000


def signed(**changes):
    fields = {'auth_date': str(NOW), 'user': json.dumps({'id': 8727627355,
              'first_name': 'Тест', 'username': 'not_an_identity'}), 'query_id': 'test-query'}
    fields.update(changes)
    check = '\n'.join(f'{k}={v}' for k, v in sorted(fields.items()))
    key = hmac.digest(b'WebAppData', TOKEN.encode(), 'sha256')
    fields['hash'] = hmac.new(key, check.encode(), hashlib.sha256).hexdigest()
    return urlencode(fields)


class ConnectIdentityTests(unittest.TestCase):
    def verify(self, raw, **kwargs):
        return validate_init_data(raw, bot_token=kwargs.get('bot_token', TOKEN), now=NOW)

    def test_verified_subject_is_not_username_or_receiver(self):
        launch = self.verify(signed(receiver='{"id":999}', signature='opaque-provider-signature'))
        self.assertEqual(launch.identity, ProviderIdentity('telegram', '123456', '8727627355'))
        self.assertEqual(launch.issued_at, NOW)
        self.assertEqual(len(launch.proof_id), 64)
        self.assertNotIn('not_an_identity', repr(launch))
        self.assertFalse(hasattr(launch, 'userid'))

    def test_tamper_and_other_bot_rejected(self):
        with self.assertRaises(ValueError):
            self.verify(signed().replace('8727627355', '8727627356'))
        with self.assertRaises(ValueError):
            self.verify(signed(), bot_token='987654:another-synthetic-test-secret')

    def test_age_and_clock_skew_boundaries(self):
        for delta in (-300, 0, 30):
            self.assertEqual(self.verify(signed(auth_date=str(NOW + delta))).issued_at, NOW + delta)
        for value in (str(NOW-301), str(NOW+31), '-1', '1.2', 'true', '01800000000', ''):
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.verify(signed(auth_date=value))

    def test_duplicate_query_fields_even_identical_rejected(self):
        for extra in ('&user=%7B%22id%22%3A1%7D', '&auth_date=1800000000',
                      '&%75ser=%7B%7D', '&hash=' + '0'*64):
            with self.subTest(extra=extra), self.assertRaises(ValueError):
                self.verify(signed() + extra)

    def test_strict_user_ids_and_duplicate_json_keys(self):
        for user in ('{}', '[]', 'null', '{"id":true}', '{"id":1.0}', '{"id":"42"}',
                     '{"id":0}', '{"id":-1}', '{"id":4503599627370496}',
                     '{"id":1,"id":2}', '{"id":1,"is_bot":true}',
                     '{"id":1,"first_name":NaN}', '['*1000+'0'+']'*1000):
            with self.subTest(user=user[:60]), self.assertRaises(ValueError):
                self.verify(signed(user=user))

    def test_malformed_transport_and_limits(self):
        for raw in ('', 'a=b', signed()+'&bad=%ZZ', signed()+'&bad=%FF',
                    signed()+'&bare', signed()+'&bad=line%0Abreak',
                    signed()+'&=emptykey', signed()+'&bad=%', '\ud800',
                    signed()+'&x='+'a'*17000,
                    signed(**{f'field{i}':'x' for i in range(33)})):
            with self.subTest(raw=raw[:60]), self.assertRaises(ValueError):
                self.verify(raw)

    def test_error_does_not_disclose_input_or_token(self):
        secret = 'PRIVATE_INPUT_CANARY'
        with self.assertRaises(ValueError) as error:
            self.verify(signed(user=secret))
        self.assertNotIn(secret, str(error.exception))
        self.assertNotIn(TOKEN, str(error.exception))

    def test_proof_id_is_stable_for_reordered_encoding(self):
        raw = signed()
        self.assertEqual(self.verify(raw).proof_id, self.verify('&'.join(reversed(raw.split('&')))).proof_id)

    def test_identity_namespaces_do_not_collide(self):
        identities = [ProviderIdentity('telegram', '10', '42'),
                      ProviderIdentity('telegram', '11', '42'),
                      ProviderIdentity('bitrix', '10', '42'),
                      ProviderIdentity('whatsapp', '10', '42')]
        self.assertEqual(len({item.key() for item in identities}), 4)
        for args in [('unknown', '10', '42'), ('telegram', '', '42'),
                     ('telegram', ' 10', '42'), ('telegram', '10', '42\n'),
                     ('bitrix', 'a'*129, '42'), ('telegram', '10', 42)]:
            with self.subTest(args=args), self.assertRaises(ValueError):
                ProviderIdentity(*args)

    def test_link_codes_are_random_canonical_and_channel_neutral(self):
        codes = [issue_code() for _ in range(100)]
        self.assertEqual(len(set(codes)), 100)
        for code in codes:
            self.assertRegex(code, r'^uc1_[A-Za-z0-9_-]{43}$')
            digest = code_digest(code)
            self.assertEqual(len(digest), 64)
            self.assertEqual(digest, code_digest(code))
            self.assertNotEqual(digest, code)

    def test_bad_link_codes_rejected(self):
        for code in ('', 'uc1_short', 'uc1_'+'A'*42+'B', 'uc1_'+'A'*43+'=',
                     'uc1_'+'A'*42+'/', 'uc2_'+'A'*43, 'uc1_'+'A'*43+'\n', 42):
            with self.subTest(code=code), self.assertRaises(ValueError):
                code_digest(code)


if __name__ == '__main__':
    unittest.main()
