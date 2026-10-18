#!/usr/bin/env python3
"""Read-only comparison of fixed web-log sources and the last collector snapshot.

Does not request Academy pages, reload Apache, or export raw logs/configuration.
"""
import json
import math
import os
from pathlib import Path
import stat
import time

from ustar_console.data import epoch, safe_json, stamp
from ustar_console.web_observability import ACCESS_LOG, collect

REASONS={'measured','source_empty','source_unavailable','source_invalid','source_stale',
         'source_limit','insufficient_coverage'}


def _summary(value):
    value=value if isinstance(value,dict) else {}
    def state(key):return value.get(key) if value.get(key) in ('ok','no_data') else 'no_data'
    def reason(key):return value.get(key) if value.get(key) in REASONS else 'source_invalid'
    counters={}
    for key in ('requests','rps','p95_ms','errors_4xx','errors_5xx','coverage_seconds'):
        number=value.get(key)
        counters[key]=number if type(number) in (int,float) and math.isfinite(number) and number>=0 else None
    return dict(requests=dict(status=state('status'),reason_code=reason('reason_code'),**counters),
                events=dict(status=state('events_status'),reason_code=reason('events_reason_code'),
                            categories=len(value['events']) if isinstance(value.get('events'),list) else None))


def diagnose(settings,now=None):
    now=time.time() if now is None else now
    result=dict(observed_at=stamp(now),direct=_summary(collect()),
                snapshot=dict(status='unavailable_or_invalid'),numeric_log=dict(present=False))
    try:
        value=safe_json(settings.snapshot,owner=0)
        at=value.get('observed_at');age=now-epoch(at)
        valid=math.isfinite(age) and age>=-5
        result['snapshot']=dict(status='fresh' if valid and age<=45 else 'stale_or_invalid_time',
                                observed_at=at if valid else None,**_summary(value.get('web_observability')))
    except (OSError,ValueError,TypeError,KeyError):
        pass
    try:
        info=ACCESS_LOG.lstat()
        result['numeric_log']=dict(present=True,regular=stat.S_ISREG(info.st_mode),
            bytes=info.st_size,owner_id=info.st_uid,mode=oct(stat.S_IMODE(info.st_mode)))
    except OSError:
        pass
    return result


def main():
    if os.geteuid()!=0:raise SystemExit('Run with sudo')
    from ustar_console.config import load
    print(json.dumps(diagnose(load()),ensure_ascii=False,indent=2,allow_nan=False))


if __name__=='__main__':main()
