#!/usr/bin/env python3
"""Build a separate ordinary.py from immutable application and operator commits."""
import ast
import base64
import contextlib
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
import connect_con02_contract as c
import connect_telegram_update as t
from build_connect_con02_update import reference_from_git as predecessor_from_git

ROOT = Path(__file__).resolve().parents[2]


def reference_from_git(repo, tool_commit):
    repo=Path(repo)
    c.require(re.fullmatch('[a-f0-9]{40}',tool_commit),'exact_tool_commit_required')
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL,timeout=30)
    c.require(git('rev-parse','--verify',tool_commit+'^{commit}').decode().strip()==tool_commit,'exact_tool_commit_required')
    previous=predecessor_from_git(repo,t.PREDECESSOR_TOOL)
    payload={}
    for entry in git('ls-tree','-r','-z',t.APP_COMMIT,'--','moodle/local/ustar','moodle/theme/ustar').split(b'\0'):
        if not entry:continue
        meta,path=entry.split(b'\t',1);mode,kind,oid=meta.decode().split()
        c.require(kind=='blob' and mode in ('100644','100755'),'regular_git_source_required')
        payload[path.decode().removeprefix('moodle/')]=git('cat-file','blob',oid)
    fields,indexes={},{}
    for table in ET.fromstring(payload['local/ustar/db/install.xml']).findall('.//TABLE'):
        name=table.attrib['NAME']
        fields[name]={f.attrib['NAME']:{'type':f.attrib['TYPE'],'length':f.attrib.get('LENGTH'),
            'notnull':f.attrib.get('NOTNULL')=='true','default':f.attrib.get('DEFAULT'),
            'sequence':f.attrib.get('SEQUENCE')=='true'} for f in table.findall('./FIELDS/FIELD')}
        indexes[name]=[{'fields':[f.strip() for f in k.attrib['FIELDS'].split(',')],
            'unique':k.attrib.get('TYPE') in ('primary','unique','foreign-unique') or k.attrib.get('UNIQUE')=='true'}
            for k in [*table.findall('./KEYS/KEY'),*table.findall('./INDEXES/INDEX')]]
    versions={}
    for key,scope in [('local_ustar','local/ustar'),('theme_ustar','theme/ustar')]:
        found=re.findall(rb'(?m)^\s*\$plugin->version\s*=\s*([0-9]{10})\s*;',payload[scope+'/version.php'])
        c.require(len(found)==1,'release_version');versions[key]=int(found[0])
    candidate={'files':{k:c.sha(v) for k,v in sorted(payload.items())},'tables':sorted(fields),
               'versions':versions,'schema_fields':fields,'schema_indexes':indexes}
    source=git('show',t.APP_COMMIT+':connect-worker/deploy/build_release.py')
    ns={'__name__':'_telegram_worker_builder','__file__':str(repo/'connect-worker/deploy/build_release.py')}
    exec(compile(source,ns['__file__'],'exec'),ns);ns['ROOT']=repo
    with tempfile.TemporaryDirectory() as td,contextlib.redirect_stdout(io.StringIO()):
        archive=ns['build'](t.APP_COMMIT,td).read_bytes()
    reference={'schema':1,'tool_commit':tool_commit,'app_commit':t.APP_COMMIT,'worker_commit':t.APP_COMMIT,
        'baseline_commit':c.APP_COMMIT,'legacy_commit':c.LEGACY_COMMIT,
        'baseline':previous.reference['candidate'],'candidate':candidate}
    return t.Bundle(reference,{},payload,archive,previous.legacy,previous)


def build(tool_commit, output, *, repo=ROOT):
    repo=Path(repo)
    c.require(re.fullmatch('[a-f0-9]{40}',tool_commit),'exact_tool_commit_required')
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL,timeout=30)
    for n in ('build_connect_telegram_update','build_connect_con02_update','connect_telegram_update','connect_con02_contract'):
        path='scripts/infra/'+n+'.py'
        c.require((repo/path).read_bytes()==git('show',tool_commit+':'+path),'dirty_builder_dependency')
    # The main native/browser jobs test the tool checkout. Require its actual
    # application and worker trees to be the very same immutable release.
    for scope in ('moodle/local/ustar','moodle/theme/ustar','connect-worker'):
        c.require(git('rev-parse',tool_commit+':'+scope)==git('rev-parse',t.APP_COMMIT+':'+scope),
                  'tool_release_component_mismatch')
    b=reference_from_git(repo,tool_commit)
    sources={n+'.py':git('show',tool_commit+':'+('scripts/' if n=='production_manifest' else 'scripts/infra/')+n+'.py').decode()
             for n in t.MODULES}
    values={'reference.json':(json.dumps(b.reference,sort_keys=True)+'\n').encode(),
        'predecessor.json':(json.dumps(b.predecessor.reference,sort_keys=True)+'\n').encode(),
        'worker.tar.gz':b.worker_archive,'predecessor-worker.tar.gz':b.predecessor.worker_archive,
        **{'payload/'+n:v for n,v in b.payload.items()},**{'legacy/'+n:v for n,v in b.legacy.items()}}
    assets={n:base64.b64encode(raw).decode() for n,raw in sorted(values.items())}
    manifest={'schema':1,'tool_commit':tool_commit,'app_commit':t.APP_COMMIT,'worker_commit':t.APP_COMMIT,
        'sources':{n:{'sha256':c.sha(raw.encode()),'bytes':len(raw.encode())} for n,raw in sorted(sources.items())},
        'assets':{n:{'sha256':c.sha(raw),'bytes':len(raw)} for n,raw in sorted(values.items())}}
    t.load_embedded(manifest,sources,assets)
    contract=sources['connect_con02_contract.py']
    decoder=next(ast.get_source_segment(contract,n) for n in ast.parse(contract).body if isinstance(n,ast.FunctionDef) and n.name=='decode_envelope')
    header='''#!/usr/bin/env python3
"""Exact-Git Telegram successor. Default: offline PLAN_ONLY."""
import json,sys
import importlib.abc,importlib.util
from pathlib import Path
class Refusal(ValueError):pass
def require(value,reason):
    if not value:raise Refusal(reason)
def unique(pairs):
    result={}
    for key,value in pairs:
        require(key not in result,'duplicate_embedded_json_key');result[key]=value
    return result
'''
    constants=''.join(n+' = '+repr(json.dumps(v,sort_keys=True,ensure_ascii=True))+'\n' for n,v in
                      [('MANIFEST_JSON',manifest),('SOURCES_JSON',sources),('ASSETS_JSON',assets)])
    bootstrap='''
try:
    manifest=json.loads(MANIFEST_JSON,object_pairs_hook=unique)
    sources=json.loads(SOURCES_JSON,object_pairs_hook=unique)
    assets=json.loads(ASSETS_JSON,object_pairs_hook=unique)
    decoded,_=decode_envelope(manifest,sources,assets,app_commit=APP_IDENTITY)
    require(set(decoded)==MODULE_INVENTORY,'embedded_module_inventory')
    parent=Path(__file__).absolute().parent
    sys.path[:]=[p for p in sys.path if p and Path(p).resolve() not in (parent,Path.cwd())]
    class Embedded(importlib.abc.MetaPathFinder,importlib.abc.Loader):
        def find_spec(self,name,path=None,target=None):
            return importlib.util.spec_from_loader(name,self) if name+'.py' in decoded else None
        def create_module(self,spec):return None
        def exec_module(self,module):
            module.__file__='<ustar-telegram/'+module.__name__+'.py>'
            exec(compile(decoded[module.__name__+'.py'],module.__file__,'exec'),module.__dict__)
    require(not any(n[:-3] in sys.modules for n in decoded),'embedded_module_shadowed')
    sys.meta_path.insert(0,Embedded())
    from connect_telegram_update import main,load_embedded
    bundle=load_embedded(manifest,sources,assets)
except BaseException:
    print('{"phase":"refused","reason":"embedded_bundle_invalid"}');sys.exit(2)
sys.exit(main(bundle=bundle))
'''
    raw=(header+decoder+'\n'+constants+'APP_IDENTITY = '+repr(t.APP_COMMIT)+'\nMODULE_INVENTORY = set('
         +repr(tuple(sorted(sources)))+')\n'+bootstrap).encode()
    c.require(len(raw)<=128*1024**2,'plain_artifact_size')
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-telegram-update-'+tool_commit[:8]+'.py');target.write_bytes(raw);target.chmod(0o644)
    digest=c.sha(raw)
    target.with_suffix('.py.sha256').write_text(digest+'  '+target.name+'\n')
    target.with_suffix('.py.metadata.json').write_text(json.dumps({'schema':1,'tool_commit':tool_commit,
        'app_commit':t.APP_COMMIT,'worker_commit':t.APP_COMMIT,'predecessor_tool':t.PREDECESSOR_TOOL,
        'payload_files':len(b.payload),'sha256':digest,'bytes':len(raw),'default_host_access':False},sort_keys=True,indent=2)+'\n')
    print(json.dumps({'artifact':str(target),'sha256':digest,'tool_commit':tool_commit},sort_keys=True))
    return target


if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--commit',required=True);p.add_argument('--output-dir',required=True)
    args=p.parse_args();build(args.commit,args.output_dir)
