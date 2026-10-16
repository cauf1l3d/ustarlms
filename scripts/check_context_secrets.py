#!/usr/bin/env python3
"""Validate structured snapshots and strong secret indicators; never echo a value."""
import argparse
import importlib.util
from pathlib import Path
import re
import sys

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('server_facts',ROOT/'scripts/infra/server_facts.py')
facts=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(facts)
PATTERNS={
 'private_key':re.compile(r'-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----'),
 'github_credential':re.compile(r'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})'),
 'telegram_credential':re.compile(r'\b\d{7,14}:[A-Za-z0-9_-]{30,}\b'),
 'jwt':re.compile(r'\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b'),
 'php_db_password_literal':re.compile(r'''\$CFG->dbpass\s*=\s*['"][^'"\s<>]{8,}['"]'''),
}

def check(root):
    errors=[]
    snapshots=root/'context/runtime/server-facts'
    if snapshots.exists():
        for path in snapshots.iterdir():
            if path.name=='README.md':
                continue
            if path.is_symlink() or not path.is_dir():
                errors.append((str(path.relative_to(root)),'unexpected_snapshot_entry'))
                continue
            try:
                facts.validate_payload(facts.read_payload(path))
            except Exception:
                errors.append((str(path.relative_to(root)),'snapshot_schema_or_checksum'))
    for path in (root/'context').rglob('*'):
        if not path.is_file() or path.suffix not in {'.md','.txt','.yaml','.yml','.json'}:
            continue
        if path.is_symlink():
            errors.append((str(path.relative_to(root)),'symlink_context_file'))
            continue
        for rule,pattern in PATTERNS.items():
            if pattern.search(path.read_text()):
                errors.append((str(path.relative_to(root)),rule))
    return errors

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',type=Path,default=ROOT)
    args=parser.parse_args()
    errors=check(args.root)
    for path,rule in errors:
        print(f'CONTEXT_SECRET_GUARD path={path} rule={rule}',file=sys.stderr)
    if errors:
        raise SystemExit(1)
    print('CONTEXT_SECRET_GUARD_OK')

if __name__=='__main__':
    main()
