"""Read the notifier's bounded public export, never its private SQLite/text queue."""
import math
from pathlib import Path
import re
import time

from .data import epoch, safe_json

STATUS = Path('/run/ustar-console-notify/status.json')
STATES = {'ok','warning','critical','no_data','stopped'}
KEYS = {'collector','host','containers','hdd','backup','cron','recovery','http_local','database',
        'ssd_space','ssd_inodes','hdd_space','hdd_inodes','observer','http_external','smart','clock','units',
        'cpu_sustained','ram_sustained','container_oom'}


def numeric(value):
    return type(value) in (int,float) and math.isfinite(value) and value>=0


def read(now=None):
    now=time.time() if now is None else now
    try:
        value=safe_json(STATUS,limit=131072)
        observed=epoch(value['observed_at'])
        if not math.isfinite(observed) or observed>now+5: raise ValueError('Invalid export time')
        if value['status'] not in ('ok','warning','no_data'): raise ValueError('Invalid worker status')
        for key in ('pending','delivery_failures'):
            if type(value[key]) is not int or not 0<=value[key]<=5010: raise ValueError('Invalid queue count')
        rules=value.get('rules',[]);incidents=value.get('incidents',[])
        if not isinstance(rules,list) or len(rules)>100 or not isinstance(incidents,list) or len(incidents)>100:
            raise ValueError('Oversized notification export')
        clean_rules=[]
        for row in rules:
            if (row['key'] not in KEYS or row['state'] not in STATES or row['emitted'] not in STATES
                    or any(not numeric(row.get(key,0)) for key in ('since','last','sent'))):
                raise ValueError('Invalid rule')
            clean_rules.append({key:row[key] for key in ('key','state','emitted')}
                               | {key:row.get(key,0) for key in ('since','last','sent')})
        clean_incidents=[]
        for row in incidents:
            if (type(row['id']) is not int or not 0<row['id']<2**63 or not numeric(row['at']) or row['at']>now+5
                    or row['check_id'] not in KEYS or row['status'] not in STATES
                    or not isinstance(row['reason_code'],str) or not re.fullmatch(r'[a-z0-9_]{1,80}',row['reason_code'])):
                raise ValueError('Invalid incident')
            clean_incidents.append({key:row[key] for key in ('id','at','check_id','status','reason_code')})
        return dict(observed_at=value['observed_at'],fresh=now-observed<=45,
                    status=value['status'],pending=value['pending'],delivery_failures=value['delivery_failures'],
                    polling='ok' if value.get('polling')=='ok' else 'no_data',
                    evaluation='ok' if value.get('evaluation')=='ok' else 'no_data',
                    rules=clean_rules,incidents=sorted(clean_incidents,key=lambda row:row['id'],reverse=True))
    except (OSError,ValueError,KeyError,TypeError,OverflowError):
        return None
