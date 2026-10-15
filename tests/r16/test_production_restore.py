"""Root filesystem, config and lifecycle tests. Docker/PostgreSQL are modeled.

An opt-in CI readiness check uses a real loopback HTTP server. No production host,
Moodle dump, mail provider or real Docker daemon is accessed by these tests.
"""
from contextlib import ExitStack, redirect_stdout
import copy
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
import importlib.util
import io
import json
import os
import shutil
from pathlib import Path
import subprocess
import tempfile
import threading
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('production_tested', REPO / 'scripts/infra/production_restore.py')
p = importlib.util.module_from_spec(spec); spec.loader.exec_module(p)

ORIGINAL = """<?php
unset($CFG); global $CFG; $CFG = new stdClass();
$CFG->dbtype = 'pgsql';
$CFG->dbhost = 'OLD_PRIVATE_DB';
$CFG->dbuser = 'old_user';
$CFG->dbpass = 'OLD_SECRET';
$CFG->prefix = 'mdl_';
$CFG->wwwroot = 'http://old-academy';
$CFG->dataroot = '/var/www/moodledata';
$CFG->passwordsaltmain = 'SALT_MUST_SURVIVE';
$CFG->theme = 'ustar';
$CFG->defaulthomepage = '/local/ustar/home.php';
require_once(__DIR__ . '/lib/setup.php');
// A historical trailing comment is valid.
"""


def strip_comments(s):
    return '\n'.join(x for x in s.splitlines() if not x.lstrip().startswith('//'))


@unittest.skipUnless(os.geteuid() == 0, 'root trusted paths')
class Production(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir='/var/lib', prefix='ustar-production-fixture-')
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.stack = ExitStack(); self.addCleanup(self.stack.close)
        try:
            os.chown(self.base, 0, 33); os.chown(self.base, 0, 0)
        except OSError:
            self.stack.enter_context(mock.patch.object(p.os, 'chown'))
            self.stack.enter_context(mock.patch.object(p.os, 'fchown'))
        self.stack.enter_context(mock.patch.object(p.socket, 'socket'))
        root, target = self.base / 'recovery', self.base / 'target'
        for k, v in {'ROOT': root, 'STATE': root / 'state.json', 'TARGET': target,
                     'CODE': target / 'data/moodle/public', 'DATA': target / 'data/moodle/moodledata',
                     'PGDATA': target / 'data/postgres', 'UNIT_DIR': self.base / 'units',
                     'SOURCE_ROOT': self.base / 'source', 'SOURCE_STATE': self.base / 'source/source.json',
                     'SOURCE_CRON_LOCK': self.base / 'source-cron.lock', 'LATEST': self.base / 'latest.json', 'RUN': self.base}.items():
            self.stack.enter_context(mock.patch.object(p, k, v))
        p.UNIT_DIR.mkdir(mode=0o700)
        p.SOURCE_CRON_LOCK.write_text(''); p.SOURCE_CRON_LOCK.chmod(0o600)
        self.counts = {'users': 150, 'courses': 8, 'files': 5151, 'scheduled_tasks': 132,
                       'enabled_tasks': 129, 'adhoc_tasks': 2, 'task_flags_md5': 'frozen-flags'}
        self.inventory, self.calls, self.db, self.networks = {}, [], [], {}
        self.fail_restore = False
        self.maintenance = False
        self._fake = self.stack.enter_context(mock.patch.object(p, 'command', side_effect=self.command))
        self.stack.enter_context(mock.patch.object(p, 'health', return_value=None))
        self.selected = {'archive': '/fixture/backup.age', 'sha256': 'a' * 64, 'identity': '/fixture/key'}
        self.preflight = {'site': {'expanded_file_bytes': 100, 'regular_files': 10, 'directories': 8},
                          'payload_bytes': 100, 'database_dump_bytes': 100, 'images_archive_bytes': 50,
                          'application_versions': ['local_ustar=2026100204', 'theme_ustar=2026100202']}
        def payload():
            (p.ROOT / 'payload').mkdir()
            (p.ROOT / 'payload/database.dump').write_bytes(b'pg-custom-dump')
            (p.ROOT / 'payload/images.tar').write_bytes(b'archived-images')
        def site(_):
            (p.ROOT / 'site/public/lib').mkdir(parents=True)
            (p.ROOT / 'site/public/public/login').mkdir(parents=True)
            (p.ROOT / 'site/public/public/admin/cli').mkdir(parents=True)
            (p.ROOT / 'site/public/lib/setup.php').write_text('<?php')
            (p.ROOT / 'site/public/public/login/index.php').write_text('<?php')
            (p.ROOT / 'site/public/config.php').write_text(ORIGINAL)
            (p.ROOT / 'site/public/public/config.php').write_text("<?php require_once(dirname(__DIR__) . '/config.php');")
            data = p.ROOT / 'site/moodledata/filedir'
            data.mkdir(parents=True)
        self.lab = SimpleNamespace(_LOG=None, extract_payload=payload, extract_site=site, strip_php_comments=strip_comments)
        self.r = SimpleNamespace(selection=mock.Mock(return_value=self.selected), engine=mock.Mock(return_value=self.lab),
                                 verify=mock.Mock(return_value=self.preflight), local_images=mock.Mock(return_value={'pg': 'sha256:pg', 'web': 'sha256:web'}))
        self.stack.enter_context(mock.patch.object(p, 'controller', return_value=self.r))
        self.args = SimpleNamespace(url='http://new-academy', bind='0.0.0.0', http_port=80,
                                    behind_proxy=False, source_stopped=True, kit=str(self.base))
        self.original_runtime = p.runtime

    def command(self, args, data=None, source=None, timeout=60, optional=False):
        self.calls.append(copy.deepcopy(args))
        out, err, rc = b'', b'', 0
        if args[0] == 'python3':
            p.LATEST.write_text(json.dumps({'success': True, 'phase': 'complete', 'manifest': {'sha256': 'b' * 64}}))
        elif args[:3] == ['docker', 'ps', '-aq']:
            out = '\n'.join(self.inventory).encode()
        elif args[:2] == ['docker', 'inspect']:
            row = next((x for x in self.inventory.values() if args[2] in (x['Id'], x['Name'].lstrip('/'))), None)
            if row: out = json.dumps([row]).encode()
            else: rc, err = 1, b'Error: No such object'
        elif args[:3] == ['docker', 'network', 'ls']:
            out = b'network-id' if self.networks else b''
        elif args[:3] == ['docker', 'network', 'create']:
            label = args[args.index('--label') + 1]
            key, value = label.split('=', 1)
            self.networks[args[-1]] = {'Driver': 'bridge', 'Labels': {key: value}}
        elif args[:3] == ['docker', 'network', 'inspect']:
            out = json.dumps([self.networks[args[-1]]]).encode()
        elif args[:2] == ['docker', 'create']:
            name = args[args.index('--name') + 1]
            cid = hashlib.sha256(name.encode()).hexdigest()
            key, value = args[args.index('--label') + 1].split('=', 1)
            mounts = []
            for i, token in enumerate(args):
                if token == '--mount':
                    parts = dict(x.split('=', 1) for x in args[i+1].split(',') if '=' in x)
                    mounts.append({'Source': parts['src'], 'Destination': parts['dst'], 'RW': 'readonly' not in args[i+1]})
            ports = {}
            if '--publish' in args:
                ip, port, inner = args[args.index('--publish') + 1].split(':')
                ports[inner + '/tcp'] = [{'HostIp': ip, 'HostPort': port}]
            image = args[args.index('--pull') + 2]
            self.inventory[name] = {'Id': cid, 'Name': '/' + name, 'Image': image, 'Config': {'Labels': {key: value}},
                'Mounts': mounts, 'State': {'Running': False}, 'HostConfig': {'NetworkMode': args[args.index('--network') + 1],
                'RestartPolicy': {'Name': 'no'}, 'PortBindings': ports}}
            out = cid.encode()
        elif args[:2] == ['docker', 'start'] or args[:2] == ['docker', 'stop']:
            row = next(x for x in self.inventory.values() if x['Id'] == args[-1])
            row['State']['Running'] = args[1] == 'start'
        elif args[:2] == ['docker', 'update']:
            row = next(x for x in self.inventory.values() if x['Id'] == args[-1])
            row['HostConfig']['RestartPolicy']['Name'] = args[args.index('--restart') + 1]
        elif args[:2] == ['docker', 'exec']:
            if '--enable' in args: self.maintenance = True
            if '--disable' in args: self.maintenance = False
            if 'pg_restore' in args and self.fail_restore: raise p.ProductionError('INJECTED_PG_RESTORE_FAILURE')
            if 'psql' in args:
                query = data.decode(); self.db.append(query)
                if 'plugin||' in query: out = '\n'.join(self.preflight['application_versions']).encode()
                elif 'json_build_object' in query: out = json.dumps(self.counts).encode()
            elif 'php' in args and '-r' in args:
                php = args[-1]
                if 'needs_upgrade' in php: out = json.dumps({'needs_upgrade': False, 'url': self.args.url}).encode()
                elif 'maintenance_enabled' in php and 'json_encode' not in php: out = b'1' if self.maintenance else b'0'
                else: out = json.dumps({'url': self.args.url, 'cron': 1, 'mail': False, 'maintenance': False}).encode()
        elif args[:2] == ['systemctl', 'start'] and args[-1] == p.RUNTIME_UNIT:
            oldlog = p.LOG
            try: self.original_runtime('resume')
            finally: p.LOG = oldlog
        if rc and not optional: raise p.ProductionError('MODEL_COMMAND_FAILED')
        return SimpleNamespace(stdout=out, stderr=err, returncode=rc)

    def restore(self):
        with redirect_stdout(io.StringIO()) as out: p.restore(self.args)
        return out.getvalue()

    def test_full_permanent_flow_preserves_tasks_and_enables_boot(self):
        out = self.restore()
        s = p.read(p.STATE)
        self.assertEqual(s['phase'], 'ready')
        self.assertEqual(s['counts_before'], self.counts)
        self.assertTrue(s['task_flags_preserved'])
        self.assertIn('PERMANENT', out)
        self.assertFalse(any('UPDATE mdl_task_scheduled' in q or 'siteidentifier' in q for q in self.db))
        self.assertIn(['systemctl', 'enable', p.RUNTIME_UNIT, p.CRON_TIMER], self.calls)
        for c in self.inventory.values(): self.assertEqual(c['HostConfig']['RestartPolicy']['Name'], 'unless-stopped')
        self.assertIn('SALT_MUST_SURVIVE', (p.CODE / 'config.php').read_text())
        self.assertIn("$CFG->theme = 'ustar'", (p.CODE / 'config.php').read_text())
        self.assertIn("$CFG->defaulthomepage = '/local/ustar/home.php'", (p.CODE / 'config.php').read_text())
        self.assertTrue(any('--search=http://old-academy' in c and '--replace=http://new-academy' in c for c in self.calls))
        self.assertTrue(any(c[:2] == ['docker', 'exec'] and "set_config('maintenance_enabled',0)" in c[-1] for c in self.calls))

    def test_restore_failure_stops_owned_containers_retains_dump_and_no_boot_enable(self):
        self.fail_restore = True
        with self.assertRaisesRegex(p.ProductionError, 'INJECTED'):
            self.restore()
        self.assertEqual(p.read(p.STATE)['phase'], 'failed')
        self.assertTrue((p.ROOT / 'payload/database.dump').exists())
        self.assertFalse(any(c['State']['Running'] for c in self.inventory.values()))
        self.assertFalse(any(c[:2] == ['systemctl', 'enable'] for c in self.calls))
        self.assertFalse(any(c[:2] == ['docker', 'rm'] for c in self.calls))

    def test_resume_after_reboot_reuses_exact_ids_and_does_not_restore_again(self):
        self.restore(); ids = {k: v['Id'] for k, v in self.inventory.items()}
        for c in self.inventory.values(): c['State']['Running'] = False
        self.calls.clear(); p.runtime('resume')
        self.assertEqual(ids, {k: v['Id'] for k, v in self.inventory.items()})
        self.assertFalse(any('pg_restore' in c or c[:2] == ['docker', 'create'] for c in self.calls))
        self.assertTrue(all(c['State']['Running'] for c in self.inventory.values()))

    def test_cron_targets_only_recorded_web_as_www_data(self):
        self.restore(); self.calls.clear(); p.runtime('cron')
        calls = [c for c in self.calls if 'docker' == c[0] and 'exec' == c[1] and 'cron.php' in c[-1]]
        self.assertEqual(len(calls), 1); self.assertEqual(calls[0][2:4], ['--user', '33:33'])
        self.assertIn('last_cron_success_utc', p.read(p.STATE))

    def test_id_drift_refuses_runtime_operation_without_stopping_foreign(self):
        self.restore(); self.inventory[p.NAMES['web']]['Id'] = 'foreign-id'; self.calls.clear()
        with self.assertRaisesRegex(p.ProductionError, 'OWNERSHIP'):
            p.runtime('stop')
        self.assertFalse(any(c[:2] == ['docker', 'stop'] for c in self.calls))

    def test_postgres_never_has_public_port_and_web_code_is_readonly(self):
        self.restore()
        pg, web = self.inventory[p.NAMES['pg']], self.inventory[p.NAMES['web']]
        self.assertFalse(pg['HostConfig']['PortBindings'])
        code = next(x for x in web['Mounts'] if x['Destination'] == '/var/www/html')
        self.assertFalse(code['RW'])

    def test_missing_source_stop_declaration_and_existing_data_refuse_before_extract(self):
        self.args.source_stopped = False
        with self.assertRaisesRegex(p.ProductionError, 'SOURCE_STOPPED'): self.restore()
        self.assertFalse(p.ROOT.exists())
        self.args.source_stopped = True; p.TARGET.mkdir()
        with self.assertRaisesRegex(p.ProductionError, 'NO_OVERWRITE'): self.restore()
        self.assertFalse(p.ROOT.exists())

    def test_foreign_container_refuses_before_any_target_files(self):
        self.inventory['other'] = {'Name': '/other'}
        with self.assertRaisesRegex(p.ProductionError, 'EMPTY_DOCKER'): self.restore()
        self.assertFalse(p.ROOT.exists())

    def test_duplicate_runtime_lock_is_rejected(self):
        with p.lock('fixture.lock'):
            with self.assertRaisesRegex(p.ProductionError, 'BUSY'):
                with p.lock('fixture.lock'): pass

    def test_config_insertion_preserves_trailing_comments_and_refuses_extra_code(self):
        p.CODE.mkdir(parents=True)
        ep = p.endpoint('http://new-academy')
        old = p.write_config(self.lab, ORIGINAL, 'a' * 64, ep, active=True)
        self.assertEqual(old, 'http://old-academy')
        txt = (p.CODE / 'config.php').read_text()
        self.assertIn('$CFG->cron_enabled = 1;', txt)
        self.assertIn('$CFG->noemailever = false;', txt)
        self.assertIn('unset($CFG->maintenance_enabled);', txt)
        self.assertNotIn('$CFG->maintenance_enabled = 0;', txt)  # Maintenance remains controllable after migration.
        self.assertEqual((p.CODE / 'config.php').stat().st_mode & 0o777, 0o640)
        with self.assertRaisesRegex(p.ProductionError, 'EXECUTABLE_CODE'):
            p.write_config(self.lab, ORIGINAL + 'exit();', 'a' * 64, ep)

    @unittest.skipUnless(shutil.which('php'), 'PHP lexer check runs when CLI is available')
    def test_generated_config_executes_with_literal_salts_and_local_classes(self):
        p.CODE.mkdir(parents=True)
        (p.CODE / 'lib').mkdir()
        (p.CODE / 'lib/setup.php').write_text("<?php echo json_encode(['session'=>$CFG->session_handler_class,'db'=>$CFG->dbhost,'salt'=>$CFG->passwordsaltmain,'cron'=>$CFG->cron_enabled]);")
        p.write_config(self.lab, ORIGINAL, 'a' * 64, p.endpoint('http://new-academy'), active=True)
        result = subprocess.run(['php', str(p.CODE / 'config.php')], capture_output=True, check=True)
        value = json.loads(result.stdout)
        self.assertEqual(value, {'session': r'\core\session\file', 'db': 'ustar_postgres', 'salt': 'SALT_MUST_SURVIVE', 'cron': 1})

    def test_multiple_urls_or_prefixes_refuse_without_evaluating_php(self):
        p.CODE.mkdir(parents=True)
        with self.assertRaisesRegex(p.ProductionError, 'STATIC_URL'):
            p.write_config(self.lab, ORIGINAL.replace("$CFG->wwwroot", "$CFG->wwwroot='http://extra';\n$CFG->wwwroot"), 'a' * 64, p.endpoint('http://new'))

    def test_foreign_unit_is_not_replaced(self):
        (p.UNIT_DIR / p.RUNTIME_UNIT).write_text('foreign')
        with self.assertRaisesRegex(p.ProductionError, 'UNOWNED'):
            p.install_units()
        self.assertEqual((p.UNIT_DIR / p.RUNTIME_UNIT).read_text(), 'foreign')

    def test_endpoint_refuses_injection_https_without_proxy_and_public_proxy_backend(self):
        for url in ('http://user:pass@host', 'http://host/academy', 'http://host/%n', 'http://host\nExecStart=x', 'file:///etc/passwd', 'https://host'):
            with self.assertRaises(p.ProductionError): p.endpoint(url)
        with self.assertRaises(p.ProductionError): p.endpoint('https://host', behind_proxy=True)
        self.assertTrue(p.endpoint('https://host', '127.0.0.1', 8082, True)['behind_proxy'])

    def source_fixture(self):
        c = {'Name': '/ustar_moodle', 'Id': 'source-web', 'Image': 'source-image', 'Mounts': [],
             'State': {'Running': True}, 'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped'}}}
        self.inventory['ustar_moodle'] = c
        p.LATEST.write_text(json.dumps({'success': True, 'phase': 'complete', 'manifest': {'sha256': 'a' * 64}}))
        p.LATEST.chmod(0o600)
        return c

    def test_source_freeze_takes_final_backup_before_stopping_and_can_abort(self):
        c = self.source_fixture()
        with mock.patch.object(p, 'checked'), redirect_stdout(io.StringIO()):
            state = p.freeze_source()
        self.assertEqual(state['phase'], 'frozen')
        self.assertFalse(c['State']['Running'])
        self.assertEqual(c['HostConfig']['RestartPolicy']['Name'], 'no')
        backup = next(i for i, a in enumerate(self.calls) if a[0] == 'python3')
        stop = next(i for i, a in enumerate(self.calls) if a[:2] == ['docker', 'stop'])
        self.assertLess(backup, stop)
        self.assertFalse(any('ustar_postgres' in a and 'stop' in a for a in self.calls))
        with self.assertRaisesRegex(p.ProductionError, 'TARGET_STOPPED'):
            p.resume_source(False)
        with redirect_stdout(io.StringIO()): p.resume_source(True)
        self.assertTrue(c['State']['Running'])
        self.assertEqual(c['HostConfig']['RestartPolicy']['Name'], 'unless-stopped')

    def test_busy_source_cron_refuses_before_maintenance_or_state(self):
        self.source_fixture()
        fd = os.open(p.SOURCE_CRON_LOCK, os.O_RDWR)
        p.fcntl.flock(fd, p.fcntl.LOCK_EX | p.fcntl.LOCK_NB)
        try:
            with mock.patch.object(p, 'checked'):
                with self.assertRaisesRegex(p.ProductionError, 'SOURCE_CRON_BUSY'):
                    p.freeze_source()
        finally: os.close(fd)
        self.assertFalse(p.SOURCE_STATE.exists())
        self.assertFalse(any('--enable' in a for a in self.calls))

    def test_source_maintenance_or_backup_failure_does_not_stop_the_site(self):
        # Separate source inventory: source identity is checked, not adopted as target.
        c = self.source_fixture()
        original = self.command
        def fail(args, **kw):
            if args and args[0] == 'python3' and '--backup' in args: raise p.ProductionError('BACKUP_FAILED')
            return original(args, **kw)
        with mock.patch.object(p, 'checked'), mock.patch.object(p, 'command', side_effect=fail):
            with self.assertRaisesRegex(p.ProductionError, 'BACKUP_FAILED'):
                p.freeze_source()
        self.assertTrue(c['State']['Running'])
        self.assertEqual(p.read(p.SOURCE_STATE)['phase'], 'resumed')
        self.assertTrue(any('--disable' in a for a in self.calls))
        self.assertTrue(any('--recover' in a for a in self.calls))


class UnitContract(unittest.TestCase):
    def test_production_units_have_no_runtime_expiry_and_stop_timer_with_site(self):
        allunits = p.units()
        self.assertNotIn('RuntimeMaxSec', allunits[p.RUNTIME_UNIT])
        self.assertIn('WantedBy=multi-user.target', allunits[p.RUNTIME_UNIT])
        self.assertIn('PartOf=' + p.RUNTIME_UNIT, allunits[p.CRON_TIMER])
        self.assertNotIn('After=' + p.RUNTIME_UNIT, allunits[p.CRON_TIMER])  # Avoid timers.target/basic.target ordering cycle.
        self.assertIn('PartOf=' + p.RUNTIME_UNIT, allunits[p.CRON_UNIT])
        self.assertIn('OnCalendar=*-*-* *:*:00', allunits[p.CRON_TIMER])
        self.assertNotIn('ExecStopPost', allunits[p.RUNTIME_UNIT])


@unittest.skipUnless(os.environ.get('USTAR_NATIVE_NETWORK_TESTS') == '1', 'native loopback exercised in CI')
class NativeHealth(unittest.TestCase):
    def test_login_readiness_uses_target_host_header(self):
        received = []
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                received.append((self.path, self.headers['Host']))
                self.send_response(200); self.end_headers()
                self.wfile.write(b'<input name="username"><input name="password">')
            def log_message(self, *args): pass
        server = HTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        try:
            state = {'endpoint': p.endpoint('http://target-academy:' + str(server.server_port), '127.0.0.1', server.server_port)}
            p.health(state)
            self.assertEqual(received, [('/login/index.php', 'target-academy:' + str(server.server_port))])
        finally: server.shutdown(); server.server_close(); thread.join()


if __name__ == '__main__': unittest.main()
