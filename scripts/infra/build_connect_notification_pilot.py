#!/usr/bin/env python3
"""Freeze the exact one-file policy overlay and one-employee sender transaction."""
import argparse,hashlib,importlib.util,io,json,re,subprocess,tempfile,zipfile
from pathlib import Path
import connect_private_bridge as bridge
ROOT=Path(__file__).resolve().parents[2]


def build(commit,output,*,repo=ROOT):
    def require(value):
        if not value:raise ValueError('exact_source_required')
    require(re.fullmatch(r'(?:HEAD|[a-f0-9]{40})',commit))
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL)
    source=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    modules=('connect_notification_pilot','connect_bridge_activation','connect_private_bridge','academy_https')
    for name in ('build_connect_notification_pilot',*modules):
        path='scripts/infra/'+name+'.py'
        require((ROOT/path).read_bytes()==git('show',source+':'+path))
    bridge.verify_application_delta(repo,source)
    path=bridge.NOTIFICATION_POLICY
    before=git('show',bridge.PAYLOAD+':'+path);after=git('show',source+':'+path)
    require(hashlib.sha256(after).hexdigest()==bridge.REVIEWED_OVERLAYS[path])
    ref={'schema':1,'tool_commit':source,'bridge_release':'27117c8c25b7dda28385c787f79e99636af10216',
        'bridge':{'schema':1,'tool_commit':source,'payload_commit':bridge.PAYLOAD,
        'files':{n:hashlib.sha256(git('show',bridge.PAYLOAD+':moodle/'+n)).hexdigest() for n in bridge.SECURITY_FILES}},
        'policy':{'path':'opt/ustar/data/moodle/public/public/local/ustar/classes/connect/notification_delivery.php',
            'before':hashlib.sha256(before).hexdigest(),'after':hashlib.sha256(after).hexdigest()}}
    files={'__main__.py':b'from connect_notification_pilot import main\nraise SystemExit(main())\n',
        'REFERENCE.json':(json.dumps(ref,sort_keys=True)+'\n').encode(),'notification_delivery.php':after}
    for name in modules:files[name+'.py']=git('show',source+':scripts/infra/'+name+'.py')
    files['connect_install.py']=git('show',source+':connect-worker/deploy/install.py')
    spec=importlib.util.spec_from_loader('exact_notification_receiver_builder',loader=None)
    builder=importlib.util.module_from_spec(spec);builder.__file__=str(Path(repo)/'connect-worker/deploy/build_release.py')
    exec(compile(git('show',source+':connect-worker/deploy/build_release.py'),builder.__file__,'exec'),builder.__dict__)
    with tempfile.TemporaryDirectory() as td:files['receiver.tar.gz']=builder.build(source,td).read_bytes()
    manifest={'schema':1,'tool_commit':source,'files':{n:hashlib.sha256(v).hexdigest() for n,v in sorted(files.items())}}
    files['MANIFEST.json']=(json.dumps(manifest,sort_keys=True)+'\n').encode()
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name,raw in sorted(files.items()):
            item=zipfile.ZipInfo(name,(2026,1,1,0,0,0));item.compress_type=zipfile.ZIP_DEFLATED
            item.external_attr=0o100644<<16;z.writestr(item,raw)
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-notify-pilot-'+source[:8]+'.pyz');target.write_bytes(stream.getvalue())
    digest=hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.pyz.sha256').write_text(digest+'  '+target.name+'\n')
    metadata={'source_commit':source,'sha256':digest,'pilot_only':True,'new_only':True,'schema_migration':False,
        'policy':ref['policy'],'default_host_access':False}
    target.with_suffix('.pyz.metadata.json').write_text(json.dumps(metadata,sort_keys=True,indent=2)+'\n')
    print(json.dumps(metadata,sort_keys=True));return target


if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--commit',default='HEAD');p.add_argument('--output-dir',required=True)
    args=p.parse_args();build(args.commit,args.output_dir)
