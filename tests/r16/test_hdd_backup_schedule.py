"""Timer/recovery gates; systemctl and Docker are never run against a live host."""
from contextlib import ExitStack, nullcontext
from datetime import datetime, timezone
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


s = module('hdd_schedule_test', 'scripts/infra/hdd_backup_schedule.py')
h = module('hdd_schedule_producer_test', 'scripts/infra/hdd_backup.py')
AT = datetime(2026, 10, 6, 1, 0, tzinfo=timezone.utc)


class HDDScheduleTests(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        old_mask = os.umask(0o077)
        self.addCleanup(os.umask, old_mask)
        self.root = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        self.state, self.units = self.root / 'state', self.root / 'units'
        self.state.mkdir()
        self.units.mkdir()
        self.stack.enter_context(mock.patch.object(s, 'UNITS', self.units))
        self.stack.enter_context(mock.patch.object(h, 'SSD_STATE', self.state))
        self.stack.enter_context(mock.patch.object(h, 'parents_trusted'))
        self.stack.enter_context(mock.patch.object(h, 'trusted', side_effect=self.trusted))
        self.command = self.stack.enter_context(mock.patch.object(h, 'command', side_effect=self.host))
        self.stack.enter_context(mock.patch.object(s, 'now', return_value=AT))
        self.output = self.stack.enter_context(mock.patch('sys.stdout', new=io.StringIO()))
        self.stack.enter_context(mock.patch('sys.stderr', new=io.StringIO()))

    def trusted(self, path, directory=False, private=False):
        info = path.lstat()
        h.require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                  and not info.st_mode & (0o077 if private else 0o022)
                  and (directory or info.st_nlink == 1), 'Fixture type/mode/links refusal')
        return info

    def host(self, argv, **kwargs):
        if argv[:2] == ['systemctl', 'show']:
            name = argv[2]
            if argv[-1] == '--property=FragmentPath,DropInPaths':
                return f'FragmentPath={self.units / name}\nDropInPaths=\n'
            return 'ActiveState=inactive\nDropInPaths=\n'
        if argv[:2] == ['systemctl', 'is-active']:
            return 'active\n'
        return ''

    def saved(self, name, value):
        path = self.state / name
        path.write_text(json.dumps(value))
        path.chmod(0o600)
        return path

    def success(self, started='2026-10-06T00:30:01+00:00'):
        return {'started_utc': started, 'finished_utc': '2026-10-06T00:32:00+00:00',
                'success': True, 'readiness': {'login_http': 200},
                'manifest': {'readback_verified': True}}

    def post_environment(self, success=True):
        return mock.patch.dict(os.environ, {'SERVICE_RESULT': 'success' if success else 'exit-code',
                                           'EXIT_CODE': 'exited', 'EXIT_STATUS': '0' if success else '1'})

    def test_moscow_window_has_explicit_utc_boundaries(self):
        for minute, second, expected in ((29, 59, False), (30, 0, True),
                                         (39, 59, True), (40, 0, False)):
            self.assertEqual(s.in_window(datetime(2026, 10, 6, 0, minute, second,
                                                 tzinfo=timezone.utc)), expected)
        self.assertFalse(s.in_window(AT))

    def test_next_event_after_trial_is_tomorrow_not_daytime(self):
        self.assertEqual(s.next_event(AT).isoformat(), '2026-10-07T03:30:00+03:00')

    def test_producer_sha_is_checked_before_source_execution(self):
        fake = mock.Mock()
        parent = mock.Mock()
        parent.lstat.return_value = SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0)
        fake.parents = [parent]
        fake.lstat.return_value = SimpleNamespace(st_mode=stat.S_IFREG | 0o750, st_uid=0, st_nlink=1)
        fake.read_bytes.return_value = b'raise AssertionError("unreviewed source executed")'
        with mock.patch.object(s, 'PRODUCER', fake):
            with self.assertRaisesRegex(RuntimeError, 'SHA mismatch'):
                s.load_producer()

    def test_damaged_report_does_not_block_ssd_marker_resume(self):
        (self.state / s.REPORT).write_text('damaged')
        with self.post_environment(False), mock.patch.object(s, 'resume_if_needed', return_value=True) as resume:
            with self.assertRaises(json.JSONDecodeError):
                s.after_service(h)
            resume.assert_called_once_with(h)

    @unittest.skipUnless(shutil.which('systemd-analyze'), 'systemd parser unavailable')
    def test_real_calendar_parser_maps_moscow_to_utc_without_catchup(self):
        result = subprocess.run(['systemd-analyze', 'calendar', '--iterations=2',
                                 '--base-time=2026-10-06 01:00:00 UTC', s.CALENDAR],
                                capture_output=True, text=True, timeout=15,
                                env={**os.environ, 'TZ': 'UTC', 'LC_ALL': 'C'})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('2026-10-07 00:30:00 UTC', result.stdout)
        self.assertIn('2026-10-08 00:30:00 UTC', result.stdout)

    @unittest.skipUnless(shutil.which('systemd-analyze'), 'systemd parser unavailable')
    def test_real_unit_parser_accepts_units_without_ordering_cycle(self):
        paths = []
        for name, value in {**s.units(), 'docker.service':
                            '[Service]\nType=oneshot\nExecStart=/usr/bin/true\n'}.items():
            path = self.root / name
            path.write_text(value)
            paths.append(str(path))
        result = subprocess.run(['systemd-analyze', 'verify', *paths], capture_output=True,
                                text=True, timeout=30, env={**os.environ, 'LC_ALL': 'C'})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('cycle', result.stderr.lower())

    def test_outside_window_refuses_before_backup_but_records_attempt(self):
        with mock.patch.object(s, 'producer_cli') as cli:
            with self.assertRaisesRegex(h.Refusal, 'Outside'):
                s.run(h)
            cli.assert_not_called()
        self.assertFalse(json.loads((self.state / s.REPORT).read_text())['success'])

    def test_resume_without_marker_does_not_load_engine_or_touch_hdd(self):
        with mock.patch.object(s, 'producer_cli') as cli, mock.patch.object(h, 'http_ready') as ready:
            self.assertFalse(s.resume_if_needed(h))
            cli.assert_not_called()
            ready.assert_not_called()
        self.assertEqual(list(self.state.iterdir()), [])

    def test_foreign_marker_is_preserved_without_recovery(self):
        marker = self.saved('moodle-was-running.json', {'producer': 'foreign'})
        before = marker.read_bytes()
        with mock.patch.object(s, 'producer_cli') as cli:
            with self.assertRaisesRegex(h.Refusal, 'Foreign'):
                s.resume_if_needed(h)
            cli.assert_not_called()
        self.assertEqual(marker.read_bytes(), before)

    def test_ssd_marker_resume_requires_no_hdd_and_checks_login(self):
        marker = self.saved('moodle-was-running.json', {'producer': 'ustar-hdd-backup-v1'})
        def resume(producer, mode):
            self.assertIs(producer, h)
            self.assertEqual(mode, '--recover')
            marker.unlink()
        with mock.patch.object(s, 'producer_cli', side_effect=resume), \
                mock.patch.object(h, 'http_ready') as ready, \
                mock.patch.object(h, 'anchored_hdd') as mount:
            self.assertTrue(s.resume_if_needed(h))
            ready.assert_called_once()
            mount.assert_not_called()

    def test_real_producer_preserves_ambiguous_running_stop_marker(self):
        marker = self.saved('moodle-was-running.json', {'producer': 'ustar-hdd-backup-v1',
                            'container_id': 'same', 'stop_phase': 'inflight'})
        engine = mock.Mock()
        engine.MOODLE = 'ustar_moodle'
        engine.inspect.return_value = {'Id': 'same', 'State': {'Running': True}}
        with mock.patch.object(h, 'load_engine', return_value=engine), \
                mock.patch.object(h.os, 'geteuid', return_value=0), \
                mock.patch.object(h, 'backup_lock', return_value=nullcontext()):
            with self.assertRaisesRegex(h.Refusal, 'completion unverified'):
                s.resume_if_needed(h)
        self.assertTrue(marker.exists())
        engine.recover.assert_not_called()

    def test_after_service_pass_requires_fresh_completed_backup(self):
        self.saved(s.REPORT, {'started_utc': '2026-10-06T00:30:00+00:00'})
        self.saved('last-hdd-success.json', self.success())
        with self.post_environment(), mock.patch.object(s, 'resume_if_needed', return_value=False):
            s.after_service(h)
        result = json.loads((self.state / s.REPORT).read_text())
        self.assertTrue(result['success'])
        self.assertEqual(result['service_result'], 'success')

    def test_zero_exit_with_old_success_cannot_be_reported_as_new_backup(self):
        self.saved(s.REPORT, {'started_utc': AT.isoformat()})
        self.saved('last-hdd-success.json', self.success())
        with self.post_environment(), mock.patch.object(s, 'resume_if_needed', return_value=False):
            with self.assertRaisesRegex(h.Refusal, 'without a fresh'):
                s.after_service(h)
        self.assertFalse(json.loads((self.state / s.REPORT).read_text())['success'])

    def test_failed_service_stays_failed_even_when_resume_succeeds(self):
        self.saved(s.REPORT, {'started_utc': '2026-10-06T00:30:00+00:00'})
        self.saved('last-hdd-success.json', self.success())
        with self.post_environment(False), mock.patch.object(s, 'resume_if_needed', return_value=True):
            s.after_service(h)
        result = json.loads((self.state / s.REPORT).read_text())
        self.assertFalse(result['success'])
        self.assertTrue(result['resumed_from_marker'])

    def test_failed_resume_preserves_marker_and_records_recovery_required(self):
        self.saved(s.REPORT, {'started_utc': AT.isoformat()})
        marker = self.saved('moodle-was-running.json', {'producer': 'ustar-hdd-backup-v1'})
        with self.post_environment(False), mock.patch.object(s, 'resume_if_needed',
                                                           side_effect=h.Refusal('uncertain stop')):
            with self.assertRaisesRegex(h.Refusal, 'Recovery failed'):
                s.after_service(h)
        result = json.loads((self.state / s.REPORT).read_text())
        self.assertTrue(marker.exists())
        self.assertFalse(result['success'])
        self.assertTrue(result['recovery_marker_present'])

    def test_trial_gate_refuses_old_or_future_trial_before_mount(self):
        for date in ('2026-10-03T01:00:00+00:00', '2026-10-07T01:00:00+00:00'):
            self.saved('last-hdd-success.json', {**self.success(), 'finished_utc': date})
            with mock.patch.object(h, 'load_engine') as load:
                with self.assertRaisesRegex(h.Refusal, '48h'):
                    s.trial_gate(h)
                load.assert_not_called()

    def test_trial_manifest_mismatch_refuses_activation(self):
        self.saved('last-hdd-success.json', self.success())
        with mock.patch.object(h, 'load_engine', return_value=object()), \
                mock.patch.object(h, 'anchored_hdd', return_value=nullcontext(10)), \
                mock.patch.object(h, 'backup_lock', return_value=nullcontext()), \
                mock.patch.object(h, 'prepare'), \
                mock.patch.object(h, 'verified_manifest', return_value={'other': True}):
            with self.assertRaisesRegex(h.Refusal, 'differ'):
                s.trial_gate(h)

    def test_foreign_unit_is_preserved_and_no_partial_units_written(self):
        foreign = self.units / s.TIMER
        foreign.write_text('foreign unit')
        before = foreign.read_bytes()
        with mock.patch.object(s, 'trial_gate'):
            with self.assertRaisesRegex(h.Refusal, 'differs'):
                s.install(h)
        self.assertEqual(foreign.read_bytes(), before)
        self.assertEqual(list(self.units.iterdir()), [foreign])
        self.assertFalse(any('enable' in call.args[0] for call in self.command.call_args_list))

    def test_unit_override_refuses_installation_before_writes(self):
        (self.units / (s.SERVICE + '.d')).mkdir()
        with mock.patch.object(s, 'trial_gate'):
            with self.assertRaisesRegex(h.Refusal, 'override'):
                s.install(h)
        self.assertFalse((self.units / s.TIMER).exists())

    def test_active_backup_refuses_installation(self):
        def host(argv, **kwargs):
            if argv[:2] == ['systemctl', 'list-units']:
                return s.SERVICE if argv[-1] == s.SERVICE else ''
            if argv[:2] == ['systemctl', 'show']:
                return 'ActiveState=activating\nDropInPaths=\n'
            return self.host(argv, **kwargs)
        self.command.side_effect = host
        with mock.patch.object(s, 'trial_gate'):
            with self.assertRaisesRegex(h.Refusal, 'service active'):
                s.install(h)
        self.assertEqual(list(self.units.iterdir()), [])

    def test_verifier_failure_keeps_units_and_timer_absent(self):
        def host(argv, **kwargs):
            if argv[0] == 'systemd-analyze':
                raise h.Refusal('unit verification failed')
            return self.host(argv, **kwargs)
        self.command.side_effect = host
        with mock.patch.object(s, 'trial_gate'):
            with self.assertRaisesRegex(h.Refusal, 'verification'):
                s.install(h)
        self.assertEqual(list(self.units.iterdir()), [])

    def test_install_and_repeat_enable_only_timer_now_and_preserve_inodes(self):
        with mock.patch.object(s, 'trial_gate'), mock.patch.object(s, 'show_status'):
            s.install(h)
            inodes = {p.name: p.stat().st_ino for p in self.units.iterdir()}
            s.install(h)
        self.assertEqual(inodes, {p.name: p.stat().st_ino for p in self.units.iterdir()})
        for name, value in s.units().items():
            path = self.units / name
            self.assertEqual(path.read_text(), value)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o644)
        enabled_now = [call.args[0] for call in self.command.call_args_list if '--now' in call.args[0]]
        self.assertEqual(enabled_now, [['systemctl', 'enable', '--now', s.TIMER]] * 2)
        self.assertFalse(any(call.args[0][:2] == ['systemctl', 'start'] for call in self.command.call_args_list))

    def test_install_near_next_event_refuses_before_trial_or_unit_write(self):
        near = datetime(2026, 10, 6, 0, 20, tzinfo=timezone.utc)
        with mock.patch.object(s, 'now', return_value=near), mock.patch.object(s, 'trial_gate') as trial:
            with self.assertRaisesRegex(h.Refusal, '15min'):
                s.install(h)
            trial.assert_not_called()
        self.assertEqual(list(self.units.iterdir()), [])

    def test_status_does_not_prepare_lock_mount_or_start_anything(self):
        path = self.saved(s.REPORT, {'success': False})
        before = path.stat().st_mtime_ns
        with mock.patch.object(h, 'private_directory') as mkdir, \
                mock.patch.object(h, 'load_engine') as engine, mock.patch.object(s, 'producer_cli') as cli:
            s.show_status(h)
            mkdir.assert_not_called()
            engine.assert_not_called()
            cli.assert_not_called()
        self.assertEqual(path.stat().st_mtime_ns, before)


if __name__ == '__main__':
    unittest.main()
