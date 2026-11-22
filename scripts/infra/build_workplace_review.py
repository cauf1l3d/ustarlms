#!/usr/bin/env python3
"""Build the cumulative workplace review package, keeping the qualified installer frozen."""

import argparse
import ast
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET

from build_workplace_release import build as build_previous
from build_connect_broadcast_release import assemble, git_file
import connect_team_release as engine


def build(repo, output):
    profile_path = 'scripts/infra/workplace_review_profile.py'
    profile = git_file(repo, 'HEAD', profile_path)
    target = next(ast.literal_eval(n.value) for n in ast.parse(profile).body
                  if isinstance(n, ast.Assign) and isinstance(n.targets[0], ast.Name) and n.targets[0].id == 'TARGET')
    with tempfile.TemporaryDirectory(prefix='ustar-workplace-review-') as temporary:
        old = build_previous(repo, Path(temporary))
        engine.require(old['sha256'] == '4dfb36f02c6f611683c8a52aa95f292d54054efe3c9c986e227b543883ce7c15',
                       'frozen_workplace_archive_changed')
        with tarfile.open(old['archive']) as tar:
            manifest = json.load(tar.extractfile('INSTALL.json'))
            operator = tar.extractfile('update.py').read()
    mid = manifest['target']
    subprocess.run(['git', '-C', repo, 'merge-base', '--is-ancestor', mid, target], check=True)
    subprocess.run(['git', '-C', repo, 'diff', '--quiet', mid, target, '--', 'moodle/theme', 'connect-worker'], check=True)
    app = 'moodle/local/ustar/'
    before = manifest['before'][old['baseline_commit']]
    after = dict(manifest['after'])
    changed = subprocess.check_output(['git', '-C', repo, 'diff', '--name-only', mid, target, '--', app]).decode().splitlines()
    for path in changed:
        after[path.removeprefix(app)] = engine.sha(git_file(repo, target, path))
    for commit, version in [(mid, '2026112200'), (target, '2026112300')]:
        engine.require(('= ' + version + ';').encode() in git_file(repo, commit, app + 'version.php'), 'version_mismatch')
    def schema(commit):
        root = ET.fromstring(git_file(repo, commit, app + 'db/install.xml'))
        root.attrib.pop('VERSION')
        return ET.canonicalize(ET.tostring(root), strip_text=True)
    engine.require(schema(target) == schema(mid), 'unexpected_schema_change')
    manifest.update(target=target, workplace_source=manifest['after'], after=after, local_ustar=2026112300)
    marker = b"\nif __name__ == '__main__':"
    engine.require(operator.count(marker) == 1, 'invalid_frozen_entrypoint')
    operator = operator.replace(marker, b'\n' + profile + b'\n' + marker)
    compile(operator, '<workplace-review>', 'exec')
    files = {'INSTALL.json': engine.canonical(manifest), 'update.py': operator}
    for name in engine.changes(before, after):
        engine.require(any(Path(key).parent == Path(name).parent for key in before), 'new_directory_unsupported')
        raw = git_file(repo, target, app + name)
        if name.endswith('.php'):
            subprocess.run(['php', '-l'], input=raw, stdout=subprocess.DEVNULL, check=True)
        files['payload/' + name] = raw
    files['SHA256SUMS'] = ''.join(f'{engine.sha(raw)}  {name}\n' for name, raw in sorted(files.items())).encode()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'ustar-workplace-review-{target[:8]}.tar.gz'
    assemble(archive, files, engine)
    return {'archive': str(archive), 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
            'application_commit': target, 'baselines': list(manifest['before']) + [mid], 'payload_files': len(files) - 3}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(Path(__file__).resolve().parents[2], args.output), sort_keys=True))
