"""Fixed production guards and the private local-command boundary."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))

def module(case):
    path=ROOT/'scripts/infra/docker_lifecycle_host.py'
    case.assertTrue(path.is_file(),'fixed production runtime boundary missing')
    spec=importlib.util.spec_from_file_location('docker_runtime_host_fixture',path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value

class GuardContract(unittest.TestCase):
    def test_recovery_quiesces_units_without_reading_or_rewriting_inflight_files(self):
        h=module(self);seen=[]
        with tempfile.TemporaryDirectory() as td:
            store=SimpleNamespace(core=object(),base=Path(td),
                load=lambda:(_ for _ in ()).throw(AssertionError('inflight files must be restored first')))
            controls=h.Controls(store,None,SimpleNamespace(log=None),{},
                SimpleNamespace(quiesce=lambda units:seen.append(units)))
            controls.quiesce({'before':{'units':{}}},recovery=True)
            self.assertEqual(seen,[{}])

    def test_predecessor_journal_needs_only_its_actual_id_image_pair(self):
        from tests.r16.test_docker_lifecycle import row
        h=module(self);profile=h.production_profile();rows={n:row(n) for n in profile['predecessors']}
        frozen={}
        for name,values in profile['predecessors'].items():
            rows[name].update(Id=values['id'],Image=values['image'],State={'Running':True})
            rows[name]['HostConfig']['AutoRemove']=False
            frozen[name]={'Id':values['id'],'Image':values['image']}
        saved={'app':{'phase':'app_ready','primitive':{'identities':frozen}},'runtime':{'phase':'runtime_active'}}
        seen=[];host=SimpleNamespace(bind_identities=lambda values:seen.append(values),
            delegate=lambda:SimpleNamespace(inspect=lambda:rows))
        guard=h.ProductionGuards.__new__(h.ProductionGuards)
        guard.journal=SimpleNamespace(load=lambda:saved);guard.ctx=SimpleNamespace(host=host)
        self.assertEqual(guard.identities(rows),saved)
        self.assertEqual(seen,[frozen])

    def test_production_profile_uses_accepted_source_images_and_local_daemon(self):
        h=module(self);p=h.production_profile()
        self.assertEqual(p['source'],Path('/opt/ustar/apps/stack/docker-compose.yml'))
        self.assertEqual(p['source_sha256'],'41de2ed318ffb7697d92c575d1fc35ab3e6b1d16900e725aca843642d1cb7a09')
        self.assertEqual(p['predecessors']['ustar_moodle']['id'],'64130d6157efbad3a290a39a64eaad29796b67c10004773e419cfa5ce4f0ecfc')
        self.assertEqual(p['core_sha256'],'5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5')
        self.assertEqual(p['socket'],Path('/var/run/docker.sock'))
        self.assertNotIn('ssh',json.dumps(p,default=str).lower())

    def test_foreign_host_is_rejected_before_any_source_or_docker_read(self):
        h=module(self)
        with patch('os.uname') as uname:
            uname.return_value.nodename='unrelated-host'
            with self.assertRaises(h.l.Refusal):h.validate_host(h.production_profile())

    def test_command_failure_is_private_and_public_reason_has_no_output(self):
        h=module(self)
        with tempfile.TemporaryDirectory() as td:
            path=Path(td)/'private.log'
            run=h.Commands(path)
            with self.assertRaises(h.l.Refusal) as error:
                run([sys.executable,'-I','-B','-c',"import sys;print('PRIVATE_SENTINEL');print('PRIVATE_ERROR',file=sys.stderr);sys.exit(9)"])
            self.assertNotIn('PRIVATE',str(error.exception))
            self.assertEqual(path.stat().st_mode&0o777,0o600)
            self.assertIn(b'PRIVATE_SENTINEL',path.read_bytes())
            self.assertIn(b'PRIVATE_ERROR',path.read_bytes())

    def test_docker_calls_force_local_socket_and_do_not_inherit_credentials(self):
        h=module(self)
        with tempfile.TemporaryDirectory() as td,patch('subprocess.Popen',wraps=subprocess.Popen) as launched:
            run=h.Commands(Path(td)/'private.log')
            with patch.dict(os.environ,{'DOCKER_HOST':'tcp://untrusted.invalid:1234','PGPASSWORD':'PRIVATE_ENV'}):
                result=run([sys.executable,'-I','-B','-c',"import os,json;print(json.dumps({'docker':os.getenv('DOCKER_HOST'),'password':os.getenv('PGPASSWORD')}))"])
            self.assertEqual(json.loads(result.stdout),{'docker':None,'password':None})
            self.assertEqual(h.docker('inspect','ustar_moodle')[:3],['/usr/bin/docker','--host','unix:///var/run/docker.sock'])

    def test_timeout_reaps_owned_process_and_is_not_reported_as_success(self):
        h=module(self)
        with tempfile.TemporaryDirectory() as td:
            run=h.Commands(Path(td)/'private.log')
            with self.assertRaises(h.l.Refusal):
                run([sys.executable,'-I','-B','-c','import time;time.sleep(60)'],timeout=.05)
