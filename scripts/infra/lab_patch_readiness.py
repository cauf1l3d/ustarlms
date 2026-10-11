#!/usr/bin/env python3
"""Read the accepted candidate and existing offline stage; never activate/upgrade.

Default plan only. --check re-hashes the accepted code and preserved extra file,
checks frozen overlays/config, inspects retained container identities, reads PHP
built-ins (no Moodle bootstrap) and bounded READ ONLY stage SQL. No files are
created, no HTTP/Composer/custom PHP helpers, no container or configuration writes.
A PASS covers this preflight only, not full-addon runtime or paired rollback.
--diagnose-php reads existing stage PHP settings and literal adapter overrides;
no candidate tree rehash or SQL, and never reports readiness PASS.
"""
import argparse
import ast
from datetime import datetime, timezone
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import xml.etree.ElementTree as ET

BUILDER = Path('/usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261012.py')
BUILDER_SHA = 'bb71ddd01566341d506a560628453bdcec045de89af7f4a80774a205f2f059c2'
SOURCE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
CANDIDATE = SOURCE / 'patch-candidate-518-20261012-0v8ncwgu'
CANDIDATE_SHA = '07dfa485c3118d4b72d6f47f1de4c7bbb421b22a02bb5a3f25c1f8634f7f459a'
LOCK = Path('/run/ustar-restore-lab-20261004.lock')
MIB = 1024 ** 2
ROOT_UID = 0


class ReadinessError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise ReadinessError(message)


def guarded(call, message):
    try:
        return call()
    except Exception:
        raise ReadinessError(message) from None


def trusted(path, directory=False):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and info.st_uid == ROOT_UID and not info.st_mode & 0o022
            and (directory or info.st_nlink == 1), 'Untrusted readiness input metadata')
    return info


def trusted_parents(path):
    for parent in path.parents:
        trusted(parent, directory=True)


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns)


def read_checked(path, expected_sha):
    info = trusted(path)
    require(info.st_size <= MIB, 'Readiness dependency exceeds limit')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        require(signature(os.fstat(stream.fileno())) == signature(info), 'Readiness input identity changed')
        data = stream.read(MIB + 1)
        require(len(data) == info.st_size and signature(os.fstat(stream.fileno())) == signature(info),
                'Readiness input changed while reading')
    require(hashlib.sha256(data).hexdigest() == expected_sha, 'Pinned builder checksum differs')
    return data


def load_builder():
    trusted_parents(BUILDER)
    data = read_checked(BUILDER, BUILDER_SHA)
    spec = importlib.util.spec_from_file_location('ustar_readiness_pinned_builder', BUILDER)
    builder = importlib.util.module_from_spec(spec)
    exec(compile(data, str(BUILDER), 'exec'), builder.__dict__)
    require(builder.SOURCE == SOURCE and builder.LOCK == LOCK,
            'Pinned candidate builder namespace differs')
    return builder


def load_acceptance(c, profile):
    require(CANDIDATE.parent == SOURCE, 'Candidate outside retained source namespace')
    c.trusted_parents(CANDIDATE)
    info = c.trusted(CANDIDATE, directory=True, private=True)
    require(info.st_gid == c.ROOT_GID and stat.S_IMODE(info.st_mode) == 0o700,
            'Candidate root permissions differ')
    for name in ('manifest.json', 'report.json'):
        info = c.trusted(CANDIDATE / name, private=True)
        require(info.st_gid == c.ROOT_GID and stat.S_IMODE(info.st_mode) == 0o600,
                'Candidate acceptance permissions differ')
    manifest = json.loads(c.read_checked(CANDIDATE / 'manifest.json', CANDIDATE_SHA))
    require(manifest.get('schema_version') == 1 and manifest.get('kind') == 'separate_patch_candidate_code'
            and manifest.get('complete') is True and manifest.get('archive_sha256') == c.ARCHIVE_SHA
            and manifest.get('upstream_commit') == c.UPSTREAM
            and manifest.get('checkpoint_manifest_sha256') == c.MANIFEST_SHA,
            'Accepted candidate provenance differs')
    inventory, preserved = manifest.get('entries'), manifest.get('preserved_extra_entries')
    require(isinstance(inventory, dict) and 0 < len(inventory) <= 100000
            and isinstance(preserved, dict) and set(preserved) == set(c.PRESERVE_SEPARATELY),
            'Unsupported candidate inventory')
    report = json.loads(c.read_checked(CANDIDATE / 'report.json'))
    expected = {'kind': 'full_addon_patch_candidate_code_only', 'candidate_directory': str(CANDIDATE),
                'candidate_manifest_sha256': CANDIDATE_SHA, 'candidate_entries': len(inventory),
                'core_version': '2025100608.00', 'archive_sha256': c.ARCHIVE_SHA,
                'upstream_commit': c.UPSTREAM, 'candidate_activated': False, 'core_upgrade_executed': False,
                'containers_mutated': False, 'PHP_SQL_executed': False, 'root_vendor_copied': False,
                'candidate_file_readback_hashes': 'PASS', 'preserved_extra_file_readback_hashes': 'PASS',
                'addon_roots_preserved': len(profile['additional_roots']),
                'addon_metadata_matching': len(profile['additional_components']),
                'ustar_matching_files': len(profile['application_files']),
                'extra_files_preserved_separately': list(c.PRESERVE_SEPARATELY)}
    require(all(report.get(key) == value for key, value in expected.items()),
            'Accepted code-only candidate report differs')
    return inventory, preserved, report


def verify_tree(c, root, inventory):
    """All entries, modes, owners, single links and hashes; deliberately no fsync."""
    first = c.trusted(root, directory=True)
    actual = set()
    def failed(_):
        raise ReadinessError('Candidate scan incomplete')
    for base, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs + files:
            path = Path(base) / name
            rel = path.relative_to(root).as_posix()
            c.relative(rel)
            actual.add(rel)
            require(rel in inventory, 'Unexpected entry in accepted candidate')
            expected = inventory[rel]
            require(isinstance(expected, dict) and expected.get('type') in ('file', 'directory'),
                    'Invalid accepted candidate entry')
            info = c.trusted(path, directory=expected['type'] == 'directory')
            require(c.metadata(info, expected['type'] == 'directory', expected.get('sha256')) == expected
                    and info.st_dev == first.st_dev, 'Candidate metadata or filesystem differs')
            if expected['type'] == 'file':
                c.read_checked(path, expected['sha256'], maximum=max(expected['size'], MIB))
    require(actual == set(inventory), 'Accepted candidate entries missing')
    require(signature(root.lstat()) == signature(first), 'Candidate root changed during scan')


def platform_requirements(c, inventory):
    code = CANDIDATE / 'code'
    for name, digest in c.CORE_HASHES.items():
        require(inventory[name]['sha256'] == digest, 'Stock core control hash differs')
    composer = json.loads(c.read_checked(code / 'composer.json', c.CORE_HASHES['composer.json']))
    requires = composer.get('require')
    require(isinstance(requires, dict) and requires.get('php') == '>=8.2.0'
            and all(key == 'php' or re.fullmatch(r'ext-[a-z0-9_]+', key) for key in requires),
            'Unsupported stock production Composer requirements; do not install blindly')
    require(not any(rel == 'vendor' or rel.startswith('vendor/') for rel in inventory),
            'Unexpected root vendor in accepted stock candidate')
    path = 'public/admin/environment.xml'
    root = ET.fromstring(c.read_checked(code / path, inventory[path]['sha256']))
    versions = [item for item in root.findall('MOODLE') if item.get('version') == '5.1']
    require(len(versions) == 1, 'Missing unique Moodle 5.1 environment requirements')
    version = versions[0]
    require(version.find('PHP').get('version') == '8.2.0', 'Stock PHP minimum differs')
    postgres = [item.get('version') for item in version.findall('DATABASE/VENDOR')
                if item.get('name') == 'postgres']
    require(postgres == ['15'], 'Stock PostgreSQL minimum differs')
    required = {key[4:] for key in requires if key.startswith('ext-')}
    optional = set()
    for item in version.findall('PHP_EXTENSIONS/PHP_EXTENSION'):
        name = item.get('name')
        require(re.fullmatch(r'[a-z0-9_]+', name or ''), 'Unsupported extension name')
        (required if item.get('level') == 'required' else optional).add(name)
    required.add('pgsql')
    return {'php_minimum': '8.2.0', 'postgres_minimum_major': 15,
            'required_extensions': sorted(required), 'optional_extensions': sorted(optional)}


def addon_composer_inventory(c, inventory, profile):
    """Report declarations only; no Composer resolver or plugin bootstrap."""
    found = []
    for root in profile['additional_roots']:
        path = root + '/composer.json'
        if path not in inventory:
            continue
        value = json.loads(c.read_checked(CANDIDATE / 'code' / path, inventory[path]['sha256']))
        require(isinstance(value, dict), 'Unsupported addon Composer metadata')
        requirements = value.get('require', {})
        require(isinstance(requirements, dict), 'Unsupported addon Composer requirements')
        keys = list(requirements)
        require(all(isinstance(key, str) for key in keys), 'Invalid addon requirement keys')
        found.append({'addon_root': root, 'production_requirement_count': len(keys),
                      'php_requirement_declared': 'php' in keys,
                      'extension_requirement_count': sum(key.startswith('ext-') for key in keys),
                      'package_requirement_count': sum(key != 'php' and not key.startswith('ext-') for key in keys),
                      'vendor_directory_preserved': root + '/vendor' in inventory,
                      'composer_lock_preserved': root + '/composer.lock' in inventory,
                      'dependency_resolution_verified': False})
    return found


def observe_php(stage, state):
    # Literal built-ins only; candidate/config/plugin files are never required.
    php_code = ('echo json_encode(["version"=>PHP_VERSION,"sapi"=>PHP_SAPI,"int_size"=>PHP_INT_SIZE,'
                '"extensions"=>get_loaded_extensions(),"memory_limit"=>ini_get("memory_limit"),'
                '"max_input_vars"=>ini_get("max_input_vars"),"sendmail_path"=>ini_get("sendmail_path"),'
                '"ini_loaded"=>php_ini_loaded_file(),"ini_scanned"=>php_ini_scanned_files()]);')
    php = json.loads(guarded(lambda: stage.lab_exec('web', state,
        ['php', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-r', php_code],
        user='33:33').stdout, 'Existing offline stage PHP built-in read failed'))
    require(isinstance(php, dict), 'Invalid stage PHP built-in result')
    return php


def safe_php_observation(php):
    """Never print an arbitrary sendmail command, INI path or malformed value."""
    def literal(key, pattern):
        value = php.get(key)
        return value if isinstance(value, str) and re.fullmatch(pattern, value) else 'REDACTED_NONSTANDARD'
    sendmail = php.get('sendmail_path')
    known = ('/bin/false', '/usr/sbin/sendmail', '/usr/sbin/sendmail -t -i', 'sendmail -t -i')
    ini = php.get('ini_scanned')
    basenames = []
    if isinstance(ini, str):
        for value in ini.split(',')[:100]:
            name = value.strip().rsplit('/', 1)[-1]
            basenames.append(name if re.fullmatch(r'[a-zA-Z0-9_.+-]{1,128}\.ini', name)
                             else 'REDACTED_NONSTANDARD')
    return {'version': literal('version', r'\d+\.\d+\.\d+'),
            'sapi': literal('sapi', r'[a-zA-Z0-9_]{1,32}'),
            'int_size': php.get('int_size') if type(php.get('int_size')) is int else None,
            'memory_limit': literal('memory_limit', r'(?:-1|\d{1,12}[KMGkmg]?)'),
            'max_input_vars': literal('max_input_vars', r'\d{1,12}'),
            'sendmail_path': sendmail if isinstance(sendmail, str) and sendmail in known
                             else 'REDACTED_NONSTANDARD',
            'sendmail_path_matches_bin_false': sendmail == '/bin/false',
            'scanned_ini_basenames': basenames}


def adapter_literal_overrides(c, resume):
    """Only AST literals in the SHA-pinned function; no private function source."""
    c.trusted_parents(resume.ENGINE)
    tree = ast.parse(c.read_checked(resume.ENGINE, resume.ENGINE_SHA, maximum=MIB))
    functions = [node for node in ast.walk(tree) if isinstance(node, ast.FunctionDef) and node.name == 'lab_exec']
    # A wrapped/delegated adapter may not have one definition in this file. The
    # engine pin still applies; unknown literal analysis must not hide the live
    # built-in observation or be misreported as absence of overrides.
    if len(functions) != 1:
        return []
    values = set()
    for node in ast.walk(functions[0]):
        if isinstance(node, ast.Constant) and isinstance(node.value, str):
            if re.fullmatch(r'memory_limit=(?:-1|\d{1,12}[KMGkmg]?)', node.value):
                values.add(node.value)
            elif node.value == 'sendmail_path=/bin/false':
                values.add(node.value)
    return sorted(values)


def diagnose_php(c):
    _, _, resume, source, stage, template, profile = c.load_dependencies()
    before = c.inspect_labs(source, stage)
    load_acceptance(c, profile)
    frozen = c.load_manifest()
    c.prepare_config(resume, source, template, frozen)
    expected_ini = b'memory_limit=512M\nsendmail_path=/bin/false\ndisplay_errors=Off\nlog_errors=On\n'
    require(c.read_checked(c.CHECKPOINT / 'lab.ini', frozen['lab.ini']['sha256'], maximum=8192) == expected_ini,
            'Frozen isolation INI differs; no diagnostic PHP executed')
    c.trusted_parents(c.WORK / 'lab.ini')
    c.trusted(c.WORK / 'lab.ini', private=True)
    require(c.read_checked(c.WORK / 'lab.ini', maximum=8192) == expected_ini,
            'Stage lab.ini differs from frozen isolation INI; no diagnostic PHP executed')
    overrides = adapter_literal_overrides(c, resume)
    state = stage.load_state()
    owned = stage.owned('web', state)
    mounts = [item for item in owned.get('Mounts', []) if item.get('Source') == str(c.WORK / 'lab.ini')]
    php = observe_php(stage, state)
    scanned = {path.strip() for path in php.get('ini_scanned', '').split(',')} \
        if isinstance(php.get('ini_scanned'), str) else set()
    observation = safe_php_observation(php)
    require(c.inspect_labs(source, stage) == before, 'Container identities changed during PHP diagnostic')
    require(c.read_checked(c.WORK / 'lab.ini', maximum=8192) == expected_ini,
            'Stage isolation INI changed during PHP diagnostic')
    c.read_checked(CANDIDATE / 'manifest.json', CANDIDATE_SHA)
    return {'kind': 'offline_stage_php_settings_diagnostic', 'measured_at': datetime.now(timezone.utc).isoformat(),
            'expected_stage_ini': {'memory_limit': '512M', 'sendmail_path': '/bin/false'},
            'stage_ini_matches_frozen': True, 'stage_ini_bind_count': len(mounts),
            'stage_ini_bind_read_only': len(mounts) == 1 and mounts[0].get('RW') is False,
            'stage_ini_bind_listed_as_scanned': len(mounts) == 1 and mounts[0].get('Destination') in scanned,
            'php_observed': observation, 'lab_exec_literal_overrides': overrides,
            'adapter_review_scope': 'AST literals only; not final argv/precedence; empty list does not exclude other overrides',
            'memory_limit_matches_stage_ini': php.get('memory_limit') == '512M',
            'sendmail_path_matches_stage_ini': php.get('sendmail_path') == '/bin/false',
            'read_only': True, 'SQL_executed': False, 'moodle_bootstrap_executed': False,
            'candidate_tree_rehashed': False, 'candidate_activated': False, 'core_upgrade_executed': False,
            'containers_mutated': False, 'production_containers_unchanged': True,
            'readiness_accepted': False}


def runtime_checks(stage, state, p, profile, requirements):
    php = observe_php(stage, state)
    require(php.get('version') == profile['patch_runtime']['php_version']
            and php.get('sapi') == 'cli' and php.get('int_size') == 8,
            'Stage PHP version/SAPI/64-bit differs from selected runtime')
    require(isinstance(php.get('extensions'), list)
            and all(isinstance(name, str) for name in php['extensions']), 'Invalid PHP extension result')
    loaded = {name.lower() for name in php['extensions']}
    missing = sorted(set(requirements['required_extensions']) - loaded)
    require(not missing, 'Required core PHP extensions missing: ' + ','.join(missing))
    if php.get('memory_limit') != '512M' or php.get('sendmail_path') != '/bin/false':
        observation = safe_php_observation(php)
        print('LAB_PATCH_PHP_OBSERVED=' + json.dumps(observation, sort_keys=True), flush=True)
        raise ReadinessError('Offline stage PHP memory/email settings differ; memory_limit='
                             + observation['memory_limit'] + '; sendmail_matches_bin_false='
                             + str(observation['sendmail_path_matches_bin_false']))
    guarded(lambda: p.readonly_database(stage, state, profile), 'Stage original-core/addon/disabled-task read failed')
    query = ("BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; SET LOCAL lock_timeout='3s'; "
             "SELECT current_setting('server_version_num'); COMMIT;")
    pg = guarded(lambda: stage.sql(state, query).strip(), 'Bounded stage PostgreSQL version read failed')
    require(pg == profile['patch_runtime']['postgres_version_num'],
            'Stage PostgreSQL differs from selected immutable runtime')
    return {'php_cli': safe_php_observation(php),
            'required_php_extensions': 'PASS', 'optional_php_extensions_missing':
            sorted(set(requirements['optional_extensions']) - loaded), 'postgres_version_num': pg,
            'stage_original_core_db_and_addon_versions': 'PASS', 'stage_enabled_tasks': 0,
            'apache_php_settings_verified': False}


def check(c):
    dependencies = c.load_dependencies()
    _, p, resume, source, stage, template, profile = dependencies
    before = c.inspect_labs(source, stage)
    inventory, preserved, report = load_acceptance(c, profile)
    frozen = c.load_manifest()
    selected = c.selected_entries(frozen, profile['additional_roots'])
    c.validate_overlay(frozen, selected, profile, source)
    expected_config = c.prepare_config(resume, source, template, frozen)
    for name, mode in (('code', 0o755), ('preserved-extra-files', 0o700)):
        info = c.trusted(CANDIDATE / name, directory=True)
        require(info.st_gid == c.ROOT_GID and stat.S_IMODE(info.st_mode) == mode
                and info.st_dev == CANDIDATE.stat().st_dev, 'Candidate tree root permissions/filesystem differ')
    verify_tree(c, CANDIDATE / 'code', inventory)
    verify_tree(c, CANDIDATE / 'preserved-extra-files', preserved)
    require(c.read_checked(CANDIDATE / 'code/config.php') == expected_config,
            'Candidate offline config/homepage differs')
    for rel, entry in selected.items():
        if entry['type'] == 'directory':
            continue
        sidecar = rel in c.PRESERVE_SEPARATELY
        require((preserved if sidecar else inventory).get(rel) == entry,
                'Frozen addon/extra file differs in accepted candidate')
        path = CANDIDATE / ('preserved-extra-files' if sidecar else 'code') / rel
        original = c.CHECKPOINT / 'site/public' / rel
        require((path.stat().st_dev, path.stat().st_ino) != (original.stat().st_dev, original.stat().st_ino),
                'Overlay inode is shared with immutable checkpoint')
    original_vendor = any(key == c.PREFIX + 'vendor' or key.startswith(c.PREFIX + 'vendor/') for key in frozen)
    require(report.get('original_root_vendor_present_in_checkpoint') == original_vendor,
            'Original vendor receipt differs')
    requirements = platform_requirements(c, inventory)
    addon_composer = addon_composer_inventory(c, inventory, profile)
    fs = os.statvfs(SOURCE)
    free = shutil.disk_usage(SOURCE).free
    require(free >= c.RESERVE and fs.f_favail >= 1000, 'Space/inode reserve is exhausted')
    runtime = runtime_checks(stage, stage.load_state(), p, profile, requirements)
    require(c.inspect_labs(source, stage) == before,
            'Source/stage/production container identity or start time changed during readiness check')
    require(c.prepare_config(resume, source, template, frozen) == expected_config,
            'Baseline offline configuration changed during readiness check')
    c.read_checked(CANDIDATE / 'manifest.json', CANDIDATE_SHA)
    c.read_checked(c.CHECKPOINT / 'manifest.json', c.MANIFEST_SHA)
    return {'kind': 'accepted_candidate_readonly_platform_preflight',
            'measured_at': datetime.now(timezone.utc).isoformat(), 'result': 'PASS',
            'candidate_directory': str(CANDIDATE), 'candidate_manifest_sha256': CANDIDATE_SHA,
            'candidate_entries': len(inventory), 'candidate_file_readback_hashes': 'PASS',
            'preserved_extra_file_readback_hashes': 'PASS', 'candidate_offline_config_static': 'PASS',
            'addon_roots_preserved': len(profile['additional_roots']),
            'addon_metadata_matching': len(profile['additional_components']),
            'ustar_matching_files': len(profile['application_files']),
            'core_platform_requirements': requirements, 'runtime': runtime,
            'addon_root_composer_declarations': addon_composer,
            'original_root_vendor_present_in_checkpoint': original_vendor,
            'root_vendor_present_in_candidate': False, 'composer_executed': False,
            'read_only': True, 'PHP_execution_scope': 'builtins_in_existing_stage_no_Moodle_or_candidate_bootstrap',
            'SQL_execution_scope': 'existing_stage_bounded_BEGIN_READ_ONLY_only',
            'available_free_bytes': free, 'available_free_inodes': fs.f_favail,
            'minimum_free_after_bytes': c.RESERVE, 'activation_database_growth_budget_included': False,
            'paired_restore_space_verified': False, 'candidate_activated': False,
            'core_upgrade_executed': False, 'containers_mutated': False,
            'production_containers_unchanged': True, 'lab_network': 'loopback_only',
            'runtime_dependency_compatibility_verified': False, 'full_addon_compatibility_verified': False,
            'custom_home_runtime_verified': False, 'patch_upgrade_rollback_verified': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--diagnose-php', action='store_true')
    args = parser.parse_args()
    if not args.check and not args.diagnose_php:
        print('PLAN_ONLY; --check or --diagnose-php reads only; no activation/upgrade')
        return 0
    try:
        require(os.geteuid() == ROOT_UID, 'Run explicit readiness check as root')
        trusted_parents(LOCK)
        info = trusted(LOCK)
        fd = os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb'):
            require(signature(os.fstat(fd)) == signature(info), 'Existing coordination lock identity changed')
            fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
            c = load_builder()
            report = diagnose_php(c) if args.diagnose_php else check(c)
            require(signature(LOCK.lstat()) == signature(info), 'Coordination lock replaced during check')
            print('LAB_PATCH_PHP_DIAGNOSTIC=COMPLETE; NOT_ACTIVATED; NO_SQL; NO_CORE_UPGRADE'
                  if args.diagnose_php else 'LAB_PATCH_READINESS=PASS; NOT_ACTIVATED; NO_CORE_UPGRADE', flush=True)
            print(json.dumps(report, sort_keys=True, indent=2), flush=True)
        return 0
    except Exception as error:
        message = str(error) if isinstance(error, ReadinessError) else type(error).__name__
        print('LAB_PATCH_READINESS_ERROR=' + message, file=sys.stderr, flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
