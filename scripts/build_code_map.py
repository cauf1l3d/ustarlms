#!/usr/bin/env python3
"""Deterministic source/schema/API inventory of an immutable Git tree; no runtime reads."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SCOPES = ['moodle/local/ustar', 'moodle/theme/ustar']


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def generate(commit, output):
    sha = git('rev-parse', '--verify', commit + '^{commit}').decode().strip()
    stamp = git('show', '-s', '--format=%cI', sha).decode().strip()
    names = sorted(filter(None, git('ls-tree', '-r', '--name-only', sha, '--', *SCOPES).decode().splitlines()))
    # One batch process keeps generation bounded by source size instead of process count.
    specs = [sha + ':' + p for p in names]
    raw = subprocess.check_output(['git', '-C', str(ROOT), 'cat-file', '--batch'], input=('\n'.join(specs)+'\n').encode())
    contents = {}; pos = 0
    for name in names:
        end = raw.index(b'\n', pos)
        header = raw[pos:end].split()
        if len(header) != 3 or header[1] != b'blob':
            raise ValueError('Expected source blob: ' + name)
        size = int(header[2]); pos = end + 1
        contents[name] = raw[pos:pos+size]; pos += size + 1
    prefix = 'moodle/local/ustar/'
    schema = ET.fromstring(contents[prefix+'db/install.xml'])
    tables = schema.findall('./TABLES/TABLE')
    services = contents[prefix+'db/services.php'].decode()
    funcs = re.findall(r"^\s*'(local_ustar_\w+)'\s*=>\s*\[(.*?)\n\s*\],", services, re.M|re.S)
    if output.is_symlink() or any(p.is_symlink() for p in output.parents):
        raise ValueError('symlink_output_forbidden')
    output.mkdir(parents=True, exist_ok=True)
    def write(name, text):
        target = output/name
        if target.is_symlink():
            raise ValueError('symlink_output_forbidden')
        target.write_text(text.rstrip()+'\n')
    heading = f'Source: `{sha}`. Git source only; not a live database snapshot.\n\n'
    write('php_files.md', '# PHP files\n\n'+heading+'\n'.join('- `'+p+'`' for p in names if p.endswith('.php')))
    classes=[]
    for p,b in contents.items():
        if '/classes/' not in p or not p.endswith('.php'): continue
        text=b.decode(); ns=re.search(r'^namespace\s+([^;]+);',text,re.M)
        for match in re.finditer(r'^\s*(?:(?:abstract|final|readonly)\s+)?(?:class|interface|trait)\s+(\w+)',text,re.M):
            classes.append(((ns[1]+'\\' if ns else '')+match[1],p))
    write('classes.md','# Classes, interfaces and traits\n\n'+heading+'| Symbol | File |\n|---|---|\n'+'\n'.join(f'| `{c}` | `{p}` |' for c,p in classes))
    rows=['# XMLDB tables','',heading, '| Logical table | Fields | Indexes / keys |','|---|---|---|']
    for t in tables:
        fields=', '.join('`'+f.attrib['NAME']+':'+f.attrib['TYPE']+'`' for f in t.findall('./FIELDS/FIELD'))
        keys='; '.join('`'+k.attrib['NAME']+'('+k.attrib['FIELDS']+')`'+(' UNIQUE' if k.attrib.get('UNIQUE')=='true' else '') for k in [*t.findall('./KEYS/KEY'),*t.findall('./INDEXES/INDEX')])
        rows.append(f"| `{t.attrib['NAME']}` | {fields} | {keys} |")
    write('database_tables.md','\n'.join(rows))
    rows=['# Registered USTAR external functions','',heading,'Source: `moodle/local/ustar/db/services.php`. Service: `ustar_workspace`. Registration does not prove coverage of current native pages or production enablement.','','| Function | Type | Class | Capability declaration |','|---|---|---|---|']
    for name,body in funcs:
        def value(key):
            match=re.search(r"'"+key+r"'\s*=>\s*'([^']*)'",body)
            return match[1].replace('\\\\','\\') if match else ''
        rows.append(f"| `{name}` | {value('type')} | `{value('classname')}` | `{value('capabilities')}` |")
    write('web_services.md','\n'.join(rows))
    manifest={'schema_version':1,'kind':'git_source','source_commit':sha,'source_commit_date':stamp,'scopes':SCOPES,'tree_ids':{p:git('rev-parse',sha+':'+p).decode().strip() for p in SCOPES},'counts':{'files':len(names),'php':sum(p.endswith('.php') for p in names),'symbols':len(classes),'tables':len(tables),'ustar_external_functions':len(funcs)},'files':[{'path':p,'sha256':hashlib.sha256(b).hexdigest()} for p,b in contents.items()]}
    write('source_manifest.json',json.dumps(manifest,ensure_ascii=False,indent=2))
    write('git_commit.txt',sha)
    write('generated_at.txt','Deterministic source commit date: '+stamp+'\nThis is not a production observation time.')
    print(json.dumps(manifest['counts']))


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit',required=True)
    parser.add_argument('--output-dir',type=Path,default=ROOT/'context/code_map/generated')
    args=parser.parse_args()
    generate(args.commit,args.output_dir)
