#!/usr/bin/env python3
"""Build a deterministic read-only inventory zipapp from exact Git objects."""
import argparse,hashlib,io,json,re,subprocess,zipfile
from pathlib import Path
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[2]


def build(commit,output,*,repo=ROOT):
    if not re.fullmatch(r'(?:HEAD|[a-f0-9]{40})',commit):raise ValueError('exact_commit_required')
    def git(*args):return subprocess.check_output(['git','-C',str(repo),*args])
    sha=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    baseline=json.loads(git('show',sha+':context/code_map/generated/source_manifest.json'))['source_commit']
    if not re.fullmatch('[a-f0-9]{40}',baseline):raise ValueError('baseline_required')
    def source(ref):
        entries=git('ls-tree','-r','-z',ref,'--','moodle/local/ustar','moodle/theme/ustar')
        files={}
        for entry in entries.split(b'\0'):
            if not entry:continue
            meta,rawpath=entry.split(b'\t',1);mode,kind,oid=meta.decode().split()
            if kind!='blob' or mode not in ('100644','100755'):raise ValueError('regular_source_required')
            name=rawpath.decode().removeprefix('moodle/')
            files[name]=hashlib.sha256(git('cat-file','blob',oid)).hexdigest()
        versions={}
        for name,scope in [('local_ustar','local/ustar'),('theme_ustar','theme/ustar')]:
            raw=git('show',ref+':moodle/'+scope+'/version.php')
            found=re.findall(rb'(?m)^\s*\$plugin->version\s*=\s*([0-9]{10})\s*;',raw)
            if len(found)!=1:raise ValueError('version_required')
            versions[name]=int(found[0])
        tables=sorted(t.attrib['NAME'] for t in ET.fromstring(git('show',ref+':moodle/local/ustar/db/install.xml')).findall('.//TABLE'))
        return {'files':dict(sorted(files.items())),'versions':versions,'tables':tables}
    reference={'schema':1,'source_commit':sha,'baseline_commit':baseline,'baseline':source(baseline),'candidate':source(sha)}
    files={'__main__.py':b'from connect_readiness import main\nraise SystemExit(main())\n',
        'SOURCE_COMMIT':(sha+'\n').encode(),'connect_preflight_data/__init__.py':b'',
        'connect_preflight_data/reference.json':(json.dumps(reference,sort_keys=True)+'\n').encode()}
    for name,path in [('connect_readiness.py','scripts/infra/connect_readiness.py'),
                      ('production_manifest.py','scripts/production_manifest.py'),
                      ('production_patch_readiness.py','scripts/infra/production_patch_readiness.py')]:
        files[name]=git('show',sha+':'+path)
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-connect-preflight-'+sha[:8]+'.pyz')
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for name,value in sorted(files.items()):
            entry=zipfile.ZipInfo(name,(2026,1,1,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;entry.external_attr=0o100644<<16
            archive.writestr(entry,value)
    target.write_bytes(stream.getvalue());target.chmod(0o644)
    digest=hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.pyz.sha256').write_text(digest+'  '+target.name+'\n')
    target.with_suffix('.pyz.metadata.json').write_text(json.dumps({'source_commit':sha,'baseline_commit':baseline,
        'sha256':digest,'files':{name:hashlib.sha256(value).hexdigest() for name,value in files.items()},
        'counts':{name:{'files':len(data['files']),'tables':len(data['tables'])} for name,data in reference.items() if name in ('baseline','candidate')}},indent=2)+'\n')
    print(json.dumps({'archive':str(target),'sha256':digest,'source_commit':sha,'host_access':False}))
    return target


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--commit',default='HEAD');parser.add_argument('--output-dir',required=True)
    args=parser.parse_args();build(args.commit,args.output_dir)
