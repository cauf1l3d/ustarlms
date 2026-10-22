#!/usr/bin/env python3
"""Durable private images and ordered nonblocking locks. No lock is ever unlinked."""
import base64
from contextlib import contextmanager, ExitStack
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import tempfile
from connect_con02_contract import APP_COMMIT, require, sha, Refusal

MAX_FILE = 16 * 1024**2
APP_PHASES = {'prepared', 'fresh_backup_complete', 'workers_stopped', 'pair_complete', 'source_activated',
              'upgrade_1', 'upgrade_2', 'cron_written', 'public_release_committed', 'app_ready',
              'baseline_restored', 'recovery_required'}
RUNTIME_PHASES = {'prepared', 'workers_stopped', 'private_files', 'include_handoff', 'pointer_switched',
                  'probed', 'runtime_active', 'runtime_disabled', 'recovery_required'}


def unique(pairs):
    value = {}
    for key, item in pairs:
        require(key not in value, 'duplicate_json_key'); value[key] = item
    return value


def sync(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)


def parents(path):
    for p in path.parents:
        if not os.path.lexists(p): continue
        st = p.lstat()
        require(stat.S_ISDIR(st.st_mode), 'symlink_or_missing_parent')


def metadata(path):
    st = path.lstat()
    return {'uid': st.st_uid, 'gid': st.st_gid, 'mode': stat.S_IMODE(st.st_mode),
            'xattrs': {k: base64.b64encode(os.getxattr(path, k, follow_symlinks=False)).decode()
                       for k in sorted(os.listxattr(path, follow_symlinks=False))}}


def capture(path):
    """Read a regular file once, or the one reviewed release symlink, with readback identity."""
    path = Path(path); parents(path)
    if not os.path.lexists(path): return {'path': str(path), 'kind': 'absent', 'raw': None}
    before = path.lstat()
    if stat.S_ISLNK(before.st_mode):
        require(path.name == 'current', 'unexpected_symlink')
        raw = os.readlink(path).encode()
        require(len(raw) < 4096, 'pointer_size')
        return {'path': str(path), 'kind': 'symlink', 'raw': raw, 'metadata': metadata(path)}
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= MAX_FILE, 'unsafe_image_source')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        with os.fdopen(fd, 'rb') as f:
            opened = os.fstat(f.fileno()); raw = f.read(MAX_FILE + 1)
            end = os.fstat(f.fileno())
        def identity(s):
            return (s.st_dev, s.st_ino, s.st_mode, s.st_nlink, s.st_uid, s.st_gid, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        require(identity(before) == identity(opened) == identity(end) == identity(path.lstat())
                and len(raw) <= MAX_FILE, 'image_source_changed')
    except BaseException: raise
    return {'path': str(path), 'kind': 'file', 'raw': raw, 'metadata': metadata(path)}


def descriptor(image):
    return {**{k: v for k, v in image.items() if k != 'raw'},
            'sha256': None if image['raw'] is None else sha(image['raw'])}


def private_directory(path, uid):
    path = Path(path)
    if not path.exists():
        parents(path)
        parent = path.parent.lstat()
        require(stat.S_ISDIR(parent.st_mode) and parent.st_uid == uid and not parent.st_mode & 0o022,
                'unsafe_state_parent')
        path.mkdir(mode=0o700); os.chmod(path, 0o700); sync(path.parent)
    parents(path)
    st = path.lstat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid == uid and not stat.S_IMODE(st.st_mode) & 0o077,
            'unsafe_state_directory')


def replace(path, raw, meta):
    path = Path(path); parents(path)
    fd, name = tempfile.mkstemp(prefix='.con02-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            os.fchown(out.fileno(), meta['uid'], meta['gid']); os.fchmod(out.fileno(), meta['mode'])
            for key, value in meta['xattrs'].items(): os.setxattr(out.fileno(), key, base64.b64decode(value, validate=True))
            out.write(raw); out.flush(); os.fsync(out.fileno())
        os.replace(name, path); sync(path.parent)
    finally:
        if os.path.exists(name): os.unlink(name)


class Journal:
    def __init__(self, ctx):
        self.ctx = ctx; self.base = ctx.engine.base; self.path = self.base / 'state.json'

    def read_private(self, path):
        path = Path(path); parents(path)
        st = path.lstat()
        require(stat.S_ISREG(st.st_mode) and st.st_uid == self.ctx.uid and st.st_nlink == 1
                and stat.S_IMODE(st.st_mode) == 0o600 and st.st_size <= MAX_FILE, 'unsafe_private_image')
        return capture(path)['raw']

    def load(self):
        if not os.path.lexists(self.path): return None
        require(self.base.stat().st_uid == self.ctx.uid and stat.S_IMODE(self.base.stat().st_mode) == 0o700,
                'unsafe_state_directory')
        s = json.loads(self.read_private(self.path), object_pairs_hook=unique)
        require(isinstance(s, dict) and set(s) == {'schema', 'tool_commit', 'app_commit', 'app', 'runtime', 'history'}
                and s['schema'] == 1 and s['tool_commit'] == self.ctx.bundle.reference['tool_commit']
                and s['app_commit'] == APP_COMMIT and isinstance(s['history'], list), 'journal_identity')
        for scope in ('app', 'runtime'):
            row = s[scope]
            if row is None: continue
            require(isinstance(row, dict) and row.get('scope') == scope
                    and re.fullmatch('[a-f0-9]{32}', row.get('generation', ''))
                    and row.get('phase') in (APP_PHASES if scope == 'app' else RUNTIME_PHASES)
                    and isinstance(row.get('files'), dict), 'journal_scope')
            for name, pair in row['files'].items():
                require(re.fullmatch('[a-z][a-z0-9_]{0,63}', name) and set(pair) == {'before', 'after'}, 'journal_file_name')
                for side, item in pair.items():
                    path = Path(item['path'])
                    require(path.is_absolute() and path.is_relative_to(self.ctx.root) and '..' not in path.parts
                            and item.get('kind') in ('absent', 'file', 'symlink'), 'journal_file_path')
                    if item['kind'] == 'absent': require(item['sha256'] is None, 'journal_absent_hash')
                    else:
                        require(re.fullmatch('[a-f0-9]{64}', item.get('sha256', '')), 'journal_image_hash')
                        require(sha(self.read_private(self.folder(row) / (name + '.' + side))) == item['sha256'],
                                'saved_image_changed')
        return s

    def folder(self, row): return self.base / (row['scope'] + '-' + row['generation'])

    def save(self, s):
        replace(self.path, (json.dumps(s, sort_keys=True) + '\n').encode(),
                {'uid': self.ctx.uid, 'gid': os.getegid(), 'mode': 0o600, 'xattrs': {}})

    def begin(self, scope, before, desired, *, generation):
        require(scope in ('app', 'runtime') and re.fullmatch('[a-f0-9]{32}', generation), 'journal_scope')
        require(isinstance(before.get('files'), dict) and set(before['files']) == set(desired['files']), 'image_inventory')
        # Validate names, paths and all current inputs before creating the private namespace.
        for name, item in before['files'].items():
            require(re.fullmatch('[a-z][a-z0-9_]{0,63}', name), 'journal_file_name')
            p = Path(item['path'])
            require(p.is_absolute() and p.is_relative_to(self.ctx.root) and '..' not in p.parts
                    and descriptor(capture(p)) == descriptor(item), 'before_image_changed')
            require(desired['files'][name]['path'] == str(p), 'after_path_changed')
        s = self.load() or {'schema': 1, 'tool_commit': self.ctx.bundle.reference['tool_commit'],
                           'app_commit': APP_COMMIT, 'app': None, 'runtime': None, 'history': []}
        previous = s[scope]
        require(previous is None or previous['phase'] in ('baseline_restored', 'runtime_disabled'), 'unfinished_generation')
        if previous: s['history'].append({'scope': scope, 'generation': previous['generation'], 'phase': previous['phase']})
        private_directory(self.base, self.ctx.uid)
        row = {k: v for k, v in before.items() if k != 'files'}
        row.update(scope=scope, phase='prepared', generation=generation, files={})
        folder = self.folder(row)
        require(not folder.exists(), 'generation_collision')
        folder.mkdir(mode=0o700); sync(self.base)
        for name, item in before['files'].items():
            row['files'][name] = {}
            for side, image in [('before', item), ('after', desired['files'][name])]:
                row['files'][name][side] = descriptor(image)
                if image['raw'] is not None:
                    replace(folder / (name + '.' + side), image['raw'],
                            {'uid': self.ctx.uid, 'gid': os.getegid(), 'mode': 0o600, 'xattrs': {}})
        s[scope] = row; self.save(s)
        return row

    def checkpoint(self, scope, phase, **fields):
        require(scope in ('app', 'runtime') and phase in (APP_PHASES if scope == 'app' else RUNTIME_PHASES), 'journal_phase')
        require(not set(fields) & {'scope', 'generation', 'files', 'phase'}, 'immutable_journal_identity')
        s = self.load(); require(s is not None and s[scope] is not None, 'journal_missing')
        s[scope].update(fields, phase=phase); self.save(s); return s[scope]

    def guard(self, scope):
        require(scope in ('app', 'runtime'), 'journal_scope')
        s = self.load(); require(s is not None and s[scope] is not None, 'journal_missing')
        for name, pair in s[scope]['files'].items():
            actual = descriptor(capture(Path(pair['before']['path'])))
            allowed = [pair['before'], pair['after']]
            if scope == 'app' and s['app'].get('public_released') and s.get('runtime') and name in s['runtime']['files']:
                allowed += list(s['runtime']['files'][name].values())
            require(actual in allowed, 'unknown_control_drift')

    def put(self, scope, name, side):
        require(side in ('before', 'after') and scope in ('app', 'runtime'), 'image_side')
        self.guard(scope)
        row = self.load()[scope]; require(name in row['files'], 'unknown_image')
        item = row['files'][name][side]; path = Path(item['path'])
        if item['kind'] == 'absent':
            if os.path.lexists(path): path.unlink(); sync(path.parent)
        else:
            raw = self.read_private(self.folder(row) / (name + '.' + side))
            if item['kind'] == 'symlink':
                tmp = path.with_name('.con02-pointer-' + row['generation'])
                require(not os.path.lexists(tmp), 'pointer_temporary_collision')
                os.symlink(raw.decode(), tmp)
                os.chown(tmp, item['metadata']['uid'], item['metadata']['gid'], follow_symlinks=False)
                os.replace(tmp, path); sync(path.parent)
            else: replace(path, raw, item['metadata'])
        require(descriptor(capture(path)) == item, 'image_write_not_effective')

    def public(self):
        s = self.load()
        if s is None: return {'phase': 'uninitialized'}
        return {scope: ({k: s[scope][k] for k in ('phase', 'generation')} if s[scope] else None)
                for scope in ('app', 'runtime')}


class Locks:
    def __init__(self, ctx):
        self.ctx = ctx; self.held = []; self.depth = 0
        self.paths = [ctx.engine.base / 'lock'] + [ctx.root / p for p in (
            'var/lib/ustar-connect-academy-update/lock', 'var/lib/ustar-connect-chat-link-update/lock',
            'var/lib/ustar-academy-https/lock', 'run/ustar-cron/lock', 'run/ustar-backup/lock', 'run/ustar-connect-install.lock')]

    @contextmanager
    def acquire(self, paths, mode, create=False):
        records = []
        try:
            for p in paths:
                if create and p == self.paths[0]:
                    private_directory(p.parent, self.ctx.uid)
                    fd = os.open(p, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
                else:
                    require(os.path.lexists(p), 'managed_lock_missing')
                    fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
                records.append((p, fd)); st = os.fstat(fd); live = p.lstat()
                require(stat.S_ISREG(st.st_mode) and st.st_uid == self.ctx.uid and st.st_nlink == 1
                        and stat.S_IMODE(st.st_mode) == 0o600 and (st.st_dev, st.st_ino) == (live.st_dev, live.st_ino),
                        'unsafe_managed_lock')
                try: fcntl.flock(fd, mode | fcntl.LOCK_NB)
                except BlockingIOError: raise Refusal('managed_operation_busy_retry_later') from None
                self.held.append(p)
            yield
            for p, fd in records:
                st, live = os.fstat(fd), p.lstat()
                require((st.st_dev, st.st_ino) == (live.st_dev, live.st_ino), 'managed_lock_replaced')
        finally:
            for p, fd in reversed(records):
                if p in self.held: self.held.remove(p)
                os.close(fd)

    @contextmanager
    def prebackup(self):
        require(self.depth == 0, 'lock_order')
        with self.acquire(self.paths[:4], fcntl.LOCK_EX, create=True):
            self.depth = 1
            try: yield
            finally: self.depth = 0

    @contextmanager
    def cold(self):
        require(self.depth == 1, 'lock_order')
        with self.acquire(self.paths[4:], fcntl.LOCK_EX):
            self.depth = 2
            try: yield
            finally: self.depth = 1

    @contextmanager
    def check(self):
        paths = ([self.paths[0]] if self.paths[0].exists() else []) + self.paths[1:]
        with self.acquire(paths, fcntl.LOCK_SH): yield
