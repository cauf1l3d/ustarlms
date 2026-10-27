#!/usr/bin/env python3
"""Freeze an exact-Git, source-only Academy RC. Never installs or migrates it."""
import argparse
import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]


def source_path(name):
    path = PurePosixPath(name)
    if (not re.fullmatch(r'[A-Za-z0-9_./-]+', name) or path.is_absolute()
            or any(part in ('.', '..') for part in path.parts)
            or not name.startswith(('local/ustar/', 'theme/ustar/'))):
        raise ValueError('source_path_refused')
    if name == 'local/ustar/.org-roles-install.lock':
        return
    if any(part.startswith('.') for part in path.parts):
        raise ValueError('source_path_refused')
    if path.name.lower() in ('config.php', 'config-local.php') and name != 'theme/ustar/config.php':
        raise ValueError('source_path_refused')


def build(commit, output, *, repo=ROOT):
    if not re.fullmatch(r'(?:HEAD|[a-f0-9]{40})', commit):
        raise ValueError('exact_commit_required')

    def git(*args):
        return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL)

    sha = git('rev-parse', '--verify', commit + '^{commit}').decode().strip()
    entries = git('ls-tree', '-r', '-z', sha, '--', 'moodle/local/ustar', 'moodle/theme/ustar')
    payload = {}
    for entry in entries.split(b'\0'):
        if not entry:
            continue
        meta, rawpath = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode().split()
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise ValueError('regular_source_required')
        name = rawpath.decode().removeprefix('moodle/')
        source_path(name)
        payload[name] = git('cat-file', 'blob', oid)
    if not payload:
        raise ValueError('candidate_missing')

    # Reuse the same exact-source preflight builder; its executable modules and
    # accepted/candidate reference are read from Git, never from dirty sources.
    spec = importlib.util.spec_from_file_location('connect_preflight_builder',
                                                 ROOT / 'scripts/infra/build_connect_readiness.py')
    preflight = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(preflight)
    with tempfile.TemporaryDirectory(prefix='ustar-academy-release-') as temporary:
        probe = preflight.build(sha, temporary, repo=repo).read_bytes()
    with zipfile.ZipFile(io.BytesIO(probe)) as archive:
        reference = json.loads(archive.read('connect_preflight_data/reference.json'))
    expected = reference['candidate']['files']
    if expected != {name: hashlib.sha256(data).hexdigest() for name, data in payload.items()}:
        raise ValueError('candidate_reference_mismatch')

    files = {'payload/' + name: data for name, data in payload.items()}
    files.update({'SOURCE_COMMIT': (sha + '\n').encode(), 'preflight.pyz': probe})
    manifest = {'schema': 1, 'kind': 'connect_academy_source_only_rc', 'source_commit': sha,
                'reference': reference, 'installation_supported': False, 'production_mutation': False,
                'files': {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())}}
    files['manifest.json'] = (json.dumps(manifest, sort_keys=True, indent=2) + '\n').encode()
    files['SHA256SUMS'] = ''.join(hashlib.sha256(data).hexdigest() + '  ' + name + '\n'
                                for name, data in sorted(files.items())).encode()
    stream = io.BytesIO()
    with gzip.GzipFile(fileobj=stream, filename='', mode='wb', mtime=0, compresslevel=9) as compressed:
        with tarfile.open(fileobj=compressed, mode='w', format=tarfile.USTAR_FORMAT) as archive:
            for name, data in sorted(files.items()):
                member = tarfile.TarInfo(name)
                member.size = len(data)
                member.mode = 0o644
                member.mtime = 0
                archive.addfile(member, io.BytesIO(data))
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    target = output / ('ustar-connect-academy-' + sha[:8] + '.tar.gz')
    target.write_bytes(stream.getvalue())
    target.chmod(0o644)
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix(target.suffix + '.sha256').write_text(digest + '  ' + target.name + '\n')
    print(json.dumps({'archive': str(target), 'sha256': digest, 'source_commit': sha,
                      'payload_files': len(payload), 'installation_supported': False}))
    return target


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit', default='HEAD')
    parser.add_argument('--output-dir', type=Path, required=True)
    args = parser.parse_args()
    build(args.commit, args.output_dir)
