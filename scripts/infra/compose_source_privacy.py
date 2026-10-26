#!/usr/bin/env python3
"""Restrict the accepted Compose source to its existing deployer; no Docker mutation."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import types

CORE = Path('/usr/local/sbin/USTAR_INFRA_ONE_PASS_20261025.py')
CORE_SHA = '5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5'
SOURCE = Path('/opt/ustar/apps/stack/docker-compose.yml')
SOURCE_SHA = '41de2ed318ffb7697d92c575d1fc35ab3e6b1d16900e725aca843642d1cb7a09'
BASE = Path('/var/lib/ustar-code-hardening/compose-source')

class Refusal(ValueError):
    pass

def load_core(path=CORE):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if not (stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == 0
                and stat.S_IMODE(info.st_mode) == 0o600 and info.st_size <= 65536):
            raise Refusal('unsafe_pinned_core')
        with os.fdopen(os.dup(fd), 'rb') as stream:
            raw = stream.read(65537)
        if hashlib.sha256(raw).hexdigest() != CORE_SHA:
            raise Refusal('pinned_core_digest_drift')
    finally:
        os.close(fd)
    result = types.ModuleType('ustar_compose_privacy_pinned_core')
    result.__file__ = str(path)
    exec(compile(raw, str(path), 'exec'), result.__dict__)
    return result

def snapshot(core, source, expected_sha, expected_size, expected_owner):
    with core.opened(source) as fd:
        before = core.description(fd)
        core.require((before['uid'], before['gid']) == tuple(expected_owner)
            and before['bytes'] == expected_size, 'compose_source_owner_or_size_drift')
        with os.fdopen(os.dup(fd), 'rb') as stream:
            raw = stream.read(expected_size + 1)
        core.require(len(raw) == expected_size and core.digest(raw) == expected_sha
            and core.description(fd) == before, 'compose_source_content_drift')
    return before, raw

def image(core, path, raw, *, create=False, required=False):
    if not os.path.lexists(path):
        core.require(not required, 'compose_private_image_missing')
        if not create:
            return
        core.atomic(path, raw)
    with core.opened(path) as fd:
        row = core.description(fd)
        core.require((row['uid'], row['gid'], row['mode'], row['bytes'])
            == (0, 0, 0o600, len(raw)), 'compose_private_image_metadata_drift')
    core.require(core.read_private(path, 65536) == raw, 'compose_private_image_content_drift')

def protect(core, source, base, adapter_raw, *, action='check', expected_sha=SOURCE_SHA,
            expected_size=904, expected_owner=(1000, 1000)):
    source, base = Path(source), Path(base)
    core.require(action in ('check', 'apply', 'undo'), 'unsupported_action')
    core.require(0 < len(adapter_raw) <= 65536, 'adapter_size_bound')
    before, raw = snapshot(core, source, expected_sha, expected_size, expected_owner)
    core.require(before['mode'] in (0o644, 0o600), 'compose_source_permission_drift')
    store = core.Store(base)
    scan = {'kind': 'compose_source_privacy_v1', 'source_sha256': expected_sha,
        'source_bytes': expected_size, 'source_owner': list(expected_owner),
        'adapter_sha256': core.digest(adapter_raw), 'core_sha256': CORE_SHA}
    if os.path.lexists(base):
        core.private_dir(base)
    with store.locked(create=action != 'check'):
        state = store.load()
        if state is None:
            core.require(action != 'undo', 'compose_privacy_state_required')
            core.require(before['mode'] == 0o644, 'unowned_compose_permission_change')
        else:
            core.require(state['scan'] == scan and state['managed'] == [] and state['units'] == {}
                and state['phase'] in ('prepared', 'exports_restricted', 'restoring', 'metadata_restored')
                and len(state['exports']) == 1, 'compose_privacy_record_drift')
            row = state['exports'][0]
            core.require(row['path'] == str(source) and row['after_mode'] == 0o600
                and (row['before']['uid'], row['before']['gid'], row['before']['mode'], row['before']['bytes'])
                == (*expected_owner, 0o644, expected_size), 'compose_privacy_scope_drift')
            core.Exports(base).guard()
        for name, contents in [('compose.yml.before', raw), ('operator.py', adapter_raw)]:
            image(core, base / name, contents, create=action == 'apply' and state is None,
                required=state is not None)
        changed = False
        tx = core.Exports(base)
        if action == 'apply':
            if state is None:
                current, _ = snapshot(core, source, expected_sha, expected_size, expected_owner)
                core.require(current == before, 'compose_changed_before_preparation')
                state = tx.prepare({'rows': [{'path': str(source), 'before': before,
                    'after_mode': 0o600}], **scan})
            elif state['phase'] == 'metadata_restored':
                state['phase'] = 'prepared'
                store.save(state)
            if state['phase'] != 'exports_restricted':
                tx.apply()
                changed = before['mode'] != 0o600
        elif action == 'undo' and state['phase'] != 'metadata_restored':
            tx.undo()
            changed = before['mode'] != 0o644
        after, _ = snapshot(core, source, expected_sha, expected_size, expected_owner)
        if store.load() is not None:
            state = tx.guard()
        phase = ('ready_for_compose_source_privacy' if state is None else
            'compose_source_private' if state['phase'] == 'exports_restricted' else
            'compose_source_metadata_restored' if state['phase'] == 'metadata_restored' else
            'compose_source_recovery_required')
        return {'phase': phase, 'source_sha256': expected_sha, 'uid': after['uid'],
            'gid': after['gid'], 'mode': oct(after['mode']), 'configuration_changes': changed,
            'contents_unchanged': True, 'container_configuration_changed': False,
            'business_rows_restored': False, 'provider_called': False}

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    for name in ('check', 'apply', 'undo'):
        group.add_argument('--' + name, action='store_true')
    args = parser.parse_args(argv)
    if not any(vars(args).values()):
        print(json.dumps({'phase': 'PLAN_ONLY', 'configuration_changes': False, 'provider_called': False}))
        return 0
    try:
        if os.geteuid() != 0:
            raise Refusal('root_required')
        if os.uname().nodename != 'server1.example.com':
            raise Refusal('unexpected_host')
        core = load_core()
        raw = core.read_private(Path(__file__).absolute(), 65536)
        with core.opened(BASE.parent / 'lock', os.O_RDWR) as fd:
            row = core.description(fd)
            core.require(row['uid'] == 0 and row['mode'] == 0o600, 'unsafe_existing_hardening_lock')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise Refusal('hardening_or_companion_busy_retry_later') from None
            action = 'apply' if args.apply else 'undo' if args.undo else 'check'
            result = protect(core, SOURCE, BASE, raw, action=action)
        print(json.dumps(result, sort_keys=True))
        return 0
    except BaseException as error:
        reason = str(error) if type(error).__name__ == 'Refusal' and re.fullmatch('[A-Za-z_]{1,100}', str(error)) else type(error).__name__
        print(json.dumps({'phase': 'refused', 'reason': reason, 'business_rows_restored': False,
            'provider_called': False}))
        return 2

if __name__ == '__main__':
    sys.exit(main())
