#!/usr/bin/env python3
"""Export only versioned plugin/theme blobs into the isolated test input."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]
runtime = json.loads((ROOT / 'tests/stage/runtime.json').read_text())
commit = runtime['upgrade_from_commit']
out = ROOT / '.stage-input/baseline'
if out.exists():
    raise SystemExit('Refusing to overwrite stage input; remove only .stage-input before preparing a fresh run')
manifest = {}
entries = subprocess.check_output(['git', 'ls-tree', '-r', '-z', commit, '--', 'moodle/local/ustar', 'moodle/theme/ustar'], cwd=ROOT)
for entry in entries.split(b'\0'):
    if not entry:
        continue
    meta, raw = entry.split(b'\t')
    mode, kind, oid = meta.decode().split()
    path = Path(raw.decode())
    if mode not in ('100644', '100755') or kind != 'blob' or '..' in path.parts:
        raise SystemExit(f'Unsupported baseline entry: {path}')
    data = subprocess.check_output(['git', 'cat-file', 'blob', oid], cwd=ROOT)
    dest = out / path
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_bytes(data)
    manifest[path.as_posix()] = hashlib.sha256(data).hexdigest()
(out.parent / 'baseline.json').write_text(json.dumps({'commit': commit, 'files': manifest}, indent=2) + '\n')
print(f'STAGE_INPUT_READY commit={commit} files={len(manifest)}')
