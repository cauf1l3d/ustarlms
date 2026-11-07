#!/usr/bin/env python3
"""Build the fixed, source-only2900 SCORM overlay from immutable Git objects."""
import argparse
import base64
import gzip
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import route_scorm_patch as patch

ROOT = Path(__file__).resolve().parents[2]


def build(tool_commit, output, *, repo=ROOT):
    repo = Path(repo)
    def git(*args):
        return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL, timeout=30)
    patch.require(re.fullmatch('[a-f0-9]{40}', tool_commit), 'exact_tool_commit_required')
    patch.require(git('rev-parse', tool_commit + '^{commit}').decode().strip() == tool_commit, 'exact_tool_commit_required')
    scopes = ('moodle/local/ustar', 'moodle/theme/ustar')
    for scope in scopes:
        patch.require(git('rev-parse', tool_commit + ':' + scope)
                      == git('rev-parse', patch.APPLICATION + ':' + scope), 'tool_application_tree_changed')
    def source(ref):
        result = {}
        for row in git('ls-tree', '-rz', ref, '--', *scopes).split(b'\0'):
            if not row:
                continue
            meta, path = row.split(b'\t', 1)
            mode, kind, oid = meta.decode().split()
            patch.require(kind == 'blob' and mode in ('100644', '100755'), 'regular_git_source_required')
            result[path.decode().removeprefix('moodle/')] = git('cat-file', 'blob', oid)
        return result
    before, after = source(patch.BASELINE), source(patch.APPLICATION)
    patch.require(len(before) == 606 and len(after) == 608, 'fixed_overlay_source_count')
    def images(values):
        return {p: patch.Patch.image(raw) for p, raw in sorted(values.items())}
    manifest = {'schema': 1, 'baseline_commit': patch.BASELINE, 'application_commit': patch.APPLICATION,
                'tool_commit': tool_commit, 'before': images(before), 'after': images(after)}
    payload = {p: after[p] for p in patch.FILES}
    patch.Patch(Path('/'), manifest, payload, None)  # Validate bounds/digests; no host access.
    template = git('show', tool_commit + ':scripts/infra/route_scorm_patch.py')
    patch.require(template == (repo / 'scripts/infra/route_scorm_patch.py').read_bytes(), 'dirty_operator_source')
    patch.require(git('show', tool_commit + ':scripts/infra/build_route_scorm_patch.py')
                  == (repo / 'scripts/infra/build_route_scorm_patch.py').read_bytes(), 'dirty_builder_source')
    embedded = {'manifest': manifest, 'payload': {p: base64.b64encode(raw).decode() for p, raw in payload.items()}}
    patch.require(template.count(b'EMBEDDED = None') == 1, 'operator_template_changed')
    script = template.replace(b'EMBEDDED = None', ('EMBEDDED = ' + repr(embedded)).encode())
    compile(script, '<fixed-scorm-overlay>', 'exec')
    metadata = {'schema': 1, 'tool_commit': tool_commit, 'application_commit': patch.APPLICATION,
                'baseline_commit': patch.BASELINE, 'versions_unchanged': {'local_ustar': 2026102900,
                'theme_ustar': 2026102700}, 'files': list(patch.FILES), 'schema_changed': False,
                'worker_changed': False, 'database_restore': False, 'web_restart_required': True,
                'script_sha256': patch.digest(script), 'source_overlay_not_a_new_full_installer': True}
    members = {'update.py': script, 'INSTALL.json': (json.dumps(metadata, sort_keys=True, indent=2) + '\n').encode()}
    members['SHA256SUMS'] = ''.join(patch.digest(raw) + '  ' + name + '\n'
        for name, raw in sorted(members.items())).encode()
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    target = output / ('ustar-scorm-direct-' + tool_commit[:8] + '.tar.gz')
    with target.open('wb') as raw, gzip.GzipFile(fileobj=raw, mode='wb', filename='', mtime=0) as zipped:
        with tarfile.open(fileobj=zipped, mode='w') as tar:
            for name, content in sorted(members.items()):
                entry = tarfile.TarInfo(name)
                entry.mode, entry.size, entry.mtime = 0o600, len(content), 0
                tar.addfile(entry, io.BytesIO(content))
    digest = patch.digest(target.read_bytes())
    target.with_suffix('.gz.sha256').write_text(digest + '  ' + target.name + '\n')
    target.with_suffix('.gz.metadata.json').write_text(json.dumps(metadata, sort_keys=True, indent=2) + '\n')
    return {'archive': str(target), 'sha256': digest, 'bytes': target.stat().st_size, **metadata}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--tool-commit', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.tool_commit, args.output), sort_keys=True))
