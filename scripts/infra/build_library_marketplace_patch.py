#!/usr/bin/env python3
"""Build the bounded bounded Library0200 Marketplace successor."""
import argparse
import base64
import gzip
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import library_marketplace_patch as patch
from build_route_access_update import profile

ROOT=Path(__file__).resolve().parents[2]
FROZEN='51332a218f9ff58f5aa7074f80a1a00426502108'

def build(tool_commit,output,*,repo=ROOT):
    repo=Path(repo)
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL,timeout=30)
    def require(value,reason):
        if not value:raise ValueError(reason)
    require(re.fullmatch('[a-f0-9]{40}',tool_commit),'exact_tool_commit_required')
    require(git('rev-parse',tool_commit+'^{commit}').decode().strip()==tool_commit,'exact_tool_commit_required')
    for scope in ('moodle/local/ustar','moodle/theme/ustar'):
        require(git('rev-parse',tool_commit+':'+scope)==git('rev-parse',patch.APPLICATION+':'+scope),'qualified_application_tree_changed')
    oldprofile,before=profile(repo,patch.BASELINE);newprofile,after=profile(repo,patch.APPLICATION)
    require(oldprofile['versions']==newprofile['versions']=={'local_ustar':2026110200,'theme_ustar':2026110200},'fixed0200_versions')
    require(set(before)==set(after) and len(before)==649 and
        {p for p in before if before[p]!=after[p]}==set(patch.FILES),'bounded_marketplace_delta_required')
    require(before['local/ustar/db/install.xml']==after['local/ustar/db/install.xml']
        and before['local/ustar/db/upgrade.php']==after['local/ustar/db/upgrade.php'],'schema_or_migration_changed')
    payload={p:after[p] for p in patch.FILES}
    image=lambda raw:{'sha256':patch.digest(raw),'bytes':len(raw)}
    manifest={'schema':1,'baseline_commit':patch.BASELINE,'application_commit':patch.APPLICATION,
        'tool_commit':tool_commit,'before':{p:image(raw) for p,raw in sorted(before.items())},
        'after':{p:image(raw) for p,raw in sorted(after.items())},'preserved':list(patch.PRESERVED)}
    patch.runtime().Patch(Path('/'),manifest,payload,None)
    for name in ('library_marketplace_patch','build_library_marketplace_patch'):
        path='scripts/infra/'+name+'.py'
        require(git('show',tool_commit+':'+path)==(repo/path).read_bytes(),'dirty_builder_source')
    sources={name:git('show',tool_commit+':scripts/infra/'+name+'.py') for name in
        ('library_marketplace_patch','connect_con02_contract','connect_con02_state')}
    for name,raw in sources.items():
        if name!='library_marketplace_patch':require(raw==git('show',FROZEN+':scripts/infra/'+name+'.py'),'frozen_helper_changed')
    legacy=git('show',FROZEN+':scripts/infra/route_scorm_patch.py')
    require(patch.digest(legacy)==patch.LEGACY_SHA,'frozen_overlay_changed')
    adapter=git('show',tool_commit+':scripts/infra/scorm_tracking_patch.py')
    require(patch.digest(adapter)==patch.ADAPTER_SHA,'frozen_adapter_changed')
    manifest['modules']={p:patch.digest(raw) for p,raw in sources.items()}
    envelope={'manifest':manifest,'sources':{p:base64.b64encode(raw).decode() for p,raw in sources.items()},
        'payload':{p:base64.b64encode(raw).decode() for p,raw in payload.items()},'legacy':base64.b64encode(legacy).decode(),'adapter':base64.b64encode(adapter).decode()}
    packed=base64.b64encode(gzip.compress(json.dumps(envelope,sort_keys=True).encode(),mtime=0)).decode()
    bootstrap='''#!/usr/bin/env python3
"""USTAR Library0200 Marketplace overlay. Without arguments: offline PLAN_ONLY."""
import base64,gzip,hashlib,importlib.abc,importlib.util,json,sys
from pathlib import Path
PACKED = None
try:
    data=json.loads(gzip.decompress(base64.b64decode(PACKED,validate=True)))
    sources={p:base64.b64decode(raw,validate=True) for p,raw in data['sources'].items()}
    if set(sources)!=set(data['manifest']['modules']):raise ValueError('module_inventory')
    if any(hashlib.sha256(raw).hexdigest()!=data['manifest']['modules'][p] for p,raw in sources.items()):raise ValueError('module_digest')
    folder=Path(__file__).absolute().parent
    sys.path[:]=[p for p in sys.path if p and Path(p).resolve() not in (folder,Path.cwd())]
    class Embedded(importlib.abc.MetaPathFinder,importlib.abc.Loader):
        def find_spec(self,name,path=None,target=None):return importlib.util.spec_from_loader(name,self) if name in sources else None
        def create_module(self,spec):return None
        def exec_module(self,module):
            module.__file__='<ustar-library-marketplace/'+module.__name__+'.py>'
            exec(compile(sources[module.__name__],module.__file__,'exec'),module.__dict__)
    sys.meta_path.insert(0,Embedded())
    for name in sources:sys.modules.pop(name,None)
    from library_marketplace_patch import main
    payload={p:base64.b64decode(raw,validate=True) for p,raw in data['payload'].items()}
    legacy=base64.b64decode(data['legacy'],validate=True)
    adapter=base64.b64decode(data['adapter'],validate=True)
except BaseException:
    print('{"phase":"refused","reason":"embedded_bundle_invalid"}');sys.exit(2)
sys.exit(main(bundle=(data['manifest'],payload,legacy,adapter)))
'''
    script=bootstrap.replace('PACKED = None','PACKED = '+repr(packed)).encode();compile(script,'<marketplace-overlay>','exec')
    metadata={'schema':1,'tool_commit':tool_commit,'application_commit':patch.APPLICATION,
        'baseline_commit':patch.BASELINE,'local_ustar':2026110200,'theme_ustar':2026110200,'payload_files':649,
        'changed_files':list(patch.FILES),'schema_changed':False,'database_restore':False,'business_data_changed':False,'cache_purge_required':True,
        'worker_changed':False,'web_restart_required':True,'script_sha256':patch.digest(script)}
    members={'update.py':script,'INSTALL.json':(json.dumps(metadata,sort_keys=True,indent=2)+'\n').encode()}
    members['SHA256SUMS']=''.join(patch.digest(raw)+'  '+p+'\n' for p,raw in sorted(members.items())).encode()
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-library-marketplace-'+tool_commit[:8]+'.tar.gz')
    with target.open('wb') as raw,gzip.GzipFile(fileobj=raw,mode='wb',filename='',mtime=0) as zipped:
        with tarfile.open(fileobj=zipped,mode='w') as tar:
            for name,content in sorted(members.items()):
                item=tarfile.TarInfo(name);item.mode=0o600;item.size=len(content);item.mtime=0;tar.addfile(item,io.BytesIO(content))
    result={'archive':str(target),'bytes':target.stat().st_size,'sha256':patch.digest(target.read_bytes()),**metadata}
    target.with_suffix('.gz.sha256').write_text(result['sha256']+'  '+target.name+'\n')
    return result

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--tool-commit',required=True);parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args();print(json.dumps(build(args.tool_commit,args.output),sort_keys=True))
