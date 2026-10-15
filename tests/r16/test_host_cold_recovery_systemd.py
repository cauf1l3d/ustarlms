"""Actual systemd shutdown of unique sleep-only units on the Ubuntu CI runner."""
import importlib.util
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import unittest
import uuid

SOURCE = Path(__file__).resolve().parents[2] / 'scripts/infra/host_cold_recovery.py'
spec = importlib.util.spec_from_file_location('cold_native', SOURCE)
cold = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cold)


@unittest.skipUnless(os.environ.get('USTAR_REQUIRE_HOST_RECOVERY_SYSTEMD') == '1',
                     'native systemd fixtures run explicitly on Ubuntu CI')
class NativeSystemdTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() != 0 or not Path('/run/systemd/system').is_dir():
            raise AssertionError('Native root systemd runtime is required; no skip fallback')

    def exercise(self, hard_kill=False):
        prefix = 'ustar-ci-host-cold-' + uuid.uuid4().hex
        service, target = prefix + '.service', prefix + '.target'
        unit_dir = Path('/run/systemd/system')
        files = [unit_dir / service, unit_dir / target]
        created = []
        with tempfile.TemporaryDirectory(prefix=prefix, dir='/root') as directory:
            runner = cold.Runner(Path(directory) / 'commands.private.log')
            marker = {}
            try:
                body = ('[Unit]\nDescription=USTAR isolated sleep-only shutdown fixture\n'
                        '[Service]\nType=simple\nExecStart=/usr/bin/sleep infinity\n'
                        'TimeoutStopSec=5s\nKillSignal=' + ('SIGKILL' if hard_kill else 'SIGTERM') + '\n')
                if hard_kill:
                    # Even an explicit systemd "success" must not admit SIGKILL.
                    body += 'SuccessExitStatus=SIGKILL\n'
                texts = [body, '[Unit]\nDescription=Keep fixture metadata loaded\nWants=' + service + '\n']
                for path, text in zip(files, texts):
                    with path.open('x') as out:
                        created.append(path)
                        out.write(text)
                    path.chmod(0o644)
                runner.run(['systemctl', 'daemon-reload'])
                # An active target retains the stopped service object and its exit
                # metadata; its Wants dependency does not restart a manual stop.
                runner.run(['systemctl', 'start', target])
                self.assertEqual('active', cold.unit(runner, service)['ActiveState'])
                if hard_kill:
                    with self.assertRaisesRegex(cold.RecoveryError, 'SERVICE_UNCLEAN_STOP'):
                        cold.stop_service(runner, marker, service)
                    self.assertEqual(service, marker['failed_service'])
                    receipt = marker['failed_service_stop']
                    expected_signal = signal.SIGKILL
                else:
                    cold.stop_service(runner, marker, service)
                    receipt = marker['service_stop_receipts'][-1]
                    expected_signal = signal.SIGTERM
                    self.assertNotIn('failed_service', marker)
                self.assertEqual('inactive', receipt['ActiveState'])
                self.assertEqual('dead', receipt['SubState'])
                self.assertEqual('success', receipt['Result'])
                self.assertEqual('2', receipt['ExecMainCode'])
                self.assertEqual(str(expected_signal), receipt['ExecMainStatus'])
                self.assertEqual('0', receipt['MainPID'])
            finally:
                subprocess.run(['systemctl', 'stop', service, target], capture_output=True, timeout=20)
                subprocess.run(['systemctl', 'reset-failed', service, target], capture_output=True, timeout=10)
                for path in created:
                    path.unlink()
                subprocess.run(['systemctl', 'daemon-reload'], check=True, capture_output=True, timeout=20)

    def test_real_sigterm_stop_is_success_and_accepted(self):
        self.exercise()

    def test_real_sigkill_with_success_override_is_still_refused(self):
        self.exercise(hard_kill=True)


if __name__ == '__main__':
    unittest.main()
