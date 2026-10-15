"""Root file/tar/hash/permission tests; Docker/apt/systemd/age are modeled."""
from contextlib import ExitStack, redirect_stdout
import hashlib
import importlib.util
import io
import json
import os
import subprocess
from pathlib import Path
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('transfer_tested', REPO / 'scripts/infra/backup_transfer.py')
t = importlib.util.module_from_spec(spec)
spec.loader.exec_module(t)


@unittest.skipUnless(os.geteuid() == 0, 'root trusted-path fixture')
class Transfer(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir='/var/lib', prefix='ustar-transfer-fixture-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.installed = self.base / 'installed'
        self.installed.mkdir(mode=0o700)
        for key in ('CONTROLLER', 'ENGINE', 'CHECKER'):
            path = self.installed / getattr(t, key).name
            path.write_text('# ' + key + ' fixture\n')
            path.chmod(0o600)
            self.stack.enter_context(mock.patch.object(t, key, path))
            self.stack.enter_context(mock.patch.object(t, key + '_SHA', t.digest(path)))
        self.src = self.base / 'source'
        self.src.mkdir(mode=0o700)
        self.key = self.src / 'key'
        self.key.write_text('private-key-fixture')
        self.key.chmod(0o600)
        self.name = 'ustar-recovery-20261015T003045Z-01234567.tar.gz.age'
        self.archive = self.src / self.name
        self.archive.write_bytes(b'encrypted-test-fixture')
        self.archive.chmod(0o600)
        self.sidecar = self.src / (self.name + '.json')
        self.sidecar.write_text(json.dumps({'file': self.name, 'sha256': t.digest(self.archive)}))
        self.sidecar.chmod(0o600)
        own = self.installed / 'launcher.py'
        own.write_text('# launcher fixture\n')
        own.chmod(0o600)
        self.stack.enter_context(mock.patch.object(t, 'SELF', own))
        self.stack.enter_context(mock.patch.object(t, 'TRANSFER_PARENT', self.base / 'transfers'))
        self.stack.enter_context(mock.patch.object(t, 'BOOTSTRAP_LOG_ROOT', self.base / 'bootstrap'))
        self.stack.enter_context(mock.patch.object(t, 'UNIT_PATH', self.installed / t.UNIT))
        self.root = self.base / 'restore-data'
        self.selected = {'archive': str(self.archive), 'sha256': t.digest(self.archive), 'identity': str(self.key)}
        self.r = SimpleNamespace(IDENTITY=self.key, HDD=self.base, ROOT=self.root,
                                 selection=mock.Mock(return_value=self.selected), verify=mock.Mock(), engine=mock.Mock())
        self.stack.enter_context(mock.patch.object(t, 'controller', return_value=self.r))
        self.calls = []
        self.inventory = b''
        def command(args, **kwargs):
            self.calls.append(args)
            if args[:3] == ['docker', 'ps', '-aq']:
                return self.inventory
            if args[0] == 'systemctl' and args[1] == 'show':
                return b'0\n'
            return b'fixture\n'
        self.stack.enter_context(mock.patch.object(t, 'command', side_effect=command))
        self.stack.enter_context(mock.patch.object(t.shutil, 'which', return_value='/fixture/tool'))

    def make_kit(self):
        with redirect_stdout(io.StringIO()):
            t.pack()
        tarpath = next(t.TRANSFER_PARENT.glob('*/ustar-transfer.tar'))
        checksum = tarpath.with_suffix('.tar.sha256').read_text().split()[0]
        self.assertEqual(checksum, t.digest(tarpath))
        kit = self.base / 'kit'
        kit.mkdir(mode=0o700)
        with tarfile.open(tarpath) as archive:
            for member in archive.getmembers():
                self.assertTrue(member.isfile())
                self.assertNotIn('/', member.name)
                self.assertEqual(member.mode, 0o600)
                path = kit / member.name
                path.write_bytes(archive.extractfile(member).read())
                path.chmod(0o600)
        return kit

    def test_export_self_contained_checked_kit_and_key_without_source_mutation(self):
        old = self.archive.read_bytes()
        kit = self.make_kit()
        data = t.validate_kit(kit)
        self.assertTrue(data['contains_private_recovery_key'])
        self.assertEqual((kit / 'recovery.key').read_bytes(), self.key.read_bytes())
        self.assertEqual(self.archive.read_bytes(), old)
        self.r.verify.assert_called_once()
        self.assertFalse(self.calls)  # No Docker stop or service action in exporter.

    def test_modified_archive_is_rejected(self):
        kit = self.make_kit()
        (kit / self.name).write_bytes(b'corrupt')
        with self.assertRaisesRegex(t.TransferError, 'HASH_MISMATCH'):
            t.validate_kit(kit)

    def test_modified_key_is_rejected(self):
        kit = self.make_kit()
        (kit / 'recovery.key').write_text('changed')
        with self.assertRaisesRegex(t.TransferError, 'HASH_MISMATCH'):
            t.validate_kit(kit)

    def test_missing_key_fails(self):
        kit = self.make_kit()
        (kit / 'recovery.key').unlink()
        with self.assertRaisesRegex(t.TransferError, 'MEMBER_SET'):
            t.validate_kit(kit)

    def test_extra_or_symlink_members_fail(self):
        kit = self.make_kit()
        (kit / 'extra').write_text('x')
        with self.assertRaisesRegex(t.TransferError, 'MEMBER_SET'):
            t.validate_kit(kit)
        (kit / 'extra').unlink()
        (kit / 'recovery.key').unlink()
        (kit / 'recovery.key').symlink_to(self.key)
        with self.assertRaisesRegex(t.TransferError, 'UNTRUSTED_PATH'):
            t.validate_kit(kit)

    def test_world_readable_extracted_folder_refused(self):
        kit = self.make_kit()
        kit.chmod(0o755)
        with self.assertRaisesRegex(t.TransferError, 'UNTRUSTED_PATH'):
            t.validate_kit(kit)

    def test_absolute_or_parent_manifest_member_refused(self):
        kit = self.make_kit()
        meta = kit / 'transfer.json'
        data = json.loads(meta.read_text())
        data['files']['../escape'] = {'bytes': 1, 'sha256': '0' * 64}
        meta.write_text(json.dumps(data))
        with self.assertRaisesRegex(t.TransferError, 'MEMBER_SET'):
            t.validate_kit(kit)

    def test_unit_finite_and_unlimited_have_consistent_watchdog(self):
        self.assertIn('--duration 120m', t.unit_text(7200))
        self.assertIn('RuntimeMaxSec=11100', t.unit_text(7200))
        unit = t.unit_text(0, self.base / 'kit')
        self.assertIn('RuntimeMaxSec=0', unit)
        self.assertIn('--duration 0', unit)
        self.assertIn('--load-images', unit)
        self.assertIn('ExecStopPost=', unit)
        self.assertNotIn('Restart=', unit)

    def test_unsafe_unit_path_refused(self):
        for path in ('/root/kit with spaces', '/root/kit\nExecStart=x', '/root/../etc'):
            with self.assertRaises(t.TransferError):
                t.unit_text(0, Path(path))

    def test_portable_launch_empty_host_defaults_duration_zero_at_caller(self):
        kit = self.make_kit()
        with mock.patch.object(t, 'SELF', kit / 'restore.py'), redirect_stdout(io.StringIO()) as out:
            t.launch(0, portable=True)
        unit = t.UNIT_PATH.read_text()
        self.assertIn('--duration 0', unit)
        self.assertIn('RuntimeMaxSec=0', unit)
        self.assertIn('NOT_YET_READY', out.getvalue())
        self.assertIn(['systemctl', 'start', t.UNIT], self.calls)
        self.assertFalse(any('stop' in c or 'restart' in c or 'enable' in c for c in self.calls))

    def test_nonempty_clean_host_refuses_before_service_install(self):
        kit = self.make_kit()
        self.inventory = b'other-container'
        with mock.patch.object(t, 'SELF', kit / 'restore.py'):
            with self.assertRaisesRegex(t.TransferError, 'EMPTY_DOCKER'):
                t.launch(0, portable=True)
        self.assertFalse(t.UNIT_PATH.exists())

    def test_existing_restore_refuses_before_bootstrap(self):
        kit = self.make_kit()
        self.root.mkdir()
        with mock.patch.object(t, 'SELF', kit / 'restore.py'), mock.patch.object(t, 'install_dependencies') as install:
            with self.assertRaisesRegex(t.TransferError, 'NO_OVERWRITE'):
                t.launch(0, portable=True, bootstrap=True)
            install.assert_not_called()

    def test_unowned_unit_never_replaced(self):
        t.UNIT_PATH.write_text('[Service]\nExecStart=/keep\n')
        with self.assertRaisesRegex(t.TransferError, 'UNOWNED_UNIT'):
            t.launch(3600)
        self.assertIn('/keep', t.UNIT_PATH.read_text())

    def test_wrong_installed_script_never_replaced(self):
        src, dst = self.base / 'src', self.base / 'dst'
        src.write_text('new'); dst.write_text('keep')
        with self.assertRaisesRegex(t.TransferError, 'SHA_MISMATCH'):
            t.install_exact(src, dst, t.digest(src))
        self.assertEqual(dst.read_text(), 'keep')

    def test_short_archive_write_rejects_expected_hash(self):
        with tempfile.TemporaryFile() as output, tarfile.open(fileobj=output, mode='w') as archive:
            with self.assertRaisesRegex(t.TransferError, 'HASH_MISMATCH'):
                t.add_checked(archive, self.archive, self.name, '0' * 64)

    def test_documented_gnu_tar_extraction_preserves_private_valid_kit(self):
        self.make_kit()
        archive = next(t.TRANSFER_PARENT.glob('*/ustar-transfer.tar'))
        extracted = self.base / 'gnu-extracted'
        extracted.mkdir(mode=0o700)
        subprocess.run(['tar', '--no-same-owner', '--no-same-permissions',
                        '-xf', str(archive), '-C', str(extracted)], check=True,
                       umask=0o077, capture_output=True)
        self.assertEqual(t.validate_kit(extracted)['archive'], self.name)

    def test_cli_defaults_and_explicit_duration_reach_launcher(self):
        for argv, seconds, portable in [(['--start'], 0, True),
                                         (['--start-lab'], 3600, False),
                                         (['--start-lab', '--duration', '2h'], 7200, False)]:
            with mock.patch.object(t.sys, 'argv', ['restore.py', *argv]), \
                    mock.patch.object(t, 'launch_lock'), mock.patch.object(t, 'launch') as run:
                t.main()
                run.assert_called_once_with(seconds, portable=portable, bootstrap=False)

    def test_bootstrap_rejects_wrong_os_before_commands(self):
        with mock.patch.object(Path, 'read_text', return_value='ID=debian\nVERSION_ID=13\n'):
            with self.assertRaisesRegex(t.TransferError, 'UBUNTU_24_04'):
                t.install_dependencies()
        self.assertFalse(self.calls)

    def test_bootstrap_existing_container_refuses_apt(self):
        self.inventory = b'existing-container'
        with mock.patch.object(Path, 'read_text', return_value='ID=ubuntu\nVERSION_ID="24.04"\n'), \
                mock.patch.object(t.subprocess, 'run') as run:
            with self.assertRaisesRegex(t.TransferError, 'EMPTY_DOCKER'):
                t.install_dependencies()
            run.assert_not_called()

    def test_bootstrap_all_tools_present_does_not_run_apt(self):
        with mock.patch.object(Path, 'read_text', return_value='ID=ubuntu\nVERSION_ID="24.04"\n'), \
                mock.patch.object(t.subprocess, 'run') as run:
            t.install_dependencies()
            run.assert_not_called()
        self.assertIn(['systemctl', 'start', 'docker.service'], self.calls)

    def test_bootstrap_installs_only_missing_tools_private_log(self):
        fd, logfile = tempfile.mkstemp(dir=self.base)
        with mock.patch.object(Path, 'read_text', return_value='ID=ubuntu\nVERSION_ID="24.04"\n'), \
                mock.patch.object(t.shutil, 'which', side_effect=lambda x: None if x in ('docker', 'age') else '/bin/tool'), \
                mock.patch.object(Path, 'exists', return_value=False), \
                mock.patch.object(t.tempfile, 'mkstemp', return_value=(fd, logfile)), \
                mock.patch.object(t.subprocess, 'run', return_value=SimpleNamespace(returncode=0)) as run, \
                redirect_stdout(io.StringIO()):
            t.install_dependencies()
        args = run.call_args_list[1].args[0]
        self.assertEqual(args, ['apt-get', '-o', 'Dpkg::Lock::Timeout=60', 'install', '-y',
                                '--no-install-recommends', 'docker.io', 'age'])
        self.assertEqual(Path(logfile).stat().st_mode & 0o777, 0o600)
        self.assertEqual(len(run.call_args_list), 2)
        self.assertEqual(t.BOOTSTRAP_LOG_ROOT.stat().st_mode & 0o777, 0o700)

    def test_duration_input(self):
        for value, seconds in [('30m', 1800), ('2h', 7200), ('0', 0), ('1d', 86400)]:
            self.assertEqual(t.parse_duration(value), seconds)
        for value in ('-1', '0h', '31d', '$(id)', '1.5h'):
            with self.assertRaises(t.TransferError):
                t.parse_duration(value)


if __name__ == '__main__':
    unittest.main()

class SourceBinding(unittest.TestCase):
    def test_controller_pin_matches_shipped_source(self):
        actual = hashlib.sha256((REPO / 'scripts/infra/backup_restore_test.py').read_bytes()).hexdigest()
        self.assertEqual(actual, t.CONTROLLER_SHA)
