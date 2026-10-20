"""Read-only TLS preparation facts; synthetic host metadata, real config files."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('https_readiness', ROOT / 'scripts/infra/connect_https_readiness.py')
x = importlib.util.module_from_spec(spec)
spec.loader.exec_module(x)


class HttpsPreparation(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.config = self.root / 'config.php'
        self.config.write_text("<?php\n$CFG->wwwroot = 'http://ustar.local';\n$CFG->dbpass = 'PRIVATE_SENTINEL';\n")
        self.commands = []

    def runner(self, argv):
        self.commands.append(argv)
        if argv[:2] == ['docker', 'inspect']:
            return json.dumps({'running': True, 'ports': {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '8082'}]},
                'networks': {'isolated': {'IPAddress': '172.25.0.5', 'Gateway': '172.25.0.1'}}})
        if argv[0] == 'systemctl': return 'ActiveState=active\nSubState=running\n'
        if argv[0] == 'ss': return 'LISTEN 0 511 0.0.0.0:443 0.0.0.0:*\nLISTEN 0 128 127.0.0.1:8443 0.0.0.0:*\n'
        raise AssertionError('unexpected command')

    def test_report_preserves_HTTP_dependency_and_never_claims_activation(self):
        report = x.check(config=self.config, runner=self.runner)
        self.assertEqual('https_preparation_required', report['status'])
        self.assertFalse(report['academy_origin']['https'])
        self.assertFalse(report['activation_ready'])
        self.assertEqual([{'container_port': '80/tcp', 'host_port': 8082}], report['moodle']['loopback_bindings'])
        self.assertTrue(report['listeners']['443'])
        self.assertFalse(report['listeners']['18443'])
        raw = json.dumps(report)
        for secret in ('PRIVATE_SENTINEL', '172.25.0.5', '172.25.0.1', 'dbpass'):
            self.assertNotIn(secret, raw)
        self.assertTrue(report['read_only'])
        self.assertFalse(report['PHP_or_Moodle_bootstrap'])
        self.assertFalse(any('exec' in a or 'restart' in a or 'reload' in a for a in self.commands))

    def test_https_literal_is_metadata_not_verified_certificate(self):
        self.config.write_text("<?php\n$CFG->wwwroot='https://academy.example.invalid';\n")
        report = x.check(config=self.config, runner=self.runner)
        self.assertEqual('https_origin_present_acceptance_pending', report['status'])
        self.assertFalse(report['activation_ready'])
        self.assertFalse(report['academy_origin']['certificate_verified'])

    def test_expressions_or_credential_bearing_URLs_are_not_evaluated_or_printed(self):
        for expression in ('getenv("PRIVATE_SENTINEL")', "'https://user:PRIVATE_SENTINEL@academy.invalid'", "'https://academy.invalid?token=PRIVATE_SENTINEL'"):
            self.config.write_text('<?php\n$CFG->wwwroot = ' + expression + ';\n')
            report = x.check(config=self.config, runner=self.runner)
            self.assertEqual('unavailable', report['academy_origin']['status'])
            self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))

    def test_unavailable_command_does_not_export_error_or_skip_other_sections(self):
        def missing(argv): raise OSError('PRIVATE_SENTINEL')
        report = x.check(config=self.config, runner=missing)
        self.assertEqual('unavailable', report['moodle']['status'])
        self.assertEqual('unavailable', report['services']['apache2']['status'])
        self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))

    def test_symlink_configuration_is_unavailable(self):
        link = self.root / 'alias.php'; link.symlink_to(self.config)
        self.assertEqual('unavailable', x.check(config=link, runner=self.runner)['academy_origin']['status'])

    def test_default_plan_has_no_host_access(self):
        with patch.object(x, 'check', side_effect=AssertionError('host access')), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, x.main([]))
        self.assertIn('plan_only', output.getvalue())

    def test_deadline_escapes_section_errors_but_cli_refuses_without_traceback(self):
        def expired(argv):
            raise x.ReadinessDeadline()
        with self.assertRaises(x.ReadinessDeadline):
            x.check(config=self.config, runner=expired)
        with patch.object(x.os, 'geteuid', return_value=0), patch.object(x.sys, 'version_info', (3, 12)), \
                patch.object(x, 'check', side_effect=x.ReadinessDeadline()), \
                patch.object(x.signal, 'signal') as handler, patch.object(x.signal, 'alarm') as alarm, \
                contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(2, x.main(['--check']))
        self.assertEqual('refused', json.loads(output.getvalue())['status'])
        self.assertEqual(0, alarm.call_args.args[0])
        self.assertEqual(handler.return_value, handler.call_args.args[1])
