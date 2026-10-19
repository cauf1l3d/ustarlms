"""Real PHP value-contract checks; native Moodle DB checks live in connect-db CI."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'connect-worker'))
from ustar_connect.identity import ProviderIdentity
from ustar_connect.link_code import code_digest


@unittest.skipUnless(os.environ.get('USTAR_TEST_PHP') or shutil.which('php'), 'PHP runtime not installed')
class ConnectFacadeValues(unittest.TestCase):
    def run_php(self, operation):
        script = ('define("MOODLE_INTERNAL",true); require ' +
                  json.dumps(str(ROOT / 'moodle/local/ustar/classes/connect/identity.php')) + '; ' + operation)
        result = subprocess.run([os.environ.get('USTAR_TEST_PHP', 'php'), '-n', '-r', script],
                                text=True, capture_output=True, check=True)
        self.assertEqual(result.stderr, '')
        return result.stdout.strip()

    def test_worker_and_php_share_identity_namespace_and_hash(self):
        self.assertEqual(self.run_php('echo (new \\local_ustar\\connect\\identity("telegram","123456","8727627355"))->key();'),
                         ProviderIdentity('telegram', '123456', '8727627355').key())

    def test_php_codes_are_canonical_worker_codes(self):
        values = json.loads(self.run_php(r'''$out=[]; for($i=0;$i<10;$i++) {
            $code=\local_ustar\connect\identity::issue_code();
            $out[] = [$code, \local_ustar\connect\identity::code_hash($code)];
        } echo json_encode($out);'''))
        self.assertEqual(len({code for code, _ in values}), 10)
        for code, digest in values:
            self.assertEqual(code_digest(code), digest)

    def test_noncanonical_code_is_rejected_without_exposing_it(self):
        self.assertEqual(self.run_php(r'''try {
            \local_ustar\connect\identity::code_hash("uc1_".str_repeat("A",42)."B");
        } catch (\InvalidArgumentException $e) {echo $e->getMessage();}'''), 'invalid_link_code')


class ConnectSchemaContract(unittest.TestCase):
    def test_schema_has_unique_identity_owner_slot_and_replay_constraints(self):
        schema = ET.parse(ROOT / 'moodle/local/ustar/db/install.xml')
        expected = {'local_ustar_conn_links': {'identityhash', 'userchannelhash'},
                    'local_ustar_conn_challenges': {'codehash'},
                    'local_ustar_conn_proofs': {'proofhash'}}
        for name, unique in expected.items():
            table = schema.find(f'.//TABLE[@NAME="{name}"]')
            self.assertIsNotNone(table)
            indexes = {i.attrib['FIELDS'] for i in table.findall('./INDEXES/INDEX')
                       if i.attrib['UNIQUE'] == 'true'}
            self.assertTrue(unique <= indexes)
            fields = {f.attrib['NAME'] for f in table.findall('./FIELDS/FIELD')}
            self.assertFalse(fields & {'password', 'token', 'code', 'sesskey', 'role'})

    def test_privileged_claim_has_no_public_ajax_registration(self):
        # Public source inventory must not register this internal trust boundary.
        source = (ROOT / 'moodle/local/ustar/db/services.php').read_text()
        self.assertNotIn('claim_verified', source)


if __name__ == '__main__':
    unittest.main()
