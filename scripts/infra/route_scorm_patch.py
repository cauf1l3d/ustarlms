#!/usr/bin/env python3
"""Bounded source-only SCORM fix on accepted2900. Default: offline plan."""
import argparse
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

BASELINE = '5cc02944e1059ba015afcc7c5e8068ca56c34a92'
APPLICATION = '994bf23b0019e48428021884e744cda3cec4abc4'
WEB = 'opt/ustar/data/moodle/public/public'
CHANGED = ('local/ustar/course_launch.php', 'local/ustar/scorm_launch.php')
ADDED = ('local/ustar/classes/scorm_route_launch.php', 'local/ustar/tests/scorm_route_launch_test.php')
FILES = CHANGED + ADDED
LOCKS = ('var/lib/ustar-connect-academy-update/lock', 'var/lib/ustar-connect-chat-link-update/lock',
         'var/lib/ustar-academy-https/lock', 'run/ustar-cron/lock', 'run/ustar-backup/lock',
         'run/ustar-connect-install.lock')
EMBEDDED = None


class Refusal(ValueError):
    pass


def require(value, reason):
    if not value:
        raise Refusal(reason)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def sync(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def parents_safe(path):
    require(all(not p.is_symlink() for p in [path, *path.parents]), 'symlink_path_forbidden')


def read(path):
    parents_safe(path)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as f:
        st = os.fstat(f.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_nlink == 1 and st.st_size <= 128 * 1024**2,
                'unsafe_regular_file')
        return f.read(), {'uid': st.st_uid, 'gid': st.st_gid, 'mode': stat.S_IMODE(st.st_mode)}


def atomic(path, raw, metadata):
    parents_safe(path)
    fd, name = tempfile.mkstemp(prefix='.ustar-scorm-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(raw)
            os.fchown(f.fileno(), metadata['uid'], metadata['gid'])
            os.fchmod(f.fileno(), metadata['mode'])
            f.flush()
            os.fsync(f.fileno())
        os.replace(name, path)
        sync(path.parent)
    finally:
        if os.path.lexists(name):
            os.unlink(name)


class Host:
    def __init__(self):
        self.container_id = None

    def run(self, args, *, raw=None, timeout=120):
        result = subprocess.run(args, input=raw, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=timeout, check=False)
        require(result.returncode == 0 and len(result.stdout) <= 1024**2, 'host_command_failed')
        return result.stdout

    def identity(self):
        row = json.loads(self.run(['docker', 'inspect', '--format',
            '{"Id":{{json .Id}},"State":{"Running":{{json .State.Running}}}}', 'ustar_moodle']))
        require(self.container_id is None or self.container_id == row['Id'], 'web_container_replaced')
        self.container_id = row['Id']
        return row

    def lint(self, raw):
        require(self.container_id is not None, 'web_identity_required')
        self.run(['docker', 'exec', '-i', '-u', 'www-data', self.container_id, 'php', '-n', '-l'], raw=raw)

    def stop(self):
        require(self.container_id is not None, 'web_identity_required')
        self.run(['docker', 'stop', '--time', '30', self.container_id])

    def start(self):
        require(self.container_id is not None, 'web_identity_required')
        self.run(['docker', 'start', self.container_id])


class Patch:
    def __init__(self, root, manifest, payload, host):
        self.root, self.manifest, self.payload, self.host = Path(root), manifest, payload, host
        self.web = self.root / WEB
        self.base = self.root / ('var/lib/ustar-route-scorm-fix-' + APPLICATION[:12])
        require(manifest.get('schema') == 1 and manifest.get('baseline_commit') == BASELINE
                and manifest.get('application_commit') == APPLICATION, 'fixed_source_required')
        before, after = manifest['before'], manifest['after']
        require(set(after) == set(before) | set(ADDED)
                and {p for p in before if before[p] != after[p]} == set(CHANGED)
                and not (set(before) & set(ADDED)) and set(payload) == set(FILES), 'bounded_four_file_delta_required')
        for name in after:
            require(name.startswith(('local/ustar/', 'theme/ustar/')) and '..' not in Path(name).parts
                    and '\\' not in name, 'unsafe_source_path')
        require(all(self.image(payload[p]) == after[p] for p in FILES), 'embedded_payload_changed')

    @staticmethod
    def image(raw):
        return {'sha256': digest(raw), 'bytes': len(raw)}

    @contextmanager
    def locks(self, writing=False):
        handles = []
        try:
            paths = [self.root / p for p in LOCKS]
            if writing:
                parents_safe(self.base)
                self.base.mkdir(mode=0o700, exist_ok=True)
                st = self.base.stat()
                require(st.st_uid == os.geteuid() and stat.S_IMODE(st.st_mode) == 0o700, 'unsafe_journal_directory')
                paths.insert(0, self.base / 'lock')
            for p in paths:
                parents_safe(p)
                flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
                if writing and p == self.base / 'lock':
                    flags = os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW
                fd = os.open(p, flags, 0o600)
                handles.append(fd)
                st = os.fstat(fd)
                require(stat.S_ISREG(st.st_mode) and st.st_nlink == 1 and st.st_uid == os.geteuid()
                        and stat.S_IMODE(st.st_mode) == 0o600, 'unsafe_managed_lock')
                try:
                    fcntl.flock(fd, (fcntl.LOCK_EX if writing else fcntl.LOCK_SH) | fcntl.LOCK_NB)
                except BlockingIOError:
                    raise Refusal('managed_operation_busy_retry_later') from None
            yield
        finally:
            for fd in reversed(handles):
                os.close(fd)

    def saved(self):
        p = self.base / 'state.json'
        if not os.path.lexists(p):
            return None
        raw, metadata = read(p)
        require(metadata['uid'] == os.geteuid() and metadata['mode'] == 0o600, 'unsafe_journal')
        state = json.loads(raw)
        require(state.get('manifest') == self.manifest and set(state.get('files', {})) == set(FILES), 'journal_source_changed')
        return state

    def record(self, state, phase):
        state['phase'] = phase
        atomic(self.base / 'state.json', (json.dumps(state, sort_keys=True) + '\n').encode(),
               {'uid': os.geteuid(), 'gid': os.getegid(), 'mode': 0o600})

    def verify(self, target):
        expected = self.manifest['after' if target else 'before']
        for name, image in expected.items():
            require(self.image(read(self.web / name)[0]) == image, 'source_changed:' + name)
        if not target:
            require(all(not os.path.lexists(self.web / p) for p in ADDED), 'new_source_path_already_exists')

    def identity(self, state=None):
        row = self.host.identity()
        require(row.get('Id') and isinstance(row.get('State', {}).get('Running'), bool), 'invalid_web_identity')
        if state:
            require(row['Id'] == state['container_id'], 'web_container_replaced')
        return row

    def check(self):
        with self.locks():
            state = self.saved()
            phase = state['phase'] if state else 'ready'
            require(phase in ('ready', 'active', 'rolled_back', 'prepared', 'web_stopped', 'files_applied'), 'unknown_journal_phase')
            if phase in ('ready', 'active', 'rolled_back'):
                self.verify(phase == 'active')
                require(self.identity(state)['State']['Running'], 'web_not_running')
            else:
                phase = 'recovery_required'
            return {'phase': phase, 'host_changes': False, 'database_changed': False, 'worker_changed': False}

    def write(self, name, raw, metadata):
        atomic(self.web / name, raw, metadata)

    def rollback(self, state):
        # Validate all recovery inputs before changing service state or any file.
        restores = {}
        self.identity(state)
        for name, image in self.manifest['before'].items():
            if name not in CHANGED:
                require(self.image(read(self.web / name)[0]) == image, 'later_source_cannot_be_rolled_back')
        for name in FILES:
            row = state['files'][name]
            if row['before'] is not None:
                raw, _ = read(self.base / row['backup'])
                require(self.image(raw) == self.manifest['before'][name], 'backup_changed')
                restores[name] = raw
            path = self.web / name
            if os.path.lexists(path):
                current = self.image(read(path)[0])
                require(current == self.manifest['after'][name] or current == self.manifest['before'].get(name),
                        'later_source_cannot_be_rolled_back')
            else:
                require(name in ADDED, 'baseline_source_missing')
        if self.identity(state)['State']['Running']:
            self.host.stop()
        require(not self.identity(state)['State']['Running'], 'web_stop_not_confirmed')
        for name, row in state['files'].items():
            if row['before'] is None:
                if os.path.lexists(self.web / name):
                    (self.web / name).unlink()
                    sync((self.web / name).parent)
            else:
                self.write(name, restores[name], row['before'])
        self.verify(False)
        self.host.start()
        require(self.identity(state)['State']['Running'], 'web_start_not_confirmed')
        self.record(state, 'rolled_back')

    def apply(self):
        with self.locks(True):
            state = self.saved()
            if state and state['phase'] == 'active':
                self.verify(True)
                require(self.identity(state)['State']['Running'], 'web_not_running')
                return {'phase': 'active', 'already_applied': True}
            require(state is None or state['phase'] == 'rolled_back', 'recovery_required')
            self.verify(False)
            web = self.identity(state)
            require(web['State']['Running'], 'web_not_running')
            for raw in self.payload.values():
                self.host.lint(raw)
            state = {'manifest': self.manifest, 'container_id': web['Id'], 'files': {}}
            for i, name in enumerate(FILES):
                old = None
                if name in CHANGED:
                    raw, old = read(self.web / name)
                    backup = 'before-' + str(i) + '.php'
                    atomic(self.base / backup, raw, {'uid': os.geteuid(), 'gid': os.getegid(), 'mode': 0o600})
                state['files'][name] = {'before': old, 'backup': 'before-' + str(i) + '.php' if old else None}
            self.record(state, 'prepared')
            try:
                self.host.stop()
                require(not self.identity(state)['State']['Running'], 'web_stop_not_confirmed')
                self.record(state, 'web_stopped')
                for name in FILES:
                    metadata = state['files'][name]['before'] or {
                        'uid': os.geteuid(), 'gid': (self.web / name).parent.stat().st_gid, 'mode': 0o644}
                    self.write(name, self.payload[name], metadata)
                self.verify(True)
                self.record(state, 'files_applied')
                self.host.start()
                require(self.identity(state)['State']['Running'], 'web_start_not_confirmed')
                self.record(state, 'active')
            except Exception:
                self.rollback(state)
                raise
            return {'phase': 'active', 'database_changed': False, 'worker_changed': False}

    def recover(self):
        with self.locks(True):
            state = self.saved()
            require(state is not None, 'journal_missing')
            if state['phase'] == 'rolled_back':
                self.verify(False)
                require(self.identity(state)['State']['Running'], 'web_not_running')
                return {'phase': 'rolled_back', 'already_recovered': True}
            self.rollback(state)
            return {'phase': 'rolled_back', 'database_changed': False, 'worker_changed': False}


def main(argv=None):
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--upgrade', action='store_true')
    mode.add_argument('--recover', action='store_true')
    parser.add_argument('--acknowledge-outage', action='store_true')
    args = parser.parse_args(argv)
    if not (args.check or args.upgrade or args.recover):
        print(json.dumps({'phase': 'PLAN_ONLY', 'host_access': False, 'source_only': True,
                          'baseline': BASELINE, 'application': APPLICATION, 'files': list(FILES)}))
        return 0
    try:
        require(os.geteuid() == 0, 'root_required')
        require(EMBEDDED is not None, 'use_built_verified_package')
        require(args.check or args.acknowledge_outage, 'outage_acknowledgement_required')
        raw, metadata = read(Path(__file__).absolute())
        require(metadata['uid'] == 0 and metadata['mode'] == 0o600, 'private_root_script_required')
        manifest = EMBEDDED['manifest']
        payload = {p: base64.b64decode(v, validate=True) for p, v in EMBEDDED['payload'].items()}
        tx = Patch(Path('/'), manifest, payload, Host())
        result = tx.check() if args.check else tx.apply() if args.upgrade else tx.recover()
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception as e:
        reason = str(e) if isinstance(e, Refusal) else 'operation_failed_check_and_recover'
        print(json.dumps({'phase': 'refused', 'reason': reason}, sort_keys=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())
