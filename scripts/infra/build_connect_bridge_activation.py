#!/usr/bin/env python3
"""Freeze one stdlib-only coordinated bridge installer and receiver from Git."""
import argparse,hashlib,importlib.util,io,json,re,subprocess,tempfile,zipfile
from pathlib import Path
import connect_private_bridge as bridge
ROOT=Path(__file__).resolve().parents[2]

def build(commit,output,*,repo=ROOT):
    if not re.fullmatch(r'(?:HEAD|[a-f0-9]{40})',commit):raise ValueError('exact_commit_required')
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL)
    source=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    for name in ('build_connect_bridge_activation','connect_bridge_activation','connect_private_bridge'):
        path='scripts/infra/'+name+'.py'
        if (ROOT/path).read_bytes()!=git('show',source+':'+path):raise ValueError('dirty_builder_dependency')
    subprocess.run(['git','-C',str(repo),'diff','--quiet',bridge.PAYLOAD,source,'--','moodle/local/ustar','moodle/theme/ustar'],check=True)
    ref={'schema':1,'tool_commit':source,'payload_commit':bridge.PAYLOAD,'files':{
        name:hashlib.sha256(git('show',bridge.PAYLOAD+':moodle/'+name)).hexdigest() for name in bridge.SECURITY_FILES}}
    files={'__main__.py':b'from connect_bridge_activation import main\nraise SystemExit(main())\n',
        'REFERENCE.json':(json.dumps(ref,sort_keys=True)+'\n').encode()}
    for name in ('connect_bridge_activation','connect_private_bridge','academy_https'):
        files[name+'.py']=git('show',source+':scripts/infra/'+name+'.py')
    files['connect_install.py']=git('show',source+':connect-worker/deploy/install.py')
    spec=importlib.util.spec_from_loader('exact_receiver_builder',loader=None)
    builder=importlib.util.module_from_spec(spec);builder.__file__=str(Path(repo)/'connect-worker/deploy/build_release.py')
    exec(compile(git('show',source+':connect-worker/deploy/build_release.py'),builder.__file__,'exec'),builder.__dict__)
    with tempfile.TemporaryDirectory() as td:files['receiver.tar.gz']=builder.build(source,td).read_bytes()
    manifest={'schema':1,'tool_commit':source,'files':{n:hashlib.sha256(v).hexdigest() for n,v in sorted(files.items())}}
    files['MANIFEST.json']=(json.dumps(manifest,sort_keys=True)+'\n').encode()
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name,raw in sorted(files.items()):
            info=zipfile.ZipInfo(name,(2026,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED
            info.external_attr=0o100644<<16;z.writestr(info,raw)
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-bridge-activate-'+source[:8]+'.pyz');target.write_bytes(stream.getvalue())
    digest=hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.pyz.sha256').write_text(digest+'  '+target.name+'\n')
    target.with_suffix('.pyz.metadata.json').write_text(json.dumps({'source_commit':source,'payload_commit':bridge.PAYLOAD,
        'sha256':digest,'activation_scope':'telegram_link_claim_only','application_upgrade':False},sort_keys=True)+'\n')
    print(json.dumps({'file':target.name,'sha256':digest,'source_commit':source}));return target

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--commit',default='HEAD');p.add_argument('--output-dir',required=True)
    args=p.parse_args();build(args.commit,args.output_dir)
