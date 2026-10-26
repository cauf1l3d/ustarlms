#!/usr/bin/env python3
"""Deterministic stdlib-only receiver package from exact immutable Git objects."""
import argparse,hashlib,io,json,re,subprocess,tarfile,zipfile,gzip
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]

def build(commit,output):
    def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
    sha=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha):raise ValueError('invalid_source')
    paths=git('ls-tree','-r','--name-only',sha,'--','connect-worker/ustar_connect').decode().splitlines()
    modules={p.removeprefix('connect-worker/'):git('show',sha+':'+p) for p in paths if p.endswith('.py')}
    if not modules:raise ValueError('source_missing')
    def zipapp(main,contents):
        stream=io.BytesIO()
        with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for name,value in sorted({'__main__.py':main,**contents}.items()):
                entry=zipfile.ZipInfo(name,(2026,1,1,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;entry.external_attr=0o100644<<16
                z.writestr(entry,value)
        return stream.getvalue()
    files={'SOURCE_COMMIT':(sha+'\n').encode()}
    for name,module in [('receiver','receiver_runtime'),('notifications','notification_runtime')]:
        main=('from ustar_connect.'+module+' import main\nraise SystemExit(main())\n').encode()
        files[name+'.pyz']=zipapp(main,modules)
    installer=git('show',sha+':connect-worker/deploy/install.py')
    files['admin.pyz']=zipapp(installer,{})
    for name in ('receiver','probe','notify'):
        path='units/ustar-connect-'+name+'.service'
        files[path]=git('show',sha+':connect-worker/deploy/ustar-connect-'+name+'.service')
    manifest={'schema':1,'source_commit':sha,'files':{n:hashlib.sha256(v).hexdigest() for n,v in sorted(files.items())}}
    files['manifest.json']=(json.dumps(manifest,sort_keys=True,indent=2)+'\n').encode()
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-connect-receiver-'+sha[:8]+'.tar.gz')
    with target.open('wb') as raw,gzip.GzipFile(filename='',mode='wb',fileobj=raw,mtime=0) as gz,tarfile.open(fileobj=gz,mode='w') as tar:
        for name,value in sorted(files.items()):
            item=tarfile.TarInfo(name);item.size=len(value);item.mode=0o644;item.mtime=0
            tar.addfile(item,io.BytesIO(value))
    script=output/'ustar-connect-install.py';script.write_bytes(installer);script.chmod(0o644)
    for path in (target,script):
        digest=hashlib.sha256(path.read_bytes()).hexdigest()
        path.with_name(path.name+'.sha256').write_text(digest+'  '+path.name+'\n')
    print(json.dumps({'source_commit':sha,'archive':str(target),'bytes':target.stat().st_size,
        'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'python_modules':len(modules)},sort_keys=True))
    return target

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--commit',default='HEAD');parser.add_argument('--output-dir',required=True)
    args=parser.parse_args();build(args.commit,args.output_dir)
