import copy
from contextlib import nullcontext, redirect_stdout
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[2] / 'scripts/infra/host_cold_recovery.py'
spec = importlib.util.spec_from_file_location('cold', SOURCE)
cold = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cold)


class FakeRunner:
    def __init__(self, marker):
        self.calls = []
        self.c = copy.deepcopy(marker['containers'])
        self.u = copy.deepcopy(marker['units'])
        self.fail_start = set()
        self.forced = set()

    def run(self, args, timeout=30):
        self.calls.append(args)
        if args[:2] == ['docker', 'ps']:
            return '\n'.join(self.c) + '\n'
        if args[:2] == ['docker', 'inspect']:
            return '\n'.join(json.dumps({'name': '/' + n, 'id': c['id'], 'image': c['image'],
                               'state': {'Running': c['running'], 'ExitCode': c['exit'], 'OOMKilled': c['oom']},
                               'policy': c['policy'], 'mounts':
                               [{'Type': m['type'], 'Source': m['source'], 'Destination': m['destination'],
                                 'RW': m['rw']} for m in c['mounts']]}) for n, c in self.c.items())
        if args[:2] == ['systemctl', 'show']:
            return '\n'.join(k + '=' + v for k, v in self.u[args[2]].items()) + '\n'
        if args[:2] == ['systemctl', 'stop']:
            # Real Docker has Requires=docker.socket: stopping it first is unsafe.
            if args[2] == 'docker.socket':
                self.u['docker.service']['ActiveState'] = 'inactive'
            self.u[args[2]]['ActiveState'] = 'inactive'
            return ''
        if args[:2] == ['systemctl', 'start']:
            if args[2] in self.fail_start:
                raise cold.RecoveryError('INJECTED_START_FAILURE')
            self.u[args[2]]['ActiveState'] = 'active'
            return ''
        if args[:2] in (['docker', 'update'], ['docker', 'stop'], ['docker', 'start']):
            name = next(n for n, c in self.c.items() if c['id'] == args[-1])
            c = self.c[name]
            if args[1] == 'update':
                value = args[2].split('=', 1)[1]
                name, _, maximum = value.partition(':')
                c['policy'] = {'Name': name, 'MaximumRetryCount': int(maximum or '0')}
            elif args[1] == 'stop':
                c['running'] = False
                c['exit'] = 137 if args[-1] in self.forced else 0
            else:
                c['running'] = True
                c['exit'] = 0
            return ''
        if args[:2] == ['docker', 'exec']:
            return ''
        raise AssertionError(args)


def state():
    services = ['apache2.service', 'mariadb.service', 'postfix.service', 'postfix@-.service',
                'dovecot.service', 'named.service', 'cron.service', 'containerd.service', 'docker.service']
    sockets = ['docker.socket', 'snap.lxd.daemon.unix.socket']
    timers = ['ustar-hdd-backup.timer', 'apt-daily.timer']
    names = services + sockets + timers
    return {'services': services, 'sockets': sockets, 'timers': timers,
            'units': {n: {'ActiveState': 'active', 'LoadState': 'loaded', 'SubState': 'running',
                          'Result': 'success', 'ExecMainStatus': '0', 'UnitFileState': 'enabled'} for n in names},
            'containers': {n: {'id': n + '-id', 'image': n + '-image', 'running': True, 'oom': False, 'exit': 0,
                               'policy': {'Name': 'unless-stopped', 'MaximumRetryCount': 0}, 'mounts': []}
                           for n in cold.NAMES}}


class MountPreflightTests(unittest.TestCase):
    def preflight(self, extra):
        root = {'target': '/', 'fstype': 'ext4', 'uuid': cold.ROOT_UUID,
                'options': 'rw', 'children': [
                    {'target': '/sys', 'fstype': 'sysfs', 'uuid': None,
                     'children': [extra]}]}
        hdd = {'target': str(cold.HDD), 'fstype': 'ext4', 'uuid': cold.HDD_UUID,
               'options': 'rw'}

        def findmnt(args, timeout=30):
            self.assertEqual('findmnt', args[0])
            if '--mountpoint' in args:
                mounts = [root if args[3] == '/' else hdd]
            else:
                mounts = [root, hdd]
            return json.dumps({'filesystems': mounts})

        runner = unittest.mock.Mock()
        runner.run.side_effect = findmnt
        space = SimpleNamespace(f_blocks=1000, f_bfree=900, f_frsize=4096,
                                f_bavail=10000000, f_favail=1000000)
        with patch.object(cold, 'trusted'), \
                patch.object(Path, 'stat', lambda p: SimpleNamespace(
                    st_dev=2 if p == cold.HDD else 1)), \
                patch.object(cold.os, 'statvfs', return_value=space):
            return cold.disk(runner)

    def test_observed_configfs_inside_excluded_sys_passes_preflight(self):
        self.assertIn('sys', cold.EXCLUDED)
        device, budget = self.preflight(
            {'target': '/sys/kernel/config', 'fstype': 'configfs', 'uuid': None})
        self.assertEqual(2, device)
        self.assertGreater(budget, 0)

    def test_configfs_at_other_location_remains_unaccounted(self):
        with self.assertRaisesRegex(cold.RecoveryError, 'ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED'):
            self.preflight({'target': '/var/lib/config', 'fstype': 'configfs', 'uuid': None})

    def test_persistent_filesystem_at_same_path_is_not_exempt(self):
        for kind in ('ext4', 'vfat', 'nfs4'):
            with self.subTest(kind=kind), self.assertRaisesRegex(
                    cold.RecoveryError, 'ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED'):
                self.preflight({'target': '/sys/kernel/config', 'fstype': kind, 'uuid': 'foreign'})

    def test_persistent_child_of_configfs_is_not_hidden(self):
        mount = {'target': '/sys/kernel/config', 'fstype': 'configfs', 'uuid': None,
                 'children': [{'target': '/sys/kernel/config/foreign',
                               'fstype': 'ext4', 'uuid': 'foreign'}]}
        with self.assertRaisesRegex(cold.RecoveryError, 'ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED'):
            self.preflight(mount)

    def test_other_persistent_mounts_still_refuse(self):
        for kind in ('ext4', 'vfat', 'nfs4', 'overlay'):
            with self.subTest(kind=kind), self.assertRaisesRegex(
                    cold.RecoveryError, 'ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED'):
                self.preflight({'target': '/mnt/foreign', 'fstype': kind, 'uuid': 'foreign'})


class RecoveryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.environ.get('USTAR_REQUIRE_HOST_RECOVERY_ROOT') == '1' and os.geteuid() != 0:
            raise AssertionError('Root file transaction tests are required')

    def setUp(self):
        # No fixture touches a real service, Docker object, HDD, APT DB or kernel.
        self.temp = tempfile.TemporaryDirectory(prefix='ustar-host-cold-', dir='/root' if os.geteuid() == 0 else None)
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / 'root'
        self.root.mkdir()
        self.log = self.base / 'tar.private.log'
        self.payload = self.base / 'root.tar'

    def fixture(self):
        directory = self.root / 'etc'
        directory.mkdir()
        (directory / 'private.conf').write_bytes(b'private contents\0not printed\n')
        os.chmod(directory / 'private.conf', 0o600)
        os.link(directory / 'private.conf', directory / 'second.conf')
        (directory / 'link.conf').symlink_to('private.conf')
        (directory / 'кириллица').write_text('test\n')
        sparse = directory / 'sparse'
        with sparse.open('wb') as f:
            f.seek(8 * 1024 * 1024)
            f.write(b'end')
        os.setxattr(directory / 'private.conf', 'user.ustar_fixture', b'value')
        full = cold.manifest([str(directory)])
        prefix = str(self.root).lstrip('/') + '/'
        expected = {name.removeprefix(prefix): entry for name, entry in full.items()}
        digest, size = cold.copy_archive(cold.tar_command(self.root, []), self.payload, self.log, 50 * 1024**2)
        return expected, digest, size

    def test_real_tar_sparse_hardlinks_private_modes_xattrs_and_restore(self):
        expected, digest, size = self.fixture()
        self.assertLess(size, 1024 * 1024)
        self.assertEqual(len(expected), cold.verify_archive(self.payload, digest, expected))
        restored = self.base / 'restored'
        restored.mkdir()
        subprocess.run(['tar', '-xf', str(self.payload), '--xattrs', '--xattrs-include=*', '--acls',
                        '-C', str(restored)], check=True, capture_output=True)
        f = restored / 'etc/private.conf'
        self.assertEqual(b'private contents\0not printed\n', f.read_bytes())
        self.assertEqual(0o600, f.stat().st_mode & 0o777)
        self.assertEqual(f.stat().st_ino, (restored / 'etc/second.conf').stat().st_ino)
        self.assertEqual(b'value', os.getxattr(f, 'user.ustar_fixture'))
        self.assertEqual('private.conf', os.readlink(restored / 'etc/link.conf'))
        self.assertEqual(8 * 1024 * 1024 + 3, (restored / 'etc/sparse').stat().st_size)

    def test_readback_corruption_is_refused(self):
        expected, digest, _ = self.fixture()
        with self.payload.open('r+b') as f:
            f.seek(-1, os.SEEK_END)
            f.write(b'x')
        with self.assertRaisesRegex(cold.RecoveryError, 'READBACK_SHA'):
            cold.verify_archive(self.payload, digest, expected)

    def test_missing_protected_data_is_refused(self):
        expected, digest, _ = self.fixture()
        expected['etc/missing-db'] = expected['etc/private.conf']
        with self.assertRaisesRegex(cold.RecoveryError, 'MEMBERS_MISSING'):
            cold.verify_archive(self.payload, digest, expected)

    def test_changed_file_payload_is_refused_even_with_valid_archive_checksum(self):
        expected, digest, _ = self.fixture()
        expected['etc/private.conf']['sha256'] = '0' * 64
        with self.assertRaisesRegex(cold.RecoveryError, 'FILE_HASH_MISMATCH'):
            cold.verify_archive(self.payload, digest, expected)

    def test_no_archive_outside_space_budget(self):
        (self.root / 'large').write_bytes(os.urandom(65536))
        with self.assertRaisesRegex(cold.RecoveryError, 'BUDGET'):
            cold.copy_archive(cold.tar_command(self.root, []), self.payload, self.log, 1024)

    def test_failed_tar_does_not_produce_success(self):
        with self.assertRaisesRegex(cold.RecoveryError, 'TAR_COPY_FAILED'):
            cold.copy_archive(['python3', '-c', 'import sys; sys.stdout.write("partial"); sys.exit(7)'],
                              self.payload, self.log, 1024)

    def test_changed_id_refuses_operations_on_replacement(self):
        marker = state()
        runner = FakeRunner(marker)
        runner.c['ustar_postgres']['id'] = 'replacement'
        with self.assertRaisesRegex(cold.RecoveryError, 'IDENTITY'):
            cold.same_containers(marker['containers'], cold.containers(runner))

    def test_stop_database_before_daemon_socket_and_resume_exact_policies(self):
        marker = state()
        marker['containers']['ustar_postgres']['policy'] = {'Name': 'on-failure', 'MaximumRetryCount': 3}
        runner = FakeRunner(marker)
        with patch.object(Path, 'glob', return_value=[]):
            cold.stop(runner, marker)
        calls = runner.calls
        pg_stop = next(i for i, a in enumerate(calls) if a[:2] == ['docker', 'stop'] and a[-1] == 'ustar_postgres-id')
        socket_stop = calls.index(['systemctl', 'stop', 'docker.socket'])
        daemon_stop = calls.index(['systemctl', 'stop', 'docker.service'])
        self.assertLess(pg_stop, daemon_stop)
        self.assertLess(daemon_stop, socket_stop)
        response = unittest.mock.MagicMock()
        response.__enter__.return_value.status = 200
        with patch.object(cold.urllib.request, 'urlopen', return_value=response):
            cold.resume(runner, marker)
        self.assertTrue(all(c['running'] for c in runner.c.values()))
        self.assertEqual(marker['containers']['ustar_postgres']['policy'], runner.c['ustar_postgres']['policy'])
        self.assertNotIn(['systemctl', 'start', 'snap.lxd.daemon.service'], runner.calls)
        for pg in cold.PG:
            self.assertLess(runner.calls.index(['docker', 'start', pg + '-id']),
                            runner.calls.index(['docker', 'start', 'ustar_moodle-id']))

    def test_killed_postgres_refuses_cold_capture(self):
        marker = state()
        runner = FakeRunner(marker)
        runner.forced.add('ustar_postgres-id')
        with self.assertRaisesRegex(cold.RecoveryError, 'UNCLEAN_STOP'):
            cold.stop(runner, marker)

    def test_resume_continues_other_services_if_mariadb_start_fails(self):
        marker = state()
        runner = FakeRunner(marker)
        runner.fail_start.add('mariadb.service')
        for c in runner.c.values():
            c['running'] = False
        response = unittest.mock.MagicMock()
        response.__enter__.return_value.status = 200
        with patch.object(cold.urllib.request, 'urlopen', return_value=response):
            with self.assertRaisesRegex(cold.RecoveryError, 'RESUME_VERIFICATION_FAILED'):
                cold.resume(runner, marker)
        self.assertIn(['systemctl', 'start', 'named.service'], runner.calls)
        self.assertIn(['systemctl', 'start', 'dovecot.service'], runner.calls)
        self.assertIn(['systemctl', 'start', 'ustar-hdd-backup.timer'], runner.calls)
        self.assertTrue(runner.c['ustar_moodle']['running'])

    @unittest.skipUnless(os.geteuid() == 0, 'requires trusted root control fixtures')
    def test_private_atomic_receipts_refuse_symlinks(self):
        target = self.base / 'receipt.json'
        cold.write_json(target, {'phase': 'prepared'})
        self.assertEqual(0o600, target.stat().st_mode & 0o777)
        self.assertEqual('prepared', cold.read_json(target)['phase'])
        target.unlink()
        victim = self.base / 'victim'
        victim.write_text('preserve')
        target.symlink_to(victim)
        with self.assertRaises(cold.RecoveryError):
            cold.write_json(target, {'phase': 'bad'})
        self.assertEqual('preserve', victim.read_text())

    @unittest.skipUnless(os.geteuid() == 0, 'requires trusted root control fixtures')
    def test_real_posix_apt_lock_busy_refuses_without_any_stop(self):
        backup = self.base / 'lock'
        apt = self.base / 'apt-lock'
        apt.touch(mode=0o600)
        child = subprocess.Popen(['python3', '-c',
                                  'import fcntl,sys; f=open(sys.argv[1],"r+"); fcntl.lockf(f,fcntl.LOCK_EX); '
                                  'print("ready",flush=True); sys.stdin.read()', str(apt)],
                                 stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        try:
            self.assertEqual('ready', child.stdout.readline().strip())
            with patch.object(cold, 'BACKUP_LOCK', backup), patch.object(cold, 'APT_LOCKS', (apt,)):
                with self.assertRaisesRegex(cold.RecoveryError, 'LOCK_BUSY_NO_STOP'):
                    with cold.locks():
                        self.fail('entered with a busy APT lock')
        finally:
            child.stdin.close()
            child.wait(timeout=5)
            child.stdout.close()

    def test_cold_source_manifest_detects_changed_contents(self):
        directory = self.root / 'data'
        directory.mkdir()
        f = directory / 'database'
        f.write_bytes(b'one')
        before = cold.manifest([str(directory)])
        f.write_bytes(b'two')
        self.assertNotEqual(before, cold.manifest([str(directory)]))

    @unittest.skipUnless(os.geteuid() == 0, 'requires trusted root control fixtures')
    def transaction(self, fail_copy=False, fail_verify=False, fail_marker=False):
        marker = state()
        hdd, state_dir = self.base / 'hdd', self.base / 'state'
        hdd.mkdir(mode=0o700)
        mysql = self.root / 'mysql'
        mysql.mkdir()
        (mysql / 'database').write_bytes(b'private cold bytes')
        calls = []
        original_tar = cold.tar_command
        original_manifest = cold.manifest
        original_phase = cold.phase

        class Metadata:
            def __init__(self, *args):
                self.log = hdd / 'copy.private.log'

            def run(self, args, timeout=30):
                if args[:2] == ['systemctl', 'list-units']:
                    return '\n'.join(marker['units'])
                if args[:2] == ['systemctl', 'show']:
                    return '\n'.join(k + '=' + v for k, v in marker['units'][args[2]].items())
                if args[0] == 'dpkg-query':
                    return 'test-package\tii\t1\n'
                if args[0] == 'findmnt':
                    return '{}'
                raise AssertionError(args)

        def mapped_manifest(paths):
            prefix = str(self.root).lstrip('/') + '/'
            return {k.removeprefix(prefix): v for k, v in original_manifest(paths).items()}

        def marker_phase(marker, value):
            if fail_marker and value == 'resuming_original_services':
                raise OSError('injected marker I/O failure')
            return original_phase(marker, value)

        with patch.object(cold, 'HDD', hdd), patch.object(cold, 'STATE', state_dir), \
                patch.object(cold, 'MARKER', state_dir / 'active.json'), \
                patch.object(cold, 'Runner', Metadata), \
                patch.object(cold, 'disk', return_value=(hdd.stat().st_dev, 50 * 1024**2)), \
                patch.object(cold, 'containers', return_value=marker['containers']), \
                patch.object(cold, 'protected_paths', return_value=[str(mysql)]), \
                patch.object(cold, 'locks', side_effect=lambda: nullcontext()), \
                patch.object(cold, 'stop', side_effect=lambda *_: calls.append('stop')), \
                patch.object(cold, 'resume', side_effect=lambda *_: calls.append('resume')), \
                patch.object(cold, 'manifest', side_effect=mapped_manifest), \
                patch.object(cold, 'phase', side_effect=marker_phase), \
                patch.object(cold, 'tar_command', side_effect=lambda *_: original_tar(self.root, [])), \
                redirect_stdout(io.StringIO()) as stdout:
            if fail_copy:
                with patch.object(cold, 'copy_archive', side_effect=cold.RecoveryError('INJECTED_COPY_FAILURE')):
                    with self.assertRaisesRegex(cold.RecoveryError, 'COPY_FAILURE'):
                        cold.capture()
            elif fail_verify:
                with patch.object(cold, 'verify_archive', side_effect=cold.RecoveryError('INJECTED_READBACK_FAILURE')):
                    with self.assertRaisesRegex(cold.RecoveryError, 'READBACK_FAILURE'):
                        cold.capture()
            elif fail_marker:
                with self.assertRaisesRegex(cold.RecoveryError, 'SSD_RECEIPT_WRITE_FAILED_SERVICES_RESUMED'):
                    cold.capture()
            else:
                cold.capture()
            result = cold.read_json(state_dir / 'active.json')
        self.assertEqual(['stop', 'resume'], calls)
        self.assertTrue(result['services_resumed'])
        if fail_copy or fail_verify or fail_marker:
            self.assertEqual('failed_resumed', result['phase'])
            self.assertNotIn('HOST_COLD_RECOVERY=PASS', stdout.getvalue())
        else:
            self.assertEqual('complete', result['phase'])
            self.assertTrue(result['archive_readback_sha256_verified'])
            self.assertGreater(result['protected_members_verified'], 0)
            self.assertIn('HOST_COLD_RECOVERY=PASS', stdout.getvalue())
            self.assertFalse(result['package_install_or_reboot'])

    def test_actual_transaction_archive_and_receipts(self):
        self.transaction()

    def test_copy_failure_resumes_original_services_and_records_failure(self):
        self.transaction(fail_copy=True)

    def test_corrupt_readback_never_passes_after_services_resume(self):
        self.transaction(fail_verify=True)

    def test_ssd_marker_write_failure_still_resumes_services(self):
        self.transaction(fail_marker=True)


if __name__ == '__main__':
    unittest.main()
