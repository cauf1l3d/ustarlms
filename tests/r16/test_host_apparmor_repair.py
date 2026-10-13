"""Real temporary file transactions; service/Docker operations are simulated."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SOURCE = Path(__file__).resolve().parents[2] / 'scripts/infra/host_apparmor_repair.py'
spec = importlib.util.spec_from_file_location('host_apparmor_repair', SOURCE)
h = importlib.util.module_from_spec(spec)
spec.loader.exec_module(h)
ORIGINAL = (b'#include "/var/lib/snapd/apparmor/snap-confine"\n'
            b'/usr/lib/snapd/snap-confine flags=(attach_disconnected) {\n  /bin/true rix,\n}\n')
NAME = '/usr/lib/snapd/snap-confine'


class IncludeContract(unittest.TestCase):
    def test_only_conditional_keyword_changes_and_unknown_or_duplicate_refused(self):
        for source in (ORIGINAL, ORIGINAL.replace(b'"\n', b'" # comment\r\n', 1),
                       ORIGINAL.replace(b'#include ', b'  include ', 1)):
            fixed = h.conditional_include(source)
            self.assertEqual(fixed.replace(b'include if exists ', b'include ', 1), source)
        for source in (b'profile unrelated {}\n', ORIGINAL + ORIGINAL,
                       ORIGINAL.replace(b'snap-confine"', b'another-directory"')):
            with self.assertRaises(h.Refusal):
                h.conditional_include(source)

    def test_default_is_plan_only(self):
        p = subprocess.run([os.sys.executable, '-I', '-B', str(SOURCE)],
                           capture_output=True, text=True, check=True)
        self.assertEqual(json.loads(p.stdout)['mode'], 'PLAN_ONLY')

    def test_real_parser_optional_missing_directory(self):
        binary = shutil.which('apparmor_parser')
        if not binary:
            if os.environ.get('USTAR_REQUIRE_APPARMOR_PARSER') == '1':
                self.fail('Mandatory AppArmor parser fixture unavailable')
            self.skipTest('AppArmor parser unavailable locally; mandatory in isolated CI')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            missing = root / 'missing-snippets'
            source = ORIGINAL.replace(b'/var/lib/snapd/apparmor/snap-confine', os.fsencode(missing))
            fixed = h.conditional_include(ORIGINAL).replace(b'/var/lib/snapd/apparmor/snap-confine', os.fsencode(missing))
            profile = root / 'fixture'
            for data, ok in ((source, False), (fixed, True)):
                profile.write_bytes(data)
                p = subprocess.run([binary, '-Q', '-K', '-j', '1', str(profile)],
                                   capture_output=True, text=True, timeout=20, check=False)
                self.assertEqual(p.returncode == 0, ok, p.stderr)
            self.assertEqual(sorted(p.name for p in root.iterdir()), ['fixture'])
            self.assertFalse(missing.exists())
            missing.mkdir()
            # A present include is still read; conditional does not ignore invalid rules.
            (missing / 'snippet').write_text('THIS_IS_NOT_VALID_APPARMOR_POLICY\n')
            profile.write_bytes(fixed)
            p = subprocess.run([binary, '-Q', '-K', '-j', '1', str(profile)],
                               capture_output=True, text=True, timeout=20, check=False)
            self.assertNotEqual(p.returncode, 0)
            (missing / 'snippet').write_text('# valid restored generated snippet\n')
            p = subprocess.run([binary, '-Q', '-K', '-j', '1', str(profile)],
                               capture_output=True, text=True, timeout=20, check=False)
            self.assertEqual(p.returncode, 0, p.stderr)


@unittest.skipUnless(os.geteuid() == 0, 'Transaction fixture needs root file ownership')
class RepairTransactions(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.profile = self.root / 'profile'
        self.profile.write_bytes(ORIGINAL)
        self.profile.chmod(0o644)
        self.state = self.root / 'private-state'
        self.kernel = self.root / 'kernel-profiles'
        self.kernel.write_text('docker-default (enforce)\nmail-policy (enforce)\n')
        s = self.profile.stat()
        self.identity = (s.st_dev, s.st_ino)
        self.calls = []
        self.active = False
        self.error_label = None
        self.change_at_compile = None
        self.lose_kernel_profile = False
        self.changed_container_after = False
        self.containers = [dict(name=n, id='stable-'+n, image=i, running=True,
                                profile='docker-default', restart_count=0) for n,i in h.IMAGES.items()]
        self.proc = patch.object(h, 'snap_processes', return_value=0)
        self.proc.start()

    def tearDown(self):
        self.proc.stop()
        self.tmp.cleanup()

    def fake_command(self, argv, label, **kwargs):
        self.calls.append((argv, label, kwargs))
        if label == self.error_label:
            raise h.Refusal(label.upper()+'_FAILED', 1)
        if label == 'docker_read':
            items = [dict(c) for c in self.containers]
            if self.active and self.changed_container_after:
                items[0]['restart_count'] += 1
            return '\n'.join(json.dumps(c) for c in items)
        if label == 'snap_units_read':
            return 'Id=snap.lxd.daemon.unix.socket\nActiveState=active\nSubState=listening\n'
        if label == 'candidate_compile':
            self.assertIn('-Q', argv)
            self.assertIn('-K', argv)
            self.assertEqual(Path(argv[-1]).read_bytes(), h.conditional_include(ORIGINAL))
            if self.change_at_compile:
                self.change_at_compile()
            return ''
        if label == 'candidate_names':
            return NAME+'\n'
        if label == 'apparmor_state_before':
            return ('LoadState=loaded\nActiveState=active\nResult=success\n' if self.active
                    else 'LoadState=loaded\nActiveState=failed\nResult=exit-code\n')
        if label == 'apparmor_load':
            self.assertEqual(argv, [h.SYSTEMCTL, 'start', 'apparmor.service'])
            self.active = True
            self.kernel.write_text('docker-default (enforce)\n'+NAME+' (enforce)\n'
                                   + ('' if self.lose_kernel_profile else 'mail-policy (enforce)\n'))
            return ''
        if label == 'apparmor_state_after':
            return 'Result=success\nActiveState=active\n'
        self.fail('Unexpected command '+label)

    def repair(self):
        return h.repair(profile=self.profile, state_dir=self.state,
                        include=self.root / 'absent-snippets', expected_sha=h.digest(ORIGINAL),
                        expected_identity=self.identity, kernel_path=self.kernel, run=self.fake_command)

    def test_success_preserves_lxd_docker_backup_modes_and_repeat_has_no_reload(self):
        report = self.repair()
        self.assertEqual(report['result'], 'PASS')
        self.assertEqual(self.profile.read_bytes(), h.conditional_include(ORIGINAL))
        self.assertEqual((self.profile.stat().st_uid, self.profile.stat().st_gid,
                          self.profile.stat().st_mode & 0o777), (0,0,0o644))
        self.assertEqual((self.state / 'original.profile').read_bytes(), ORIGINAL)
        self.assertEqual((self.state / 'original.profile').stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.state.stat().st_mode & 0o777, 0o700)
        self.assertFalse((self.root / 'absent-snippets').exists())
        self.assertFalse(report['full_Snap_LXD_runtime_verified'])
        count = sum(label == 'apparmor_load' for _,label,_ in self.calls)
        second = self.repair()
        self.assertEqual(second['original_file_metadata'], report['original_file_metadata'])
        self.assertFalse(second['live_file_replaced'])
        self.assertEqual(second['AppArmor_action'], 'none')
        self.assertEqual(sum(label == 'apparmor_load' for _,label,_ in self.calls), count)
        self.assertFalse(any('stop' in argv or 'restart' in argv or 'disable' in argv for argv,_,_ in self.calls))

    def test_wrong_hash_or_file_identity_refuses_before_commands_and_storage(self):
        for wrong_content in (False, True):
            with self.subTest(content=wrong_content):
                if wrong_content:
                    self.profile.write_bytes(ORIGINAL+b'# drift\n')
                else:
                    self.identity = (self.identity[0], self.identity[1]+1)
                with self.assertRaises((h.Refusal, FileNotFoundError)):
                    self.repair()
                self.assertEqual(self.calls, [])
                self.assertFalse(self.state.exists())

    def test_symlink_and_hardlinked_profile_refused(self):
        self.profile.unlink()
        target = self.root / 'target'
        target.write_bytes(ORIGINAL)
        target.chmod(0o644)
        self.profile.symlink_to(target)
        with self.assertRaises(OSError):
            self.repair()
        self.profile.unlink()
        os.link(target, self.profile)
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(target.read_bytes(), ORIGINAL)
        self.assertFalse(self.state.exists())

    def test_active_snap_executable_or_unconfined_docker_refused(self):
        h.snap_processes.return_value = 1
        with self.assertRaises(h.Refusal):
            self.repair()
        h.snap_processes.return_value = 0
        self.kernel.write_text('docker-default (complain)\n')
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(self.profile.read_bytes(), ORIGINAL)
        self.assertFalse(self.state.exists())

    def test_compile_failure_keeps_live_profile_and_records_specific_phase(self):
        self.error_label = 'candidate_compile'
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(self.profile.read_bytes(), ORIGINAL)
        receipt = json.loads((self.state / 'receipt.json').read_text())
        self.assertFalse(receipt['live_file_replaced'])
        self.assertEqual(receipt['failure_phase'], 'prepared')
        self.assertEqual(receipt['failure_code'], 'CANDIDATE_COMPILE_FAILED')
        self.assertNotIn('apparmor_load', [label for _,label,_ in self.calls])

    def test_concurrent_source_change_prevents_replacement(self):
        changed = ORIGINAL+b'# other administrator\n'
        self.change_at_compile = lambda: self.profile.write_bytes(changed)
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(self.profile.read_bytes(), changed)
        self.assertNotIn('apparmor_load', [label for _,label,_ in self.calls])

    def test_include_directory_appearing_after_compile_prevents_replacement(self):
        self.change_at_compile = lambda: (self.root / 'absent-snippets').mkdir()
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(self.profile.read_bytes(), ORIGINAL)
        self.assertNotIn('apparmor_load', [label for _,label,_ in self.calls])

    def test_service_load_failure_retains_fixed_file_original_and_loading_phase(self):
        self.error_label = 'apparmor_load'
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(self.profile.read_bytes(), h.conditional_include(ORIGINAL))
        receipt = json.loads((self.state / 'receipt.json').read_text())
        self.assertTrue(receipt['live_file_replaced'])
        self.assertEqual(receipt['failure_phase'], 'AppArmor_loading')
        self.assertFalse(receipt['automatic_restore_or_unload_executed'])
        self.assertEqual((self.state / 'original.profile').read_bytes(), ORIGINAL)

    def test_missing_old_kernel_policy_prevents_pass(self):
        self.lose_kernel_profile = True
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(json.loads((self.state/'receipt.json').read_text())['result'], 'ERROR')
    def test_container_restart_prevents_pass(self):
        self.changed_container_after = True
        with self.assertRaises(h.Refusal):
            self.repair()
        self.assertEqual(json.loads((self.state/'receipt.json').read_text())['failure_code'],
                         'POST_REPAIR_FILE_OR_SERVICE_DRIFT')


if __name__ == '__main__':
    unittest.main()
