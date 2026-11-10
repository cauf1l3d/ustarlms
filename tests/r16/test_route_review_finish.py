"""Finish the committed release when Apache redirects and Moodle itself is healthy."""
import importlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))


def recovery():
    path = ROOT / 'scripts/infra/route_review_finish.py'
    if not path.is_file():
        raise AssertionError('Missing guarded recovery for the observed PORT80=302 / PORT8082=200')
    return importlib.import_module('route_review_finish')


class Contract(unittest.TestCase):
    def test_redirecting_frontend_does_not_hide_healthy_backend(self):
        fix = recovery()
        old = importlib.import_module('route_access_update')
        host = old.Host(None)

        def response(args, **kwargs):
            if args[-1] == 'http://127.0.0.1/login/index.php':
                return b'302'
            if args[-1] == 'http://127.0.0.1:8082/login/index.php':
                self.assertIn('--noproxy', args)
                self.assertIn('Host: ustar.local', args)
                self.assertNotIn('--location', args)
                return b'200'
            raise AssertionError('Unexpected network destination')

        with patch.object(host, 'run', side_effect=response), patch.object(old.time, 'sleep'):
            self.assertFalse(host.login(), 'Reproduce the frozen installer failure')
            self.assertTrue(fix.backend_login(host))

    def test_backend_error_and_redirect_are_refused(self):
        fix = recovery()
        old = importlib.import_module('route_access_update')
        for status in (b'302', b'403', b'500', b'503', b'000'):
            host = old.Host(None)
            with self.subTest(status=status), patch.object(host, 'run', return_value=status):
                self.assertFalse(fix.backend_login(host))

    def test_default_is_offline_and_recover_requires_outage_acknowledgement(self):
        fix = recovery()
        with patch.object(fix, 'private_script', side_effect=AssertionError('host read')):
            with patch('sys.stdout', new_callable=io.StringIO) as output:
                self.assertEqual(0, fix.main([]))
                self.assertFalse(json.loads(output.getvalue())['host_access'])
            with patch('sys.stderr', new_callable=io.StringIO), self.assertRaises(SystemExit):
                fix.main(['--recover'])

    def test_exact_original_bundle_is_loaded_and_changed_bundle_refused(self):
        fix = recovery()
        builder = importlib.import_module('build_route_access_update')
        with tempfile.TemporaryDirectory() as td:
            result = builder.build('f879822a406eefc3c65fe4a5d0c36d28bc091ba5', td)
            with tarfile.open(result['archive']) as archive:
                raw = archive.extractfile('update.py').read()
            with fix.frozen_release(raw) as (module, manifest, payload):
                self.assertEqual('b9f170d89cead30021b0acbfba6ee681cec024f8', manifest['application_commit'])
                self.assertEqual(614, len(manifest['candidate']['files']))
                module.Update(Path('/'), manifest, payload, None)
            self.assertIs(importlib.import_module('route_access_update'), builder.update)
            with self.assertRaisesRegex(ValueError, 'original_script_digest'):
                with fix.frozen_release(raw + b'\n'):
                    self.fail('Modified original accepted')
            folder = Path(td) / 'isolated'
            folder.mkdir()
            (folder / 'route_review_finish.py').write_bytes((ROOT / 'scripts/infra/route_review_finish.py').read_bytes())
            (folder / 'route_access_update.py').write_text('raise RuntimeError("adjacent poison")')
            run = subprocess.run([sys.executable, '-I', '-S', str(folder / 'route_review_finish.py')],
                                 capture_output=True, text=True, check=True)
            self.assertFalse(json.loads(run.stdout)['host_access'])


@unittest.skipUnless(os.geteuid() == 0, 'private root journals are checked by the mandatory sudo CI job')
class Transaction(unittest.TestCase):
    def setUp(self):
        self.fix = recovery()
        fixture = importlib.import_module('tests.r16.test_route_access_update').Transaction('runTest')
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        self.tx = fixture.tx
        self.host = fixture.host
        self.old = fixture.m
        self.host.login = lambda: False
        with self.assertRaisesRegex(self.old.Refusal, 'application_login_failed'):
            self.tx.apply()
        self.assertEqual('publication_committed', self.tx.load()['phase'])
        self.before_calls = list(self.host.calls)
        self.before_dump = (self.tx.base / 'before.dump').read_bytes()

    def test_committed_finish_restores_workers_without_code_database_or_container_restart(self):
        self.host.login = lambda: True
        result = self.fix.finish_existing(self.tx, recover=True)
        self.assertEqual('active', result['phase'])
        self.assertEqual(['restore_workers'], self.host.calls[len(self.before_calls):])
        self.assertEqual(self.before_dump, (self.tx.base / 'before.dump').read_bytes())
        self.assertEqual('active', self.tx.load()['phase'])
        self.assertEqual('active', self.fix.finish_existing(self.tx, recover=True)['phase'])

    def test_readonly_check_does_not_close_journal_or_start_workers(self):
        self.host.login = lambda: True
        before = (self.tx.base / 'state.json').read_bytes()
        self.assertEqual('ready_to_finish', self.fix.finish_existing(self.tx, recover=False)['phase'])
        self.assertEqual(before, (self.tx.base / 'state.json').read_bytes())
        self.assertEqual(self.before_calls, self.host.calls)

    def test_unhealthy_backend_keeps_journal_pending(self):
        with self.assertRaisesRegex(self.old.Refusal, 'application_login_failed'):
            self.fix.finish_existing(self.tx, recover=True)
        self.assertEqual('publication_committed', self.tx.load()['phase'])
        self.assertEqual(self.before_calls, self.host.calls)

    def test_source_drift_is_still_refused(self):
        self.host.login = lambda: True
        target = self.tx.web / 'local/ustar/course_launch.php'
        target.write_bytes(b'foreign source')
        with self.assertRaises(self.old.Refusal):
            self.fix.finish_existing(self.tx, recover=True)
        self.assertEqual(self.before_calls, self.host.calls)

    def test_corrupted_database_copy_is_refused_before_workers_start(self):
        self.host.login = lambda: True
        (self.tx.base / 'before.dump').write_bytes(b'corrupt')
        with self.assertRaisesRegex(self.old.Refusal, 'database_copy_changed'):
            self.fix.finish_existing(self.tx, recover=True)
        self.assertEqual('publication_committed', self.tx.load()['phase'])
        self.assertEqual(self.before_calls, self.host.calls)

    def test_earlier_phase_cannot_be_upgraded_by_finish_helper(self):
        state = self.tx.load()
        self.tx.checkpoint(state, 'upgrade_2')
        self.host.login = lambda: True
        with self.assertRaisesRegex(ValueError, 'publication_not_committed'):
            self.fix.finish_existing(self.tx, recover=True)
        self.assertEqual('upgrade_2', self.tx.load()['phase'])
        self.assertEqual(self.before_calls, self.host.calls)


if __name__ == '__main__':
    unittest.main()
