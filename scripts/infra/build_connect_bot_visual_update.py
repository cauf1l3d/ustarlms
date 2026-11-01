#!/usr/bin/env python3
"""Immutable worker-only visual package, retaining the frozen2900 controller envelope."""
import argparse
import ast
import base64
import contextlib
import gzip
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import zipfile

import build_connect_role_bot_update as predecessor
import connect_con02_contract as c

ROOT=Path(__file__).resolve().parents[2]
PREDECESSOR_TOOL='9a68246b'
ALLOWED={'ustar_connect/'+n+'.py' for n in ('bot_cards','bot_graphics','_bot_font','bot_handler','telegram_api')}


def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args],stderr=subprocess.DEVNULL,timeout=30)


def check_delta(before,after):
    def files(raw):
        with tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz') as archive:
            return {m.name:archive.extractfile(m).read() for m in archive}
    old,new=files(before),files(after)
    c.require(set(old)==set(new),'visual_archive_inventory')
    for name in old:
        if name in ('manifest.json','SOURCE_COMMIT'):continue
        if name not in ('receiver.pyz','notifications.pyz'):
            c.require(old[name]==new[name],'visual_nonpresentation_delta');continue
        def modules(raw):
            with zipfile.ZipFile(io.BytesIO(raw)) as z:
                c.require(z.testzip() is None,'visual_zip_crc')
                return {n:z.read(n) for n in z.namelist()}
        a,b=modules(old[name]),modules(new[name])
        c.require(set(a)<=set(b) and (set(b)-set(a))<=ALLOWED
            and {n for n in a if a[n]!=b[n]}<=ALLOWED,'visual_worker_scope')


@contextlib.contextmanager
def batch_git_blobs():
    from unittest.mock import patch
    original=subprocess.check_output
    cache={}
    process=subprocess.Popen(['git','-C',str(ROOT),'cat-file','--batch'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
    def read(argv,*args,**kwargs):
        if (isinstance(argv,list) and len(argv)==6 and argv[:3]==['git','-C',str(ROOT)]
                and argv[3:5]==['cat-file','blob']):
            oid=argv[5]
            if oid not in cache:
                process.stdin.write((oid+'\n').encode());process.stdin.flush()
                fields=process.stdout.readline().split()
                c.require(len(fields)==3 and fields[1]==b'blob' and 0<=int(fields[2])<=32*1024**2,'build_blob_response')
                cache[oid]=process.stdout.read(int(fields[2]))
                c.require(len(cache[oid])==int(fields[2]) and process.stdout.read(1)==b'\n','build_blob_length')
            return cache[oid]
        return original(argv,*args,**kwargs)
    try:
        with patch.object(subprocess,'check_output',read):yield
    finally:
        process.stdin.close();process.stdout.close()
        try:process.wait(timeout=5)
        except subprocess.TimeoutExpired:process.kill();process.wait()


def build(commit,output):
    commit=git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    tool=git('rev-parse',PREDECESSOR_TOOL+'^{commit}').decode().strip()
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    with tempfile.TemporaryDirectory() as temporary,contextlib.redirect_stdout(io.StringIO()),batch_git_blobs():
        folder=Path(temporary)
        old=predecessor.build(tool,folder/'old',repo=ROOT).read_bytes()
        # The old builder already validated this envelope. Reuse its worker asset
        # instead of reconstructing the complete historical application chain twice.
        line=next(line for line in old.decode().splitlines() if line.startswith('ASSETS_JSON = '))
        assets=json.loads(ast.literal_eval(line.removeprefix('ASSETS_JSON = ')))
        old_worker=base64.b64decode(assets['worker.tar.gz'],validate=True)
        source=git('show',commit+':connect-worker/deploy/build_release.py')
        code=folder/'build_release.py';code.write_bytes(source)
        spec=importlib.util.spec_from_file_location('visual_worker_builder',code)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);module.ROOT=ROOT
        candidate=module.build(commit,folder/'worker').read_bytes()
        # Compare actual zip entries, including unchanged units and admin code.
        check_delta(old_worker,candidate)
    operator=git('show',commit+':scripts/infra/connect_bot_visual_update.py')
    for before,after in [('__PREDECESSOR_SHA__',c.sha(old)),('__WORKER_SHA__',c.sha(candidate)),('__WORKER_COMMIT__',commit)]:
        c.require(operator.count(before.encode())==1,'visual_operator_placeholders')
        operator=operator.replace(before.encode(),after.encode())
    compile(operator,'<fixed-visual-update>','exec')
    info={'schema':1,'worker_commit':commit,'predecessor_tool':tool,'application_version':'2026102900',
        'application_changed':False,'runtime_dependencies_added':False,'worker_sha256':c.sha(candidate),
        'predecessor_sha256':c.sha(old),'operator_sha256':c.sha(operator),'journal':'/var/lib/ustar-connect-bot-visual-update',
        'default_host_access':False,'mini_app':False,'public_academy_https':False}
    readme=('USTAR Connect: графические карточки и цветные кнопки\n\n'
        'Только worker поверх принятого app2900 + включённого role-bot5cc02944.\n'
        'Профили, привязки, ACK, cursor, журнал и личные настройки не переписываются.\n'
        'Root-owned700 новый каталог, файлы600. Сначала SHA256SUMS, затем:\n'
        'python3 -I -B update.py --check (ready_for_visual_update)\n'
        'python3 -I -B -u update.py --upgrade (visuals_active)\n'
        'python3 -I -B update.py --check (visuals_ready)\n'
        'При прерывании: python3 -I -B -u update.py --recover (rolled_back)\n'
        'Recover возвращает worker5cc02944 с работающим ролевым ботом, не app/БД.\n'
        'После возврата можно повторить --upgrade. Старый installer2900 --check/enable\n'
        'после visuals_active не использовать: он принимает только прежний worker.\n'
        'Ранее присланные сообщения остаются прежними; нажать Меню заново.\n'
        'Цвета primary/success/danger задаёт Telegram-клиент. Графика сохраняет текстовый\n'
        'fallback для длинных карточек или определённого отказа медиа; при timeout нет автоповтора.\n').encode()
    files={'update.py':operator,'predecessor.py':old,'worker.tar.gz':candidate,
        'INSTALL.json':(json.dumps(info,sort_keys=True,indent=2)+'\n').encode(),'README.txt':readme}
    files['SHA256SUMS']=''.join(c.sha(v)+'  '+n+'\n' for n,v in sorted(files.items())).encode()
    target=output/('ustar-connect-visual-install-'+commit[:8]+'.tar.gz')
    with target.open('wb') as raw,gzip.GzipFile(fileobj=raw,mode='wb',mtime=0,filename='') as gz,tarfile.open(fileobj=gz,mode='w') as tar:
        for name,value in sorted(files.items()):
            entry=tarfile.TarInfo(name);entry.size=len(value);entry.mode=0o600;entry.mtime=0
            tar.addfile(entry,io.BytesIO(value))
    target.with_name(target.name+'.sha256').write_text(c.sha(target.read_bytes())+'  '+target.name+'\n')
    (output/'INSTALL.json').write_text(json.dumps(info,sort_keys=True,indent=2)+'\n')
    print(json.dumps({'archive':str(target),'worker_commit':commit,'sha256':c.sha(target.read_bytes()),'bytes':target.stat().st_size}))
    return target


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit',required=True);parser.add_argument('--output-dir',required=True)
    args=parser.parse_args();build(args.commit,args.output_dir)
