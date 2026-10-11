"""Real archive/copy/hash/lock tests; portable fixture owners, Docker inspect mocked.

No production dependency loader, Docker daemon, PHP or SQL is executed here.
"""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import stat
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('patch_candidate_tested', REPO / 'scripts/infra/lab_patch_candidate.py')
c = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(c)


def sha(data):
    return hashlib.sha256(data).hexdigest()


class InspectOnlyLab:
    PRODUCTION = ('production_web', 'production_pg')

    def __init__(self, name):
        self.NAMES = {'web': name + '_web', 'pg': name + '_pg'}
        self.running, self.drift = True, False
        self.calls = []

    def load_state(self):
        self.calls.append('load_state')
        return {'ready': True, 'stopped': False, 'containers': dict(self.NAMES),
                'images': {'web': 'web_image', 'pg': 'pg_image'}}

    def owned(self, kind, state):
        self.calls.append('owned')
        return {'Id': self.NAMES[kind], 'Image': kind + '_image',
                'State': {'Running': self.running, 'StartedAt': 'changed' if self.drift else 'original'}}

    def assert_offline(self, state):
        self.calls.append('assert_offline')

    def production_state(self):
        self.calls.append('production_state')
        return {'production': 'original'}

    @staticmethod
    def strip_php_comments(text):
        return re.sub(r'/\*.*?\*/|//[^\n]*', '', text, flags=re.S)


class CandidateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        source = self.base / 'source'
        source.mkdir(mode=0o700)
        self.source, self.stage = InspectOnlyLab('source'), InspectOnlyLab('stage')
        self.config = ("<?php\n$CFG->wwwroot = 'http://127.0.0.1:18084';\n"
                       "$CFG->sessioncookie = 'USTAR_RECOVERY_LAB_20261004';\n"
                       "require_once(__DIR__ . '/lib/setup.php');\n").encode()
        self.stock = {'public/index.php': b'<?php // official index\n',
                      'public/version.php': b'<?php $version = 2025100608.00;\n',
                      'public/config.php': b'<?php // official config loader\n',
                      'composer.json': b'{}\n', 'composer.lock': b'{}\n',
                      'public/lib/setup.php': b'<?php // official setup\n',
                      'githash.php': b'<?php $githash = "new-package";\n'}
        replacements = {'SOURCE': source, 'CHECKPOINT': source / 'rollback',
                        'WORK': source / 'workspace', 'PACKAGE_DIR': source / 'package',
                        'ARCHIVE': source / 'package/moodle-5.1.8.tgz', 'LOCK': source / 'coord.lock',
                        'COLD': source / 'installed.py', 'ROOT_UID': os.getuid(),
                        'ROOT_GID': os.getgid(), 'WEB_GID': os.getgid(),
                        'EXTRAS': ('private.backup', 'public/reports/private.pdf', 'githash.php'),
                        'CORE_HASHES': {p: sha(self.stock[p]) for p in
                                        ('public/version.php', 'public/index.php', 'composer.json', 'composer.lock')}}
        for name, value in replacements.items():
            patch = mock.patch.object(c, name, value)
            patch.start()
            self.addCleanup(patch.stop)
        # Production rejects /tmp as a parent. Only the external fixture ancestors
        # are skipped; real per-entry ownership/type/mode/link checks stay enabled.
        def fixture_parents(path):
            for parent in path.parents:
                if parent == self.base or parent.is_relative_to(self.base):
                    c.trusted(parent, directory=True)
        patch = mock.patch.object(c, 'trusted_parents', fixture_parents)
        patch.start()
        self.addCleanup(patch.stop)
        for root in (c.CHECKPOINT, c.WORK, c.PACKAGE_DIR):
            root.mkdir(mode=0o700)
        c.LOCK.write_bytes(b'')
        c.LOCK.chmod(0o644)
        for root in (c.SOURCE, c.WORK):
            for name in ('state.json', 'pg.env', 'lab.ini', 'apache.conf', 'report.json'):
                self.file(root / name, b'private fixture', 0o600)
        self.file(c.SOURCE / 'site/public/config.php', self.config, 0o640)
        stage_config = self.config.replace(b':18084', b':18085').replace(
            b'USTAR_RECOVERY_LAB_20261004', b'USTAR_PATCH_STAGE_20261005')
        self.file(c.WORK / 'site/public/config.php', stage_config, 0o640)
        frozen = {'config.php': self.config, 'public/config.php': b'<?php // old shim',
                  'public/index.php': b'<?php // old redirect',
                  'public/local/ustar/home.php': b'<?php // private home',
                  'public/local/ustar/version.php': b'<?php $plugin->version = 2026100204;\n$plugin->requires = 2025100600;\n',
                  'public/blocks/fixture/version.php': b'<?php $plugin->version = 2026090000;\n$plugin->requires = $unknown;\n',
                  'private.backup': b'private-secret-config',
                  'githash.php': b'<?php $githash = "old-package";\n',
                  'public/reports/private.pdf': b'private-pdf-fixture',
                  'vendor/private-dev.php': b'old dev vendor'}
        for rel, data in frozen.items():
            self.file(c.CHECKPOINT / 'site/public' / rel, data)
        self.entries = {}
        for path in (c.CHECKPOINT / 'site/public').rglob('*'):
            self.entries[path.relative_to(c.CHECKPOINT).as_posix()] = c.metadata(
                path.lstat(), path.is_dir(), None if path.is_dir() else sha(path.read_bytes()))
        self.profile = {'additional_roots': ['public/local/ustar', 'public/blocks/fixture'],
                        'additional_components': [
                            {'version_file': 'local/ustar/version.php', 'disk_version': 2026100204,
                             'requires_literal': 2025100600},
                            {'version_file': 'blocks/fixture/version.php', 'disk_version': 2026090000,
                             'requires_literal': None}],
                        'application_files': {rel: self.entries[c.PREFIX + rel]['sha256'] for rel in
                                              ('public/local/ustar/home.php', 'public/local/ustar/version.php')}}
        manifest = {'schema_version': 1, 'complete': True, 'source': str(c.SOURCE),
                    'source_core_db_version': '2025100601.03', 'entries': self.entries}
        data = json.dumps(manifest).encode()
        self.file(c.CHECKPOINT / 'manifest.json', data, 0o600)
        patch = mock.patch.object(c, 'MANIFEST_SHA', sha(data))
        patch.start()
        self.addCleanup(patch.stop)
        report = {'kind': 'lab_cold_checkpoint_and_patch_workspace',
                  'checkpoint_manifest_sha256': c.MANIFEST_SHA, 'core_upgrade_executed': False,
                  'http_login': 200, 'cold_copy_file_hashes': 'PASS', 'cold_copy_restore_bootstrap': 'PASS'}
        (c.WORK / 'report.json').write_text(json.dumps(report))
        self.resume = SimpleNamespace(check_config=lambda *args: None)
        self.deps = (None, None, self.resume, self.source, self.stage, 'template', self.profile)
        self.make_archive(self.stock)
        self.assertGreater(c.budget({}, {})['available_free_bytes'], c.RESERVE)

    @staticmethod
    def file(path, data, mode=0o644):
        path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(mode)

    def make_archive(self, files, special=None):
        dirs = {'.'}
        for path in files:
            dirs.update(str(p) for p in Path(path).parents)
        with tarfile.open(c.ARCHIVE, 'w:gz') as tar:
            for directory in sorted(dirs, key=lambda p: (len(Path(p).parts), p)):
                member = tarfile.TarInfo('moodle' if directory == '.' else 'moodle/' + directory)
                member.type, member.mode = tarfile.DIRTYPE, 0o755
                tar.addfile(member)
            for path, data in files.items():
                member = tarfile.TarInfo('moodle/' + path)
                member.size, member.mode = len(data), 0o644
                tar.addfile(member, io.BytesIO(data))
            if special is not None:
                tar.addfile(special)
        c.ARCHIVE.chmod(0o600)
        patch = mock.patch.object(c, 'ARCHIVE_SHA', sha(c.ARCHIVE.read_bytes()))
        patch.start()
        self.addCleanup(patch.stop)

    @staticmethod
    def snapshot():
        return {str(p.relative_to(c.SOURCE)): (p.lstat().st_ino, p.read_bytes())
                for p in c.SOURCE.rglob('*') if p.is_file()}

    @contextlib.contextmanager
    def checked(self):
        stream, tar, info = c.archive_open()
        try:
            yield stream, tar, info, c.precheck(self.deps, tar)
        finally:
            tar.close()
            stream.close()

    def build(self):
        with self.checked() as (stream, tar, info, checked), contextlib.redirect_stdout(io.StringIO()):
            return c.prepare(self.deps, tar, checked, (stream, info))

    def test_copy_preserves_stock_addons_extras_and_existing_input_inodes(self):
        before = self.snapshot()
        report = self.build()
        root = Path(report['candidate_directory'])
        code = root / 'code'
        self.assertEqual(before, {k: v for k, v in self.snapshot().items() if k in before})
        for rel, data in self.stock.items():
            self.assertEqual((code / rel).read_bytes(), data)
        for rel in (*self.profile['application_files'], *(x for x in c.EXTRAS if x != 'githash.php')):
            src, dst = c.CHECKPOINT / 'site/public' / rel, code / rel
            self.assertEqual(src.read_bytes(), dst.read_bytes())
            self.assertNotEqual(src.stat().st_ino, dst.stat().st_ino)
        self.assertIn(b"$CFG->defaulthomepage = '/local/ustar/home.php';", (code / 'config.php').read_bytes())
        self.assertNotIn(b'18084', (code / 'config.php').read_bytes())
        self.assertEqual(stat.S_IMODE(root.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((code / 'config.php').stat().st_mode), 0o640)
        self.assertEqual(stat.S_IMODE((root / 'manifest.json').stat().st_mode), 0o600)
        self.assertFalse((code / 'vendor').exists())
        old, saved = c.CHECKPOINT / 'site/public/githash.php', root / 'preserved-extra-files/githash.php'
        self.assertEqual(old.read_bytes(), saved.read_bytes())
        self.assertNotEqual(old.stat().st_ino, saved.stat().st_ino)
        manifest = json.loads((root / 'manifest.json').read_text())
        self.assertEqual(manifest['preserved_extra_entries']['githash.php']['sha256'], sha(old.read_bytes()))
        self.assertEqual(report['extra_files_preserved'], 3)
        self.assertEqual(report['extra_files_in_candidate_code'], 2)
        self.assertEqual(report['extra_files_preserved_separately'], ['githash.php'])
        self.assertFalse(report['candidate_activated'])
        self.assertFalse(report['core_upgrade_executed'])
        self.assertFalse(report['full_addon_compatibility_verified'])
        self.assertTrue(report['original_root_vendor_present_in_checkpoint'])
        self.assertNotIn('private-secret-config', json.dumps(report))
        self.assertLessEqual(set(self.source.calls + self.stage.calls),
                             {'load_state', 'owned', 'assert_offline', 'production_state'})

    def test_repeated_copy_gets_fresh_directory_and_keeps_previous_copy(self):
        first = self.build()
        before = self.snapshot()
        second = self.build()
        self.assertNotEqual(first['candidate_directory'], second['candidate_directory'])
        self.assertEqual(before, {k: v for k, v in self.snapshot().items() if k in before})

    def test_umask_077_does_not_remove_web_read_or_directory_search_permissions(self):
        old = os.umask(0o077)
        try:
            report = self.build()
        finally:
            os.umask(old)
        code = Path(report['candidate_directory']) / 'code'
        self.assertEqual(stat.S_IMODE(code.stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE((code / 'public/lib').stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE((code / 'public/index.php').stat().st_mode), 0o644)

    def test_readonly_mode_creates_no_files_and_executes_only_inspect_adapter_calls(self):
        before = self.snapshot()
        with mock.patch.object(c, 'load_dependencies', return_value=self.deps), \
                mock.patch.object(sys, 'argv', ['candidate', '--check']), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(c.main(), 0)
        self.assertEqual(before, self.snapshot())

    def test_default_mode_does_not_load_private_inputs(self):
        with mock.patch.object(c, 'load_dependencies', side_effect=AssertionError('private read')), \
                mock.patch.object(c, 'archive_open', side_effect=AssertionError('archive read')), \
                mock.patch.object(sys, 'argv', ['candidate']), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(c.main(), 0)

    def test_archive_checksum_refusal_before_copy(self):
        with mock.patch.object(c, 'ARCHIVE_SHA', '0' * 64), self.assertRaises(c.CandidateError):
            c.archive_open()
        self.assertFalse(list(c.SOURCE.glob('patch-candidate-*')))

    def test_manifest_checksum_refusal_before_copy(self):
        (c.CHECKPOINT / 'manifest.json').write_bytes(b'{}')
        with self.assertRaises(c.CandidateError), self.checked():
            pass
        self.assertFalse(list(c.SOURCE.glob('patch-candidate-*')))

    def test_changed_frozen_content_refused(self):
        (c.CHECKPOINT / 'site/public/public/local/ustar/home.php').write_bytes(b'altered')
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_changed_stage_config_refused(self):
        with (c.WORK / 'site/public/config.php').open('ab') as stream:
            stream.write(b'// changed')
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_changed_source_config_refused(self):
        with (c.SOURCE / 'site/public/config.php').open('ab') as stream:
            stream.write(b'// changed')
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_running_lab_required(self):
        self.stage.running = False
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_production_namespace_refused(self):
        self.stage.NAMES['web'] = self.stage.PRODUCTION[0]
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_frozen_hardlink_and_symlink_refused(self):
        src = c.CHECKPOINT / 'site/public/public/local/ustar/home.php'
        os.link(src, self.base / 'hardlink')
        with self.assertRaises(c.CandidateError), self.checked():
            pass
        (self.base / 'hardlink').unlink()
        src.unlink()
        src.symlink_to(self.base / 'absent')
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_world_writable_input_refused(self):
        (c.CHECKPOINT / 'site/public/private.backup').chmod(0o666)
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_archive_symlink_refused(self):
        member = tarfile.TarInfo('moodle/public/link')
        member.type, member.linkname = tarfile.SYMTYPE, '/etc/passwd'
        self.make_archive(self.stock, member)
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_archive_duplicate_and_traversal_refused(self):
        for name in ('moodle/public/index.php', 'moodle/../outside'):
            with self.subTest(name=name):
                self.make_archive(self.stock, tarfile.TarInfo(name))
                with self.assertRaises(c.CandidateError), self.checked():
                    pass

    def test_stock_core_metadata_refused(self):
        self.make_archive({**self.stock, 'public/index.php': b'changed official core'})
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_stock_collision_with_addon_or_extra_refused(self):
        for name in ('public/local/ustar/home.php', 'private.backup'):
            with self.subTest(name=name):
                self.make_archive({**self.stock, name: b'stock collision'})
                with self.assertRaises(c.CandidateError), self.checked():
                    pass

    def test_distribution_root_vendor_and_root_config_refused(self):
        for name in ('vendor/autoload.php', 'config.php'):
            with self.subTest(name=name):
                self.make_archive({**self.stock, name: b'unexpected'})
                with self.assertRaises(c.CandidateError), self.checked():
                    pass

    def test_failed_copy_keeps_partial_data_without_accepted_controls(self):
        before = self.snapshot()
        with mock.patch.object(c, 'copy_overlay', side_effect=OSError('fixture full disk')), \
                self.assertRaises(OSError):
            self.build()
        roots = list(c.SOURCE.glob('patch-candidate-*'))
        self.assertEqual(len(roots), 1)
        self.assertTrue((roots[0] / 'code/public/index.php').exists())
        self.assertFalse((roots[0] / 'manifest.json').exists())
        self.assertEqual(before, {k: v for k, v in self.snapshot().items() if k in before})

    def test_late_acceptance_failure_quarantines_final_manifest_and_report(self):
        original = c.sync_directory
        def fail(path):
            if path == c.SOURCE:
                raise OSError('late directory sync failure')
            return original(path)
        with mock.patch.object(c, 'sync_directory', side_effect=fail), self.assertRaises(OSError):
            self.build()
        root, = c.SOURCE.glob('patch-candidate-*')
        for name in ('manifest.json', 'report.json'):
            self.assertFalse((root / name).exists())
            self.assertTrue((root / ('unaccepted-' + name)).exists())

    def test_container_restart_during_copy_rejects_candidate(self):
        original = c.verify_candidate
        def drift(*args, **kwargs):
            original(*args, **kwargs)
            if kwargs.get('stock_core', True):
                self.stage.drift = True
        with mock.patch.object(c, 'verify_candidate', side_effect=drift), self.assertRaises(c.CandidateError):
            self.build()
        root, = c.SOURCE.glob('patch-candidate-*')
        self.assertFalse((root / 'manifest.json').exists())

    def test_short_write_wrong_hash_and_existing_destination_fail_closed(self):
        out = c.SOURCE / 'test-output'
        with self.assertRaises(c.CandidateError):
            c.write_file(out, io.BytesIO(b'a'), 2, c.ROOT_UID, c.ROOT_GID, 0o600)
        with self.assertRaises(FileExistsError):
            c.write_file(out, io.BytesIO(b'overwrite'), 9, c.ROOT_UID, c.ROOT_GID, 0o600)
        self.assertEqual(out.read_bytes(), b'a')
        with self.assertRaises(c.CandidateError):
            c.write_file(c.SOURCE / 'wrong-hash', io.BytesIO(b'abc'), 3,
                         c.ROOT_UID, c.ROOT_GID, 0o600, '0' * 64)

    def test_candidate_readback_catches_corruption_and_unknown_files(self):
        report = self.build()
        root = Path(report['candidate_directory'])
        inventory = json.loads((root / 'manifest.json').read_text())['entries']
        (root / 'code/public/index.php').write_bytes(b'corrupt')
        with self.assertRaises(c.CandidateError):
            c.verify_candidate(root / 'code', inventory)
        (root / 'code/public/index.php').write_bytes(self.stock['public/index.php'])
        (root / 'code/unexpected-file').write_bytes(b'unknown')
        with self.assertRaises(c.CandidateError):
            c.verify_candidate(root / 'code', inventory)

    def test_space_and_inode_budget_refusal(self):
        with mock.patch.object(c.shutil, 'disk_usage', return_value=SimpleNamespace(free=c.RESERVE)), \
                self.assertRaises(c.CandidateError):
            c.budget({}, {})
        with mock.patch.object(c.os, 'statvfs', return_value=SimpleNamespace(f_frsize=4096, f_favail=0)), \
                mock.patch.object(c.shutil, 'disk_usage', return_value=SimpleNamespace(free=10 * c.GIB)), \
                self.assertRaises(c.CandidateError):
            c.budget({}, {})

    def test_missing_extra_and_mismatching_addon_profile_refused(self):
        self.profile['additional_components'][0]['disk_version'] = 1
        with self.assertRaises(c.CandidateError), self.checked():
            pass
        self.profile['additional_components'][0]['disk_version'] = 2026100204
        (c.CHECKPOINT / 'site/public/private.backup').unlink()
        with self.assertRaises(FileNotFoundError), self.checked():
            pass

    def test_interrupt_after_manifest_write_quarantines_acceptance(self):
        original = c.private_json
        def interrupt(path, value):
            if path.name == 'report.json':
                raise KeyboardInterrupt()
            return original(path, value)
        with mock.patch.object(c, 'private_json', side_effect=interrupt), self.assertRaises(KeyboardInterrupt):
            self.build()
        root, = c.SOURCE.glob('patch-candidate-*')
        self.assertFalse((root / 'manifest.json').exists())
        self.assertTrue((root / 'unaccepted-manifest.json').exists())

    def test_missing_lock_not_created(self):
        c.LOCK.unlink()
        with mock.patch.object(sys, 'argv', ['candidate', '--check']), \
                contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(c.main(), 1)
        self.assertFalse(c.LOCK.exists())

    def test_busy_lock_does_not_start_copy(self):
        import subprocess
        holder = subprocess.Popen([sys.executable, '-c',
            'import fcntl,sys; f=open(sys.argv[1],"rb"); fcntl.flock(f,fcntl.LOCK_EX); '
            'print("ready",flush=True); sys.stdin.read()', str(c.LOCK)],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        try:
            self.assertEqual(holder.stdout.readline().strip(), 'ready')
            with mock.patch.object(c, 'load_dependencies', side_effect=AssertionError('dependency read')), \
                    mock.patch.object(sys, 'argv', ['candidate', '--prepare-candidate']), \
                    contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(c.main(), 1)
            self.assertFalse(list(c.SOURCE.glob('patch-candidate-*')))
        finally:
            holder.communicate('finish', timeout=5)

    def test_pinned_dependency_checksum_required(self):
        self.file(c.COLD, b'raise AssertionError("must not execute")', 0o600)
        with self.assertRaisesRegex(c.CandidateError, 'checksum'):
            c.load_dependencies()

    def test_preserved_extra_hash_corruption_rejects_entire_candidate(self):
        original = c.copy_overlay
        def corrupt(code, *args):
            original(code, *args)
            if code.name == 'preserved-extra-files':
                (code / 'githash.php').write_bytes(b'corrupt preserved bytes')
        with mock.patch.object(c, 'copy_overlay', side_effect=corrupt), self.assertRaises(c.CandidateError):
            self.build()
        root, = c.SOURCE.glob('patch-candidate-*')
        self.assertFalse((root / 'manifest.json').exists())

    def test_reviewed_name_cannot_allow_archive_directory_collision(self):
        self.make_archive({**{k: v for k, v in self.stock.items() if k != 'githash.php'},
                           'githash.php/inside.php': b'file in unexpected directory'})
        with self.assertRaises(c.CandidateError), self.checked():
            pass

    def test_real_package_and_complete_known_overlay_namespace_are_compatible(self):
        # Actual full package catalog, fixed extras and every profile addon root.
        # Deliberately separate from private server content/runtime acceptance.
        package = Path(os.environ.get('USTAR_OFFICIAL_PACKAGE_FIXTURE',
                                     str(REPO.parent / 'moodle-5.1.8.tgz')))
        if not package.is_file():
            self.skipTest('Optional public pinned distribution fixture not supplied')
        production = importlib.util.module_from_spec(SPEC)
        SPEC.loader.exec_module(production)
        self.assertEqual(sha(package.read_bytes()), production.ARCHIVE_SHA)
        profile = json.loads((REPO / 'scripts/infra/lab_patch_profile_20261005.json').read_text())
        with tarfile.open(package) as tar:
            catalog = production.archive_catalog(tar)
        selected = {rel: {'type': 'file'} for rel in production.EXTRAS}
        for root in profile['additional_roots']:
            selected[root] = {'type': 'directory'}
            self.assertFalse(any(r == root or r.startswith(root + '/') for r in catalog))
        for rel in list(selected):
            for parent in Path(rel).parents:
                if str(parent) != '.':
                    selected[str(parent)] = {'type': 'directory'}
        mounted, preserved = production.route_overlay(selected, catalog)
        self.assertEqual(set(preserved), {'githash.php'})
        self.assertNotIn('githash.php', mounted)
        self.assertEqual(len(catalog), 39376)
        # Execute the entire builder against the real distribution plus private
        # synthetic addon/config fixtures, not just stock extraction in isolation.
        import shutil
        shutil.copyfile(package, c.ARCHIVE)
        c.ARCHIVE.chmod(0o600)
        with mock.patch.object(c, 'ARCHIVE_SHA', production.ARCHIVE_SHA), \
                mock.patch.object(c, 'CORE_HASHES', production.CORE_HASHES):
            report = self.build()
        root = Path(report['candidate_directory'])
        with tarfile.open(package) as tar:
            stock_githash = tar.extractfile('moodle/githash.php').read()
        self.assertEqual((root / 'code/githash.php').read_bytes(), stock_githash)
        self.assertEqual((root / 'preserved-extra-files/githash.php').read_bytes(),
                         (c.CHECKPOINT / 'site/public/githash.php').read_bytes())
        self.assertEqual(report['stock_core_files_unchanged'], 29409)
        self.assertEqual(report['candidate_file_readback_hashes'], 'PASS')


if __name__ == '__main__':
    unittest.main()
