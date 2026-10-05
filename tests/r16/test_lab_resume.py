"""Retained-lab safety/failure tests. No Docker, systemd, PHP or production access."""

import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('lab_resume_tested', REPO / 'scripts/infra/resume_restore_lab.py')
resume = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(resume)

TEMPLATE = """<?php
$CFG->dbhost = '127.0.0.1';
$CFG->dbpass = '%s';
$CFG->cronremotepassword = '%s';
$CFG->noemailever = true;
$CFG->cron_enabled = 0;
"""


def state():
    return {'stopped': True, 'ready': False, 'token': 'a' * 32,
            'archive_sha256': 'b' * 64, 'containers': {'pg': 'old-pg', 'web': 'old-web'},
            'images': {'pg': 'sha256:' + 'c' * 64, 'web': 'sha256:' + 'd' * 64},
            'preflight': {'images': {'ustar_postgres': {'id': 'sha256:' + 'c' * 64},
                                      'ustar_moodle': {'id': 'sha256:' + 'd' * 64}},
                          'application_versions': ['local_ustar=2026100204', 'theme_ustar=2026100202']}}


class FakeLab:
    NAMES = {'pg': 'ustar_recovery_lab_20261004_pg', 'web': 'ustar_recovery_lab_20261004_web'}
    PRODUCTION = ('ustar_moodle', 'ustar_postgres')
    PORT = 0
    UNIT = 'ustar-recovery-lab-20261004-http.service'

    def __init__(self):
        self.actions = []
        self.sql_queries = []
        self.occupied = False
        self.http_failure = False
        self.bootstrap_failure = False
        self.production_drift = False
        self.enabled = '0'
        self._LOG = None

    def static_login_secrets(self, _):
        return {}

    def inspect(self, name, absent_ok=False):
        self.actions.append(('inspect', name))
        return {'Id': 'foreign'} if self.occupied else None

    def relay_unit_absent(self):
        self.actions.append(('relay_absent',))

    def command(self, argv, **kwargs):
        self.actions.append(('command', argv))
        if argv[:3] == ['docker', 'image', 'inspect']:
            pg = argv[3] == state()['images']['pg']
            return SimpleNamespace(stdout=json.dumps([{'Id': argv[3], 'Os': 'linux', 'Architecture': 'amd64',
                'Config': {'Entrypoint': ['docker-entrypoint.sh' if pg else 'docker-php-entrypoint'],
                           'Cmd': ['postgres' if pg else 'apache2-foreground']}}]).encode(), returncode=0)
        return SimpleNamespace(returncode=0, stdout=b'')

    def reserve_space(self):
        self.actions.append(('space',))

    def production_state(self):
        self.actions.append(('production_inspect',))
        return {'ustar_moodle': {'id': 'production-web', 'started': 'changed' if self.production_drift else 'same'},
                'ustar_postgres': {'id': 'production-pg', 'started': 'same'}}

    def write_private(self, path, data):
        if isinstance(data, str):
            data = data.encode()
        with path.open('xb') as handle:
            handle.write(data)
        path.chmod(0o600)

    def save_state(self, current):
        (resume.ROOT / 'state.json').write_text(json.dumps(current))
        self.actions.append(('save_state',))

    def create_container(self, kind, current):
        self.actions.append(('create', kind))
        current['containers'][kind] = 'new-' + kind

    def owned(self, kind, current):
        return {'Id': current['containers'][kind], 'State': {'Running': True}}

    def sql(self, current, query):
        if not query.startswith('BEGIN READ ONLY;') or any(w in query for w in ('INSERT ', 'UPDATE ', 'DELETE ', 'CREATE ', 'DROP ')):
            raise AssertionError('Non-read-only lab SQL')
        self.sql_queries.append(query)
        return self.enabled if 'count(*)' in query else '\n'.join(current['preflight']['application_versions'])

    def assert_offline(self, _):
        self.actions.append(('offline',))

    def lab_exec(self, kind, _, argv, user=None):
        self.actions.append(('lab_exec', kind, argv, user))
        if argv[1] == '-l':
            return SimpleNamespace(stdout=b'')
        boot = {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab', 'wwwroot': 'http://127.0.0.1:18084',
                'noemail': True, 'cron': 0, 'needs_upgrade': self.bootstrap_failure}
        return SimpleNamespace(stdout=json.dumps(boot).encode())

    def start_relay(self, _):
        self.actions.append(('relay',))

    def http_check(self):
        if self.http_failure:
            raise RuntimeError('synthetic HTTP failure')
        return 200

    def stop_lab(self, current):
        self.actions.append(('stop_owned_lab',))
        current.update(ready=False, stopped=True)


class LabResumeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        patch = mock.patch.object(resume, 'ROOT', self.root)
        patch.start()
        self.addCleanup(patch.stop)
        self.lab, self.state = FakeLab(), state()
        code = self.root / 'site/public'
        for name in ('public/login', 'lib', 'admin/cli'):
            (code / name).mkdir(parents=True, exist_ok=True)
        (code / 'config.php').write_text(TEMPLATE % ('e' * 64, 'f' * 32) + "require_once(__DIR__ . '/lib/setup.php');\n")
        (code / 'public/config.php').write_text("<?php\nrequire_once(dirname(__DIR__) . '/config.php');\n")
        for name in ('base', 'global', 'pg_wal', 'pg_tblspc'):
            (self.root / 'postgres' / name).mkdir(parents=True)
        (self.root / 'postgres/PG_VERSION').write_text('16\n')
        (self.root / 'site/moodledata/filedir').mkdir(parents=True)
        (self.root / 'site/moodledata').chmod(0o700)
        (self.root / 'pg.env').write_text('POSTGRES_USER=ustar_lab_admin\nPOSTGRES_PASSWORD=' + 'a' * 64 +
                                        '\nPOSTGRES_DB=postgres\nPOSTGRES_INITDB_ARGS=--auth-host=scram-sha-256\n')
        (self.root / 'lab.ini').write_text('memory_limit=512M\nsendmail_path=/bin/false\ndisplay_errors=Off\nlog_errors=On\n')
        (self.root / 'apache.conf').write_text(resume.APACHE_CONFIG)
        report = {'snapshot_sha256': self.state['archive_sha256'], 'database_restore': 'PASS',
                  'moodle_bootstrap': 'PASS', 'http_login': 200, 'lab_network': 'loopback_only'}
        (self.root / 'report.json').write_text(json.dumps(report))
        for name in ('pg.env', 'lab.ini', 'apache.conf', 'report.json'):
            (self.root / name).chmod(0o600)
        original = resume.protected
        def metadata(path, directory=False, root_owned=True):
            info = original(path, directory, root_owned=False)  # Tests are portable to a non-root CI runner.
            if path == self.root / 'site/moodledata':
                return SimpleNamespace(st_uid=33, st_mode=info.st_mode)
            return info
        ownership = mock.patch.object(resume, 'protected', side_effect=metadata)
        ownership.start()
        self.addCleanup(ownership.stop)
        original_text = Path.read_text
        def read_text(path, *args, **kwargs):
            return 'MemAvailable: 8000000 kB\n' if path == Path('/proc/meminfo') else original_text(path, *args, **kwargs)
        memory = mock.patch.object(Path, 'read_text', read_text)
        memory.start()
        self.addCleanup(memory.stop)

    def preflight(self):
        return resume.check_saved_lab(self.lab, self.state, TEMPLATE)

    def test_check_is_read_only_and_does_not_launch_php(self):
        self.preflight()
        self.assertFalse((self.root / 'state.json').exists())
        self.assertFalse(self.lab.sql_queries)
        self.assertFalse(any(a[0] in ('create', 'lab_exec', 'relay', 'save_state') for a in self.lab.actions))

    def test_live_or_occupied_lab_refused_before_writes(self):
        self.state['stopped'] = False
        with self.assertRaises(resume.ResumeError):
            self.preflight()
        self.state['stopped'] = True
        self.lab.occupied = True
        with self.assertRaises(resume.ResumeError):
            self.preflight()
        self.assertFalse((self.root / 'state.json').exists())

    def test_config_change_or_injected_php_refused(self):
        config = self.root / 'site/public/config.php'
        old = config.read_text()
        for text in (old.replace("'127.0.0.1'", "'ustar_postgres'"),
                     old.replace('noemailever = true', 'noemailever = false'), old + "echo 'unexpected';\n"):
            config.write_text(text)
            with self.assertRaises(resume.ResumeError):
                self.preflight()
        self.assertFalse(any(a[0] == 'lab_exec' for a in self.lab.actions))

    def test_code_and_cluster_symlinks_and_writable_code_refused(self):
        for parent in (self.root / 'site/public', self.root / 'postgres/pg_tblspc'):
            link = parent / 'escape'
            link.symlink_to(self.root.parent)
            with self.assertRaises(resume.ResumeError):
                self.preflight()
            link.unlink()
        config = self.root / 'site/public/config.php'
        config.chmod(0o660)
        with self.assertRaises(resume.ResumeError):
            self.preflight()

    def test_environment_apache_and_sendmail_drift_refused(self):
        for name in ('pg.env', 'apache.conf', 'lab.ini'):
            path = self.root / name
            old = path.read_text()
            path.write_text(old + 'UNEXPECTED=1\n')
            with self.assertRaises(resume.ResumeError):
                self.preflight()
            path.write_text(old)

    def test_cluster_version_and_snapshot_image_drift_refused(self):
        version = self.root / 'postgres/PG_VERSION'
        version.write_text('17\n')
        with self.assertRaises(resume.ResumeError):
            self.preflight()
        version.write_text('16\n')
        self.state['images']['pg'] = 'sha256:' + 'f' * 64
        with self.assertRaises(resume.ResumeError):
            self.preflight()

    def test_resume_retains_database_files_and_original_report(self):
        before = self.preflight()
        original_report = (self.root / 'report.json').read_bytes()
        resume.resume(self.lab, self.state, before)
        self.assertTrue(self.state['ready'])
        self.assertFalse(self.state['stopped'])
        self.assertEqual((self.root / 'report.json').read_bytes(), original_report)
        self.assertEqual((self.root / 'postgres/PG_VERSION').read_text(), '16\n')
        self.assertEqual([a[1] for a in self.lab.actions if a[0] == 'create'], ['pg', 'web'])
        self.assertEqual(len(self.lab.sql_queries), 2)
        self.assertTrue(list(self.root.glob('resume-state-*-before.json')))
        report = json.loads(next(self.root.glob('resume-report-*.json')).read_bytes())
        self.assertFalse(report['archive_restored_again'])
        self.assertFalse(report['core_upgrade_executed'])
        commands = [a[1] for a in self.lab.actions if a[0] == 'command']
        self.assertFalse(any('production-web' in c or 'production-pg' in c for c in commands))

    def test_http_bootstrap_and_production_drift_failure_stop_only_lab(self):
        for failure in ('http_failure', 'bootstrap_failure', 'production_drift'):
            with self.subTest(failure=failure):
                self.lab, self.state = FakeLab(), state()
                before = self.lab.production_state()
                setattr(self.lab, failure, True)
                with self.assertRaises((resume.ResumeError, RuntimeError)):
                    resume.resume(self.lab, self.state, before)
                self.assertTrue(self.state['stopped'])
                self.assertFalse(self.state['ready'])
                self.assertIn(('stop_owned_lab',), self.lab.actions)
                self.assertTrue((self.root / 'postgres/PG_VERSION').exists())
                self.assertIsNone(self.lab._LOG)

    def test_enabled_tasks_refused_before_web_or_relay(self):
        self.lab.enabled = '1'
        with self.assertRaises(resume.ResumeError):
            resume.resume(self.lab, self.state, self.lab.production_state())
        self.assertFalse(any(a in self.lab.actions for a in (('create', 'web'), ('relay',))))
        self.assertIn(('stop_owned_lab',), self.lab.actions)

    def test_template_is_parsed_without_execution_and_duplicates_rejected(self):
        source = 'raise RuntimeError("never execute")\ndef prepare_config():\n    config = ' + repr(TEMPLATE) + ' % (a,b)\n'
        self.assertEqual(resume.config_template(source), TEMPLATE)
        with self.assertRaises(resume.ResumeError):
            resume.config_template(source + '\ndef prepare_config():\n    pass\n')


if __name__ == '__main__':
    unittest.main()
