#!/usr/bin/env python3
"""Manifest immutable Git blobs; never include runtime config, databases or secrets."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
from production_manifest import git_files


def build(commit):
    sha, files, skipped = git_files(ROOT, commit)
    if skipped:
        raise ValueError('Non-regular application source must be reviewed')
    frontend = {}
    entries = subprocess.check_output(['git', 'ls-tree', '-r', '-z', sha, '--', 'frontend'], cwd=ROOT)
    for entry in entries.split(b'\0'):
        if not entry:
            continue
        meta, path = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode().split()
        path = path.decode()
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise ValueError('Non-regular frontend entry: ' + path)
        if Path(path).name.startswith('.env') and Path(path).name != '.env.example':
            raise ValueError('Runtime environment must not be in a release: ' + path)
        frontend[path] = hashlib.sha256(subprocess.check_output(['git', 'cat-file', 'blob', oid], cwd=ROOT)).hexdigest()
    runtime = json.loads(subprocess.check_output(['git', 'show', sha + ':tests/stage/runtime.json'], cwd=ROOT))
    baseline_sha, baseline_files, baseline_skipped = git_files(ROOT, runtime['upgrade_from_commit'])
    if baseline_skipped:
        raise ValueError('Baseline contains unreviewed non-regular application source')
    # An overlay copy cannot retire removed endpoints/assets. Publish an explicit,
    # hash-guarded removal plan; this manifest never performs filesystem writes.
    removed = {path: digest for path, digest in baseline_files.items() if path not in files}
    added = {path: digest for path, digest in files.items() if path not in baseline_files}
    changed = {path: {'before': baseline_files[path], 'after': digest}
               for path, digest in files.items()
               if path in baseline_files and digest != baseline_files[path]}
    locks = {}
    for path in ('frontend/package-lock.json', 'tests/release_20260912/package-lock.json', 'tests/stage/python-requirements.txt'):
        blob = subprocess.check_output(['git', 'show', sha + ':' + path], cwd=ROOT)
        locks[path] = hashlib.sha256(blob).hexdigest()
    return {'format': 1, 'source_commit': sha, 'created_at': datetime.now(timezone.utc).isoformat(),
            'runtime_reference': runtime, 'source_files': files, 'frontend_source_files': frontend,
            'dependency_lock_sha256': locks,
            'upgrade_plan': {'baseline_commit': baseline_sha, 'added': added,
                             'changed': changed, 'removed': removed,
                             'removal_policy': 'Require exact baseline hash; abort on drift; never delete outside plugin/theme'},
            'deployment_status': 'not_deployed', 'production_restore': 'not_verified',
            'preflight': 'Compare installed source to upgrade_from_commit before any release apply'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit', default='HEAD')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = build(args.commit)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2) + '\n')
    print(f'RELEASE_MANIFEST commit={result["source_commit"]} files={len(result["source_files"])}')
