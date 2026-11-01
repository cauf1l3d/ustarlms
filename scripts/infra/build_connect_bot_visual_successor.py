#!/usr/bin/env python3
"""Exact-source visual successor; frozen operators and history remain unchanged."""
import argparse
import contextlib
import gzip
import io
import json
from pathlib import Path
import tarfile
import tempfile

import build_connect_bot_visual_update as visual
import connect_con02_contract as c

FROZEN='5646e2de78b798056168169249c2ed764062d756'
EGRESS_SHA='4d922d295a38fd5a1ed7304388684801c1b8553dcf4508d769b0242b3a32ac6d'


def build(commit,output):
    commit=visual.git('rev-parse','--verify',commit+'^{commit}').decode().strip()
    for name in ('connect_bot_visual_update.py','build_connect_bot_visual_update.py'):
        path='scripts/infra/'+name
        c.require(visual.git('show',commit+':'+path)==visual.git('show',FROZEN+':'+path),'frozen_visual_source_changed')
    with tempfile.TemporaryDirectory() as temporary,contextlib.redirect_stdout(io.StringIO()):
        original=visual.build(commit,temporary)
        with tarfile.open(original,'r:gz') as tar:
            files={m.name:tar.extractfile(m).read() for m in tar}
    files.pop('SHA256SUMS')
    files['visual_v1.py']=files.pop('update.py')
    source=visual.git('show',commit+':scripts/infra/connect_bot_visual_successor.py')
    c.require(source.count(b'__FROZEN_VISUAL_SHA__')==1,'successor_placeholder')
    source=source.replace(b'__FROZEN_VISUAL_SHA__',c.sha(files['visual_v1.py']).encode())
    compile(source,'<fixed-successor>','exec');files['update.py']=source
    refresh=visual.git('show',commit+':scripts/infra/connect_egress_refresh.py')
    c.require(c.sha(refresh)==EGRESS_SHA,'frozen_egress_refresh_changed')
    files['refresh-egress.py']=refresh
    guide=visual.git('show',commit+':docs/CONNECT_VISUAL_SUCCESSOR_20261101_RU.md')
    files['INSTRUCTION_RU.md']=guide.replace(b'@SOURCE8@',commit[:8].encode())
    info=json.loads(files['INSTALL.json'])
    info.update(operator_sha256=c.sha(source),frozen_visual_sha256=c.sha(files['visual_v1.py']),
        journal='/var/lib/ustar-connect-visual-successor-'+commit[:12],
        legacy_journal_preserved='/var/lib/ustar-connect-bot-visual-update/state.json',
        legacy_requires='absent_or_known_rolled_back',egress_refresh='manual_only')
    files['INSTALL.json']=(json.dumps(info,sort_keys=True,indent=2)+'\n').encode()
    files['README.txt']=('USTAR Connect — successor графических карточек\n'
        'Сначала INSTRUCTION_RU.md: восстановить Telegram TLS, затем --check/--upgrade.\n'
        'Из принятого worker5cc; старый cb7128/5646 journal только absent/rolled_back.\n'
        'Не удалять старые журналы и не заменять receiver.pyz вручную.\n'
        'Проверка --check не вызывает провайдера. --upgrade сначала проверяет связь.\n'
        '--recover возвращает worker5cc, app2900/configs/SQLite сохраняются.\n'
        'refresh-egress.py запускается отдельно и интерактивно, автоматически не вызывается.\n').encode()
    files['SHA256SUMS']=''.join(c.sha(v)+'  '+n+'\n' for n,v in sorted(files.items())).encode()
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-connect-visual-successor-'+commit[:8]+'.tar.gz')
    with target.open('wb') as raw,gzip.GzipFile(fileobj=raw,mode='wb',mtime=0,filename='') as gz,tarfile.open(fileobj=gz,mode='w') as tar:
        for name,value in sorted(files.items()):
            entry=tarfile.TarInfo(name);entry.size=len(value);entry.mode=0o600;entry.mtime=0
            tar.addfile(entry,io.BytesIO(value))
    digest=c.sha(target.read_bytes())
    target.with_name(target.name+'.sha256').write_text(digest+'  '+target.name+'\n')
    (output/'INSTALL.json').write_bytes(files['INSTALL.json'])
    (output/'INSTRUCTION_RU.md').write_bytes(files['INSTRUCTION_RU.md'])
    print(json.dumps({'archive':str(target),'worker_commit':commit,'sha256':digest,'bytes':target.stat().st_size}))
    return target


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit',required=True);parser.add_argument('--output-dir',required=True)
    args=parser.parse_args();build(args.commit,args.output_dir)
