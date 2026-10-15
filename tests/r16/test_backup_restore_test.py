"""Real private paths/state; Docker, private engine and database are modeled.

Root fixture tests protect the new controller's archive selection, daemon
boundaries, failure cleanup and the full 3600-second lifecycle. They do not
claim a real PostgreSQL/Moodle or clean-host recovery acceptance.
"""
from contextlib import ExitStack
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('backup_restore_tested', REPO / 'scripts/infra/backup_restore_test.py')
r = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r)


class Clock:
    def __init__(self):
        self.value, self.sleeps = 0, []

    def monotonic(self):
        return self.value

    def sleep(self, seconds):
        if seconds <= 0:
            raise AssertionError('non-positive sleep')
        self.sleeps.append(seconds)
        self.value += seconds


class FakeLab:
    PRODUCTION = ('ustar_moodle', 'ustar_postgres')

    def __init__(self):
        self.actions = []
        self.production = {name: {'Id': name, 'Image': name + '-image', 'Mounts': [],
                           'State': {'Running': True, 'StartedAt': 'fixed', 'Health': {'Log': []}},
                           'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped'}}}
                           for name in self.PRODUCTION}
        self.database_failure = False
        self.unsafe_boot = False
        self.presentation = {'defaulthomepage': None, 'theme': None}
        self._LOG = None

    def inspect(self, name, absent_ok=False):
        self.actions.append(('inspect', name))
        return copy.deepcopy(self.production.get(name))

    def relay_unit_absent(self):
        self.actions.append(('relay_probe',))

    def write_private(self, path, data):
        with path.open('xb') as stream:
            stream.write(data.encode() if isinstance(data, str) else data)
        path.chmod(0o600)

    def save_state(self, state):
        (r.ROOT / 'state.json').write_text(json.dumps(state))

    def extract_payload(self):
        (r.ROOT / 'payload').mkdir(mode=0o700)
        self.write_private(r.ROOT / 'payload/images.tar', b'checked-image-fixture')
        self.actions.append(('extract_payload',))

    def extract_site(self, _):
        (r.ROOT / 'site/moodledata').mkdir(parents=True)
        (r.ROOT / 'site/public').mkdir()
        self.write_private(r.ROOT / 'site/moodledata/retained-content', b'snapshot-data')
        self.actions.append(('extract_site',))

    def create_container(self, kind, state):
        state['containers'][kind] = kind + '-owned-only'
        self.actions.append(('create', r.NAMES[kind]))
        self.save_state(state)

    def restore_database(self, state, password):
        self.actions.append(('restore_new_database',))
        if self.database_failure:
            raise RuntimeError('PRIVATE_DATABASE_FAILURE_MUST_NOT_BE_PRINTED')
        return {'users': 100, 'courses': 8, 'files': 5000}

    def check_file_references(self, _):
        self.actions.append(('check_contenthashes',))
        return 4200

    def assert_offline(self, _):
        self.actions.append(('assert_offline',))

    def lab_exec(self, kind, state, args, user=None):
        self.actions.append(('lab_exec', r.NAMES[kind]))
        if args[1] == '-l':
            return SimpleNamespace(stdout=b'')
        boot = {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab', 'wwwroot': r.URL,
                'noemail': True, 'cron': 1 if self.unsafe_boot else 0, 'needs_upgrade': False,
                **self.presentation}
        return SimpleNamespace(stdout=json.dumps(boot).encode())

    def start_relay(self, _):
        self.actions.append(('relay_start',))

    def stop_lab(self, state):
        self.actions.extend(('stop_owned', r.NAMES[k]) for k in ('web', 'pg') if k in state['containers'])
        state.update(stopped=True, ready=False)
        self.save_state(state)

    def command(self, args, **kwargs):
        self.actions.append(tuple(args))
        return SimpleNamespace(stdout=b'')


@unittest.skipUnless(os.geteuid() == 0, 'native root path/state fixture job uses sudo')
class RestoreExperiment(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix='ustar-restore-fixture-', dir='/var/lib')
        self.addCleanup(self.folder.cleanup)
        self.base = Path(self.folder.name)
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.export = self.base / 'managed'
        self.export.mkdir(mode=0o700)
        self.out = self.base / 'restore'
        self.out.mkdir(mode=0o700)
        for key, value in {'ROOT': self.out / 'run', 'STATE': self.out / 'run/state.json',
                           'LATEST': self.base / 'latest.json', 'EXPORT': self.export,
                           'HDD': self.base, 'IDENTITY': self.base / 'key',
                           'SCRIPT': self.base / 'controller.py', 'LOCK': self.base / 'lock'}.items():
            self.stack.enter_context(mock.patch.object(r, key, value))
        r.SCRIPT.write_text('# fixture controller')
        r.SCRIPT.chmod(0o600)
        r.IDENTITY.write_text('PRIVATE_KEY_FIXTURE')
        r.IDENTITY.chmod(0o600)
        self.name = 'ustar-recovery-20261014T003045Z-01234567.tar.gz.age'
        self.archive = self.export / self.name
        self.archive.write_bytes(b'encrypted-fixture')
        self.archive.chmod(0o600)
        self.sidecar = self.export / (self.name + '.json')
        self.manifest = {'file': self.name, 'bytes': self.archive.stat().st_size,
                         'sha256': hashlib.sha256(self.archive.read_bytes()).hexdigest(),
                         'readback_verified': True, 'hdd_uuid': r.HDD_UUID}
        self.write_manifests()
        self.args = SimpleNamespace(manifest=None, input_directory=None, identity=str(r.IDENTITY), load_images=False, check=False, duration=3600)
        self.lab = FakeLab()
        self.container_inventory = b''
        self.preflight = {'site': {'symlinks': 0, 'hardlinks': 0, 'regular_files': 2,
                                   'directories': 4, 'expanded_file_bytes': 100},
                          'payload_bytes': 1000, 'database_dump_bytes': 100,
                          'images_archive_bytes': 500, 'snapshot_completed_utc': '2026-10-14T00:31:40Z'}
        self.mount = {'filesystems': [{'target': str(r.HDD), 'uuid': r.HDD_UUID}]}
        self.stack.enter_context(mock.patch.object(r, 'lab_command', side_effect=self.host_probe))

    def write_manifests(self):
        self.sidecar.write_text(json.dumps(self.manifest))
        r.LATEST.write_text(json.dumps({'phase': 'complete', 'success': True,
                          'manifest': {**self.manifest, 'archive': str(self.archive)}}))

    def host_probe(self, args, **kwargs):
        if args[0] == 'findmnt':
            return SimpleNamespace(stdout=json.dumps(self.mount).encode())
        if args == ['docker', 'ps', '-aq']:
            value = self.container_inventory.pop(0) if isinstance(self.container_inventory, list) else self.container_inventory
            return SimpleNamespace(stdout=value)
        raise AssertionError('Unexpected host command: ' + repr(args))

    def execute(self, clock=None, images=None, image_sequence=None, carried=None):
        clock = clock or Clock()
        old_text = Path.read_text
        def read_text(path, *args, **kwargs):
            return 'MemAvailable: 10000000 kB\n' if str(path) == '/proc/meminfo' else old_text(path, *args, **kwargs)
        with mock.patch.object(r, 'engine', return_value=self.lab), \
                mock.patch.object(r, 'verify', return_value=self.preflight), \
                mock.patch.object(r, 'local_images', return_value=images if images is not None else
                                  {'pg': 'pg-id', 'web': 'web-id'}, side_effect=image_sequence), \
                mock.patch.object(r, 'prepare_config', side_effect=lambda *_: (
                    r.PRESENTATION_RESULT.update(carried=dict(carried or {}), skipped=[]), 2)[1]), \
                mock.patch.object(r, 'http_check', return_value=200), \
                mock.patch.object(r.shutil, 'which', return_value='/fixture/bin'), \
                mock.patch.object(r.shutil, 'disk_usage', return_value=SimpleNamespace(free=100 * r.GIB)), \
                mock.patch.object(r.os, 'statvfs', return_value=SimpleNamespace(f_favail=1000000)), \
                mock.patch.object(Path, 'read_text', read_text), \
                mock.patch.object(r.time, 'monotonic', side_effect=clock.monotonic), \
                mock.patch.object(r.time, 'sleep', side_effect=clock.sleep), \
                mock.patch('sys.stdout', new_callable=io.StringIO) as stdout:
            r.run(self.args)
            return clock, stdout.getvalue()

    def test_latest_uses_completed_manifest_and_its_appended_locator(self):
        selected = r.selection(self.args)
        self.assertEqual(selected['archive'], str(self.archive))
        self.assertEqual(selected['sha256'], self.manifest['sha256'])

    def test_explicit_sidecar_is_portable_without_source_server_state(self):
        r.LATEST.unlink()
        self.args.manifest = str(self.sidecar)
        self.assertEqual(r.selection(self.args)['archive'], str(self.archive))

    def test_input_directory_selects_exactly_one_portable_pair(self):
        r.LATEST.unlink()
        self.args.input_directory = str(self.export)
        self.assertEqual(r.selection(self.args)['archive'], str(self.archive))

    def test_ambiguous_portable_directory_refuses_guessing(self):
        self.args.input_directory = str(self.export)
        another = self.export / 'ustar-recovery-20261014T003145Z-87654321.tar.gz.age.json'
        another.write_text(json.dumps(self.manifest))
        with self.assertRaisesRegex(r.RestoreTestError, 'EXACTLY_ONE_BACKUP'):
            r.selection(self.args)

    def test_sidecar_mismatch_refuses_without_decryption(self):
        self.sidecar.write_text(json.dumps({**self.manifest, 'bytes': 99}))
        with self.assertRaisesRegex(r.RestoreTestError, 'SIDECAR_MISMATCH'):
            r.selection(self.args)

    def test_missing_hdd_refuses_without_ssd_fallback(self):
        self.mount = {'filesystems': []}
        with self.assertRaisesRegex(r.RestoreTestError, 'NOT_MOUNTED'):
            r.selection(self.args)

    def test_failed_attempt_is_not_a_completed_backup(self):
        value = json.loads(r.LATEST.read_text())
        value['success'] = False
        r.LATEST.write_text(json.dumps(value))
        with self.assertRaisesRegex(r.RestoreTestError, 'NO_COMPLETED_BACKUP'):
            r.selection(self.args)

    def test_nonmatching_archive_locator_is_refused(self):
        value = json.loads(r.LATEST.read_text())
        value['manifest']['archive'] = '/another/backup'
        r.LATEST.write_text(json.dumps(value))
        with self.assertRaisesRegex(r.RestoreTestError, 'LOCATOR_MISMATCH'):
            r.selection(self.args)

    def test_private_key_permissions_refuse_before_any_restore(self):
        r.IDENTITY.chmod(0o644)
        with self.assertRaisesRegex(r.RestoreTestError, 'UNTRUSTED_INPUT'):
            r.selection(self.args)

    def test_missing_key_has_specific_error_before_decryption(self):
        r.IDENTITY.unlink()
        with self.assertRaisesRegex(r.RestoreTestError, 'PRIVATE_RECOVERY_IDENTITY_MISSING'):
            r.selection(self.args)

    def test_missing_archive_has_specific_error(self):
        self.archive.unlink()
        with self.assertRaisesRegex(r.RestoreTestError, 'ENCRYPTED_ARCHIVE_MISSING'):
            r.selection(self.args)

    def test_archive_symlink_refuses(self):
        real = self.archive.with_suffix('.real')
        self.archive.rename(real)
        self.archive.symlink_to(real)
        with self.assertRaisesRegex(r.RestoreTestError, 'UNTRUSTED_INPUT'):
            r.selection(self.args)

    def test_duplicate_manifest_key_refuses(self):
        self.sidecar.write_text('{"file":"one","file":"two"}')
        self.args.manifest = str(self.sidecar)
        with self.assertRaisesRegex(r.RestoreTestError, 'DUPLICATE_METADATA_KEY'):
            r.selection(self.args)

    def test_unverified_partial_bundle_refuses(self):
        self.manifest['readback_verified'] = False
        self.write_manifests()
        with self.assertRaisesRegex(r.RestoreTestError, 'NOT_VERIFIED_COMPLETE'):
            r.selection(self.args)

    def test_size_drift_refuses(self):
        self.archive.write_bytes(b'different-archive-size')
        with self.assertRaisesRegex(r.RestoreTestError, 'SIZE_MISMATCH'):
            r.selection(self.args)

    def test_dependency_mismatch_never_executes_code(self):
        path, sentinel = self.base / 'engine.py', self.base / 'executed'
        path.write_text('from pathlib import Path\nPath(' + repr(str(sentinel)) + ').touch()\n')
        with self.assertRaisesRegex(r.RestoreTestError, 'SHA_MISMATCH'):
            r.load_module(path, '0' * 64, 'never_execute')
        self.assertFalse(sentinel.exists())

    def test_running_health_history_does_not_cause_identity_drift(self):
        old = r.production_state(self.lab)
        self.lab.production['ustar_moodle']['State']['Health']['Log'].append('advanced')
        self.assertEqual(r.production_state(self.lab), old)

    def test_partial_production_pair_refuses(self):
        del self.lab.production['ustar_postgres']
        with self.assertRaisesRegex(r.RestoreTestError, 'PARTIAL_PRODUCTION_PAIR'):
            r.production_state(self.lab)

    def test_check_is_read_only_and_does_not_create_plaintext(self):
        self.args.check = True
        _, output = self.execute()
        self.assertFalse(r.ROOT.exists())
        self.assertFalse(any(a[0] in ('create', 'lab_exec', 'stop_owned') for a in self.lab.actions))
        self.assertFalse(json.loads(output.split('\n', 1)[1])['restore_executed'])

    def test_one_hour_starts_after_success_and_stops_only_owned_clone(self):
        before = copy.deepcopy(self.lab.production)
        archive_bytes = self.archive.read_bytes()
        clock, output = self.execute()
        self.assertEqual(sum(clock.sleeps), 3600)
        self.assertIn('RESTORE_TEST_ONE_HOUR=COMPLETE', output)
        saved = json.loads(r.STATE.read_text())
        self.assertEqual(saved['phase'], 'stopped')
        self.assertTrue(saved['one_hour_completed'])
        self.assertEqual(self.lab.production, before)
        self.assertEqual(self.archive.read_bytes(), archive_bytes)
        self.assertTrue((r.ROOT / 'site/moodledata/retained-content').is_file())
        touched = [a[1] for a in self.lab.actions if a[0] in ('create', 'stop_owned', 'lab_exec')]
        self.assertTrue(touched)
        self.assertTrue(set(touched).isdisjoint(self.lab.PRODUCTION))

    def test_duration_parser(self):
        for value, expected in [('30m', 1800), ('2h', 7200), ('1d', 86400), ('0', 0)]:
            self.assertEqual(r.duration_seconds(value), expected)
        for value in ('-1h', '0h', '1.5h', '90', '31d', '1h;id'):
            with self.assertRaises(Exception):
                r.duration_seconds(value)

    def test_two_hours_control_loop_and_relay_share_duration(self):
        self.args.duration = 7200
        clock, output = self.execute()
        self.assertEqual(sum(clock.sleeps), 7200)
        self.assertIn('ACTIVE_FOR_SECONDS=7200', output)
        state = json.loads(r.STATE.read_text())
        self.assertTrue(state['duration_completed'])
        self.assertFalse(state['one_hour_completed'])
        relay = next(a for a in self.lab.actions if a[0] == 'systemd-run')
        self.assertIn('--property=RuntimeMaxSec=7800', relay)

    def test_unlimited_has_no_expiry_and_runs_beyond_old_two_hour_limit(self):
        self.args.duration = 0
        clock = Clock()
        original_sleep = clock.sleep
        def sleep(seconds):
            if clock.value >= 10800:
                state = json.loads(r.STATE.read_text())
                self.assertIsNone(state['expires_at'])
                self.assertTrue(state['unlimited'])
                self.assertEqual(state['phase'], 'ready')
                raise KeyboardInterrupt()
            original_sleep(seconds)
        clock.sleep = sleep
        with self.assertRaises(KeyboardInterrupt):
            self.execute(clock=clock)
        self.assertEqual(clock.value, 10800)
        relay = next(a for a in self.lab.actions if a[0] == 'systemd-run')
        self.assertIn('--property=RuntimeMaxSec=0', relay)
        self.assertIn(('stop_owned', r.NAMES['web']), self.lab.actions)

    def test_database_failure_stops_clone_and_retains_diagnostic_data(self):
        self.lab.database_failure = True
        with self.assertRaises(RuntimeError):
            self.execute()
        self.assertEqual(json.loads(r.STATE.read_text())['phase'], 'failed_stopped')
        self.assertIn(('stop_owned', r.NAMES['pg']), self.lab.actions)
        self.assertNotIn(('create', r.NAMES['web']), self.lab.actions)
        self.assertTrue(self.archive.is_file())

    def test_carried_homepage_and_theme_must_be_effective_and_are_reported(self):
        carried = {'defaulthomepage': '/local/ustar/home.php', 'theme': 'ustar'}
        self.lab.presentation = dict(carried)
        self.args.duration = 1800
        _, output = self.execute(carried=carried)
        self.assertIn('RESTORE_TEST_PRESENTATION={"defaulthomepage": "/local/ustar/home.php", "theme": "ustar"}', output)
        report = json.loads((r.ROOT / 'report.json').read_text())
        self.assertEqual((report['effective_theme'], report['effective_defaulthomepage']),
                         ('ustar', '/local/ustar/home.php'))
        self.assertEqual(report['presentation_settings_carried'], ['defaulthomepage', 'theme'])

    def test_carried_setting_not_effective_refuses_before_relay(self):
        self.lab.presentation = {'defaulthomepage': None, 'theme': 'boost'}
        with self.assertRaisesRegex(r.RestoreTestError, 'PRESENTATION_SETTINGS_NOT_EFFECTIVE'):
            self.execute(carried={'defaulthomepage': '/local/ustar/home.php', 'theme': 'ustar'})
        self.assertFalse(any(a and a[0] == 'systemd-run' for a in self.lab.actions))
        self.assertEqual(json.loads((r.ROOT / 'state.json').read_text())['phase'], 'failed_stopped')

    def test_enabled_cron_bootstrap_is_refused_and_clone_stopped(self):
        self.lab.unsafe_boot = True
        with self.assertRaisesRegex(r.RestoreTestError, 'BOOTSTRAP_MISMATCH'):
            self.execute()
        self.assertEqual(json.loads(r.STATE.read_text())['phase'], 'failed_stopped')
        self.assertNotIn(('relay_start',), self.lab.actions)

    def test_interrupt_during_hour_stops_clone(self):
        clock = Clock()
        clock.sleep = mock.Mock(side_effect=KeyboardInterrupt)
        with self.assertRaises(KeyboardInterrupt):
            self.execute(clock=clock)
        self.assertEqual(json.loads(r.STATE.read_text())['phase'], 'failed_stopped')
        self.assertIn(('stop_owned', r.NAMES['web']), self.lab.actions)

    def test_existing_lab_is_not_overwritten(self):
        r.ROOT.mkdir()
        marker = r.ROOT / 'must-keep'
        marker.write_text('keep')
        with self.assertRaisesRegex(r.RestoreTestError, 'ALREADY_EXISTS'):
            self.execute()
        self.assertEqual(marker.read_text(), 'keep')
        self.assertFalse(any(a[0] == 'create' for a in self.lab.actions))

    def test_missing_images_refuse_without_implicit_pull_or_load(self):
        with self.assertRaisesRegex(r.RestoreTestError, 'IMAGES_MISSING'):
            self.execute(images={})
        self.assertFalse(r.ROOT.exists())
        self.assertNotIn(('docker', 'load'), self.lab.actions)

    def test_image_load_on_production_host_is_refused(self):
        self.args.load_images = True
        with self.assertRaisesRegex(r.RestoreTestError, 'EMPTY_DOCKER_HOST'):
            self.execute(images={})
        self.assertFalse(r.ROOT.exists())
        self.assertNotIn(('docker', 'load'), self.lab.actions)

    def test_image_load_on_nonempty_separate_daemon_is_refused(self):
        self.lab.production = {}
        self.args.load_images = True
        self.container_inventory = b'unrelated-container-id'
        with self.assertRaisesRegex(r.RestoreTestError, 'EMPTY_DOCKER_HOST'):
            self.execute(images={})
        self.assertFalse(r.ROOT.exists())

    def test_empty_clean_daemon_loads_checked_payload_and_runs_hour(self):
        self.lab.production = {}
        self.args.load_images = True
        self.execute(image_sequence=[{}, {'pg': 'pg-loaded-id', 'web': 'web-loaded-id'}])
        self.assertIn(('docker', 'load'), self.lab.actions)
        self.assertTrue(json.loads(r.STATE.read_text())['image_archive_loaded'])
        self.assertTrue(json.loads(r.STATE.read_text())['one_hour_completed'])

    def test_daemon_collision_before_image_load_keeps_payload_without_load(self):
        self.lab.production = {}
        self.args.load_images = True
        self.container_inventory = [b'', b'new-container']
        with self.assertRaisesRegex(r.RestoreTestError, 'NO_LONGER_EMPTY'):
            self.execute(images={})
        self.assertNotIn(('docker', 'load'), self.lab.actions)
        self.assertTrue((r.ROOT / 'payload/images.tar').is_file())
        self.assertEqual(json.loads(r.STATE.read_text())['phase'], 'failed_stopped')

    def test_real_kernel_lock_conflict_does_not_replace_lock_inode(self):
        with r.locked():
            inode = r.LOCK.stat().st_ino
            with self.assertRaisesRegex(r.RestoreTestError, 'BUSY'):
                with r.locked():
                    self.fail('must not acquire twice')
            self.assertEqual(r.LOCK.stat().st_ino, inode)


class ImageIdentityTests(unittest.TestCase):
    def preflight(self):
        return {'images': {name: {'id': 'manifest-' + kind, 'config_id': 'config-' + kind,
                                 'standard_postgres': kind == 'pg', 'standard_php_apache': kind == 'web'}
                           for kind, name in (('pg', 'ustar_postgres'), ('web', 'ustar_moodle'))}}

    def image(self, kind, identifier):
        return {'Id': identifier, 'Os': 'linux', 'Architecture': 'amd64',
                'Config': {'Entrypoint': ['docker-entrypoint.sh'] if kind == 'pg' else ['docker-php-entrypoint'],
                           'Cmd': ['postgres'] if kind == 'pg' else ['apache2-foreground']}}

    def test_oci_manifest_and_classic_config_ids_are_supported(self):
        def command(args, **kwargs):
            identifier = args[-1]
            if identifier.startswith('manifest-'):
                return SimpleNamespace(returncode=1, stdout=b'')
            kind = identifier.rsplit('-', 1)[1]
            return SimpleNamespace(returncode=0, stdout=json.dumps([self.image(kind, identifier)]).encode())
        with mock.patch('subprocess.run', side_effect=command):
            self.assertEqual(r.local_images(None, self.preflight()), {'pg': 'config-pg', 'web': 'config-web'})

    def test_unlinked_image_identity_is_not_accepted(self):
        result = SimpleNamespace(returncode=0, stdout=json.dumps([self.image('pg', 'different-id')]).encode())
        with mock.patch('subprocess.run', return_value=result), self.assertRaisesRegex(r.RestoreTestError, 'ID_OR_PLATFORM'):
            r.local_images(None, self.preflight())

    def test_unreviewed_image_entrypoint_is_not_executed(self):
        image = self.image('pg', 'manifest-pg')
        image['Config']['Entrypoint'] = ['different-program']
        result = SimpleNamespace(returncode=0, stdout=json.dumps([image]).encode())
        with mock.patch('subprocess.run', return_value=result), self.assertRaisesRegex(r.RestoreTestError, 'ENTRYPOINT'):
            r.local_images(None, self.preflight())


@unittest.skipUnless(os.geteuid() == 0, 'native root path/state fixture job uses sudo')
class PresentationCarry(unittest.TestCase):
    """Backup config.php homepage/theme must survive the engine's config rewrite."""
    GENERATED = ("<?php\n$CFG->wwwroot = 'http://127.0.0.1:18084';\n"
                 "$CFG->sessioncookie = 'USTAR_RECOVERY_LAB_20261004';\n"
                 "$CFG->noemailever = true;\n$CFG->cron_enabled = 0;\n"
                 "require_once(__DIR__ . '/lib/setup.php');\n")

    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix='ustar-presentation-', dir='/var/lib')
        self.addCleanup(self.folder.cleanup)
        root = Path(self.folder.name) / 'run'
        patcher = mock.patch.object(r, 'ROOT', root)
        patcher.start()
        self.addCleanup(patcher.stop)
        (root / 'site/public/public/theme/ustar').mkdir(parents=True)
        (root / 'site/public/public/theme/ustar/config.php').write_text('<?php\n')
        (root / 'lab.ini').write_text('memory_limit=512M\nsendmail_path=/bin/false\ndisplay_errors=Off\nlog_errors=On\n')
        self.config = root / 'site/public/config.php'
        self.lab = FakeLab()
        generated = self.GENERATED
        def engine_prepare(_password):
            self.config.write_text(generated)
            return 2
        self.lab.prepare_config = engine_prepare

    def run_prepare(self, original):
        self.config.write_text(original)
        with mock.patch.object(r.os, 'chown'):
            self.assertEqual(r.prepare_config(self.lab, 'p' * 64), 2)
        return self.config.read_text()

    def test_homepage_and_theme_are_carried_before_setup(self):
        text = self.run_prepare("<?php\n$CFG->dbpass = 'secret';\n$CFG->wwwroot = 'http://prod';\n"
                                "$CFG->theme = 'ustar';\n$CFG->defaulthomepage = '/local/ustar/home.php';\n"
                                "require_once(__DIR__ . '/lib/setup.php');\n// trailing comment\n")
        self.assertTrue(text.endswith("$CFG->defaulthomepage = '/local/ustar/home.php';\n$CFG->theme = 'ustar';\n"
                                      "require_once(__DIR__ . '/lib/setup.php');\n"))
        self.assertIn("$CFG->wwwroot = '" + r.URL + "';", text)
        self.assertNotIn('secret', text)
        self.assertNotIn('http://prod', text)
        self.assertEqual(r.PRESENTATION_RESULT, {'carried': {'defaulthomepage': '/local/ustar/home.php',
                                                             'theme': 'ustar'}, 'skipped': []})

    def test_config_without_settings_keeps_generated_config(self):
        text = self.run_prepare("<?php\n$CFG->dbpass = 'x';\nrequire_once(__DIR__ . '/lib/setup.php');\n")
        self.assertEqual(text, self.GENERATED.replace('http://127.0.0.1:18084', r.URL)
                         .replace('USTAR_RECOVERY_LAB_20261004', 'USTAR_RESTORE_TEST_20261014'))
        self.assertEqual(r.PRESENTATION_RESULT, {'carried': {}, 'skipped': []})

    def test_conditional_or_dynamic_values_are_not_carried(self):
        text = self.run_prepare("<?php\nif ($x) { $CFG->theme = 'ustar'; }\n$CFG->theme = 'ustar';\n"
                                "$CFG->defaulthomepage = $other;\nrequire_once(__DIR__ . '/lib/setup.php');\n")
        self.assertNotIn('$CFG->theme', text)
        self.assertNotIn('$CFG->defaulthomepage', text)
        self.assertEqual(r.PRESENTATION_RESULT['skipped'], ['defaulthomepage', 'theme'])

    def test_external_or_injected_homepage_is_refused(self):
        for value in ("https://evil.example/", "/local/ustar/home.php'; $CFG->x = '1", '/admin/index.php'):
            carried, skipped = r.presentation_settings("<?php\n$CFG->defaulthomepage = '" + value + "';\n")
            self.assertEqual((carried, skipped), ({}, ['defaulthomepage']), value)

    def test_missing_theme_directory_is_not_forced(self):
        carried, skipped = r.presentation_settings("<?php\n$CFG->theme = 'boost_union';\n")
        self.assertEqual((carried, skipped), ({}, ['theme']))

    def test_commented_assignment_is_ignored(self):
        carried, skipped = r.presentation_settings("<?php\n// $CFG->theme = 'boost';\n$CFG->theme = \"ustar\";\n"
                                                   "$CFG->defaulthomepage = 1;\n")
        self.assertEqual(carried, {'theme': 'ustar'})
        self.assertEqual(skipped, ['defaulthomepage'])

    def test_generated_config_already_setting_value_is_refused(self):
        self.GENERATED = self.GENERATED.replace('require_once', "$CFG->theme = 'boost';\nrequire_once")
        self.config.write_text("<?php\n")
        def engine_prepare(_password):
            self.config.write_text(self.GENERATED)
            return 2
        self.lab.prepare_config = engine_prepare
        with self.assertRaisesRegex(r.RestoreTestError, 'GENERATED_LAB_CONFIG_MISMATCH'):
            r.prepare_config(self.lab, 'p' * 64)


if __name__ == '__main__':
    unittest.main()
