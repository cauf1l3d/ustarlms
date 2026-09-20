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
    runtime = json.loads(subprocess.check_output(['git', 'show', sha + ':tests/stage/runtime.json'], cwd=ROOT))
    locks = {}
    for path in ('frontend/package-lock.json', 'tests/release_20260912/package-lock.json', 'tests/stage/python-requirements.txt'):
        blob = subprocess.check_output(['git', 'show', sha + ':' + path], cwd=ROOT)
        locks[path] = hashlib.sha256(blob).hexdigest()
    return {'format': 1, 'source_commit': sha, 'created_at': datetime.now(timezone.utc).isoformat(),
            'runtime_reference': runtime, 'source_files': files, 'dependency_lock_sha256': locks,
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
