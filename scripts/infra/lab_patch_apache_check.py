#!/usr/bin/env python3
"""Inspect the upgraded owned stage Apache without starting its public relay.

Default plan only. --check uses pinned installed upgrade dependencies, an existing
exclusive coordination lock and the completed transaction. One nonce-protected
built-in-only PHP probe is created in the checked stage document root, requested
through its isolated network namespace and removed with inode/content guards.
No existing code/config edits, Moodle bootstrap in the probe, SQL writes,
container/service starts/stops, cron/mail/Composer or production operations.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
from decimal import Decimal
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import signal
import stat
import sys
from types import ModuleType

UPGRADE = Path('/usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py')
UPGRADE_SHA = '452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4'
SCRIPT = Path('/usr/local/sbin/USTAR_LAB_PATCH_APACHE_CHECK_20261012.py')
SOURCE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
WORK = SOURCE / 'patch-stage-20261005'
RUN = SOURCE / 'patch-upgrade-518-20261012-xdgki_yo'
PAIR_SHA = 'd15a50027186ae6da5ae2a7a83ac74579d88399c36dcb8605ee4c0799fcff342'
ROOT_UID = 0
MAX_RESPONSE = 16384


class ApacheCheckError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise ApacheCheckError(message)


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns)


def trusted(path, directory=False):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and info.st_uid == ROOT_UID and not info.st_mode & 0o022
            and (directory or info.st_nlink == 1), 'Untrusted inspection input')
    return info


def parents(path):
    for parent in path.parents:
        trusted(parent, directory=True)


def read_pinned(path, expected):
    parents(path)
    before = trusted(path)
    require(before.st_size <= 1024 ** 2, 'Pinned helper exceeds limit')
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        require(identity(os.fstat(stream.fileno())) == identity(before), 'Pinned input replaced')
        data = stream.read(1024 ** 2 + 1)
        require(identity(os.fstat(stream.fileno())) == identity(before), 'Pinned input changed')
    require(hashlib.sha256(data).hexdigest() == expected, 'Pinned upgrade checksum differs')
    return data


def load_upgrade():
    module = ModuleType('ustar_apache_pinned_upgrade')
    exec(compile(read_pinned(UPGRADE, UPGRADE_SHA), str(UPGRADE), 'exec'), module.__dict__)
    require(module.SOURCE == SOURCE and module.WORK == WORK, 'Upgrade namespace differs')
    return module


@contextmanager
def coordination_lock(path):
    parents(path)
    before = trusted(path)
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        require(identity(os.fstat(stream.fileno())) == identity(before), 'Existing lock replaced')
        fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
        require(identity(path.lstat()) == identity(before), 'Coordination lock changed')


def probe_source(token):
    require(re.fullmatch(r'[0-9a-f]{64}', token), 'Invalid probe token')
    return ("<?php\n"
            "if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || !hash_equals('" + token +
            "', $_SERVER['HTTP_X_USTAR_PROBE'] ?? '')) { http_response_code(404); exit; }\n"
            "header('Content-Type: application/json'); header('Cache-Control: no-store');\n"
            "echo json_encode(['version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'int_size'=>PHP_INT_SIZE,"
            "'extensions'=>get_loaded_extensions(),'memory_limit'=>ini_get('memory_limit'),"
            "'sendmail_path'=>ini_get('sendmail_path'),'max_input_vars'=>ini_get('max_input_vars'),"
            "'display_errors'=>ini_get('display_errors'),'log_errors'=>ini_get('log_errors'),"
            "'auto_prepend_file'=>ini_get('auto_prepend_file'),'auto_append_file'=>ini_get('auto_append_file'),"
            "'ini_scanned'=>php_ini_scanned_files(),'bound_ini_readable'=>"
            "is_readable('/usr/local/etc/php/conf.d/zz-ustar-lab.ini')]);\n").encode()


@contextmanager
def temporary_probe(document_root, token):
    parents(document_root)
    info = trusted(document_root, directory=True)
    name = 'ustar-apache-probe-' + token + '.php'
    payload = probe_source(token)
    directory = os.open(document_root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    created = None
    complete = False
    try:
        require(os.fstat(directory).st_ino == info.st_ino
                and os.fstat(directory).st_dev == info.st_dev, 'Document root changed')
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                     0o600, dir_fd=directory)
        created = os.fstat(fd)
        try:
            offset = 0
            while offset < len(payload):
                written = os.write(fd, payload[offset:])
                require(written > 0, 'Probe write incomplete')
                offset += written
            os.fchmod(fd, 0o644)
            os.fsync(fd)
            complete = True
        finally:
            created = os.fstat(fd)
            os.close(fd)
        os.fsync(directory)
        yield name
    finally:
        handlers = {}
        if created is not None:
            # A second interrupt must not cut the owned-file cleanup in half.
            for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
                handlers[number] = signal.signal(number, signal.SIG_IGN)
        try:
            if created is not None:
                current = os.stat(name, dir_fd=directory, follow_symlinks=False)
                require(identity(current) == identity(created), 'Probe changed; replacement retained')
                with os.fdopen(os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory), 'rb') as stream:
                    content = stream.read(len(payload) + 1)
                    require(identity(os.fstat(stream.fileno())) == identity(created)
                            and (content == payload if complete else payload.startswith(content)),
                            'Probe content changed; replacement retained')
                os.unlink(name, dir_fd=directory)
                os.fsync(directory)
                require(not os.path.lexists(document_root / name), 'Owned probe cleanup incomplete')
        finally:
            for number, handler in handlers.items():
                signal.signal(number, handler)
            os.close(directory)


def request_probe(stage, state, name, token):
    require(name == 'ustar-apache-probe-' + token + '.php', 'Unexpected probe path')
    item = stage.owned('web', state)
    require(item['State']['Running'] and type(item['State']['Pid']) is int
            and item['State']['Pid'] > 1, 'Owned stage web is stopped')
    # Host Python runs only in the already checked loopback-only namespace.
    program = ("import http.client,json; c=http.client.HTTPConnection('127.0.0.1',80,timeout=10); "
               "c.request('GET'," + repr('/' + name) + ",headers={'X-Ustar-Probe':" + repr(token) + "}); "
               "r=c.getresponse(); b=r.read(" + str(MAX_RESPONSE + 1) + "); "
               "assert r.status==200 and len(b)<=" + str(MAX_RESPONSE) + ", 'bounded probe HTTP'; "
               "assert r.getheader('Content-Type','').split(';')[0]=='application/json', 'probe MIME'; "
               "v=json.loads(b); c.close(); print(json.dumps(v))")
    result = stage.command(['nsenter', '--target', str(item['State']['Pid']), '--net',
                            '/usr/bin/python3', '-I', '-B', '-c', program], timeout=20)
    require(len(result.stdout) <= MAX_RESPONSE, 'Probe response exceeds limit')
    value = json.loads(result.stdout)
    require(isinstance(value, dict), 'Probe did not return settings')
    return value


def stage_identity(stage, state):
    rows = {}
    for kind in ('pg', 'web'):
        item = stage.owned(kind, state)
        require(item['State']['Running'], 'Stage container stopped; inspection does not start it')
        rows[kind] = [item['Id'], item['Image'], item['State']['StartedAt'], item['State']['Pid']]
    return rows


def verify_completed(u, c, stage, candidate_sha):
    marker = u.validate_marker(c, stage)
    require(marker.get('phase') == 'upgrade_complete' and marker.get('run_directory') == str(RUN)
            and marker.get('baseline_manifest_sha256') == PAIR_SHA
            and marker.get('candidate_manifest_sha256') == candidate_sha,
            'Expected completed upgraded-stage transaction differs')
    return marker


def report_settings(r, observation, platform):
    required = set(platform['required_extensions'])
    extensions = observation.get('extensions')
    require(isinstance(extensions, list) and len(extensions) <= 200
            and all(isinstance(name, str) and re.fullmatch(r'[a-zA-Z0-9_ ]{1,64}', name)
                    for name in extensions), 'Invalid PHP extension list')
    loaded = {name.lower() for name in extensions}
    safe = r.safe_php_observation(observation)
    settings = {
        'apache_sapi': observation.get('sapi') == 'apache2handler',
        'memory_limit': observation.get('memory_limit') == '512M',
        'mail_blocked': observation.get('sendmail_path') == '/bin/false',
        'display_errors_off': observation.get('display_errors') in ('', '0', 'Off'),
        'log_errors_on': observation.get('log_errors') in ('1', 'On'),
        'no_auto_prepend_or_append': observation.get('auto_prepend_file') == ''
                                      and observation.get('auto_append_file') == '',
        'integer_64bit': observation.get('int_size') == 8,
        'php_minimum_82': bool(re.fullmatch(r'\d+\.\d+\.\d+', str(observation.get('version', ''))))
                          and tuple(map(int, observation['version'].split('.'))) >= (8, 2, 0),
        'input_vars_5000_or_more': str(observation.get('max_input_vars', '')).isdigit()
                                  and int(observation['max_input_vars']) >= 5000,
        'required_extensions': required <= loaded,
    }
    safe['bound_lab_ini_readable_by_apache'] = observation.get('bound_ini_readable') is True
    safe['bound_lab_ini_listed_as_scanned'] = 'zz-ustar-lab.ini' in safe['scanned_ini_basenames']
    return safe, settings, sorted(required - loaded)


def inspect_stage(u, r, c, dependencies):
    _, _, _, source, stage, _, profile = dependencies
    marker = verify_completed(u, c, stage, r.CANDIDATE_SHA)
    state = stage.load_state()
    outside_before = u.outside_identity(source)
    before = stage_identity(stage, state)
    stage.assert_offline(state)
    manifest = json.loads(c.read_checked(r.CANDIDATE / 'manifest.json', r.CANDIDATE_SHA))
    c.verify_candidate(WORK / 'site/public', manifest['entries'])
    frozen = c.load_manifest()
    controls = {}
    for name in ('lab.ini', 'apache.conf'):
        current = c.read_checked(WORK / name, frozen[name]['sha256'], maximum=8192)
        controls[name] = hashlib.sha256(current).hexdigest()
    require(c.read_checked(WORK / 'lab.ini', maximum=8192) == r.ISOLATION_INI,
            'Stage isolation INI differs')
    versions = u.database_versions(stage, state)
    require(Decimal(versions['core']) == Decimal(u.TARGET_VERSION) and versions['enabled_tasks'] == 0,
            'Upgraded stage core/tasks differ')
    addons = marker['baseline_versions']['addons']
    require(len(addons) == len(profile['additional_components']) == 57
            and all(versions['addons'].get(name) == version for name, version in addons.items()),
            'Frozen addon versions differ')
    platform = r.platform_requirements(c, manifest['entries'])
    token = secrets.token_hex(32)
    with temporary_probe(WORK / 'site/public/public', token) as name:
        observation = request_probe(stage, state, name, token)
    c.verify_candidate(WORK / 'site/public', manifest['entries'])
    for name, digest in controls.items():
        c.read_checked(WORK / name, digest, maximum=8192)
    require(u.validate_marker(c, stage) == marker, 'Stage transaction changed')
    require(stage_identity(stage, state) == before and u.outside_identity(source) == outside_before,
            'Container IDs, images, PIDs or start times changed')
    stage.assert_offline(state)
    safe, checks, missing = report_settings(r, observation, platform)
    return {'kind': 'upgraded_stage_apache_builtin_probe',
            'measured_at': datetime.now(timezone.utc).isoformat(),
            'result': 'PASS' if all(checks.values()) else 'SETTINGS_DIFFER',
            'apache_php_settings_verified': all(checks.values()), 'apache_php': safe,
            'checks': checks, 'required_php_extensions_missing': missing,
            'core_db_version': versions['core'], 'addon_versions_matching': len(addons),
            'scheduled_tasks_enabled': 0, 'lab_network': 'loopback_only',
            'temporary_probe_removed_and_code_rehashed': True,
            'existing_code_config_bytes_unchanged': True,
            'source_production_and_stage_container_identities_unchanged': True,
            'SQL_scope': 'bounded_BEGIN_READ_ONLY_stage_only',
            'probe_Moodle_bootstrap': False, 'PHP_scope': 'builtins_in_existing_Apache_handler',
            'relay_started_or_stopped': False, 'container_or_service_restart': False,
            'core_upgrade_executed': False, 'full_addon_compatibility_verified': False}


def interrupted(_number, _frame):
    raise InterruptedError('Inspection interrupted')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if not args.check:
        print('PLAN_ONLY; --check reads the upgraded stage and creates/removes one private HTTP probe')
        return 0
    try:
        require(os.geteuid() == ROOT_UID, 'Run stage inspection as root')
        os.umask(0o077)
        sys.dont_write_bytecode = True
        parents(SCRIPT)
        trusted(SCRIPT)
        u = load_upgrade()
        r = u.load_readiness()
        with coordination_lock(u.LOCK):
            for number in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
                signal.signal(number, interrupted)
            c = r.load_builder()
            result = inspect_stage(u, r, c, c.load_dependencies())
            print('LAB_PATCH_APACHE_CHECK=' + result['result'] + '; STAGE_ONLY; PROBE_REMOVED; NO_UPGRADE')
            print(json.dumps(result, sort_keys=True, indent=2))
            return 0 if result['result'] == 'PASS' else 1
    except Exception as error:
        print('LAB_PATCH_APACHE_CHECK_ERROR=' + (str(error) if isinstance(error, ApacheCheckError)
              else type(error).__name__), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
