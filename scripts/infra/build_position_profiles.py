#!/usr/bin/env python3
"""Assemble the next Workplace package using the frozen Workplace Assignment installer."""

import argparse
import ast
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET

from build_connect_broadcast_release import assemble, git_file
from build_workplace_assignment import build as build_assignment
import connect_team_release as engine


def build(repo, output):
    profile = git_file(repo, 'HEAD', 'scripts/infra/position_profiles_profile.py')
    target = next(ast.literal_eval(node.value) for node in ast.parse(profile).body
                  if isinstance(node, ast.Assign) and isinstance(node.targets[0], ast.Name)
                  and node.targets[0].id == 'TARGET')
    with tempfile.TemporaryDirectory(prefix='ustar-position-profiles-') as directory:
        baseline = build_assignment(repo, Path(directory))
        engine.require(baseline['sha256'] == 'ada75f355a481eca54c25bbc7090a67d4bdbd392e9fa809b4a3290a43025c103',
                       'frozen_assignment_archive_changed')
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
    def tables(ref):
        indexes = {}
        for table in ET.fromstring(git_file(repo, ref, schema)).findall('.//TABLE'):
            table.tail = None
            indexes[table.get('NAME')] = ET.tostring(table)
        return indexes
    old, new = tables(prior), tables(target)
    engine.require(set(new) - set(old) == {'local_ustar_pos_profiles', 'local_ustar_pos_profile_users'}
                   and all(new.get(name) == old[name] for name in old), 'unexpected_schema_change')
    engine.require(b'= 2026112400;' in git_file(repo, target, prefix + 'version.php'), 'version_mismatch')
    manifest.update(target=target, assignment_source=manifest['after'], after=after, local_ustar=2026112400)
    marker = b"\nif __name__ == '__main__':"
    engine.require(operator.count(marker) == 1, 'invalid_frozen_entrypoint')
    output_marker = b'result.update(schema_changed=False, worker_changed=False)'
    engine.require(operator.count(output_marker) == 1, 'invalid_frozen_schema_output')
    operator = operator.replace(output_marker, b'result.update(schema_changed=True, worker_changed=False)')
    operator = operator.replace(marker, b'\n' + profile + b'\n' + marker)
    compile(operator, '<position-profiles>', 'exec')
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
    archive = output / f'ustar-position-profiles-{target[:8]}.tar.gz'
    assemble(archive, files, engine)
    return {'archive': str(archive), 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
            'application_commit': target, 'payload_files': len(files) - 3}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(Path(__file__).resolve().parents[2], args.output), sort_keys=True))
