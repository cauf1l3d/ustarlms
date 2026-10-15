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
    production = json.loads(subprocess.check_output(
        ['git', 'show', sha + ':tests/stage/production.json'], cwd=ROOT))
    baseline_sha, baseline_files, baseline_skipped = git_files(ROOT, production['source_commit'])
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
    for path in ('tests/release_20260912/package-lock.json', 'tests/mobile/package-lock.json', 'tests/stage/python-requirements.txt'):
        blob = subprocess.check_output(['git', 'show', sha + ':' + path], cwd=ROOT)
        locks[path] = hashlib.sha256(blob).hexdigest()
    return {'format': 2, 'source_commit': sha, 'created_at': datetime.now(timezone.utc).isoformat(),
            'runtime_reference': runtime, 'production_source_evidence': production,
            'application_scope': ['moodle/local/ustar', 'moodle/theme/ustar'],
            'legacy_excluded': ['frontend/'], 'source_files': files,
            'dependency_lock_sha256': locks,
            'upgrade_plan': {'baseline_commit': baseline_sha, 'added': added,
                             'changed': changed, 'removed': removed,
                             'removal_policy': 'Require exact baseline hash; abort on drift; never delete outside plugin/theme'},
            'deployment_status': 'not_deployed', 'production_restore': 'not_verified',
            'preflight': 'Compare installed source to upgrade_plan.baseline_commit before any release apply. '
                         'The isolated test fixture in runtime_reference is not a production fingerprint.'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit', default='HEAD')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = build(args.commit)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2) + '\n')
    print(f'RELEASE_MANIFEST commit={result["source_commit"]} files={len(result["source_files"])}')
