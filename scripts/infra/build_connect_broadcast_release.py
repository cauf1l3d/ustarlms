#!/usr/bin/env python3
"""Build the versioned Academy and Connect successor from immutable Git objects."""

import argparse
import gzip
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile

import connect_broadcast_release as release


OPERATOR = 'scripts/infra/connect_broadcast_release.py'
WORKER_CHANGES = {
    'bot_cards.py', 'bridge.py', 'notification_broadcast.py', 'notification_worker.py', 'telegram_api.py',
}


def git_file(repo, commit, path):
    return subprocess.check_output(['git', '-C', str(repo), 'show', f'{commit}:{path}'], timeout=40)


def assemble(archive, files, engine):
    with archive.open('wb') as output, gzip.GzipFile(filename='', fileobj=output, mode='wb', mtime=0) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as tar:
            for name, value in sorted(files.items()):
                entry = tarfile.TarInfo(name)
                entry.mode, entry.size = 0o600, len(value)
                tar.addfile(entry, io.BytesIO(value))
    with tarfile.open(fileobj=io.BytesIO(gzip.decompress(archive.read_bytes())), mode='r:') as tar:
        entries = tar.getmembers()
        engine.require([entry.name for entry in entries] == sorted(files) and all(
            entry.isfile() and entry.mode == 0o600 and entry.uid == entry.gid == entry.mtime == 0
            and tar.extractfile(entry).read() == files[entry.name] for entry in entries), 'archive_membership_mismatch')


def build(repo, target, output):
    return build_successor(repo, target, output, release, OPERATOR, WORKER_CHANGES, 'ustar-connect-broadcast')


def build_successor(repo, target, output, profile, operator_path, worker_changes, archive_prefix, *,
                    schema_check=None, new_subdirs=(), compatible_baselines=None, **extra):
    frozen = git_file(repo, release.FROZEN_COMMIT, 'scripts/infra/connect_team_release.py')
    engine = profile.successor(frozen, target, **{name.lower(): value for name, value in extra.items()})
    baseline = engine.WORKER_BEFORE
    compatible_baselines = compatible_baselines or {}
    versions = {baseline: engine.BEFORE, **compatible_baselines}
    engine.require(engine.BASES == tuple(versions), 'unexpected_application_baselines')
    core_source = git_file(repo, engine.CORE_COMMIT, 'scripts/infra/reward_shop_release.py')
    worker_source = git_file(repo, target, 'connect-worker/deploy/install.py')
    core = engine.embedded(core_source, engine.CORE_SHA256, '_ustar_reward_shop_v3')
    worker = engine.embedded(worker_source, engine.WORKER_INSTALLER_SHA256, '_ustar_connect_worker_installer')
    engine.require(core.git(repo, 'rev-parse', target + '^{commit}') == target
                   and all(core.git(repo, 'merge-base', commit, target) == commit for commit in versions),
                   'wrong_application_ancestry')
    operator = Path(profile.__file__).read_bytes()
    engine.require(operator == git_file(repo, 'HEAD', operator_path), 'operator_not_committed')

    with tempfile.TemporaryDirectory(prefix='ustar-connect-broadcast-build-') as work:
        work = Path(work)
        after = work / 'after'
        core.archive(repo, target, after)
        new = core.scan(after / core.APP_REL)
        theme = core.scan(after / core.THEME_REL)
        allowed_dirs = tuple(item.rstrip('/') + '/' for item in new_subdirs)
        sources = {}
        delta = set()
        for root, version in [(work / commit, version) for commit, version in versions.items()] + [(after, engine.AFTER)]:
            if root != after:
                core.archive(repo, root.name, root)
                old = core.scan(root / core.APP_REL)
                sources[root.name] = old
                engine.require(core.scan(root / core.THEME_REL) == theme, 'theme_changed')
                if root.name == baseline:
                    if schema_check is None:
                        engine.require(old['db/install.xml'] == new['db/install.xml'], 'unexpected_schema_change')
                    else:
                        schema_check(root / core.APP_REL / 'db/install.xml', after / core.APP_REL / 'db/install.xml')
                    engine.require(all(not (root / core.APP_REL / item.rstrip('/')).exists() for item in new_subdirs),
                                   'new_source_directory_already_exists')
                changes = engine.changes(old, new)
                engine.require(all((root / core.APP_REL / name).parent.is_dir() or
                                   any(name.startswith(prefix) for prefix in allowed_dirs) for name in changes),
                               'new_source_directory_unsupported')
                delta.update(changes)
            source = (root / core.APP_REL / 'version.php').read_text()
            match = re.search(r'\$plugin->version\s*=\s*(\d+)', source)
            engine.require(match and int(match[1]) == version, 'application_version_mismatch')
        payload = {}
        for name in sorted(delta):
            path = after / core.APP_REL / name
            if name.endswith('.php'):
                core.run(['php', '-l', str(path)], timeout=20)
            payload['payload/' + name] = path.read_bytes()

        changed_worker = core.git(repo, 'diff', '--name-only', baseline, target, '--', 'connect-worker')
        engine.require(set(changed_worker.splitlines()) == {'connect-worker/ustar_connect/' + name for name in worker_changes},
                       'unexpected_worker_delta')
        builder = repo / 'connect-worker/deploy/build_release.py'
        engine.require(builder.read_bytes() == git_file(repo, target, 'connect-worker/deploy/build_release.py'),
                       'worker_builder_changed')
        workers = {}
        for commit in (*versions, target):
            core.run(['python3', '-I', '-B', str(builder), '--commit', commit, '--output-dir', str(work)], timeout=120)
            path = work / f'ustar-connect-receiver-{commit[:8]}.tar.gz'
            recorded, values = worker.validate_archive(path, core.digest(path))
            engine.require(recorded == commit, 'unexpected_worker_commit')
            workers[commit] = values
        worker_archive = path.read_bytes()
        engine.worker_identity(workers[target])

    manifest = {'schema': 1, 'target': target, 'before': sources, 'after': new, 'theme': theme,
                'worker_before': baseline, 'worker_identity': engine.worker_identity(workers[baseline]),
                'local_ustar': engine.AFTER, 'theme_ustar': engine.THEME}
    if compatible_baselines:
        manifest['compatibility'] = {
            commit: {'local_ustar': version, 'worker_identity': engine.worker_identity(workers[commit])}
            for commit, version in compatible_baselines.items()
        }
    bootstrap = {'FROZEN_SOURCE': frozen, 'CORE_SOURCE': core_source,
                 'WORKER_INSTALLER': worker_source, 'TARGET': target, **extra}
    for name, value in bootstrap.items():
        marker = ('^' + name + ' = None$').encode()
        replacement = (name + ' = ' + repr(value)).encode()
        operator, replaced = re.subn(marker, lambda _: replacement, operator, flags=re.MULTILINE)
        engine.require(replaced == 1, 'invalid_bootstrap_marker')
    compile(operator, '<connect-broadcast-operator>', 'exec')
    files = {'update.py': operator, 'INSTALL.json': engine.canonical(manifest), 'worker.tar.gz': worker_archive, **payload}
    files['SHA256SUMS'] = ''.join(f'{engine.sha(raw)}  {name}\n' for name, raw in sorted(files.items())).encode()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'{archive_prefix}-{target[:8]}.tar.gz'
    assemble(archive, files, engine)
    return {'archive': str(archive), 'sha256': core.digest(archive), 'members': len(files),
            'application_files': len(delta), 'worker_files': len(workers[target]), 'application_commit': target}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.target, args.output), sort_keys=True))
