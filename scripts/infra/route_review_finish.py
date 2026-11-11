#!/usr/bin/env python3
"""Finish only the already committed f879 release, probing Moodle on loopback8082."""
import argparse
import ast
import base64
from contextlib import contextmanager
import gzip
import hashlib
import importlib
import importlib.abc
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys
import traceback

ORIGINAL = Path('/root/ustar-route-review-f879822a/update.py')
ORIGINAL_SHA256 = 'a811fd52db1e8d1debdf32b07e075ed91db4220adc91ac701ae57b4782ede04c'
TOOL = 'f879822a406eefc3c65fe4a5d0c36d28bc091ba5'
APPLICATION = 'b9f170d89cead30021b0acbfba6ee681cec024f8'
MODULES = {'route_access_update', 'connect_con02_contract', 'connect_con02_state', 'connect_academy_installer'}


def private_script(path):
    parent = path.parent.lstat()
    if not (stat.S_ISDIR(parent.st_mode) and parent.st_uid == 0
            and stat.S_IMODE(parent.st_mode) == 0o700):
        raise ValueError('private_root_artifact_required')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not (stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                and stat.S_IMODE(info.st_mode) == 0o600 and info.st_size <= 2 * 1024**2):
            raise ValueError('private_root_artifact_required')
        return stream.read()


@contextmanager
def frozen_release(raw):
    if hashlib.sha256(raw).hexdigest() != ORIGINAL_SHA256:
        raise ValueError('original_script_digest')
    values = [node.value for node in ast.parse(raw).body if isinstance(node, ast.Assign)
              and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name)
              and node.targets[0].id == 'PACKED']
    if len(values) != 1:
        raise ValueError('original_bundle_missing')
    bundle = json.loads(gzip.decompress(base64.b64decode(ast.literal_eval(values[0]), validate=True)))
    manifest = bundle['manifest']
    sources = {name: base64.b64decode(value, validate=True) for name, value in bundle['sources'].items()}
    if manifest['tool_commit'] != TOOL or manifest['application_commit'] != APPLICATION:
        raise ValueError('fixed_release_required')
    if set(sources) != MODULES or set(manifest['modules']) != MODULES:
        raise ValueError('module_inventory')
    if any(hashlib.sha256(value).hexdigest() != manifest['modules'][name] for name, value in sources.items()):
        raise ValueError('module_digest')
    payload = {name: base64.b64decode(value, validate=True) for name, value in bundle['payload'].items()}

    class Embedded(importlib.abc.MetaPathFinder, importlib.abc.Loader):
        def find_spec(self, fullname, path=None, target=None):
            return importlib.util.spec_from_loader(fullname, self) if fullname in sources else None

        def create_module(self, spec):
            return None

        def exec_module(self, module):
            module.__file__ = '<ustar-route-finish/' + module.__name__ + '.py>'
            exec(compile(sources[module.__name__], module.__file__, 'exec'), module.__dict__)

    finder = Embedded()
    previous = {name: sys.modules.pop(name) for name in sources if name in sys.modules}
    sys.meta_path.insert(0, finder)
    try:
        update = importlib.import_module('route_access_update')
        update.Update(Path('/'), manifest, payload, None)
        yield update, manifest, payload
    finally:
        sys.meta_path.remove(finder)
        for name in sources:
            sys.modules.pop(name, None)
        sys.modules.update(previous)


def backend_login(host):
    status = host.run(['curl', '--silent', '--show-error', '--noproxy', '*',
                       '--connect-timeout', '3', '--max-time', '10',
                       '--output', '/dev/null', '--write-out', '%{http_code}',
                       '--header', 'Host: ustar.local',
                       'http://127.0.0.1:8082/login/index.php'], timeout=12).strip()
    host.last_login_http = status.decode('ascii') if re.fullmatch(rb'[0-9]{3}', status) else 'invalid'
    return status == b'200'


def finish_existing(operation, *, recover):
    with operation.locks(recover):
        state = operation.load()
        if not state or state['phase'] not in ('publication_committed', 'active'):
            raise ValueError('publication_not_committed')
        if recover:
            # The immutable operator retains every source/DB/control/dump/worker guard.
            return operation.finish(state)
        operation.guard(state)
        operation.verify_target(state)
        if not operation.host.login():
            raise ValueError('application_login_failed')
        if state['phase'] == 'active' and operation.host.services() != state['services']:
            raise ValueError('worker_states_not_restored')
        return {'phase': 'active' if state['phase'] == 'active' else 'ready_to_finish',
                'host_changes': False, 'local_ustar': '2026110100'}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--recover', action='store_true')
    parser.add_argument('--acknowledge-outage', action='store_true')
    args = parser.parse_args(argv)
    if args.recover != args.acknowledge_outage:
        parser.error('recover requires --acknowledge-outage; check does not accept it')
    if not (args.check or args.recover):
        print(json.dumps({'phase': 'PLAN_ONLY', 'host_access': False}))
        return 0
    log = None
    host = None
    try:
        if os.geteuid() != 0:
            raise ValueError('private_root_artifact_required')
        private_script(Path(sys.argv[0]).absolute())
        raw = private_script(ORIGINAL)
        with frozen_release(raw) as (update, manifest, payload):
            class FinishHost(update.Host):
                def login(self):
                    return backend_login(self)

            host = FinishHost(None)
            operation = update.Update(Path('/'), manifest, payload, host)
            if args.recover:
                # Do not create a new journal or bootstrap an unstarted installation.
                if not operation.base.is_dir():
                    raise ValueError('publication_not_committed')
                update.private_directory(operation.base, 0)
                fd = os.open(operation.base / 'private.log', os.O_APPEND | os.O_WRONLY | os.O_NOFOLLOW)
                info = os.fstat(fd)
                if not (stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                        and stat.S_IMODE(info.st_mode) == 0o600):
                    os.close(fd)
                    raise ValueError('unsafe_private_log')
                log = os.fdopen(fd, 'ab')
                host.log = log
            result = finish_existing(operation, recover=args.recover)
            result['login_probe'] = 'loopback8082'
            result['login_http'] = host.last_login_http
            print(json.dumps(result, sort_keys=True), flush=True)
            return 0
    except Exception as error:
        if log:
            log.write(traceback.format_exc().encode())
            log.flush()
            os.fsync(log.fileno())
        reason = str(error) if re.fullmatch('[a-z0-9_]+', str(error)) else 'route_finish_failed'
        result = {'phase': 'refused', 'reason': reason}
        if host is not None and hasattr(host, 'last_login_http'):
            result['login_http'] = host.last_login_http
        print(json.dumps(result, sort_keys=True), flush=True)
        return 2
    finally:
        if log:
            log.close()


if __name__ == '__main__':
    raise SystemExit(main())
