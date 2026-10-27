#!/usr/bin/env python3
"""Build the coordinated update from immutable Git objects; never read server1."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile
import zipfile

import build_connect_readiness
import connect_private_bridge as bridge

ROOT=Path(__file__).resolve().parents[2]
PAYLOAD='1e8a8819f50785ebb4fb2640eeb9386c969d59d1'
MODULES=('connect_chat_link_update','connect_academy_installer','connect_readiness',
    'connect_delivery_readiness','cron_release_update','academy_https','backup_https_prepare',
    'production_patch_readiness','production_patch_upgrade','production_patch_switch_preflight',
    'lab_cold_checkpoint','lab_patch_upgrade','lab_patch_candidate')


def build(commit,output,*,repo=ROOT):
    if not re.fullmatch(r'(?:HEAD|[a-f0-9]{40})',commit):raise ValueError('exact_commit_required')
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL)
    tool=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    # A later tooling/docs head must not silently add an unreviewed application.
    bridge.verify_application_delta(repo,tool)
    if (ROOT/'scripts/infra/build_connect_readiness.py').read_bytes()!=git('show',tool+':scripts/infra/build_connect_readiness.py'):
        raise ValueError('dirty_builder_dependency')
    with tempfile.TemporaryDirectory() as temp:
        readiness=build_connect_readiness.build(tool,temp,repo=repo,profile='chat-link')
        with zipfile.ZipFile(readiness) as z:reference=json.loads(z.read('connect_preflight_data/reference.json'))
    reference['source_commit']=PAYLOAD
    files={'reference.json':(json.dumps(reference,sort_keys=True)+'\n').encode(),
           '__main__.py':b'from connect_chat_link_update import main\nraise SystemExit(main())\n'}
    for name in reference['candidate']['files']:
        files['payload/'+name]=git('show',PAYLOAD+':moodle/'+name)
    for name in MODULES:files[name+'.py']=git('show',tool+':scripts/infra/'+name+'.py')
    files['backup_tls_sources/producer.py']=git('show',tool+':scripts/infra/hdd_backup.py')
    files['backup_tls_sources/scheduler.py']=git('show',tool+':scripts/infra/hdd_backup_schedule.py')
    files['production_manifest.py']=git('show',tool+':scripts/production_manifest.py')
    manifest={'schema':1,'tool_commit':tool,'payload_commit':PAYLOAD,
              'baseline_commit':reference['baseline_commit'],
              'files':{name:hashlib.sha256(raw).hexdigest() for name,raw in sorted(files.items())}}
    files['INSTALL_MANIFEST.json']=(json.dumps(manifest,sort_keys=True,indent=2)+'\n').encode()
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for name,raw in sorted(files.items()):
            entry=zipfile.ZipInfo(name,(2026,1,1,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED
            entry.external_attr=0o100644<<16;archive.writestr(entry,raw)
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-chat-link-update-'+tool[:8]+'.pyz');target.write_bytes(stream.getvalue())
    digest=hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.pyz.sha256').write_text(digest+'  '+target.name+'\n')
    metadata={'tool_commit':tool,'payload_commit':PAYLOAD,'baseline_commit':reference['baseline_commit'],
              'sha256':digest,'files':len(reference['candidate']['files']),
              'tables':len(reference['candidate']['tables']),'host_access':False}
    target.with_suffix('.pyz.metadata.json').write_text(json.dumps(metadata,indent=2)+'\n')
    print(json.dumps(metadata));return target


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--commit',default='HEAD')
    parser.add_argument('--output-dir',required=True)
    args=parser.parse_args();build(args.commit,args.output_dir)
