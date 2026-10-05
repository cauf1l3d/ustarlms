#!/usr/bin/env python3
"""Compare installed Moodle files with an exact upstream Git tree; never load PHP."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess


REFERENCE = '1cd17816c56a7df7ee796892efccaf1ed5347340'
# Dated owner inventory, 2026-10-05: checked separately from stock Moodle.
PLUGIN_ROOTS = (
    'public/admin/tool/mulib', 'public/admin/tool/murelation',
    'public/blocks/ai_chat', 'public/blocks/rbreport', 'public/blocks/xp',
    'public/filter/filtercodes', 'public/lib/editor/tiny/plugins/ai',
    'public/local/ai_manager', 'public/local/cohortrole', 'public/local/geniai',
    'public/local/intelliboard', 'public/local/lumination', 'public/local/preventcopy',
    'public/local/ustar', 'public/mod/customcert', 'public/mod/pulse',
    'public/mod/rumbletalkchat', 'public/theme/boost_union', 'public/theme/ustar',
)


def excluded(path):
    parts = PurePosixPath(path).parts
    return (path in ('config.php', 'public/config.php')
            or any(p.startswith('.') or p in ('tests', 'node_modules') for p in parts)
            or path == 'vendor' or path.startswith('vendor/')
            or any(path == p or path.startswith(p + '/') for p in PLUGIN_ROOTS))


def reference_tree(repo, commit):
    if not re.fullmatch(r'[0-9a-f]{40}', commit):
        raise ValueError('An exact 40-character commit SHA is required')
    env = dict(os.environ, GIT_NO_REPLACE_OBJECTS='1', GIT_OPTIONAL_LOCKS='0')
    data = subprocess.check_output(
        ['git', '-c', 'safe.directory=' + str(repo.resolve()), '-C', str(repo),
         'ls-tree', '-r', '-z', commit], env=env, stderr=subprocess.DEVNULL, timeout=30)
    files, skipped = {}, []
    for entry in data.split(b'\0'):
        if not entry:
            continue
        meta, rawpath = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode('ascii').split()
        path = rawpath.decode('utf-8')
        if path.startswith('/') or '..' in PurePosixPath(path).parts:
            raise ValueError('Unsafe reference path')
        if excluded(path):
            continue
        if kind != 'blob' or mode not in ('100644', '100755'):
            skipped.append(path)
        else:
            files[path] = oid
    if 'public/version.php' not in files or not files:
        raise ValueError('Reference does not contain the expected Moodle 5.1 tree')
    return files, skipped


def file_oid(root, rel):
    path = root / rel
    if any(p.is_symlink() for p in (path, *path.parents)):
        raise ValueError('symlink')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode):
            raise ValueError('not_regular_file')
        digest = hashlib.sha1(b'blob ' + str(before.st_size).encode('ascii') + b'\0')
        while chunk := os.read(fd, 1024 * 1024):
            digest.update(chunk)
        after = os.fstat(fd)
        if (before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (
                after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise ValueError('changed_during_read')
        return digest.hexdigest()
    finally:
        os.close(fd)


def audit(repo, root, commit=REFERENCE):
    root = Path(root).absolute()
    if not root.is_dir() or any(p.is_symlink() for p in (root, *root.parents)):
        raise ValueError('Missing or symlink Moodle root')
    files, skipped = reference_tree(Path(repo), commit)
    result = {
        'measured_at': datetime.now(timezone.utc).isoformat(),
        'reference_repository': 'https://github.com/moodle/moodle.git',
        'reference_commit': commit,
        'moodle_root': str(root), 'not_an_atomic_snapshot': True,
        'comparison_method': 'Git blob SHA-1; no PHP/bootstrap/DB/network execution',
        'scope': 'Reference-tracked non-hidden runtime files; extra names only',
        'exclusions': ['config.php', 'public/config.php', 'hidden paths',
                       'tests', 'node_modules', 'root vendor', *PLUGIN_ROOTS],
        'matching': [], 'changed': [], 'missing': [], 'extra': [],
        'errors': {}, 'reference_skipped': skipped,
    }
    for rel, expected in sorted(files.items()):
        try:
            actual = file_oid(root, rel)
        except FileNotFoundError:
            result['missing'].append(rel)
        except OSError as e:
            result['errors'][rel] = 'os_error_' + str(e.errno)
        except ValueError as e:
            result['errors'][rel] = str(e)
        else:
            if actual == expected:
                result['matching'].append(rel)
            else:
                result['changed'].append({'path': rel, 'expected_oid': expected,
                                          'observed_oid': actual})

    def onerror(e):
        result['errors'][str(Path(e.filename).relative_to(root))] = 'unreadable_directory'

    for parent, dirs, names in os.walk(root, followlinks=False, onerror=onerror):
        for name in list(dirs):
            path = Path(parent) / name
            rel = path.relative_to(root).as_posix()
            if excluded(rel):
                dirs.remove(name)
            elif path.is_symlink():
                result['errors'][rel] = 'symlink_directory'
                dirs.remove(name)
        for name in names:
            rel = (Path(parent) / name).relative_to(root).as_posix()
            if not excluded(rel) and rel not in files:
                result['extra'].append(rel)
    result['extra'].sort()
    result['complete'] = not result['errors'] and not skipped
    result['match'] = result['complete'] and not any(
        result[k] for k in ('changed', 'missing', 'extra'))
    return result


def save_report(result, output):
    output = Path(output).absolute()
    root = Path(result['moodle_root'])
    if output.is_relative_to(root) or root.is_relative_to(output):
        raise ValueError('Report directory must be outside the Moodle tree')
    if any(p.is_symlink() for p in (output, *output.parents)):
        raise ValueError('Symlink report directory')
    if not output.exists():
        output.mkdir(mode=0o700)
    if not output.is_dir() or output.stat().st_mode & 0o077:
        raise ValueError('Report directory must be private (0700)')
    counts = {k: len(result[k]) for k in ('matching', 'changed', 'missing', 'extra',
                                       'errors', 'reference_skipped')}
    with (output / 'core_comparison.json').open('x') as stream:
        json.dump(result, stream, ensure_ascii=False, indent=2)
        stream.write('\n')
    summary = {k: result[k] for k in ('measured_at', 'reference_commit', 'complete', 'match')}
    summary.update(counts)
    summary['report'] = str(output / 'core_comparison.json')
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reference-repo', required=True, type=Path,
                        help='Existing upstream metadata repository; no network fetch here')
    parser.add_argument('--reference-commit', default=REFERENCE,
                        help='Exact immutable upstream commit; no branch or tag defaults')
    parser.add_argument('--moodle-root', required=True, type=Path,
                        help='Parent of public/, not the public directory itself')
    parser.add_argument('--output-dir', required=True, type=Path,
                        help='Separate new/private directory; existing reports never overwritten')
    args = parser.parse_args()
    try:
        result = audit(args.reference_repo, args.moodle_root, args.reference_commit)
        print(json.dumps(save_report(result, args.output_dir), ensure_ascii=False))
    except (ValueError, OSError, subprocess.SubprocessError) as e:
        print('CORE_PREFLIGHT_ERROR=' + type(e).__name__)
        return 2
    return 0 if result['match'] else (3 if result['complete'] else 2)


if __name__ == '__main__':
    raise SystemExit(main())
