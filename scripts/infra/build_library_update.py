#!/usr/bin/env python3
"""Deterministic exact-Git route access installer; default artifact mode is offline."""
import argparse
import base64
import gzip
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import xml.etree.ElementTree as ET
import library_update as update

ROOT=Path(__file__).resolve().parents[2]
FROZEN='51332a218f9ff58f5aa7074f80a1a00426502108'
MODULES=('library_update','connect_con02_contract','connect_con02_state','connect_academy_installer')

def git(repo,*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL,timeout=30)

def profile(repo,commit):
    payload={}
    for row in git(repo,'ls-tree','-rz',commit,'--','moodle/local/ustar','moodle/theme/ustar').split(b'\0'):
        if not row:continue
        meta,name=row.split(b'\t',1);mode,kind,oid=meta.decode().split()
        update.require(kind=='blob' and mode in ('100644','100755'),'regular_git_source_required')
        payload[name.decode().removeprefix('moodle/')]=git(repo,'cat-file','blob',oid)
    fields,indexes={},{}
    for table in ET.fromstring(payload['local/ustar/db/install.xml']).findall('.//TABLE'):
        name=table.attrib['NAME']
        fields[name]={f.attrib['NAME']:{'type':f.attrib['TYPE'],'length':f.attrib.get('LENGTH'),
            'notnull':f.attrib.get('NOTNULL')=='true','default':f.attrib.get('DEFAULT'),
            'sequence':f.attrib.get('SEQUENCE')=='true'} for f in table.findall('./FIELDS/FIELD')}
        indexes[name]=[{'fields':[f.strip() for f in k.attrib['FIELDS'].split(',')],
            'unique':k.attrib.get('TYPE') in ('primary','unique','foreign-unique') or k.attrib.get('UNIQUE')=='true'}
            for k in [*table.findall('./KEYS/KEY'),*table.findall('./INDEXES/INDEX')]]
    versions={key:int(re.search(rb'\$plugin->version\s*=\s*(\d+)',payload[scope+'/version.php'])[1])
        for key,scope in [('local_ustar','local/ustar'),('theme_ustar','theme/ustar')]}
    return {'files':{p:update.sha(raw) for p,raw in sorted(payload.items())},'tables':sorted(fields),
        'schema_fields':fields,'schema_indexes':indexes,'versions':versions},payload

def build(tool_commit,output,*,repo=ROOT):
    repo=Path(repo)
    update.require(re.fullmatch('[a-f0-9]{40}',tool_commit),'exact_tool_commit_required')
    update.require(git(repo,'rev-parse',tool_commit+'^{commit}').decode().strip()==tool_commit,'exact_tool_commit_required')
    for scope in ('moodle/local/ustar','moodle/theme/ustar'):
        update.require(git(repo,'rev-parse',tool_commit+':'+scope)==git(repo,'rev-parse',update.APPLICATION+':'+scope),
            'qualified_application_tree_changed')
    before={key:profile(repo,key)[0] for key in update.BASELINES}
    candidate,payload=profile(repo,update.APPLICATION)
    update.require(candidate['versions']=={k:int(v) for k,v in update.VERSIONS.items()}
        and len(candidate['tables'])==108,'fixed_library_profile')
    update.require(all(len(p['tables'])==104 and p['versions']=={k:int(v) for k,v in update.BEFORE_VERSIONS.items()}
        for p in before.values()),'fixed_1100_profile')
    needed={p for p in candidate['files'] if any(old['files'].get(p)!=candidate['files'][p] for old in before.values())}
    payload={p:payload[p] for p in needed}
    sources={}
    for name in (*MODULES,'build_library_update'):
        path='scripts/infra/'+name+'.py';raw=git(repo,'show',tool_commit+':'+path)
        update.require(raw==(repo/path).read_bytes(),'dirty_builder_source')
        if name not in ('library_update','build_library_update'):
            update.require(raw==git(repo,'show',FROZEN+':'+path),'frozen_helper_changed')
        if name!='build_library_update':sources[name]=raw
    preserved=['opt/ustar-connect/current',CONFIG,
        'opt/ustar/data/moodle/public/.ustar-connect/bridge.php',
        'etc/ustar-connect/receiver.json','etc/ustar-connect/notifications.json','etc/ustar-connect/probe.json',
        'etc/ustar-console-egress/xray.json',update.CRON_SCHEDULE]
    manifest={'schema':1,'tool_commit':tool_commit,'application_commit':update.APPLICATION,'baselines':before,
        'candidate':candidate,'preserved':preserved,
        'modules':{p:update.sha(raw) for p,raw in sources.items()}}
    update.Update(Path('/'),manifest,payload,None)
    envelope={'manifest':manifest,'payload':{p:base64.b64encode(raw).decode() for p,raw in sorted(payload.items())},
        'sources':{p:base64.b64encode(raw).decode() for p,raw in sources.items()}}
    packed=base64.b64encode(gzip.compress(json.dumps(envelope,sort_keys=True).encode(),mtime=0)).decode()
    bootstrap='''#!/usr/bin/env python3
"""USTAR unified Library update. Run without arguments for offline PLAN_ONLY."""
import base64,gzip,hashlib,importlib.abc,importlib.util,json,sys
from pathlib import Path
def unique(pairs):
    result={}
    for k,v in pairs:
        if k in result:raise ValueError('duplicate_json_key')
        result[k]=v
    return result
try:
    bundle=json.loads(gzip.decompress(base64.b64decode(PACKED,validate=True)),object_pairs_hook=unique)
    sources={p:base64.b64decode(raw,validate=True) for p,raw in bundle['sources'].items()}
    if set(sources)!=set(bundle['manifest']['modules']):raise ValueError('module_inventory')
    if any(hashlib.sha256(raw).hexdigest()!=bundle['manifest']['modules'][p] for p,raw in sources.items()):raise ValueError('module_digest')
    folder=Path(__file__).absolute().parent
    sys.path[:]=[p for p in sys.path if p and Path(p).resolve() not in (folder,Path.cwd())]
    class Embedded(importlib.abc.MetaPathFinder,importlib.abc.Loader):
        def find_spec(self,name,path=None,target=None):return importlib.util.spec_from_loader(name,self) if name in sources else None
        def create_module(self,spec):return None
        def exec_module(self,module):
            module.__file__='<ustar-route-access/'+module.__name__+'.py>'
            exec(compile(sources[module.__name__],module.__file__,'exec'),module.__dict__)
    sys.meta_path.insert(0,Embedded())
    for name in sources:sys.modules.pop(name,None)
    from library_update import main
    payload={p:base64.b64decode(raw,validate=True) for p,raw in bundle['payload'].items()}
    from library_update import Update
    Update(Path('/'),bundle['manifest'],payload,None)
except BaseException:
    print('{"phase":"refused","reason":"embedded_bundle_invalid"}');sys.exit(2)
sys.exit(main(bundle=(bundle['manifest'],payload)))
'''
    script=bootstrap.replace('try:\n    bundle=',"PACKED = "+repr(packed)+"\ntry:\n    bundle=",1).encode()
    compile(script,'<route-access-installer>','exec')
    metadata={'schema':1,'tool_commit':tool_commit,'application_commit':update.APPLICATION,
        'baseline_commits':list(update.BASELINES),'versions':update.VERSIONS,'tables':108,
        'payload_files':len(candidate['files']),'changed_payload_files':len(payload),
        'worker_changed':False,'preserved_worker':update.WORKER,'database_restore':False,'recovery':'forward_only',
        'script_sha256':update.sha(script),'default_host_access':False}
    members={'update.py':script,'INSTALL.json':(json.dumps(metadata,sort_keys=True,indent=2)+'\n').encode()}
    members['SHA256SUMS']=''.join(update.sha(raw)+'  '+p+'\n' for p,raw in sorted(members.items())).encode()
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-library-'+tool_commit[:8]+'.tar.gz')
    with target.open('wb') as raw,gzip.GzipFile(fileobj=raw,mode='wb',filename='',mtime=0) as zipped:
        with tarfile.open(fileobj=zipped,mode='w') as tar:
            for name,content in sorted(members.items()):
                entry=tarfile.TarInfo(name);entry.mode=0o600;entry.size=len(content);entry.mtime=0;tar.addfile(entry,io.BytesIO(content))
    result={'archive':str(target),'sha256':update.sha(target.read_bytes()),'bytes':target.stat().st_size,**metadata}
    target.with_suffix('.gz.sha256').write_text(result['sha256']+'  '+target.name+'\n')
    target.with_suffix('.gz.metadata.json').write_text(json.dumps(result,sort_keys=True,indent=2)+'\n')
    return result

CONFIG=update.CONFIG
if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--tool-commit',required=True);p.add_argument('--output',type=Path,required=True)
    args=p.parse_args();print(json.dumps(build(args.tool_commit,args.output),sort_keys=True))
