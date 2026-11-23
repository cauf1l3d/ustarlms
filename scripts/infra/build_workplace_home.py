#!/usr/bin/env python3
"""Assemble the next Workplace package using the frozen review installer."""

import argparse
import ast
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile

from build_connect_broadcast_release import assemble, git_file
from build_workplace_review import build as build_review
import connect_team_release as engine


def build(repo, output):
    profile = git_file(repo, 'HEAD', 'scripts/infra/workplace_home_profile.py')
    target = next(ast.literal_eval(node.value) for node in ast.parse(profile).body
                  if isinstance(node, ast.Assign) and isinstance(node.targets[0], ast.Name)
                  and node.targets[0].id == 'TARGET')
    with tempfile.TemporaryDirectory(prefix='ustar-workplace-home-') as directory:
        baseline = build_review(repo, Path(directory))
        engine.require(baseline['sha256'] == '4a9abd0155e89dda0a823db7b09af5d7402b360af9e0351da99448135be09624',
                       'frozen_review_archive_changed')
        with tarfile.open(baseline['archive']) as archive:
            manifest = json.load(archive.extractfile('INSTALL.json'))
            operator = archive.extractfile('update.py').read()

    prior = manifest['target']
    subprocess.run(['git', '-C', repo, 'merge-base', '--is-ancestor', prior, target], check=True)
    subprocess.run(['git', '-C', repo, 'diff', '--quiet', prior, target, '--', 'moodle/theme', 'connect-worker'], check=True)
    prefix = 'moodle/local/ustar/'
    before = next(iter(manifest['before'].values()))
    after = dict(manifest['after'])
    paths = subprocess.check_output(['git', '-C', repo, 'diff', '--name-only', prior, target, '--', prefix]).decode().splitlines()
    for path in paths:
        after[path.removeprefix(prefix)] = engine.sha(git_file(repo, target, path))
    schema = prefix + 'db/install.xml'
    engine.require(git_file(repo, prior, schema) == git_file(repo, target, schema), 'unexpected_schema_change')
    engine.require(b'= 2026112301;' in git_file(repo, target, prefix + 'version.php'), 'version_mismatch')
    manifest.update(target=target, review_source=manifest['after'], after=after, local_ustar=2026112301)
    marker = b"\nif __name__ == '__main__':"
    engine.require(operator.count(marker) == 1, 'invalid_frozen_entrypoint')
    operator = operator.replace(marker, b'\n' + profile + b'\n' + marker)
    compile(operator, '<workplace-home>', 'exec')
    files = {'INSTALL.json': engine.canonical(manifest), 'update.py': operator}
    existing_directories = {Path(name).parent for name in before}
    for name in engine.changes(before, after):
        engine.require(Path(name).parent in existing_directories, 'new_directory_unsupported')
        raw = git_file(repo, target, prefix + name)
        if name.endswith('.php'):
            subprocess.run(['php', '-l'], input=raw, stdout=subprocess.DEVNULL, check=True)
        files['payload/' + name] = raw
    files['SHA256SUMS'] = ''.join(f'{engine.sha(raw)}  {name}\n' for name, raw in sorted(files.items())).encode()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'ustar-workplace-home-{target[:8]}.tar.gz'
    assemble(archive, files, engine)
    return {'archive': str(archive), 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
            'application_commit': target, 'payload_files': len(files) - 3}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(Path(__file__).resolve().parents[2], args.output), sort_keys=True))
