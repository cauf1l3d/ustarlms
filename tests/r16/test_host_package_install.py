import copy
from contextlib import nullcontext, redirect_stdout
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

SOURCE = Path(__file__).resolve().parents[2] / 'scripts/infra/host_package_install.py'
spec = importlib.util.spec_from_file_location('packages', SOURCE)
packages = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packages)

if os.environ.get('USTAR_REQUIRE_HOST_RECOVERY_ROOT') == '1' and os.geteuid() != 0:
    raise RuntimeError('root fixture execution required')


def plan():
    return '\n'.join('Inst ' + name + ' (' + version + ' Ubuntu:24.04 [amd64])'
                     for name, version in packages.EXPECTED.items())


class PlanTests(unittest.TestCase):
    def test_reviewed63_and_upgrade_spelling(self):
        self.assertEqual(len(packages.EXPECTED), 63)
        packages.approved_plan(plan().replace('Inst apparmor (', 'Inst apparmor [old] ('))

    def test_version_drift_refuses(self):
        with self.assertRaisesRegex(packages.InstallError, 'EXACT_PACKAGE_PLAN_CHANGED'):
            packages.approved_plan(plan().replace('(29.', '(30.').replace('(5:29.9.0-', '(5:29.10.0-'))

    def test_extra_dependency_refuses(self):
        with self.assertRaisesRegex(packages.InstallError, 'EXACT_PACKAGE_PLAN_CHANGED'):
            packages.approved_plan(plan() + '\nInst extra-daemon (1 Ubuntu [amd64])')

    def test_missing_package_refuses(self):
        with self.assertRaisesRegex(packages.InstallError, 'EXACT_PACKAGE_PLAN_CHANGED'):
            packages.approved_plan('\n'.join(plan().splitlines()[1:]))

    def test_removal_refuses(self):
        with self.assertRaisesRegex(packages.InstallError, 'REMOVAL_PLAN'):
            packages.approved_plan(plan() + '\nRemv linux-image-6.8.0-139-generic [old]')

    def test_duplicate_or_malformed_refuses(self):
        for text in (plan() + '\n' + plan().splitlines()[0], plan() + '\nInst broken'):
            with self.subTest(text=text[-40:]), self.assertRaises(packages.InstallError):
                packages.approved_plan(text)

    def test_configured_status_and_versions_are_both_required(self):
        runner = Mock()
        rows = '\n'.join(n + '\tii \t' + v for n, v in packages.EXPECTED.items())
        runner.run.side_effect = [rows, '', '']
        packages.verify_packages(runner)
        for bad in (rows.replace('\tii \t', '\tiU \t', 1), rows.replace('29.9.0', '29.10.0', 1)):
            runner.run.side_effect = [bad, '', '']
            with self.assertRaises(packages.InstallError):
                packages.verify_packages(runner)


class ControlTests(unittest.TestCase):
    def setUp(self):
        packages.INTERRUPTED = False
        self.phases = []
        self.marker = {'config_hashes': {'opaque': 'hash'}}
        self.h = SimpleNamespace(RecoveryError=RuntimeError, now=lambda: 'now',
                                 write_json=self.record, stop=Mock(), resume=Mock())
        self.runner = Mock(log=Path('/unused'))
        self.runner.run.return_value = plan()
        self.stack = []
        for name in ('package_manager_idle', 'apt_install', 'verify_packages', 'boot_check', 'resumed_check'):
            p = patch.object(packages, name)
            self.stack.append(p)
            setattr(self, name, p.start())
        self.addCleanup(lambda: [p.stop() for p in reversed(self.stack)])
        self.output = io.StringIO()

    def record(self, path, marker):
        self.assertEqual(path, packages.MARKER)
        self.phases.append(copy.deepcopy(marker))

    def run_control(self):
        with redirect_stdout(self.output):
            packages.transact(self.h, self.runner, self.marker)

    def test_success_resumes_and_records_boot_reboot_pending(self):
        self.run_control()
        self.h.stop.assert_called_once()
        self.apt_install.assert_called_once()
        self.h.resume.assert_called_once()
        self.assertEqual(self.marker['phase'], 'complete_reboot_pending')
        self.assertEqual(self.marker['installed_versions_verified'], 63)
        self.assertTrue(self.marker['services_resumed'])
        self.assertFalse(self.marker['reboot_executed'])

    def test_failed_stop_always_attempts_resume_without_apt(self):
        self.h.stop.side_effect = packages.InstallError('STOP_FAILED')
        with self.assertRaisesRegex(packages.InstallError, 'STOP_FAILED'):
            self.run_control()
        self.apt_install.assert_not_called()
        self.h.resume.assert_called_once()
        self.assertEqual(self.marker['phase'], 'failed_resumed')

    def test_failed_apt_resumes_but_never_claims_installed(self):
        self.apt_install.side_effect = packages.InstallError('APT_INSTALL_FAILED')
        with self.assertRaisesRegex(packages.InstallError, 'APT_INSTALL_FAILED'):
            self.run_control()
        self.h.resume.assert_called_once()
        self.assertNotIn('installed_versions_verified', self.marker)
        self.assertEqual(self.marker['phase'], 'failed_resumed')

    def test_new_plan_before_install_resumes_without_apt(self):
        self.runner.run.return_value = plan() + '\nInst extra (1 origin [amd64])'
        with self.assertRaisesRegex(packages.InstallError, 'EXACT_PACKAGE_PLAN_CHANGED'):
            self.run_control()
        self.apt_install.assert_not_called()
        self.h.resume.assert_called_once()

    def test_failed_boot_check_resumes_and_blocks_reboot_ready(self):
        self.boot_check.side_effect = packages.InstallError('BOOT_BAD')
        with self.assertRaisesRegex(packages.InstallError, 'BOOT_BAD'):
            self.run_control()
        self.h.resume.assert_called_once()
        self.assertEqual(self.marker['phase'], 'failed_resumed')
        self.assertNotIn('new_kernel_boot_files_verified', self.marker)

    def test_failed_resume_retains_original_runtime_receipt(self):
        self.h.resume.side_effect = packages.InstallError('RESUME_BAD')
        with self.assertRaisesRegex(packages.InstallError, 'RESUME_BAD'):
            self.run_control()
        self.assertEqual(self.marker['phase'], 'resume_required')
        self.assertNotIn('services_resumed', self.marker)
        self.assertEqual(self.marker['installed_versions_verified'], 63)

    def test_native_package_lock_still_busy_prevents_service_resume(self):
        self.package_manager_idle.side_effect = [None, packages.InstallError('PACKAGE_MANAGER_BUSY')]
        with self.assertRaisesRegex(packages.InstallError, 'PACKAGE_MANAGER_BUSY'):
            self.run_control()
        self.h.resume.assert_not_called()
        self.assertEqual(self.marker['phase'], 'resume_required')

    def test_post_resume_config_failure_is_failed_resumed(self):
        self.resumed_check.side_effect = packages.InstallError('CRITICAL_CONFIG_BYTES_CHANGED')
        with self.assertRaisesRegex(packages.InstallError, 'CRITICAL_CONFIG_BYTES_CHANGED'):
            self.run_control()
        self.assertTrue(self.marker['services_resumed'])
        self.assertEqual(self.marker['phase'], 'failed_resumed')

    def test_stop_requested_while_native_apt_finishes_then_resumes(self):
        self.apt_install.side_effect = lambda *a: packages.signal_requested(15, None)
        with self.assertRaisesRegex(packages.InstallError, 'STOP_REQUESTED'):
            self.run_control()
        self.verify_packages.assert_called_once()
        self.h.resume.assert_called_once()
        self.assertEqual(self.marker['phase'], 'failed_resumed')

    def test_preflight_failure_has_no_stop_or_resume(self):
        h = SimpleNamespace(**vars(self.h), private_dir=Mock(), Runner=Mock(return_value=self.runner))
        with patch.object(packages, 'maintenance', return_value=nullcontext()), \
                patch.object(packages.MARKER.__class__, 'exists', return_value=False), \
                patch.object(packages, 'preflight', side_effect=packages.InstallError('CACHE_MISSING')), \
                redirect_stdout(self.output), self.assertRaisesRegex(packages.InstallError, 'CACHE_MISSING'):
            packages.install(h)
        h.stop.assert_not_called()
        h.resume.assert_not_called()
        self.assertEqual(self.phases[-1]['phase'], 'preflight_failed_no_stop')

    def test_resume_command_never_repeats_package_install(self):
        marker = {'stop_started': True, 'package_install_started': True, 'phase': 'resume_required'}
        h = SimpleNamespace(**vars(self.h), read_json=Mock(return_value=marker),
                            Runner=Mock(return_value=self.runner))
        with patch.object(packages, 'maintenance', return_value=nullcontext()), redirect_stdout(self.output):
            packages.recover(h)
        h.stop.assert_not_called()
        self.apt_install.assert_not_called()
        h.resume.assert_called_once()
        self.assertTrue(marker['services_resumed'])
        self.assertEqual(marker['phase'], 'failed_resumed')


class NativeProcessTests(unittest.TestCase):
    def test_apt_call_no_timeout_no_download_no_remove_local_conffiles_and_suspend_hook(self):
        packages.INTERRUPTED = False
        with tempfile.TemporaryDirectory() as directory:
            runner = SimpleNamespace(log=Path(directory) / 'apt.log')
            h = SimpleNamespace(now=lambda: 'now', write_json=Mock())
            marker = {}
            process = Mock(pid=123)
            process.wait.side_effect = lambda: (packages.signal_requested(15, None) or 0)
            context = Mock()
            context.__enter__ = Mock(return_value=process)
            context.__exit__ = Mock(return_value=False)
            with patch.object(packages.subprocess, 'Popen', return_value=context) as popen, redirect_stdout(io.StringIO()):
                packages.apt_install(h, marker, runner)
            args, kwargs = popen.call_args
            self.assertIn('--no-download', args[0])
            self.assertIn('--no-remove', args[0])
            self.assertIn('Dpkg::Options::=--force-confold', args[0])
            self.assertEqual(kwargs['env']['NEEDRESTART_SUSPEND'], '1')
            self.assertEqual(kwargs['env']['DEBIAN_FRONTEND'], 'noninteractive')
            self.assertNotIn('timeout', kwargs)
            process.wait.assert_called_once_with()
            self.assertEqual(marker['apt_exit_status'], 0)
            self.assertTrue(packages.INTERRUPTED)
            packages.INTERRUPTED = False

    @unittest.skipUnless(os.geteuid() == 0, 'requires actual root POSIX lock fixture')
    def test_real_foreign_posix_lock_refuses_not_flock_substitution(self):
        with tempfile.TemporaryDirectory(dir='/root') as directory:
            paths = [Path(directory) / str(i) for i in range(3)]
            for path in paths:
                path.touch(mode=0o600)
            h = SimpleNamespace(APT_LOCKS=paths)
            code = 'import fcntl,sys; f=open(sys.argv[1],"r+"); fcntl.lockf(f,fcntl.LOCK_EX); print("READY",flush=True); sys.stdin.readline()'
            child = subprocess.Popen([sys.executable, '-u', '-c', code, str(paths[0])],
                                     stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
            try:
                self.assertEqual(child.stdout.readline().strip(), 'READY')
                with self.assertRaisesRegex(packages.InstallError, 'PACKAGE_MANAGER_BUSY'):
                    packages.package_manager_idle(h)
            finally:
                child.communicate('\n', timeout=5)
            packages.package_manager_idle(h)


class BootTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.boot = Path(self.temp.name)
        (self.boot / 'grub').mkdir()
        for kernel in (*packages.FALLBACKS, packages.KERNEL):
            for stem in ('vmlinuz-', 'initrd.img-'):
                (self.boot / (stem + kernel)).write_bytes(b'fixture')
        (self.boot / 'grub/grub.cfg').write_text('linux /boot/vmlinuz-' + packages.KERNEL + '\n')
        self.runner = Mock()
        self.runner.run.return_value = 'usr/lib/modules/' + packages.KERNEL + '/kernel/fixture.ko\n'

    def check(self):
        with patch.object(packages, 'BOOT', self.boot):
            packages.boot_check(self.runner)

    def test_three_boot_file_pairs_and_new_initramfs(self):
        self.check()

    def test_working_fallback_removed_refuses(self):
        (self.boot / ('vmlinuz-' + packages.FALLBACKS[0])).unlink()
        with self.assertRaisesRegex(packages.InstallError, 'BOOT_FILE_MISSING'):
            self.check()

    def test_empty_new_initramfs_refuses(self):
        (self.boot / ('initrd.img-' + packages.KERNEL)).write_bytes(b'')
        with self.assertRaisesRegex(packages.InstallError, 'BOOT_FILE_MISSING'):
            self.check()

    def test_initramfs_other_kernel_refuses(self):
        self.runner.run.return_value = 'usr/lib/modules/6.8.0-146-generic/kernel/old.ko'
        with self.assertRaisesRegex(packages.InstallError, 'INITRAMFS_KERNEL_MISSING'):
            self.check()

    def test_missing_new_grub_entry_refuses(self):
        (self.boot / 'grub/grub.cfg').write_text('linux /boot/vmlinuz-' + packages.FALLBACKS[0])
        with self.assertRaisesRegex(packages.InstallError, 'GRUB_KERNEL_MISSING'):
            self.check()


class RuntimeTests(unittest.TestCase):
    def test_containerd_version_optional_v_and_enforcing_profile(self):
        h = SimpleNamespace(unit=lambda *a: {'ActiveState': 'active', 'Result': 'success'})
        marker = {'config_hashes': {'opaque': 'hash'}}
        for version in ('containerd containerd.io 2.4.1 abc', 'containerd github.com/containerd/containerd v2.4.1 abc'):
            runner = Mock()
            runner.run.side_effect = ['29.9.0\n', version]
            with patch.object(packages, 'config_hashes', return_value=marker['config_hashes']), \
                    patch.object(Path, 'read_text', return_value='docker-default (enforce)\n'):
                packages.resumed_check(h, marker, runner)

    def test_other_containerd_version_refuses(self):
        h = SimpleNamespace(unit=Mock())
        marker = {'config_hashes': {}}
        runner = Mock()
        runner.run.side_effect = ['29.9.0', 'containerd containerd.io v2.4.10 abc']
        with patch.object(packages, 'config_hashes', return_value={}), \
                self.assertRaisesRegex(packages.InstallError, 'CONTAINERD_RUNTIME_VERSION'):
            packages.resumed_check(h, marker, runner)

    def test_changed_network_or_ssh_config_refuses_before_reboot(self):
        with patch.object(packages, 'config_hashes', return_value={'opaque': 'new'}), \
                self.assertRaisesRegex(packages.InstallError, 'CRITICAL_CONFIG_BYTES_CHANGED'):
            packages.resumed_check(Mock(), {'config_hashes': {'opaque': 'old'}}, Mock())


@unittest.skipUnless(os.geteuid() == 0, 'requires trusted root recovery receipt fixture')
class PreflightTests(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('accepted_control_for_packages', SOURCE.parent / 'host_cold_recovery.py')
        self.h = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.h)
        self.temp = tempfile.TemporaryDirectory(dir='/root')
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.h.HDD = root / 'hdd'
        self.h.HDD.mkdir(mode=0o700)
        recovery = self.h.HDD / 'recovery'
        recovery.mkdir(mode=0o700)
        self.archive = recovery / 'root.tar'
        self.archive.touch(mode=0o600)
        with self.archive.open('wb') as out:
            out.truncate(packages.ARCHIVE_BYTES)
        (recovery / 'packages.private.tsv').write_text('pkg\tii \told\n')
        (recovery / 'packages.private.tsv').chmod(0o600)
        self.receipt = {'phase': 'complete', 'services_resumed': True,
                        'archive_readback_sha256_verified': True,
                        'package_baseline_unchanged': True,
                        'archive': str(self.archive), 'archive_sha256': packages.ARCHIVE_SHA}
        self.marker = root / 'cold.json'
        self.h.write_json(self.marker, self.receipt)
        self.boot = root / 'boot'
        self.boot.mkdir(mode=0o700)
        for kernel in packages.FALLBACKS:
            for stem in ('vmlinuz-', 'initrd.img-'):
                (self.boot / (stem + kernel)).touch()
        self.h.APT_LOCKS = []
        self.cache_missing = False
        self.baseline_changed = False
        self.runner = SimpleNamespace(run=self.command_run)

    def command_run(self, args, timeout=30):
        if args[0] == 'findmnt':
            return json.dumps({'filesystems': [{'uuid': self.h.HDD_UUID, 'fstype': 'ext4'}]})
        if args == packages.PACKAGE_QUERY:
            return 'pkg\tii \t' + ('new' if self.baseline_changed else 'old') + '\n'
        if args[:2] == ['dpkg', '--audit']:
            return ''
        if args[0] == 'uname':
            return packages.FALLBACKS[0]
        if args[:2] == ['apt-get', '--simulate']:
            return plan()
        if args[:2] == ['apt-get', '--download-only']:
            self.assertIn('--no-download', args)
            self.assertIn('--no-remove', args)
            if self.cache_missing:
                raise self.h.RecoveryError('COMMAND_FAILED')
            return 'Download complete and in download only mode'
        raise AssertionError(args)

    def check(self):
        with patch.object(packages, 'ARCHIVE', self.archive), \
                patch.object(packages, 'RECOVERY_MARKER', self.marker), \
                patch.object(packages, 'BOOT', self.boot), \
                patch.object(packages, 'config_hashes', return_value={'opaque': 'hash'}):
            return packages.preflight(self.h, self.runner)

    def test_accepted_archive_metadata_baseline_and_cache_pass(self):
        self.assertEqual(self.check(), {'opaque': 'hash'})

    def test_incomplete_cold_receipt_refuses(self):
        self.receipt['archive_readback_sha256_verified'] = False
        self.h.write_json(self.marker, self.receipt)
        with self.assertRaisesRegex(packages.InstallError, 'ACCEPTED_COLD_ARCHIVE_REQUIRED'):
            self.check()

    def test_changed_package_baseline_refuses(self):
        self.baseline_changed = True
        with self.assertRaisesRegex(packages.InstallError, 'PACKAGE_BASELINE_DRIFT_NO_STOP'):
            self.check()

    def test_missing_cache_refuses(self):
        self.cache_missing = True
        with self.assertRaisesRegex(self.h.RecoveryError, 'COMMAND_FAILED'):
            self.check()


if __name__ == '__main__':
    if os.environ.get('USTAR_REQUIRE_HOST_RECOVERY_ROOT') == '1' and os.geteuid() != 0:
        raise SystemExit('root fixture execution required')
    unittest.main()
