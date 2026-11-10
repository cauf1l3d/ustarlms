"""Real controller outputs under synthetic Moodle boundary; native browser remains separate."""
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
PHP=os.environ.get('USTAR_PHP') or shutil.which('php')

@unittest.skipUnless(PHP,'PHP runtime required')
class ManagementForms(unittest.TestCase):
    def test_rejected_form_keeps_input_and_original_tokens(self):
        for case in ('bank','offer','stale','nocorrect','malformed','selected','status'):
            with self.subTest(case=case):
                result=subprocess.run([PHP,str(ROOT/'tests/release_20260912/library_management_controller.php'),case],capture_output=True,text=True)
                self.assertEqual(0,result.returncode,result.stdout+result.stderr)
