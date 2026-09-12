#!/usr/bin/env python3
"""Read-only R00 inventory. Never copies or repairs application files."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
from datetime import datetime, timezone

BASELINE = '23243f3de9b0392aded2635f84291dd8702e4920'
SCOPES = ('local/ustar', 'theme/ustar')


def git(repo, *args):
    return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL)


def excluded(path):
    return any(p.startswith('.') for p in Path(path).parts) or Path(path).name.lower() in {'config.php', 'config-local.php'}


def production_files(root):
    files, skipped, errors = {}, {}, {}
    for scope in SCOPES:
        base = root / scope
        if not base.is_dir() or any(p.is_symlink() for p in [base, *base.parents]):
            errors[scope] = 'missing_or_symlink_root'
            continue
        def onerror(exc):
            errors[str(Path(exc.filename).relative_to(root))] = 'unreadable_directory'
        for parent, dirs, names in os.walk(base, followlinks=False, onerror=onerror):
            for name in list(dirs):
                p = Path(parent)/name
                rel = p.relative_to(root).as_posix()
                if p.is_symlink() or excluded(rel):
                    skipped[rel] = 'symlink_or_excluded_directory'
                    dirs.remove(name)
            for name in sorted(names):
                p = Path(parent)/name
                rel = p.relative_to(root).as_posix()
                if excluded(rel):
                    skipped[rel] = 'excluded_sensitive_or_hidden'
                    continue
                fd = None
                try:
                    fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
                    before = os.fstat(fd)
                    if not stat.S_ISREG(before.st_mode):
                        skipped[rel] = 'not_regular_file'
                        continue
                    h = hashlib.sha256()
                    while chunk := os.read(fd, 1024 * 1024):
                        h.update(chunk)
                    after = os.fstat(fd)
                    if (before.st_size, before.st_mtime_ns, before.st_ino) != (after.st_size, after.st_mtime_ns, after.st_ino):
                        errors[rel] = 'changed_during_read'
                    else:
                        files[rel] = h.hexdigest()
                except OSError:
                    errors[rel] = 'unreadable_or_symlink'
                finally:
                    if fd is not None:
                        os.close(fd)
    return files, skipped, errors


def git_files(repo, commit):
    sha = git(repo, 'rev-parse', '--verify', commit + '^{commit}').decode().strip()
    files, skipped = {}, {}
    entries = git(repo, 'ls-tree', '-r', '-z', sha, '--', *('moodle/'+s for s in SCOPES))
    for entry in entries.split(b'\0'):
        if not entry:
            continue
        meta, rawpath = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode().split()
        rel = rawpath.decode()[len('moodle/'):]
        if kind != 'blob' or mode == '120000' or excluded(rel):
            skipped[rel] = 'excluded_or_non_regular'
            continue
        files[rel] = hashlib.sha256(git(repo, 'cat-file', 'blob', oid)).hexdigest()
    if not files or any(not any(p.startswith(s+'/') for p in files) for s in SCOPES):
        raise ValueError('baseline_missing_plugin_or_theme')
    return sha, files, skipped


def compare(prod, source):
    p, g = set(prod), set(source)
    return {'changed': sorted(k for k in p & g if prod[k] != source[k]),
            'production_only': sorted(p-g), 'git_only': sorted(g-p),
            'matching': sorted(k for k in p & g if prod[k] == source[k])}


def audit(repo, root, commit, output):
    sha, source, git_skipped = git_files(repo, commit)
    prod, skipped, errors = production_files(root)
    comparison = compare(prod, source)
    unavailable = set(skipped) | set(errors)
    comparison['git_only'] = [p for p in comparison['git_only'] if not any(p == u or p.startswith(u + '/') for u in unavailable)]
    comparison['uncompared'] = sorted(unavailable | set(git_skipped))
    result = {'measured_at': datetime.now(timezone.utc).isoformat(), 'baseline_commit': sha,
              'production_root': str(root), 'complete': not errors and not skipped and not git_skipped,
              'not_an_atomic_snapshot': True, 'production': prod, 'git': source,
              'production_skipped': skipped, 'git_skipped': git_skipped, 'errors': errors,
              'comparison': comparison}
    output.mkdir(parents=True, exist_ok=True)
    dest = output / 'production_manifest.json'
    with dest.open('x') as f:
        json.dump(result, f, ensure_ascii=False, indent=2)
        f.write('\n')
    report = ['# R00: production / Git', '', 'Baseline: '+sha,
              'Полнота: '+str(result['complete']),
              'Только чтение; снимок не атомарный. Пропуски/ошибки требуют отдельной проверки.', '']
    for kind, paths in result['comparison'].items():
        report += [f'## {kind}: {len(paths)}', '']
        if kind != 'matching':
            report += ['- `'+p+'`' for p in paths] + ['']
    report += ['## Skipped / errors', '', 'См. production_skipped, git_skipped и errors в JSON.']
    (output/'comparison.md').write_text('\n'.join(report)+'\n')
    print(json.dumps({'baseline': sha, 'complete': result['complete'],
                      **{k: len(v) for k,v in result['comparison'].items()}}))
    return result


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[1])
    p.add_argument('--moodle-root', type=Path, required=True)
    p.add_argument('--commit', default=BASELINE)
    p.add_argument('--output-dir', type=Path, required=True)
    a = p.parse_args()
    result = audit(a.repo, a.moodle_root, a.commit, a.output_dir)
    raise SystemExit(0 if result['complete'] else 2)
