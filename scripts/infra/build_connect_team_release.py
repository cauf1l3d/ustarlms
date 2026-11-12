#!/usr/bin/env python3
"""Build the server1 archive from the qualified Git objects."""

import argparse
import gzip
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile

import connect_team_release as release


def build(repo, output):
    def git_file(commit, path):
        return subprocess.check_output(['git', '-C', str(repo), 'show', f'{commit}:{path}'], timeout=40)

    core_source = git_file(release.CORE_COMMIT, 'scripts/infra/reward_shop_release.py')
    worker_source = git_file(release.TARGET, 'connect-worker/deploy/install.py')
    core = release.embedded(core_source, release.CORE_SHA256, '_ustar_reward_shop_v3')
    worker = release.embedded(worker_source, release.WORKER_INSTALLER_SHA256, '_ustar_connect_worker_installer')
    operator = Path(release.__file__).read_bytes()
    release.require(operator == git_file('HEAD', 'scripts/infra/connect_team_release.py'), 'operator_not_committed')

    with tempfile.TemporaryDirectory(prefix='ustar-connect-team-build-') as work:
        work = Path(work)
        indexes = {}
        for commit in (*release.BASES, release.TARGET):
            core.archive(repo, commit, work / commit)
            indexes[commit] = core.scan(work / commit / core.APP_REL)
        target_root = work / release.TARGET / core.APP_REL
        theme = core.scan(work / release.TARGET / core.THEME_REL)
        before = {commit: indexes[commit] for commit in release.BASES}
        after = indexes[release.TARGET]
        delta = set()
        for commit, index in before.items():
            release.require(core.scan(work / commit / core.THEME_REL) == theme, 'theme_changed')
            release.require(index['db/install.xml'] == after['db/install.xml'], 'unexpected_schema_change')
            changed = release.changes(index, after)
            release.require(all((work / commit / core.APP_REL / name).parent.is_dir() for name in changed),
                            'new_source_directory_unsupported')
            delta.update(changed)
        for name in sorted(delta):
            if name.endswith('.php'):
                core.run(['php', '-l', str(target_root / name)], timeout=20)
        payload = {'payload/' + name: (target_root / name).read_bytes() for name in delta}

        worker_paths = core.git(repo, 'diff', '--name-only', release.WORKER_BEFORE, release.TARGET, '--', 'connect-worker')
        release.require(set(worker_paths.splitlines()) == {
            'connect-worker/ustar_connect/notification_events.py',
            'connect-worker/ustar_connect/telegram_api.py',
        }, 'unexpected_worker_delta')
        builder = repo / 'connect-worker/deploy/build_release.py'
        release.require(builder.read_bytes() == git_file(release.TARGET, 'connect-worker/deploy/build_release.py'),
                        'worker_builder_changed')
        workers = {}
        for commit in (release.WORKER_BEFORE, release.TARGET):
            core.run(['python3', '-I', '-B', str(builder), '--commit', commit, '--output-dir', str(work)], timeout=120)
            path = work / f'ustar-connect-receiver-{commit[:8]}.tar.gz'
            recorded, values = worker.validate_archive(path, core.digest(path))
            release.require(recorded == commit, 'unexpected_worker_commit')
            workers[commit] = values
        worker_archive = (work / f'ustar-connect-receiver-{release.TARGET[:8]}.tar.gz').read_bytes()

    manifest = {'schema': 1, 'target': release.TARGET, 'before': before, 'after': after, 'theme': theme,
                'worker_before': release.WORKER_BEFORE, 'worker_identity': release.worker_identity(workers[release.WORKER_BEFORE]),
                'local_ustar': release.AFTER, 'theme_ustar': release.THEME}
    script = operator.replace(b'CORE_SOURCE = None', b'CORE_SOURCE = ' + repr(core_source).encode(), 1)
    script = script.replace(b'WORKER_INSTALLER = None', b'WORKER_INSTALLER = ' + repr(worker_source).encode(), 1)
    compile(script, '<connect-team-operator>', 'exec')
    files = {'update.py': script, 'INSTALL.json': release.canonical(manifest), 'worker.tar.gz': worker_archive, **payload}
    files['SHA256SUMS'] = ''.join(f'{release.sha(raw)}  {name}\n' for name, raw in sorted(files.items())).encode()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'ustar-connect-team-{release.TARGET[:8]}.tar.gz'
    with archive.open('wb') as raw, gzip.GzipFile(filename='', fileobj=raw, mode='wb', mtime=0) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as tar:
            for name, value in sorted(files.items()):
                entry = tarfile.TarInfo(name)
                entry.mode, entry.size = 0o600, len(value)
                tar.addfile(entry, io.BytesIO(value))
    with tarfile.open(fileobj=io.BytesIO(gzip.decompress(archive.read_bytes())), mode='r:') as tar:
        entries = tar.getmembers()
        release.require([entry.name for entry in entries] == sorted(files) and all(
            entry.isfile() and entry.mode == 0o600 and entry.uid == entry.gid == entry.mtime == 0
            and tar.extractfile(entry).read() == files[entry.name] for entry in entries), 'archive_membership_mismatch')
    return {'archive': str(archive), 'sha256': core.digest(archive), 'members': len(files), 'application_files': len(delta),
            'worker_files': len(workers[release.TARGET]), 'application_commit': release.TARGET}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.output), sort_keys=True))
