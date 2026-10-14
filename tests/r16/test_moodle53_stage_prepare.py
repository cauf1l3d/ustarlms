"""Actual private file copies/archive/readback; Docker inspect is modeled.

Run as root in the dedicated Ubuntu job. No service, DB or Moodle activation.
"""
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tarfile
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('moodle53_prepare_tested', REPO / 'scripts/infra/moodle53_stage_prepare.py')
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


@unittest.skipUnless(os.geteuid() == 0, 'dedicated Ubuntu fixture job uses sudo/root')
class MajorPreparation(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='ustar-m53-fixture-', dir='/var/lib')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.source, self.assets, self.out = [self.root / name for name in ('source', 'assets', 'output')]
        for path in (self.source, self.assets):
            path.mkdir(mode=0o700)
        self.patches = []
        portable = os.environ.get('USTAR_FIXTURE_PORTABLE_UIDS') == '1'
        webuid, pguid = (0, 0) if portable else (33, 999)
        for name, value in (('SOURCE', self.source), ('ASSETS', self.assets), ('OUTPUT', self.out),
                            ('PROFILE', self.root / 'profile.json'), ('LOCK', self.root / 'lab.lock'),
                            ('DATA_UID', webuid), ('DATA_GID', webuid)):
            patch = mock.patch.object(m, name, value)
            patch.start()
            self.patches.append(patch)
            self.addCleanup(patch.stop)
        self.profile = json.loads((REPO / 'scripts/infra/moodle53_profile_20261014.json').read_text())
        self.profile.update(source_root=str(self.source), assets_directory=str(self.assets))
        self.profile['additional_roots'] = ['public/local/ustar', 'public/theme/ustar']
        self.profile['additional_components'] = [
            {'component': 'local_ustar', 'version_file': 'local/ustar/version.php', 'disk_version': 2026100204},
            {'component': 'theme_ustar', 'version_file': 'theme/ustar/version.php', 'disk_version': 2026100202},
        ]
        code = self.source / 'site/public'
        code.mkdir(parents=True, mode=0o700)
        for name, text in {
            'public/version.php': '<?php $version = 2025100608.00;',
            'public/local/ustar/version.php': '<?php $plugin->version = 2026100204;',
            'public/theme/ustar/version.php': '<?php $plugin->version = 2026100202;',
            'public/local/ustar/route.php': '<?php // synthetic',
            'config.php': '<?php // PRIVATE_FIXTURE_CREDENTIAL',
            'public/legacy-extra.php': '<?php // retained only in frozen pair',
        }.items():
            self.write(code / name, text)
        self.profile['application_files'] = {
            str(p.relative_to(code)): m.file_hash(p) for p in code.rglob('*')
            if p.is_file() and '/ustar/' in str(p)
        }
        self.data = self.source / 'site/moodledata'
        self.data.mkdir(mode=0o700)
        self.write(self.data / 'filedir/payload', 'synthetic-scorm-content', mode=0o666)
        for path in (self.data, *self.data.rglob('*')):
            os.chown(path, webuid, webuid)
        self.pg = self.source / 'postgres'
        self.pg.mkdir(mode=0o700)
        self.write(self.pg / 'PG_VERSION', '16\n')
        self.write(self.pg / 'base/relation', 'synthetic-physical-pg16-not-a-real-cluster')
        for path in (self.pg, *self.pg.rglob('*')):
            os.chown(path, pguid, pguid)
        self.state = {'ready': True, 'containers': {'web': 'web-id', 'pg': 'pg-id'},
                      'images': {'web': 'web-image-id', 'pg': 'pg16-image-id'}}
        for name in m.CONTROLS:
            self.write(self.source / name, json.dumps(self.state) if name == 'state.json'
                       else 'PRIVATE_FIXTURE_CONTROL', mode=0o600)
        self.make_archive()
        self.containers = []
        for name in (*m.PRODUCTION, *m.NAMES.values()):
            kind = next((k for k, n in m.NAMES.items() if n == name), None)
            mounts = []
            if kind == 'web':
                mounts = [self.mount(code, '/var/www/html'), self.mount(self.data, '/var/www/moodledata')]
            elif kind == 'pg':
                mounts = [self.mount(self.pg, '/var/lib/postgresql/data')]
            running = kind is None
            self.containers.append({'Name': '/' + name, 'Id': self.state['containers'][kind] if kind else name+'-id',
                                    'Image': self.state['images'][kind] if kind else name+'-image',
                                    'State': {'Running': running, 'Status': 'running' if running else 'exited',
                                              'ExitCode': 0, 'StartedAt': 'fixed-start', 'FinishedAt': 'fixed-finish',
                                              'Pid': 123 if running else 0, 'Health': {'Log': ['advancing']}},
                                    'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped' if running else 'no'}},
                                    'Config': {'Env': ['PRIVATE_FIXTURE_CREDENTIAL']}, 'Mounts': mounts})
        self.image = {'Id': 'pg17-image-id', 'Os': 'linux', 'Architecture': 'amd64',
                      'RepoDigests': ['postgres@' + self.profile['postgres17_digest']],
                      'Config': {'Env': ['PG_MAJOR=17', 'PG_VERSION=17.11-1.pgdg13+2']}}
        self.calls = []
        self.inspect_patch = mock.patch.object(m.subprocess, 'run', side_effect=self.fake_docker)
        self.inspect_patch.start()
        self.addCleanup(self.inspect_patch.stop)

    def tearDown(self):
        self.inspect_patch.stop()
        for patch in reversed(self.patches):
            patch.stop()
        self.tmp.cleanup()

    @staticmethod
    def write(path, text, mode=0o644):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
        os.chmod(path, mode)

    @staticmethod
    def mount(source, dest):
        return {'Source': str(source), 'Destination': dest, 'Type': 'bind'}

    def make_archive(self, mutation=None):
        path = self.assets / 'moodle-5.3.tgz'
        with tarfile.open(path, 'w:gz') as tar:
            for name in ('moodle', 'moodle/public', 'moodle/lib'):
                t = tarfile.TarInfo(name)
                t.type, t.mode = tarfile.DIRTYPE, 0o755
                tar.addfile(t)
            files = {'moodle/public/version.php': "<?php $version = 2026100500.00; $branch = '503'; $maturity = MATURITY_STABLE;",
                     'moodle/public/index.php': '<?php // official synthetic stock',
                     'moodle/lib/setup.php': '<?php // stock', 'moodle/githash.php': '<?php // stock provenance'}
            for name, text in files.items():
                t = tarfile.TarInfo(name)
                raw = text.encode()
                t.size, t.mode = len(raw), 0o644
                tar.addfile(t, io.BytesIO(raw))
            if mutation:
                mutation(tar)
        os.chmod(path, 0o600)
        self.profile['archive_sha256'] = m.file_hash(path)

    def fake_docker(self, command, **kwargs):
        self.calls.append(command)
        if command[:3] == ['docker', 'image', 'inspect']:
            self.assertEqual(command[3:], [self.profile['postgres17_image']])
            data = [self.image]
        else:
            self.assertEqual(command, ['docker', 'inspect', *m.PRODUCTION, m.NAMES['web'], m.NAMES['pg']])
            data = self.containers
        return mock.Mock(returncode=0, stdout=json.dumps(data), stderr='')

    def prepare(self):
        with contextlib.redirect_stdout(io.StringIO()) as out:
            report = m.prepare(self.profile)
        self.assertNotIn('PRIVATE_FIXTURE', out.getvalue())
        return report

    def source_rows(self):
        return {kind: m.inventory(self.source / path, kind) for kind, path in m.TREE_PATHS.items()}

    def test_full_pairs_stock_addons_no_activation_or_source_mutation(self):
        before = self.source_rows()
        controls = {n: (self.source/n).read_bytes() for n in m.CONTROLS}
        report = self.prepare()
        run = Path(report['run_directory'])
        self.assertEqual(report['phase'], 'prepared')
        for name in ('baseline', 'working'):
            for kind in m.TREE_PATHS:
                self.assertEqual(m.inventory(run/name/kind, kind), before[kind])
        self.assertEqual(self.source_rows(), before)
        self.assertEqual({n: (self.source/n).read_bytes() for n in m.CONTROLS}, controls)
        self.assertFalse((run/'candidate53/config.php').exists())
        self.assertFalse((run/'candidate53/public/legacy-extra.php').exists())
        self.assertTrue((run/'baseline/code/public/legacy-extra.php').is_file())
        src = self.source/'site/public/config.php'
        a, b = run/'baseline/code/config.php', run/'working/code/config.php'
        self.assertEqual(len({src.stat().st_ino, a.stat().st_ino, b.stat().st_ino}), 3)
        b.write_text('synthetic-working-edit')
        self.assertEqual(src.read_text(), a.read_text())
        self.assertEqual(len(self.calls), 4)

    def test_native_umask_does_not_make_stock_unreadable(self):
        old = os.umask(0o077)
        try:
            report = self.prepare()
        finally:
            os.umask(old)
        root = Path(report['run_directory'])/'candidate53'
        self.assertEqual(stat.S_IMODE((root/'public').stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE((root/'public/version.php').stat().st_mode), 0o644)

    def test_xattrs_uid_gid_and_nanosecond_mtime_are_preserved(self):
        target = self.source/'site/public/config.php'
        os.setxattr(target, 'user.ustar-fixture', b'xattr-proof')
        os.utime(target, ns=(1234567890123456789, 1234567890123456789))
        report = self.prepare()
        dest = Path(report['run_directory'])/'baseline/code/config.php'
        self.assertEqual(dest.stat().st_mtime_ns, target.stat().st_mtime_ns)
        self.assertEqual(os.getxattr(dest, 'user.ustar-fixture'), b'xattr-proof')

    def test_production_health_log_progress_is_not_identity_drift(self):
        row = self.containers[0]
        a = m.container_fingerprint(row)
        row['State']['Health']['Log'].append('next-legitimate-healthcheck')
        self.assertEqual(m.container_fingerprint(row), a)
        row['State']['StartedAt'] = 'unexpected-restart'
        self.assertNotEqual(m.container_fingerprint(row), a)

    def test_running_source_is_refused_before_copy(self):
        self.containers[-1]['State']['Running'] = True
        with self.assertRaisesRegex(m.PreparationError, 'SOURCE_LAB_NOT_CLEANLY_STOPPED'):
            self.prepare()
        self.assertFalse((self.out/'active.json').exists())

    def test_foreign_container_identity_is_refused(self):
        self.containers[-1]['Id'] = 'foreign-id'
        with self.assertRaisesRegex(m.PreparationError, 'SOURCE_CONTAINER_ID_OR_IMAGE_DIFFERS'):
            self.prepare()

    def test_restart_policy_change_is_refused(self):
        self.containers[-1]['HostConfig']['RestartPolicy']['Name'] = 'always'
        with self.assertRaisesRegex(m.PreparationError, 'SOURCE_LAB_NOT_CLEANLY_STOPPED'):
            self.prepare()

    def test_production_bind_overlap_is_refused(self):
        self.containers[0]['Mounts'].append(self.mount(self.source/'site/public', '/var/www/html'))
        with self.assertRaisesRegex(m.PreparationError, 'SOURCE_OVERLAPS_PRODUCTION_MOUNT'):
            self.prepare()

    def test_foreign_source_bind_is_refused(self):
        self.containers[-1]['Mounts'][0]['Source'] = '/opt/ustar/data/postgres'
        with self.assertRaisesRegex(m.PreparationError, 'SOURCE_BIND_ROOT_DIFFERS'):
            self.prepare()

    def test_temporary_container_filesystems_do_not_block_cold_copy(self):
        self.containers[-2]['Mounts'].append({'Type': 'tmpfs', 'Source': '', 'Destination': '/tmp'})
        self.assertEqual(self.prepare()['phase'], 'prepared')

    def test_wrong_pg17_digest_platform_or_version_are_refused(self):
        for key, bad in (('Architecture', 'arm64'), ('RepoDigests', ['postgres@sha256:wrong']),
                         ('Config', {'Env': ['PG_MAJOR=18', 'PG_VERSION=18.6']})):
            old = self.image[key]
            self.image[key] = bad
            with self.assertRaises(m.PreparationError):
                m.inspect_source(self.profile, self.state)
            self.image[key] = old

    def test_archive_checksum_mismatch_is_refused_before_run_directory(self):
        self.profile['archive_sha256'] = '0'*64
        with self.assertRaisesRegex(m.PreparationError, 'OFFICIAL_ARCHIVE_SHA256_MISMATCH'):
            self.prepare()
        self.assertFalse(list(self.out.glob('stage-*')))

    def test_archive_traversal_is_refused(self):
        def bad(tar):
            t = tarfile.TarInfo('moodle/../outside')
            t.size = 1
            tar.addfile(t, io.BytesIO(b'x'))
        self.make_archive(bad)
        with self.assertRaisesRegex(m.PreparationError, 'INVALID_RELATIVE_PATH'):
            self.prepare()

    def test_archive_symlink_is_refused(self):
        def bad(tar):
            t = tarfile.TarInfo('moodle/public/link')
            t.type, t.linkname = tarfile.SYMTYPE, '/etc/passwd'
            tar.addfile(t)
        self.make_archive(bad)
        with self.assertRaisesRegex(m.PreparationError, 'ARCHIVE_LINK_OR_SPECIAL_ENTRY'):
            self.prepare()

    def test_duplicate_stock_file_is_refused(self):
        def bad(tar):
            t = tarfile.TarInfo('moodle/public/index.php')
            tar.addfile(t, io.BytesIO(b''))
        self.make_archive(bad)
        with self.assertRaisesRegex(m.PreparationError, 'ARCHIVE_DUPLICATE_OR_SIZE_LIMIT'):
            self.prepare()

    def test_archive_existing_config_is_refused(self):
        self.make_archive(lambda tar: tar.addfile(tarfile.TarInfo('moodle/config.php'), io.BytesIO(b'')))
        with self.assertRaisesRegex(m.PreparationError, 'ARCHIVE_ROOT_OR_CONFIG_DIFFERS'):
            self.prepare()

    def test_wrong_target_core_metadata_is_refused(self):
        with self.assertRaisesRegex(m.PreparationError, 'CORE_VERSION_LITERAL_DIFFERS'):
            m.core_metadata(b'<?php $version = 2025100608.00;', '2026100500.00', '503')
        with self.assertRaisesRegex(m.PreparationError, 'TARGET_CORE_METADATA_DIFFERS'):
            m.core_metadata(b"<?php $version = 2026100500.00; $branch = '502'; $maturity = MATURITY_STABLE;",
                            '2026100500.00', '503')

    def test_ustar_hash_change_is_refused(self):
        (self.source/'site/public/public/local/ustar/route.php').write_text('unexpected-source-change')
        with self.assertRaisesRegex(m.PreparationError, 'USTAR_SOURCE_HASH_SET_DIFFERS'):
            self.prepare()

    def test_addon_disk_version_change_is_refused(self):
        self.profile['additional_components'][0]['disk_version'] += 1
        with self.assertRaisesRegex(m.PreparationError, 'ADDON_DISK_VERSION_DIFFERS'):
            self.prepare()

    def test_pg16_pid_or_wrong_major_is_refused(self):
        self.write(self.pg/'postmaster.pid', 'still-running')
        with self.assertRaisesRegex(m.PreparationError, 'SOURCE_PG16_NOT_COLD'):
            self.prepare()

    def test_source_link_and_hardlink_are_refused_without_following_proc(self):
        target = self.source/'site/public/public/proc-link'
        target.symlink_to('/proc/self/fd/99')
        with self.assertRaisesRegex(m.PreparationError, 'TREE_LINK_SPECIAL_ENTRY_OR_NESTED_FILESYSTEM'):
            self.prepare()
        target.unlink()
        os.link(self.source/'site/public/config.php', target)
        with self.assertRaisesRegex(m.PreparationError, 'TREE_LINK_SPECIAL_ENTRY_OR_NESTED_FILESYSTEM'):
            self.prepare()

    def test_partial_copy_is_retained_and_sources_unchanged(self):
        before = self.source_rows()
        original = m.copy_file
        def failed(source, target, entry, progress):
            original(source, target, entry, progress)
            raise OSError('fixture-I/O-failure')
        with mock.patch.object(m, 'copy_file', side_effect=failed):
            with self.assertRaises(OSError):
                self.prepare()
        receipt = json.loads((self.out/'active.json').read_text())
        self.assertEqual(receipt['phase'], 'failed_partial_preserved')
        self.assertTrue(Path(receipt['run_directory']).is_dir())
        self.assertEqual(self.source_rows(), before)
        self.assertEqual(len(self.calls), 2)

    def test_existing_successful_pair_is_not_overwritten(self):
        first = self.prepare()
        proof = (Path(first['run_directory'])/'manifest.json').read_bytes()
        with self.assertRaisesRegex(m.PreparationError, 'EXISTING_STAGE_OR_UNFINISHED_RUN'):
            self.prepare()
        self.assertEqual((Path(first['run_directory'])/'manifest.json').read_bytes(), proof)

    def test_full_profile_checksum_and_contract(self):
        p = json.loads((REPO/'scripts/infra/moodle53_profile_20261014.json').read_text())
        p.update(source_root=str(self.source), assets_directory=str(self.assets))
        self.write(m.PROFILE, json.dumps(p), mode=0o600)
        digest = m.file_hash(m.PROFILE)
        with mock.patch.object(m, 'PROFILE_SHA', digest):
            self.assertEqual(len(m.load_profile()['additional_components']), 57)
        with self.assertRaisesRegex(m.PreparationError, 'PROFILE_SHA256_MISMATCH'):
            m.load_profile()

    def test_busy_or_symlink_lock_refuses_change(self):
        with m.locked():
            with self.assertRaisesRegex(m.PreparationError, 'LAB_LOCK_BUSY_NO_CHANGE'):
                with m.locked():
                    self.fail('lock must not be acquired twice')
        m.LOCK.unlink()
        m.LOCK.symlink_to(self.source/'state.json')
        with self.assertRaises(OSError):
            with m.locked():
                self.fail('symlink lock must not be opened')


if __name__ == '__main__':
    unittest.main()
