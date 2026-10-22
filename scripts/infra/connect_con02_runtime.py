#!/usr/bin/env python3
"""Immutable worker publication; runtime transitions use a separate generation."""
import io
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import tarfile
import tempfile
from connect_con02_contract import private_failure, APP_COMMIT, require, sha
from connect_con02_state import capture, replace, sync, unique


def worker_files(bundle):
    with tarfile.open(fileobj=io.BytesIO(bundle.worker_archive), mode='r:gz') as archive:
        members = archive.getmembers()
        require(len(members) == 8 and len({m.name for m in members}) == 8
                and sum(m.size for m in members) < 32 * 1024**2, 'worker_archive_inventory')
        values = {}
        for m in members:
            require(m.isfile() and m.size <= 4 * 1024**2 and not m.name.startswith('/')
                    and str(PurePosixPath(m.name)) == m.name and '..' not in PurePosixPath(m.name).parts
                    and '\\' not in m.name, 'worker_archive_path')
            values[m.name] = archive.extractfile(m).read()
    manifest = json.loads(values.pop('manifest.json'), object_pairs_hook=unique)
    expected = {'SOURCE_COMMIT', 'receiver.pyz', 'notifications.pyz', 'admin.pyz'} | {
                'units/ustar-connect-' + n + '.service' for n in ('receiver', 'probe', 'notify')}
    require(set(values) == expected and set(manifest) == {'schema', 'source_commit', 'files'}
            and manifest['schema'] == 1 and manifest['source_commit'] == APP_COMMIT
            and values['SOURCE_COMMIT'] == (APP_COMMIT + '\n').encode()
            and manifest['files'] == {n: sha(v) for n, v in values.items()}, 'fixed_worker_required')
    return values


def stage_worker(ctx):
    values = worker_files(ctx.bundle)
    ctx.host.validate_units(values)
    parent = ctx.root / 'opt/ustar-connect/releases'
    for p in (parent, *parent.parents):
        if not p.is_relative_to(ctx.root): break
        st = p.lstat()
        require(stat.S_ISDIR(st.st_mode) and st.st_uid == ctx.uid and not st.st_mode & 0o022, 'unsafe_release_parent')
    target = parent / APP_COMMIT
    manifest = (json.dumps({'schema': 1, 'source_commit': APP_COMMIT,
                'files': {n: sha(v) for n, v in sorted(values.items())}}, sort_keys=True, indent=2) + '\n').encode()
    expected = {**values, 'manifest.json': manifest}
    def verify():
        require(not target.is_symlink() and target.is_dir() and target.stat().st_uid == ctx.uid
                and stat.S_IMODE(target.stat().st_mode) == 0o755, 'worker_release_collision')
        found = {str(p.relative_to(target)) for p in target.rglob('*') if not p.is_dir()}
        require(found == set(expected) and not any(p.is_symlink() for p in target.rglob('*')), 'worker_release_collision')
        for n, raw in expected.items():
            image = capture(target / n)
            require(image['raw'] == raw and image['metadata']['uid'] == ctx.uid
                    and image['metadata']['mode'] == 0o644 and not image['metadata']['xattrs'], 'worker_release_collision')
    if os.path.lexists(target): verify(); return target
    tmp = Path(tempfile.mkdtemp(prefix='.con02-stage-', dir=parent))
    try:
        for n, raw in expected.items():
            p = tmp / n
            if not p.parent.exists(): p.parent.mkdir(mode=0o755); p.parent.chmod(0o755)
            replace(p, raw, {'uid': ctx.uid, 'gid': os.getegid(), 'mode': 0o644, 'xattrs': {}})
        tmp.chmod(0o755); sync(tmp); os.rename(tmp, target); sync(parent); verify()
    finally:
        if tmp.exists(): shutil.rmtree(tmp)
    return target


class Runtime:
    def __init__(self, ctx, journal, locks):
        self.ctx, self.journal, self.locks = ctx, journal, locks
        self.host = ctx.host

    def row(self):
        state = self.journal.load()
        require(state is not None and state.get('runtime') is not None, 'runtime_generation_required')
        return state['runtime']

    def step(self, phase, **fields):
        row = self.journal.checkpoint('runtime', phase, **fields)
        if hasattr(self.ctx, 'progress'): self.ctx.progress(phase)
        return row

    def ready(self):
        state = self.journal.load()
        require(state is not None and isinstance(state.get('app'), dict)
                and state['app']['phase'] == 'app_ready', 'app_ready2200_required')
        self.host.application_ready(state)
        return state

    def exact(self, side):
        from connect_con02_state import descriptor
        self.journal.guard('runtime')
        for pair in self.row()['files'].values():
            require(descriptor(capture(Path(pair[side]['path']))) == pair[side], 'runtime_profile_not_effective')

    def enable(self):
        import copy
        import secrets
        import time
        import uuid
        from connect_con02_contract import https_snapshot
        from connect_con02_application import service_snapshot
        from connect_con02_profiles import profiles
        with self.locks.prebackup(), self.locks.cold():
            state = self.ready()
            self.host.controls(state)
            previous = state.get('runtime')
            if previous and previous['phase'] == 'runtime_active':
                self.exact('after'); https_snapshot(self.ctx)
                return {'phase': 'runtime_active', 'verify_only': True}
            if previous and previous['phase'] != 'runtime_disabled':
                self.journal.guard('runtime')
                self.step(previous['phase'], https=https_snapshot(self.ctx))
                return self.attempt()
            before = self.host.controls(state)
            owner = https_snapshot(self.ctx)
            # Read-only getMe/webhook/link-scope probe, never lease/send/poll.
            self.host.probe('v1')
            release = stage_worker(self.ctx)
            generation, cutoff = uuid.uuid4().hex, int(time.time()) + 1
            values = profiles(self.ctx, before, generation=generation, cutoff=cutoff,
                              notify_secret=secrets.token_hex(32).encode(), ack_secret=secrets.token_hex(32).encode())
            self.host.validate_profiles(values)
            desired = copy.deepcopy(before['files'])
            for name, raw in values.items():
                require(name in desired, 'unknown_profile_path')
                old = desired[name]
                desired[name] = {**old, 'kind': 'file', 'raw': raw,
                    'metadata': old.get('metadata', {'uid': self.ctx.uid, 'gid': os.getegid(),
                        'mode': 0o644 if name.endswith('dropin') else 0o600, 'xattrs': {}})}
            require(desired['pointer']['kind'] == 'symlink', 'managed_runtime_pointer_required')
            desired['pointer']['raw'] = str(release).encode()
            self.journal.begin('runtime', {'files': before['files'], 'cutoff': cutoff, 'https': owner,
                'fence': before['fence'], 'service_states': service_snapshot(self.host)},
                {'files': desired}, generation=generation)
            return self.attempt()

    def attempt(self):
        from connect_con02_contract import require_https_unchanged
        from connect_con02_application import stop_workers, restore_workers
        from connect_con02_state import descriptor
        try:
            row = self.row()
            self.journal.guard('runtime')
            self.host.controls(self.journal.load())
            self.step('workers_stopped')
            stop_workers(self.host)
            for name in row['files']:
                if name not in ('include', 'pointer'):
                    self.journal.put('runtime', name, 'after')
            self.step('private_files')
            self.journal.put('runtime', 'include', 'after'); self.step('include_handoff')
            self.journal.put('runtime', 'pointer', 'after'); self.step('pointer_switched')
            self.host.service('daemon-reload', '')
            self.host.probe('v2'); self.step('probed')
            # restore_workers starts only the services that were active; a disabled
            # sender stays disabled. Its normal ExecStartPre owns sender-scope proof.
            restore_workers(self.host, row['service_states'], profile='v2')
            self.exact('after'); require_https_unchanged(self.ctx, row['https'])
            self.step('runtime_active')
            return {'phase': 'runtime_active', 'ack_enabled': True, 'generation': row['generation']}
        except BaseException:
            private_failure(self.ctx)
            try: return {**self.fallback(), 'enable_succeeded': False}
            except BaseException:
                private_failure(self.ctx)
                self.step('recovery_required')
                return {'phase': 'recovery_required', 'ack_state': 'unverified'}

    def fallback(self):
        from connect_con02_contract import require_https_unchanged
        from connect_con02_application import stop_workers, restore_workers
        row = self.row()
        # All controls, not just the next file, are validated before stopping or writing.
        self.journal.guard('runtime'); self.host.controls(self.journal.load())
        require_https_unchanged(self.ctx, row['https'])
        stop_workers(self.host)
        for name in row['files']:
            if name != 'pointer': self.journal.put('runtime', name, 'before')
        self.journal.put('runtime', 'pointer', 'before')
        self.host.service('daemon-reload', '')
        restore_workers(self.host, row['service_states'], profile='v1')
        self.exact('before'); require_https_unchanged(self.ctx, row['https'])
        self.step('runtime_disabled')
        return {'phase': 'runtime_disabled', 'ack_enabled': False, 'application_restored': False}

    def disable(self):
        from connect_con02_contract import https_snapshot
        with self.locks.prebackup(), self.locks.cold():
            state = self.ready()
            if not state.get('runtime'):
                self.host.controls(state)
                return {'phase': 'runtime_disabled', 'verify_only': True}
            self.journal.guard('runtime')
            row = self.row()
            self.step(row['phase'], https=https_snapshot(self.ctx))
            if row['phase'] == 'runtime_disabled':
                self.exact('before'); return {'phase': 'runtime_disabled', 'verify_only': True}
            try: return self.fallback()
            except BaseException:
                private_failure(self.ctx)
                self.step('recovery_required')
                return {'phase': 'recovery_required', 'ack_state': 'unverified'}

    def recover(self):
        # Explicit recovery returns to the preceding v1 runtime only. A repeated
        # enable resumes the saved generation and its already generated secrets.
        return self.disable()
