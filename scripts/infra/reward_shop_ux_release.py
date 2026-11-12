#!/usr/bin/env python3
"""Build or apply the reward storefront update to the installed Reward Shop V3 release."""
import argparse
import gzip
import io
import json
import os
from pathlib import Path
import re
import stat
import sys
import tarfile
import tempfile
import types

CORE_SOURCE = None
CORE_COMMIT = '86d5fbb8acc24157da4d47317bd22bc3d94e9498'
CORE_SHA256 = 'e1f903d315f1c33b46eecbfb5e26b7e2325e01d710255adaff74e8a848397e51'
BASE = 'a7a34b4927ffd42db28a8d76e410d20a37d7a434'
VERSION = 2026111100
THEME_VERSION = 2026110200
OPERATOR = 'scripts/infra/reward_shop_ux_release.py'
INSTALLED = Path('/var/lib/ustar-reward-shop-2026111100-cli-fix-v2/state.json')
STATE = Path('/var/lib/ustar-reward-shop-ux-20261112')
MAINTENANCE = Path('/opt/ustar/data/moodle/moodledata/climaintenance.html')
CHANGES = frozenset((
    'classes/reward_shop.php', 'classes/reward_shop_cover.php', 'lang/en/local_ustar.php', 'lang/ru/local_ustar.php',
    'lib.php', 'reward_shop.php', 'reward_shop_admin.php', 'styles/reward_shop.css',
    'templates/reward_shop.mustache', 'templates/reward_shop_admin.mustache', 'tests/reward_shop_test.php',
))
ADDED = frozenset(('classes/reward_shop_cover.php', 'styles/reward_shop.css'))


def engine(source):
    import hashlib
    if hashlib.sha256(source).hexdigest() != CORE_SHA256:
        raise ValueError('frozen_reward_shop_v3_changed')
    module = types.ModuleType('_ustar_reward_shop_v3')
    exec(compile(source, '<qualified-reward-shop-v3>', 'exec'), module.__dict__)
    return module


def build(repo, target, output):
    import subprocess
    read = lambda ref: subprocess.check_output(['git', '-C', str(repo), 'show', ref], timeout=30)
    core_bytes = read(CORE_COMMIT + ':scripts/infra/reward_shop_release.py')
    core = engine(core_bytes)
    need = core.require
    need(re.fullmatch(r'[a-f0-9]{40}', target) and core.git(repo, 'rev-parse', target + '^{commit}') == target,
         'exact_target_required')
    need(core.git(repo, 'merge-base', BASE, target) == BASE, 'wrong_application_ancestry')
    source = Path(__file__).read_bytes()
    need(read('HEAD:' + OPERATOR) == source, 'builder_not_committed_or_dirty')

    with tempfile.TemporaryDirectory(prefix='ustar-reward-shop-ux-') as folder:
        folder = Path(folder)
        core.archive(repo, BASE, folder / 'before')
        core.archive(repo, target, folder / 'after')
        before = core.scan(folder / 'before' / core.APP_REL)
        after = core.scan(folder / 'after' / core.APP_REL)
        theme = core.scan(folder / 'after' / core.THEME_REL)
        need(theme == core.scan(folder / 'before' / core.THEME_REL), 'theme_changed')
        need(set(after) == set(before) | ADDED, 'unexpected_source_inventory')
        need({path for path in after if after[path] != before.get(path)} == CHANGES, 'unexpected_source_delta')
        payload = {}
        for path in sorted(CHANGES):
            candidate = folder / 'after' / core.APP_REL / path
            if path.endswith('.php'):
                core.run(['php', '-l', str(candidate)], timeout=15)
            payload['payload/' + path] = candidate.read_bytes()
    manifest = {
        'schema': 1, 'baseline_commit': BASE, 'target_commit': target, 'before': before, 'after': after,
        'theme': theme, 'changed': sorted(CHANGES), 'local_ustar': VERSION, 'theme_ustar': THEME_VERSION,
        'schema_changed': False, 'business_data_changed': False, 'worker_changed': False,
    }
    script = source.replace(b'CORE_SOURCE = None', b'CORE_SOURCE = ' + repr(core_bytes).encode(), 1)
    compile(script, '<reward-shop-ux-operator>', 'exec')
    members = {'update.py': script, 'INSTALL.json': (json.dumps(manifest, sort_keys=True, indent=2) + '\n').encode(), **payload}
    members['SHA256SUMS'] = ''.join(f'{core.digest(raw)}  {name}\n' for name, raw in sorted(members.items())).encode()
    output.mkdir(parents=True, exist_ok=True)
    result = output / f'ustar-reward-shop-ux-{target[:8]}.tar.gz'
    with result.open('wb') as raw, gzip.GzipFile(fileobj=raw, mode='wb', filename='', mtime=0) as zipped:
        with tarfile.open(fileobj=zipped, mode='w') as tar:
            for name, content in sorted(members.items()):
                entry = tarfile.TarInfo(name)
                entry.mode, entry.size = 0o600, len(content)
                tar.addfile(entry, io.BytesIO(content))
    return {'archive': str(result), 'sha256': core.digest(result), 'target_commit': target, 'files': len(CHANGES)}


def load_package(core, folder):
    names = {'update.py', 'INSTALL.json'} | {'payload/' + name for name in CHANGES}
    verified = set()
    for line in core.safe_path(folder, 'SHA256SUMS').read_text().splitlines():
        sha, separator, name = line.partition('  ')
        core.require(separator and name in names - verified and re.fullmatch(r'[a-f0-9]{64}', sha),
                     'invalid_checksum_list')
        core.require(core.digest(core.safe_path(folder, name)) == sha, 'package_digest_mismatch:' + name)
        verified.add(name)
    core.require(verified == names, 'incomplete_package')
    manifest = json.loads((folder / 'INSTALL.json').read_bytes())
    core.require(manifest['schema'] == 1 and manifest['baseline_commit'] == BASE
                 and manifest['local_ustar'] == VERSION and manifest['theme_ustar'] == THEME_VERSION,
                 'unexpected_manifest_identity')
    before, after = manifest['before'], manifest['after']
    core.require(set(manifest['changed']) == CHANGES and set(after) == set(before) | ADDED
                 and {path for path in after if before.get(path) != after[path]} == CHANGES, 'unexpected_manifest_delta')
    for path in CHANGES:
        core.require(core.digest(folder / 'payload' / path) == after[path], 'payload_digest_mismatch:' + path)
    return manifest


def private_state(core, folder, *, required=False):
    path = folder / 'state.json'
    if not os.path.lexists(folder):
        core.require(not required, 'reward_shop_v3_installation_required')
        return None
    mode = folder.lstat()
    core.require(stat.S_ISDIR(mode.st_mode) and mode.st_uid == os.geteuid()
                 and stat.S_IMODE(mode.st_mode) == 0o700, 'unsafe_journal_directory')
    if not os.path.lexists(path):
        core.require(not required, 'reward_shop_v3_installation_required')
        return None
    mode = path.lstat()
    core.require(stat.S_ISREG(mode.st_mode) and mode.st_uid == os.geteuid()
                 and stat.S_IMODE(mode.st_mode) == 0o600, 'unsafe_journal_file')
    return json.loads(path.read_bytes())


def inspect(core, manifest):
    previous = private_state(core, INSTALLED.parent, required=True)
    core.require(previous['phase'] == 'active' and previous['target_commit'] == BASE
                 and previous['database_upgrade_started'] is True, 'reward_shop_v3_installation_required')
    state = private_state(core, STATE)
    if state and state['phase'] != 'active' and state['phase'] != 'rolled_back':
        return {'phase': 'recovery_required'}
    core.require(not os.path.lexists(MAINTENANCE), 'existing_maintenance')
    version, drift = core.code_status(manifest)
    db = core.db_status()
    core.require(version == db['version'] == VERSION and all(db['tables'].get(name) is True for name in core.TABLES),
                 'unexpected_reward_shop_schema')
    theme = core.HOST_APP / 'theme' / 'ustar'
    core.require(all(core.digest(core.safe_path(theme, name)) == sha for name, sha in manifest['theme'].items()),
                 'theme_drift')
    core.cli_preflight()
    if state and state['phase'] == 'active':
        core.require(state['target_commit'] == manifest['target_commit'] and not drift(manifest['after']),
                     'target_source_drift')
        return {'phase': 'active', 'backup': state['backup']}
    baseline_drift = drift(manifest['before'])
    core.require(not baseline_drift, 'baseline_source_drift:' + ','.join(baseline_drift))
    core.require(not any(os.path.lexists(core.safe_path(core.HOST_APP / 'local/ustar', name)) for name in ADDED),
                 'new_target_file_already_exists')
    return {'phase': 'ready'}


def maintenance(core, switch):
    core.run(['docker', 'exec', '-u', 'www-data', 'ustar_moodle', 'php',
              core.CONTAINER_CLI + '/maintenance.php', switch], timeout=60)


def restore(core, manifest, state):
    backup = Path(state['backup'])
    core.require(backup.parent == STATE and backup.is_dir() and not backup.is_symlink(), 'unsafe_source_backup')
    core.require(core.digest(backup / 'local-ustar-before.tar.gz') == state['backup_sha256'], 'source_backup_mismatch')
    maintenance(core, '--enable')
    core.restore_code(backup, manifest)
    _, drift = core.code_status(manifest)
    core.require(not drift(manifest['before']), 'baseline_restore_mismatch')
    core.cli('purge_caches.php')
    maintenance(core, '--disable')
    state['phase'] = 'rolled_back'
    core.write_state(state)


def upgrade(core, folder, manifest):
    with core.exclusive_operator_guards():
        core.require(inspect(core, manifest)['phase'] == 'ready', 'update_not_ready')
        STATE.mkdir(mode=0o700, exist_ok=True)
        backup = Path(tempfile.mkdtemp(prefix='source-before-', dir=STATE))
        archive = backup / 'local-ustar-before.tar.gz'
        with tarfile.open(archive, 'w:gz', dereference=False) as tar:
            for name in sorted(CHANGES - ADDED):
                tar.add(core.safe_path(core.HOST_APP / 'local/ustar', name), arcname='local/ustar/' + name)
        state = {'phase': 'prepared', 'backup': str(backup), 'backup_sha256': core.digest(archive),
                 'target_commit': manifest['target_commit']}
        core.write_state(state)
        try:
            maintenance(core, '--enable')
            core.replace_code(folder, manifest)
            core.php_lint(manifest)
            _, drift = core.code_status(manifest)
            core.require(not drift(manifest['after']), 'target_source_mismatch')
            core.cli('purge_caches.php')
            maintenance(core, '--disable')
            state['phase'] = 'active'
            core.write_state(state)
            return {'phase': 'active', 'backup': str(backup), 'cache_purged': True}
        except BaseException:
            state['phase'] = 'recovery_required'
            core.write_state(state)
            restore(core, manifest, state)
            raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='mode', required=True)
    builder = commands.add_parser('build')
    builder.add_argument('--repo', type=Path, required=True)
    builder.add_argument('--target', required=True)
    builder.add_argument('--output', type=Path, required=True)
    for mode in ('verify', 'check', 'upgrade', 'recover'):
        command = commands.add_parser(mode)
        if mode in ('upgrade', 'recover'):
            command.add_argument('--acknowledge-outage', action='store_true', required=True)
    args = parser.parse_args()
    os.umask(0o077)
    core = None
    try:
        if args.mode == 'build':
            result = build(args.repo, args.target, args.output)
        else:
            core = engine(CORE_SOURCE)
            core.STATE_DIR = STATE
            folder = Path(__file__).absolute().parent
            manifest = load_package(core, folder)
            if args.mode == 'verify':
                result = {'phase': 'package_verified', 'files': len(CHANGES), 'target_commit': manifest['target_commit']}
            else:
                core.require(os.geteuid() == 0, 'root_required')
                if args.mode == 'check':
                    result = inspect(core, manifest)
                elif args.mode == 'upgrade':
                    result = upgrade(core, folder, manifest)
                else:
                    with core.exclusive_operator_guards():
                        state = private_state(core, STATE)
                        core.require(state and state['phase'] in ('prepared', 'recovery_required')
                                     and state['target_commit'] == manifest['target_commit'], 'no_matching_recovery')
                        restore(core, manifest, state)
                        result = {'phase': 'rolled_back', 'backup': state['backup']}
            result.update(local_ustar=VERSION, theme_ustar=THEME_VERSION, schema_changed=False, business_data_changed=False)
        print(json.dumps(result, sort_keys=True))
        return 2 if result.get('phase') == 'recovery_required' else 0
    except Exception as error:
        result = {'phase': 'refused', 'reason': str(error)[:700]}
        if core and args.mode in ('upgrade', 'recover'):
            try:
                state = private_state(core, STATE)
                if state and state['phase'] in ('prepared', 'recovery_required'):
                    result.update(phase='recovery_required', backup=state['backup'])
            except Exception:
                pass
        print(json.dumps(result, sort_keys=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())
