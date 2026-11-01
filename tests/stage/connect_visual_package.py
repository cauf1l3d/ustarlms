#!/usr/bin/env python3
"""Offline artifact acceptance: hashes/CRC, real glyph raster under isolated Python without site packages."""
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import zipfile


def main(archive):
    with tempfile.TemporaryDirectory() as temporary:
        root=Path(temporary)
        with tarfile.open(archive,'r:gz') as tar:
            members=tar.getmembers()
            assert {m.name for m in members}=={'update.py','predecessor.py','worker.tar.gz','INSTALL.json','README.txt','SHA256SUMS'}
            assert len(members)==6 and all(m.isfile() and m.mode==0o600 for m in members)
            for m in members:(root/m.name).write_bytes(tar.extractfile(m).read())
        for line in (root/'SHA256SUMS').read_text().splitlines():
            digest,name=line.split('  ');assert hashlib.sha256((root/name).read_bytes()).hexdigest()==digest
        run=subprocess.run([sys.executable,'-I','-S',str(root/'update.py')],check=True,capture_output=True,text=True)
        assert json.loads(run.stdout)=={'phase':'PLAN_ONLY','host_access':False,'network_used':False}
        with tarfile.open(root/'worker.tar.gz','r:gz') as tar:
            files={m.name:tar.extractfile(m).read() for m in tar}
        manifest=json.loads(files.pop('manifest.json'))
        assert manifest['files']=={n:hashlib.sha256(v).hexdigest() for n,v in files.items()}
        for name in ('receiver.pyz','notifications.pyz','admin.pyz'):
            with zipfile.ZipFile(io.BytesIO(files[name])) as app:assert app.testzip() is None
        receiver=root/'receiver.pyz';receiver.write_bytes(files['receiver.pyz'])
        code='''import json,sys,struct
sys.path.insert(0,sys.argv[1])
from ustar_connect.bot_graphics import presentation
v={'title':'Кадровые заявки','text':'Выберите заявку.','metrics':[],'rows':[['Сотрудник','На согласовании']],
'buttons':[{'label':'Сотрудник · Подтверждение регистрации','callback':'cb4_'+'A'*43}]}
r=presentation(v,now=1793347200)
assert r['photo'].startswith(b'\\x89PNG\\r\\n\\x1a\\n')
assert r['reply_markup']['inline_keyboard'][0][0]['text']=='1 · Открыть заявку'
assert 'PIL' not in sys.modules
print(json.dumps({'png_bytes':len(r['photo']),'width':struct.unpack('>I',r['photo'][16:20])[0],'site_packages':False}))'''
        run=subprocess.run([sys.executable,'-I','-S','-c',code,str(receiver)],check=True,capture_output=True,text=True)
        raster=json.loads(run.stdout);assert raster['width']==960 and raster['png_bytes']<262144
        # Validate the frozen envelope + font-bearing candidate without loading any sibling modules.
        bootstrap='''import importlib.util,sys
from pathlib import Path
p=Path(sys.argv[1]);s=importlib.util.spec_from_file_location('visual_artifact',p/'update.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
b=m.bootstrap(p)
assert b.reference['candidate']['versions']['local_ustar']==2026102900
print('EMBEDDED_PREDECESSOR_OK')'''
        run=subprocess.run([sys.executable,'-I','-S','-c',bootstrap,str(root)],check=True,capture_output=True,text=True)
        assert run.stdout.strip()=='EMBEDDED_PREDECESSOR_OK'
        print(json.dumps({'status':'pass','worker_commit':manifest['source_commit'],'raster':raster,'production_access':False}))


if __name__=='__main__':main(sys.argv[1])
