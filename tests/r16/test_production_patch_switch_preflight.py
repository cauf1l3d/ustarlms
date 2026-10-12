"""Real temporary metadata/config/lock reads; external commands are read-only fakes."""
import copy
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, REPO / file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


w = load('production_switch_preflight_tested', 'scripts/infra/production_patch_switch_preflight.py')
s0 = load('production_switch_config_primitive', 'scripts/infra/production_patch_candidate.py')
p0 = load('production_switch_read_primitive', 'scripts/infra/production_patch_readiness.py')


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


class SwitchPreflightGuards(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.base = Path(temp.name)
        self.candidate = self.base / 'candidates/candidate-518-test'
        self.code = self.base / 'live/code'
        self.data = self.base / 'live/moodledata'
        self.pg = self.base / 'live/postgres'
        self.state = self.base / 'state'
        for root in (self.candidate / 'code', self.code, self.data, self.pg, self.state):
            root.mkdir(parents=True, mode=0o700)
        os.chmod(self.code, 0o755)
        os.chmod(self.candidate, 0o700)
        self.config = ("<?php\n$CFG->dbpass='PRIVATE_SENTINEL';\n$CFG->prefix='mdl_';\n"
                       + s0.SETUP + ' // Do not edit\n').encode()
        self.put(self.code / 'config.php', self.config)
        self.put(self.candidate / 'code/config.php', s0.configuration(self.config))
        self.put(self.data / 'learning-file', b'PRIVATE_PERSONNEL_SENTINEL')
        self.put(self.pg / 'PG_VERSION', b'16\n')
        self.put(self.pg / 'table-file', b'PRIVATE_DATABASE_SENTINEL')
        self.lock = self.base / 'backup.lock'
        self.put(self.lock, b'')
        self.p = SimpleNamespace(CODE=self.code, DATA=self.data, PGDATA=self.pg, STATE=self.state,
                                 LOCK=self.lock, root_input=self.trusted, no_links=p0.no_links,
                                 read_file=p0.read_file, containers=self.containers, command=self.command)
        self.s = SimpleNamespace(BASE=self.candidate.parent, configuration=s0.configuration)
        self.c = SimpleNamespace(ARCHIVE_SHA='a' * 64)
        self.source = {'code_entries': {'config.php': {'type': 'file', 'size': len(self.config)}},
                       'original_config_sha256': sha(self.config)}
        self.accepted = {'kind': 'separate_unactivated_production_patch_candidate',
                         'production_activated': False, 'source_core_db_version': '2025100601.03',
                         'source_addon_versions': {'addon_' + str(n): '1' for n in range(57)},
                         'entries': {'config.php': {'type': 'file', 'size': len(self.config) + len(s0.HOME)}},
                         'official_archive_sha256': self.c.ARCHIVE_SHA}
        source_sha = self.json_file(self.candidate / 'source-manifest.json', self.source)
        self.accepted['source_manifest_sha256'] = source_sha
        candidate_sha = self.json_file(self.candidate / 'candidate-manifest.json', self.accepted)
        helpers = {}
        for name in ('cron', 'hdd_backup', 'backup_engine'):
            path = self.base / (name + '.py')
            self.put(path, b'not executable; PRIVATE_HELPER_SENTINEL\n')
            helpers[name] = (path, sha(path.read_bytes()))
        for name, value in {'CANDIDATE': self.candidate, 'CANDIDATE_SHA': candidate_sha,
                            'SOURCE_SHA': source_sha, 'HELPERS': helpers, 'ROOT_UID': os.getuid(),
                            'CANDIDATE_ENTRIES': 1, 'SOURCE_ENTRIES': 1}.items():
            patch = mock.patch.object(w, name, value)
            patch.start()
            self.addCleanup(patch.stop)
        self.rows = [self.row('ustar_moodle', '1' * 64), self.row('ustar_postgres', '2' * 64)]
        self.actions = []

    def put(self, path, raw):
        path.write_bytes(raw)
        os.chmod(path, 0o600)

    def json_file(self, path, value):
        raw = json.dumps(value, sort_keys=True).encode()
        self.put(path, raw)
        return sha(raw)

    def trusted(self, path):
        p0.no_links(path)
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o022:
            raise w.SwitchRefusal('Test control trust refused')

    def row(self, name, identity):
        return {'Id': identity, 'Name': '/' + name, 'Image': 'sha256:' + identity,
                'State': {'Running': True}, 'Config': {'Env': ['SECRET=PRIVATE_DOCKER_SENTINEL']},
                'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped', 'MaximumRetryCount': 0}},
                'Mounts': []}

    def containers(self):
        return {row['Name'][1:]: {'Id': row['Id'], 'Image': row['Image'], 'started_at': 'stable', 'pid': 1}
                for row in self.rows[:2]}

    def command(self, argv, timeout=30):
        self.actions.append(argv)
        if argv == ['docker', 'ps', '-aq', '--no-trunc']:
            return '\n'.join(row['Id'] for row in self.rows)
        if argv == ['docker', 'inspect', *(row['Id'] for row in self.rows)]:
            return json.dumps(self.rows)
        raise AssertionError('Non-read-only command forbidden: ' + repr(argv))

    def check(self):
        return w.check(self.s, self.p, self.c)

    def tree_bytes(self):
        return {str(path.relative_to(self.base)): path.read_bytes()
                for path in self.base.rglob('*') if path.is_file() and not path.is_symlink()}

    def test_real_readonly_check_has_no_outputs_or_private_values_and_preserves_live_roots(self):
        before = self.tree_bytes()
        code_root = w.root_metadata(self.p, self.code)
        with mock.patch.object(Path, 'write_bytes', side_effect=AssertionError('no outputs')), \
                mock.patch.object(Path, 'mkdir', side_effect=AssertionError('no directories')):
            report = self.check()
        self.assertEqual(self.tree_bytes(), before)
        self.assertEqual(w.root_metadata(self.p, self.code), code_root)
        self.assertEqual(report['result'], 'PASS')
        self.assertEqual(report['live_bind_roots']['code']['mode'], 0o755)
        self.assertEqual(report['private_candidate_code_root']['mode'], 0o700)
        self.assertTrue(report['live_root_metadata_must_be_preserved_not_replaced_by_private_candidate_root'])
        output = json.dumps(report)
        self.assertNotIn('PRIVATE_', output)
        self.assertNotIn('learning-file', output)
        self.assertNotIn('table-file', output)
        self.assertFalse(report['SQL_executed'])
        self.assertFalse(report['paired_cold_snapshot_created'])

    def test_changed_manifest_refuses_before_host_commands(self):
        self.put(self.candidate / 'candidate-manifest.json', b'PRIVATE_CHANGED_MANIFEST')
        with self.assertRaisesRegex(w.SwitchRefusal, 'manifest checksum'):
            self.check()
        self.assertFalse(self.actions)

    def test_changed_live_or_candidate_config_refuses_before_host_commands(self):
        for path in (self.code / 'config.php', self.candidate / 'code/config.php'):
            original = path.read_bytes()
            self.put(path, b'PRIVATE_CHANGED_CONFIG')
            with self.assertRaisesRegex(w.SwitchRefusal, 'config changed'):
                self.check()
            self.put(path, original)
        self.assertFalse(self.actions)

    def test_changed_installed_recovery_helper_is_not_executed(self):
        self.put(w.HELPERS['cron'][0], b'PRIVATE_CHANGED_HELPER')
        with self.assertRaisesRegex(w.SwitchRefusal, 'helper SHA'):
            self.check()
        self.assertFalse(self.actions)

    def test_existing_backup_resume_marker_refuses(self):
        self.put(self.state / 'moodle-was-running.json', b'{}')
        with self.assertRaisesRegex(w.SwitchRefusal, 'resume marker'):
            self.check()

    def test_postgres_major_mismatch_refuses(self):
        self.put(self.pg / 'PG_VERSION', b'17\n')
        with self.assertRaisesRegex(w.SwitchRefusal, 'major version'):
            self.check()

    def test_symlink_and_hardlinked_data_are_not_silently_included_in_recovery_budget(self):
        path = self.data / 'linked'
        path.symlink_to(self.data / 'learning-file')
        with self.assertRaisesRegex(w.SwitchRefusal, 'link/type'):
            self.check()
        path.unlink()
        os.link(self.data / 'learning-file', path)
        with self.assertRaisesRegex(w.SwitchRefusal, 'link/type'):
            self.check()

    def test_wide_live_data_modes_are_reported_without_chmod(self):
        path = self.data / 'learning-file'
        os.chmod(path, 0o777)
        report = self.check()
        data = report['live_metadata_estimates']['moodledata']
        self.assertEqual(data['executable_regular_files'], 1)
        self.assertEqual(data['group_or_other_writable_files'], 1)
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o777)

    def test_other_container_bind_even_when_stopped_requires_scope_review(self):
        other = self.row('other', '3' * 64)
        other['State']['Running'] = False
        other['Mounts'] = [{'Type': 'bind', 'Source': str(self.code.parent)}]
        self.rows.append(other)
        with self.assertRaisesRegex(w.SwitchRefusal, 'Another container'):
            self.check()

    def test_unknown_restart_policy_refuses(self):
        self.rows[0]['HostConfig']['RestartPolicy']['Name'] = 'unknown'
        with self.assertRaisesRegex(w.SwitchRefusal, 'Restart policy'):
            self.check()

    def test_insufficient_restore_space_refuses(self):
        with mock.patch.object(w.os, 'statvfs', return_value=SimpleNamespace(f_bavail=0, f_frsize=4096, f_favail=0)):
            with self.assertRaisesRegex(w.SwitchRefusal, 'space or inode'):
                self.check()

    def test_changed_production_identity_during_check_refuses(self):
        before = self.containers()
        after = copy.deepcopy(before)
        after['ustar_moodle']['started_at'] = 'changed'
        self.p.containers = mock.Mock(side_effect=[before, after])
        with self.assertRaisesRegex(w.SwitchRefusal, 'start times changed'):
            self.check()

    def test_real_shared_lock_blocks_exclusive_capture_without_creating_or_replacing_lock(self):
        before = w.signature(self.lock.stat())
        with w.existing_shared_lock(self.p):
            fd = os.open(self.lock, os.O_RDONLY)
            try:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            finally:
                os.close(fd)
        self.assertEqual(w.signature(self.lock.stat()), before)
        with self.lock.open('rb') as stream:
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_replaced_shared_lock_is_detected_after_read(self):
        with self.assertRaisesRegex(w.SwitchRefusal, 'lock changed'):
            with w.existing_shared_lock(self.p):
                self.lock.unlink()
                self.put(self.lock, b'new')

    def test_default_plan_does_not_load_helpers_or_access_server(self):
        with mock.patch.object(w, 'load_builder', side_effect=AssertionError('no helper load')), \
                mock.patch.object(w.sys, 'argv', ['switch']), \
                mock.patch.object(w.sys, 'stdout', new_callable=io.StringIO) as output:
            self.assertEqual(w.main(), 0)
        self.assertIn('PLAN_ONLY', output.getvalue())


if __name__ == '__main__':
    unittest.main()
