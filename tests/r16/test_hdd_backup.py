"""HDD backup boundary tests. No real disk/container/production service is used."""
from contextlib import ExitStack
import copy
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import stat
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('hdd_backup_tested', REPO / 'scripts/infra/hdd_backup.py')
h = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(h)
MOUNT_ROW = {'source': '/dev/sdb1', 'target': str(h.MOUNT), 'fstype': 'ext4',
             'uuid': h.UUID, 'options': 'rw,nosuid,nodev,noexec,relatime'}
DISK_ROW = {'name': '/dev/sdb', 'type': 'disk', 'size': h.CAPACITY,
            'serial': h.SERIAL, 'wwn': h.WWN,
            'children': [{'name': '/dev/sdb1', 'type': 'part', 'fstype': 'ext4', 'uuid': h.UUID}]}


def fixture_trusted(path, directory=False, private=False):
    """Real metadata guard, with only fixture owner portability."""
    info = Path(path).lstat()
    h.require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
              and not info.st_mode & (0o077 if private else 0o022)
              and (directory or info.st_nlink == 1), 'Fixture metadata refusal')
    return info


class HDDBackupTests(unittest.TestCase):
    def setUp(self):
        previous = os.umask(0o077)
        self.addCleanup(os.umask, previous)

    def mount_context(self, stack, disk=None, mount=None):
        root = SimpleNamespace(st_dev=222, st_ino=123)
        stack.enter_context(mock.patch.object(h, 'parents_trusted'))
        stack.enter_context(mock.patch.object(h, 'trusted', return_value=root))
        stack.enter_context(mock.patch.object(h, 'block_device', return_value=222))
        stack.enter_context(mock.patch.object(h, 'mount_record', return_value=mount or MOUNT_ROW))
        stack.enter_context(mock.patch.object(h.os, 'stat', return_value=SimpleNamespace(st_dev=111)))
        stack.enter_context(mock.patch.object(h, 'command', return_value=json.dumps({'blockdevices': [disk or DISK_ROW]})))

    def test_mount_flags_uuid_type_and_target_are_required(self):
        changes = [{'uuid': 'wrong'}, {'fstype': 'xfs'}, {'target': '/'},
                   {'options': 'rw,nosuid,nodev'}, {'options': 'ro,nosuid,nodev,noexec'}]
        for change in changes:
            with self.subTest(change=change), mock.patch.object(h, 'command', return_value=json.dumps(
                    {'filesystems': [{**MOUNT_ROW, **change}]})):
                with self.assertRaises(h.Refusal):
                    h.mount_record()

    def test_missing_and_ambiguous_mount_are_refused(self):
        for rows in ([], [MOUNT_ROW, MOUNT_ROW]):
            with mock.patch.object(h, 'command', return_value=json.dumps({'filesystems': rows})):
                with self.assertRaises(h.Refusal):
                    h.mount_record()

    def test_disk_wwn_serial_capacity_and_partition_are_checked(self):
        changes = [{'wwn': 'other'}, {'serial': 'other'}, {'size': h.CAPACITY - 512},
                   {'type': 'part'}, {'children': []},
                   {'children': [{**DISK_ROW['children'][0], 'uuid': 'other'}]}]
        for change in changes:
            with self.subTest(change=change), ExitStack() as stack:
                self.mount_context(stack, {**DISK_ROW, **change})
                with self.assertRaises(h.Refusal):
                    h.validate_target()

    def test_valid_target_and_changed_held_inode(self):
        with ExitStack() as stack:
            self.mount_context(stack)
            self.assertEqual(h.validate_target()['uuid'], h.UUID)
            stack.enter_context(mock.patch.object(h.os, 'fstat', return_value=SimpleNamespace(st_dev=222, st_ino=999)))
            with self.assertRaisesRegex(h.Refusal, 'anchor'):
                h.validate_target(10)

    def test_mount_and_uuid_device_disagreement_is_refused(self):
        with ExitStack() as stack:
            self.mount_context(stack)
            h.block_device.side_effect = [222, 333]
            with self.assertRaises(h.Refusal):
                h.validate_target()

    def test_system_filesystem_is_never_the_target(self):
        with ExitStack() as stack:
            self.mount_context(stack)
            h.os.stat.return_value.st_dev = 222
            with self.assertRaisesRegex(h.Refusal, 'System filesystem'):
                h.validate_target()

    def test_metadata_guard_rejects_owner_write_links_and_wrong_type(self):
        good = {'st_mode': stat.S_IFREG | 0o600, 'st_uid': 0, 'st_nlink': 1}
        for change in ({'st_uid': 1001}, {'st_mode': stat.S_IFREG | 0o666},
                       {'st_nlink': 2}, {'st_mode': stat.S_IFLNK | 0o777}):
            path = mock.Mock()
            path.lstat.return_value = SimpleNamespace(**{**good, **change})
            with self.subTest(change=change), self.assertRaises(h.Refusal):
                h.trusted(path)

    def test_anchor_keeps_real_writes_out_of_replacement_mountpoint(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            mount, held = root / 'mount', root / 'detached'
            mount.mkdir(mode=0o700)
            original_cwd = Path.cwd()
            with mock.patch.object(h, 'MOUNT', mount), mock.patch.object(h, 'validate_target'):
                with h.anchored_hdd():
                    mount.rename(held)
                    mount.mkdir(mode=0o700)
                    Path('payload').write_bytes(b'held filesystem')
            self.assertEqual((held / 'payload').read_bytes(), b'held filesystem')
            self.assertFalse((mount / 'payload').exists())
            self.assertEqual(Path.cwd(), original_cwd)

    def test_missing_mount_stops_before_lock_directories_or_backup(self):
        with ExitStack() as stack:
            stack.enter_context(mock.patch.object(h.sys, 'argv', ['backup', '--backup', '--acknowledge-outage']))
            stack.enter_context(mock.patch.object(h.os, 'geteuid', return_value=0))
            stack.enter_context(mock.patch.object(h.signal, 'signal'))
            stack.enter_context(mock.patch.object(h, 'load_engine'))
            stack.enter_context(mock.patch.object(h.shutil, 'which', return_value='/tool'))
            stack.enter_context(mock.patch.object(h, 'validate_target', side_effect=h.Refusal('missing mount')))
            lock = stack.enter_context(mock.patch.object(h, 'backup_lock'))
            prepare = stack.enter_context(mock.patch.object(h, 'prepare'))
            backup = stack.enter_context(mock.patch.object(h, 'run_backup'))
            with self.assertRaises(h.Refusal):
                h.main()
            lock.assert_not_called()
            prepare.assert_not_called()
            backup.assert_not_called()

    def test_dependency_mismatch_does_not_execute_its_code(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source, sentinel = root / 'engine.py', root / 'unsafe-executed'
            source.write_text('from pathlib import Path\nPath(' + repr(str(sentinel)) + ').touch()\n')
            source.chmod(0o600)
            with mock.patch.object(h, 'ENGINE', source), mock.patch.object(h, 'parents_trusted'), \
                    mock.patch.object(h, 'trusted', side_effect=fixture_trusted):
                with self.assertRaisesRegex(h.Refusal, 'SHA mismatch'):
                    h.load_engine()
            self.assertFalse(sentinel.exists())

    def test_backup_needs_acknowledgement_before_loading_engine(self):
        with mock.patch.object(h.sys, 'argv', ['backup', '--backup']), \
                mock.patch.object(h.os, 'geteuid', return_value=0), mock.patch.object(h, 'load_engine') as engine, \
                mock.patch.object(h.sys, 'stderr', io.StringIO()):
            with self.assertRaises(SystemExit):
                h.main()
            engine.assert_not_called()

    def test_shared_kernel_lock_conflict_preserves_same_inode(self):
        with tempfile.TemporaryDirectory() as folder:
            run = Path(folder)
            lockfile = run / 'lock'
            lockfile.touch(mode=0o600)
            original_fstat = os.fstat

            def owner_portable(fd):
                result = original_fstat(fd)
                return SimpleNamespace(st_mode=result.st_mode, st_uid=0, st_nlink=result.st_nlink)

            with lockfile.open('rb') as reader:
                fcntl.flock(reader, fcntl.LOCK_SH | fcntl.LOCK_NB)
                inode = lockfile.stat().st_ino
                with mock.patch.object(h, 'RUN', run), mock.patch.object(h, 'private_directory'), \
                        mock.patch.object(h.os, 'fstat', side_effect=owner_portable):
                    with self.assertRaisesRegex(h.Refusal, 'lock busy'):
                        with h.backup_lock(wait=0):
                            self.fail('must not acquire EX against cron SH')
                self.assertEqual(lockfile.stat().st_ino, inode)

    def test_export_symlink_partial_and_quota_refuse_without_deletion(self):
        for kind in ('symlink', 'partial', 'quota'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as folder:
                export = Path(folder)
                name = 'ustar-recovery-20261005T101659Z-7ea0ad99.tar.gz.age'
                entry = export / (name + '.partial' if kind == 'partial' else name)
                if kind == 'symlink':
                    entry.symlink_to('/missing')
                else:
                    entry.write_bytes(b'archive')
                    entry.chmod(0o600)
                with mock.patch.object(h, 'EXPORT', export), mock.patch.object(h, 'private_directory'), \
                        mock.patch.object(h, 'trusted', side_effect=fixture_trusted), \
                        mock.patch.object(h, 'EXPORT_LIMIT', 1 if kind == 'quota' else 10000):
                    with self.assertRaises(h.Refusal):
                        h.archive_usage()
                self.assertTrue(entry.exists() or entry.is_symlink())

    def test_hdd_budget_uses_source_sizes_and_refuses_low_free_or_inodes(self):
        engine = SimpleNamespace(SITE=Path('/site'), execute=lambda argv: b'[{"Size": 1024}]',
                                 db_sql=lambda query: '2048')
        for free, inodes, passes in ((20 * h.GIB, 20000, True), (h.GIB, 20000, False),
                                    (20 * h.GIB, 9, False)):
            with self.subTest(free=free, inodes=inodes), \
                    mock.patch.object(h, 'command', return_value='4096\t/site/public\n4096\t/site/moodledata\n'), \
                    mock.patch.object(h.shutil, 'disk_usage', return_value=SimpleNamespace(free=free)), \
                    mock.patch.object(h.os, 'statvfs', return_value=SimpleNamespace(f_favail=inodes)):
                if passes:
                    self.assertEqual(h.snapshot_budget(engine, [])['required_free_bytes_estimate'], 6 * h.GIB)
                else:
                    with self.assertRaises(h.Refusal):
                        h.snapshot_budget(engine, [])

    def test_prepare_checks_ssd_marker_and_restores_state_on_preflight_failure(self):
        with tempfile.TemporaryDirectory() as folder:
            state = Path(folder)
            marker = state / 'moodle-was-running.json'
            engine = SimpleNamespace(STATE=state, preflight=mock.Mock(side_effect=RuntimeError('fixture')))
            with mock.patch.object(h, 'SSD_STATE', state), mock.patch.object(h, 'validate_target'), \
                    mock.patch.object(h, 'private_directory'), mock.patch.object(h, 'archive_usage'):
                marker.write_text('{}')
                with self.assertRaisesRegex(h.Refusal, 'recovery marker'):
                    h.prepare(engine, 0)
                engine.preflight.assert_not_called()
                marker.unlink()
                with self.assertRaises(RuntimeError):
                    h.prepare(engine, 0)
                self.assertEqual(engine.STATE, state)

    def test_stop_rechecks_target_and_recover_does_not_require_hdd(self):
        engine = SimpleNamespace(execute=mock.Mock(), recover=mock.Mock(), write_json=mock.Mock(),
                                 MOODLE='ustar_moodle', POSTGRES='ustar_postgres')
        original_recover = engine.recover
        h.install_adapter(engine, 10, (), mock.Mock())
        with mock.patch.object(h, 'validate_target', side_effect=h.Refusal('lost HDD')):
            with self.assertRaises(h.Refusal):
                engine.execute(['docker', 'stop', '--time', '120', 'ustar_moodle'])
            with mock.patch.object(h.signal, 'signal'):
                engine.recover()
        original_recover.assert_called_once()
        with self.assertRaisesRegex(h.Refusal, 'Only Academy'):
            engine.execute(['docker', 'stop', 'ustar_postgres'])

    def test_recovery_mode_is_available_without_hdd(self):
        engine = SimpleNamespace(recover=mock.Mock())
        from contextlib import nullcontext
        with mock.patch.object(h.sys, 'argv', ['backup', '--recover']), \
                mock.patch.object(h.os, 'geteuid', return_value=0), \
                mock.patch.object(h.signal, 'signal'), mock.patch.object(h, 'load_engine', return_value=engine), \
                mock.patch.object(h, 'private_directory'), mock.patch.object(h, 'backup_lock', return_value=nullcontext()), \
                mock.patch.object(h, 'anchored_hdd', side_effect=AssertionError('must not require HDD')):
            h.main()
        engine.recover.assert_called_once()

    def test_http_readiness_requires_local_apache_login_200(self):
        with mock.patch.object(h, 'command', return_value='200') as command:
            self.assertEqual(h.http_ready()['login_http'], 200)
            self.assertIn('Host: ustar.local', command.call_args.args[0])
            self.assertEqual(command.call_args.args[0][-1], 'http://127.0.0.1/login/index.php')
        with mock.patch.object(h, 'command', return_value='503'), mock.patch.object(h.time, 'sleep'):
            with self.assertRaisesRegex(h.Refusal, 'HTTP200'):
                h.http_ready()

    def test_status_without_hdd_does_not_load_engine_or_create_directories(self):
        with tempfile.TemporaryDirectory() as folder, \
                mock.patch.object(h, 'SSD_STATE', Path(folder)), mock.patch.object(h, 'load_engine') as engine, \
                mock.patch.object(h, 'private_directory') as directory, \
                mock.patch.object(h, 'validate_target') as target, \
                mock.patch.object(h.sys, 'stdout', io.StringIO()) as out:
            h.status()
            self.assertIsNone(json.loads(out.getvalue())['last_success'])
            engine.assert_not_called()
            directory.assert_not_called()
            target.assert_not_called()


ENGINE_FIXTURE = os.environ.get('USTAR_TEST_BACKUP_ENGINE')


@unittest.skipUnless(ENGINE_FIXTURE, 'Exact private engine source is not supplied; no capture integration claim')
class PinnedEngineIntegration(unittest.TestCase):
    """Real source + files/tar/hash; Docker, age, mount and fixture UID mocked."""
    def setUp(self):
        import types
        previous_umask = os.umask(0o077)
        self.addCleanup(os.umask, previous_umask)
        data = Path(ENGINE_FIXTURE).read_bytes()
        self.assertEqual(hashlib.sha256(data).hexdigest(), h.ENGINE_SHA)
        self.engine = types.ModuleType('actual_reviewed_backup_fixture')
        exec(compile(data, str(ENGINE_FIXTURE), 'exec'), self.engine.__dict__)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.mount, self.state, self.run = root / 'hdd', root / 'ssd', root / 'run'
        self.detached = root / 'detached'
        site, config = root / 'site', root / 'config'
        for path in (self.mount, self.state, self.run, site, site / 'public', site / 'moodledata',
                     site / 'moodledata/filedir', config):
            path.mkdir(mode=0o700)
        for path, value in ((site / 'public/config.php', b'fixture config'),
                            (site / 'moodledata/filedir/example', b'fixture data'),
                            (config / 'recipients.txt', b'age1fixture-public-recipient\n')):
            path.write_bytes(value)
            path.chmod(0o600)
        self.engine.STATE, self.engine.RUN = self.state, self.run
        self.engine.SITE, self.engine.CONFIG = site, config
        self.running = True
        self.fault = None
        self.lost = False
        self.calls = []
        self.deferred_stop_returned = False
        self.raw_execute = self.engine.execute
        self.engine.execute = self.execute
        self.engine.private_dir = lambda p: fixture_trusted(Path(p), directory=True, private=True)
        self.engine.private_file = lambda p: fixture_trusted(Path(p), private=True)
        self.engine.db_sql = lambda q: '0' if 'pg_stat_activity' in q else (
            '1024' if 'pg_database_size' in q else 'local_ustar=fixture\ntheme_ustar=fixture')
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        for obj, name, value in ((h, 'MOUNT', self.mount), (h, 'SSD_STATE', self.state),
                                 (h, 'RUN', self.run), (h, 'parents_trusted', lambda p: None),
                                 (h, 'trusted', fixture_trusted), (h, 'validate_target', self.target),
                                 (h, 'http_ready', lambda: {'login_http': 200}),
                                 (self.engine.shutil, 'which', lambda name: '/fixture/tool')):
            self.stack.enter_context(mock.patch.object(obj, name, value))
        # chown's privileged UID transition is outside this portable fixture.
        self.stack.enter_context(mock.patch.object(self.engine.os, 'chown'))
        self.output = self.stack.enter_context(mock.patch.object(h.sys, 'stdout', io.StringIO()))

    def target(self, anchor=None):
        h.require(not self.lost, 'Fixture HDD disconnected')
        return {'uuid': h.UUID}

    def inspect_fixture(self, name):
        return {'Id': name + '-fixture', 'Image': h.IMAGES[name],
                'State': {'Running': self.running if name == 'ustar_moodle' else True},
                'Mounts': [{'Destination': '/var/www/html', 'Source': str(self.engine.SITE / 'public')},
                           {'Destination': '/var/www/moodledata', 'Source': str(self.engine.SITE / 'moodledata')}]}

    def execute(self, argv, **kwargs):
        self.calls.append(list(argv))
        if argv[0] == 'tar':
            return self.raw_execute(argv, **kwargs)
        value = b''
        if argv[:2] == ['docker', 'inspect']:
            value = json.dumps([self.inspect_fixture(argv[-1])]).encode()
        elif argv[:3] == ['docker', 'image', 'inspect']:
            value = b'[{"Size": 1024}]'
        elif argv[:2] == ['docker', 'save']:
            value = b'fixture image archive'
        elif argv[:2] == ['docker', 'stop']:
            self.running = False
            if self.fault == 'stop_signal':
                os.kill(os.getpid(), signal.SIGINT)
                self.deferred_stop_returned = True
        elif argv[:2] == ['docker', 'start']:
            if self.fault == 'resume':
                raise RuntimeError('fixture resume failure')
            self.running = True
        elif argv[0] == 'age':
            if self.fault == 'encrypt':
                raise RuntimeError('fixture encryption failure')
            value = b'FAKE-AGE\n' + kwargs['stdin'].read()
        elif argv[:2] == ['docker', 'exec']:
            if 'pg_dump' in argv[-1] and 'pg_dumpall' not in argv[-1]:
                if self.fault in ('capture', 'interrupted', 'lost_hdd'):
                    if self.fault == 'lost_hdd':
                        self.mount.rename(self.detached)
                        self.mount.mkdir(mode=0o700)
                        self.lost = True
                    if self.fault == 'interrupted':
                        raise InterruptedError('fixture signal interruption')
                    raise OSError('fixture capture I/O failure')
                value = b'fixture database dump'
            else:
                value = b'fixture globals/list'
        else:
            self.fail('Unexpected external command: ' + argv[0])
        if kwargs.get('output') is not None:
            kwargs['output'].write(value)
            return b''
        return value

    def capture(self):
        with h.anchored_hdd() as anchor:
            containers, images, budget = h.prepare(self.engine, anchor)
            return h.run_backup(self.engine, anchor, containers, images, budget)

    def test_success_uses_hdd_payloads_and_ssd_control_only(self):
        legacy = self.state / 'last-local-export.json'
        legacy.write_text('{"source":"existing SSD export"}\n')
        legacy.chmod(0o600)
        before = legacy.read_bytes()
        result = self.capture()
        self.assertTrue(result['success'])
        archive = Path(result['manifest']['archive'])
        self.assertTrue(archive.is_file())
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), result['manifest']['sha256'])
        self.assertEqual(list((self.mount / h.STAGING).iterdir()), [])
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'moodle-was-running.json').exists())
        self.assertTrue(all(path.suffix == '.json' for path in self.state.iterdir()))
        self.assertNotIn('SERVEREXPRESS', self.output.getvalue())
        self.assertEqual(self.engine.STATE, self.state)
        self.assertEqual(legacy.read_bytes(), before)
        self.assertTrue((self.state / 'last-hdd-export.json').is_file())

    def test_capture_failure_preserves_private_work_and_resumes_moodle(self):
        self.fault = 'capture'
        with self.assertRaises(OSError):
            self.capture()
        report = json.loads((self.state / 'last-hdd-attempt.json').read_text())
        self.assertFalse(report['success'])
        self.assertTrue(report['staging_retained'])
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'moodle-was-running.json').exists())
        self.assertEqual(list((self.mount / h.EXPORT).iterdir()), [])

    def test_signal_interruption_runs_resume_and_retains_work(self):
        self.fault = 'interrupted'
        with self.assertRaises(InterruptedError):
            self.capture()
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'moodle-was-running.json').exists())
        self.assertTrue(list((self.mount / h.STAGING).iterdir()))

    def test_real_sigint_during_stop_is_deferred_until_reply_and_then_resumed(self):
        self.fault = 'stop_signal'
        old = signal.signal(signal.SIGINT, h.interrupted)
        try:
            with self.assertRaises(InterruptedError):
                self.capture()
        finally:
            signal.signal(signal.SIGINT, old)
        self.assertTrue(self.deferred_stop_returned)
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'moodle-was-running.json').exists())

    def test_uncertain_running_stop_keeps_marker_for_manual_inspection(self):
        marker = self.state / 'moodle-was-running.json'
        marker.write_text(json.dumps({'container_id': 'ustar_moodle-fixture', 'stop_phase': 'inflight'}))
        marker.chmod(0o600)
        self.lost = True
        with self.assertRaisesRegex(h.Refusal, 'stop completion unverified'):
            h.resume_safely(self.engine)
        self.assertTrue(marker.exists())
        self.assertTrue(self.running)

    def test_hdd_disconnection_keeps_writes_on_anchor_and_resumes_from_ssd(self):
        self.fault = 'lost_hdd'
        with self.assertRaises(OSError):
            self.capture()
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'moodle-was-running.json').exists())
        self.assertEqual(list(self.mount.iterdir()), [])
        self.assertTrue(list((self.detached / h.STAGING).iterdir()))
        self.assertTrue((self.state / 'last-hdd-attempt.json').is_file())

    def test_failed_resume_leaves_ssd_marker_for_recover_without_mount(self):
        self.fault = 'resume'
        with self.assertRaises(RuntimeError):
            self.capture()
        self.assertTrue((self.state / 'moodle-was-running.json').exists())
        self.assertFalse(self.running)
        self.fault = None
        self.lost = True
        self.engine.recover()
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'moodle-was-running.json').exists())

    def test_encryption_failure_cannot_publish_success(self):
        self.fault = 'encrypt'
        with self.assertRaises(RuntimeError):
            self.capture()
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'last-hdd-success.json').exists())
        self.assertEqual(list((self.mount / h.EXPORT).iterdir()), [])
        self.assertTrue(list((self.mount / h.STAGING).iterdir()))

    def test_http_failure_preserves_completed_archive_and_reports_failure(self):
        with mock.patch.object(h, 'http_ready', side_effect=h.Refusal('fixture HTTP500')):
            with self.assertRaises(h.Refusal):
                self.capture()
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'last-hdd-success.json').exists())
        self.assertTrue(list((self.mount / h.EXPORT).glob('*.age')))
        report = json.loads((self.state / 'last-hdd-attempt.json').read_text())
        self.assertFalse(report['success'])

    def test_corrupt_partial_is_not_published_or_silently_removed(self):
        digest = self.engine.digest
        self.engine.digest = lambda p: '0' * 64 if str(p).endswith('.partial') else digest(p)
        with self.assertRaises(RuntimeError):
            self.capture()
        self.assertTrue(self.running)
        self.assertFalse((self.state / 'last-hdd-success.json').exists())
        self.assertFalse(list((self.mount / h.EXPORT).glob('*.age')))
        self.assertTrue(list((self.mount / h.EXPORT).glob('*.partial')))


if __name__ == '__main__':
    unittest.main()
