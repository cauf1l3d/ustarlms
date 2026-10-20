#!/usr/bin/env python3
"""Build a fixed-source private bridge readiness ZIP, never an activation package."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import zipfile

import connect_private_bridge as bridge

ROOT=Path(__file__).resolve().parents[2]


def build(commit,output,*,repo=ROOT):
    if not re.fullmatch(r'(?:HEAD|[a-f0-9]{40})',commit):raise ValueError('exact_commit_required')
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL)
    source=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    subprocess.check_call(['git','-C',str(repo),'diff','--quiet',bridge.PAYLOAD,source,'--',
                           'moodle/local/ustar','moodle/theme/ustar'])
    reference={'schema':1,'tool_commit':source,'payload_commit':bridge.PAYLOAD,
        'files':{name:hashlib.sha256(git('show',bridge.PAYLOAD+':moodle/'+name)).hexdigest()
                 for name in bridge.SECURITY_FILES}}
    files={'__main__.py':b'from connect_private_bridge import main\nraise SystemExit(main())\n',
           'REFERENCE.json':(json.dumps(reference,sort_keys=True)+'\n').encode()}
    for name in ('connect_private_bridge','academy_https'):
        files[name+'.py']=git('show',source+':scripts/infra/'+name+'.py')
    files['connect_install.py']=git('show',source+':connect-worker/deploy/install.py')
    manifest={'schema':1,'tool_commit':source,'files':{
        name:hashlib.sha256(raw).hexdigest() for name,raw in sorted(files.items())}}
    files['MANIFEST.json']=(json.dumps(manifest,sort_keys=True)+'\n').encode()
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for name,raw in sorted(files.items()):
            entry=zipfile.ZipInfo(name,(2026,1,1,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED
            entry.external_attr=0o100644<<16;archive.writestr(entry,raw)
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-private-bridge-check-'+source[:8]+'.pyz');target.write_bytes(stream.getvalue())
    digest=hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.pyz.sha256').write_text(digest+'  '+target.name+'\n')
    metadata={'source_commit':source,'payload_commit':bridge.PAYLOAD,'sha256':digest,
        'transport_files':len(reference['files']),'configuration_changed':False,'activation_supported':False}
    target.with_suffix('.pyz.metadata.json').write_text(json.dumps(metadata,sort_keys=True,indent=2)+'\n')
    print(json.dumps(metadata,sort_keys=True));return target


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit',default='HEAD');parser.add_argument('--output-dir',required=True)
    args=parser.parse_args();build(args.commit,args.output_dir)
