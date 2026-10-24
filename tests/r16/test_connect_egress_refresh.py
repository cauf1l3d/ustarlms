import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('refresh', Path(__file__).resolve().parents[2] / 'scripts/infra/connect_egress_refresh.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class RefreshTests(unittest.TestCase):
    def test_probe_verifies_tls_and_fixed_destination(self):
        with patch.object(m.http.client, 'HTTPSConnection') as cls:
            cls.return_value.getresponse.return_value.status = 302
            m.probe(18082)
            cls.return_value.set_tunnel.assert_called_once_with('api.telegram.org', 443)
            self.assertEqual(cls.call_args.args, ('127.0.0.1', 18082))
            self.assertTrue(cls.call_args.kwargs['context'].check_hostname)
            cls.return_value.close.assert_called_once()

    def test_probe_refuses_unexpected_status(self):
        with patch.object(m.http.client, 'HTTPSConnection') as cls:
            cls.return_value.getresponse.return_value.status = 503
            with self.assertRaises(RuntimeError): m.probe(18082)

    def test_failed_live_probe_restores_exact_original(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'xray.json'; p.write_bytes(b'original'); p.chmod(0o600)
            with patch.object(m, 'command') as run, patch.object(m, 'ready'), patch.object(m, 'probe', side_effect=OSError):
                with self.assertRaises(OSError): m.activate(p, b'original', b'new', 'test.service')
            self.assertEqual(p.read_bytes(), b'original')
            self.assertEqual(run.call_count, 2)
            self.assertEqual(list(Path(d).glob('xray.before-*'))[0].read_bytes(), b'original')

    def test_changed_configuration_refused_before_restart(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'xray.json'; p.write_bytes(b'admin change')
            with patch.object(m, 'command') as run:
                with self.assertRaises(RuntimeError): m.activate(p, b'original', b'new', 'test.service')
                run.assert_not_called()
            self.assertEqual(p.read_bytes(), b'admin change')

    def test_success_keeps_private_backup(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'xray.json'; p.write_bytes(b'original'); p.chmod(0o600)
            with patch.object(m, 'command'), patch.object(m, 'ready'), patch.object(m, 'probe'):
                m.activate(p, b'original', b'new', 'test.service')
            self.assertEqual(p.read_bytes(), b'new')
            self.assertEqual(p.stat().st_mode & 0o777, 0o600)
            b = list(Path(d).glob('xray.before-*'))[0]
            self.assertEqual(b.stat().st_mode & 0o777, 0o600)
            self.assertEqual(b.read_bytes(), b'original')

    def test_interrupt_after_write_restores_config(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'xray.json'; p.write_bytes(b'original'); p.chmod(0o600)
            with patch.object(m, 'command', side_effect=[KeyboardInterrupt(), None]):
                with self.assertRaises(KeyboardInterrupt): m.activate(p, b'original', b'new', 'test.service')
            self.assertEqual(p.read_bytes(), b'original')

    def test_failed_recovery_keeps_backup_and_does_not_claim_success(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'xray.json'; p.write_bytes(b'original'); p.chmod(0o600)
            with patch.object(m, 'command', side_effect=OSError):
                with self.assertRaises(OSError): m.activate(p, b'original', b'new', 'test.service')
            self.assertEqual(p.read_bytes(), b'original')
            self.assertEqual(list(Path(d).glob('xray.before-*'))[0].read_bytes(), b'original')
