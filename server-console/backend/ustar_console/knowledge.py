"""Versioned operator knowledge and metadata-only inventory; never executes scripts."""
import copy
import hashlib
import json
import os
from pathlib import Path
import re

from .data import open_regular, stamp

CATALOG=Path(__file__).parent/'knowledge'/'catalog.json'
ROOTS=(Path('/usr/local/sbin'),Path('/usr/local/lib/ustar-recovery'))


def installed_inventory(roots=ROOTS,owner=0):
    rows=[]
    for root in roots:
        try:
            names=sorted(root.glob('USTAR_*'))[:100]
        except OSError:
            continue
        for path in names:
            if path.suffix not in ('.py','.sh'): continue
            row=dict(server_path=str(path),status='unavailable')
            try:
                descriptor,info=open_regular(path,owner=owner,limit=2097152)
                with os.fdopen(descriptor,'rb') as stream:
                    digest=hashlib.file_digest(stream,'sha256').hexdigest()
                row.update(status='present',sha256=digest,bytes=info.st_size)
            except (OSError,ValueError):
                pass
            rows.append(row)
    return dict(observed_at=stamp(),scripts=rows)


def catalog(inventory=None,path=CATALOG):
    raw=path.read_bytes()
    if len(raw)>3000000: raise ValueError('Knowledge limit')
    value=json.loads(raw)
    if not re.fullmatch('[0-9a-f]{40}',value['source_commit']):
        raise ValueError('Knowledge provenance missing')
    value=copy.deepcopy(value)
    measured={row['server_path']:row for row in (inventory or {}).get('scripts',[])}
    known=set()
    for row in value['scripts']:
        target=row.get('server_path','');known.add(target)
        native=measured.get(target)
        if native:
            row['installed']={key:native[key] for key in ('status','sha256') if key in native}
            if native.get('sha256'):
                row['installed']['matches_source']=native['sha256']==row['sha256']
    for i,(target,native) in enumerate(measured.items()):
        if target in known: continue
        value['scripts'].append(dict(id='native-'+str(i),title=Path(target).name,
            category='Дополнительные серверные скрипты',purpose='Обнаружен сборщиком; назначение не сопоставлено с исходниками этого выпуска.',
            effects='Не запускается из пульта до проверки назначения и прав.',success='Не определён для этого выпуска.',
            server_path=target,source_path='',sha256='',installed={key:native[key] for key in ('status','sha256') if key in native}))
    value['inventory_observed_at']=(inventory or {}).get('observed_at')
    return value
