"""The delivered ordinary Python file must be exact, isolated and offline by default."""
import ast
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]

class Package(unittest.TestCase):
    def builder(self):
        path=ROOT/'scripts/infra/build_docker_lifecycle.py'
        self.assertTrue(path.is_file(),'standalone runtime operator builder missing')
        spec=importlib.util.spec_from_file_location('runtime_builder_fixture',path)
        m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m

    def run_file(self,raw,args=()):
        tmp=tempfile.TemporaryDirectory();self.addCleanup(tmp.cleanup);root=Path(tmp.name)
        path=root/'runtime.py';path.write_bytes(raw)
        result=subprocess.run([sys.executable,'-I','-B',str(path),*args],cwd=root,capture_output=True,text=True)
        self.assertEqual(sorted(v.name for v in root.iterdir()),['runtime.py'])
        self.assertFalse(result.stderr,result.stderr)
        return result,json.loads(result.stdout)

    def test_deterministic_bundle_contains_exact_reviewed_module_bytes(self):
        builder=self.builder();raw=builder.build();self.assertEqual(raw,builder.build())
        values={}
        for n in ast.parse(raw).body:
            if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name) and n.targets[0].id in ('SOURCES','DIGESTS'):
                values[n.targets[0].id]=ast.literal_eval(n.value)
        self.assertEqual(set(values['SOURCES']),{'docker_lifecycle','docker_lifecycle_host'})
        for name,source in values['SOURCES'].items():
            expected=(ROOT/'scripts/infra'/(name+'.py')).read_bytes()
            self.assertEqual(source.encode(),expected)
            self.assertEqual(values['DIGESTS'][name],hashlib.sha256(expected).hexdigest())

    def test_default_has_no_host_access_and_mutation_requires_outage_ack(self):
        raw=self.builder().build();result,value=self.run_file(raw)
        self.assertEqual(result.returncode,0);self.assertEqual(value['phase'],'PLAN_ONLY')
        result,value=self.run_file(raw,['--apply'])
        self.assertEqual(result.returncode,2);self.assertEqual(value['reason'],'outage_acknowledgement_required')

    def test_tampered_embedded_source_refuses_before_default_or_host_action(self):
        raw=self.builder().build();needle=b'runtime_clean_stop_failed'
        self.assertIn(needle,raw);bad=raw.replace(needle,b'runtime_clean_stop_broken',1)
        result,value=self.run_file(bad)
        self.assertEqual(result.returncode,2);self.assertEqual(value['reason'],'embedded_source_integrity')

    def test_invalid_argument_is_not_echoed_into_the_public_result(self):
        result,value=self.run_file(self.builder().build(),['--PRIVATE_SENTINEL'])
        self.assertEqual(result.returncode,2);self.assertNotIn('PRIVATE',json.dumps(value))
