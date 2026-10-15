import importlib.util
from pathlib import Path
import os
import tempfile
import unittest
from unittest import mock

spec=importlib.util.spec_from_file_location('apache_check',Path(__file__).resolve().parents[2]/'scripts/infra/lab_patch_apache_check.py')
a=importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)

class ApacheProbeTests(unittest.TestCase):
    def test_cleanup_on_exception(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder)
            with mock.patch.object(a,'ROOT_UID',os.getuid()), mock.patch.object(a,'parents'):
                with self.assertRaisesRegex(RuntimeError,'failure'):
                    with a.temporary_probe(root,'a'*64) as name:
                        self.assertTrue((root/name).is_file())
                        raise RuntimeError('failure')
                self.assertEqual(list(root.iterdir()),[])
    def test_cleanup_retains_replacement(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder)
            with mock.patch.object(a,'ROOT_UID',os.getuid()), mock.patch.object(a,'parents'):
                with self.assertRaises(a.ApacheCheckError):
                    with a.temporary_probe(root,'b'*64) as name:
                        (root/name).write_text('replacement')
                self.assertEqual((root/name).read_text(),'replacement')
    def test_invalid_token_rejected(self):
        with self.assertRaises(a.ApacheCheckError):
            a.probe_source("bad' token")
    def test_process_cli_cannot_pass_apache(self):
        obs={'extensions':['ctype'],'sapi':'cli','version':'8.3.33','int_size':8,
             'memory_limit':'512M','sendmail_path':'/usr/sbin/sendmail',
             'max_input_vars':'5000','display_errors':'','log_errors':'1',
             'auto_prepend_file':'','auto_append_file':''}
        class R:
            @staticmethod
            def safe_php_observation(value):
                return {'scanned_ini_basenames':[]}
        _,checks,missing=a.report_settings(R,obs,{'required_extensions':['ctype','zip']})
        self.assertFalse(checks['apache_sapi'])
        self.assertFalse(checks['mail_blocked'])
        self.assertEqual(missing,['zip'])
