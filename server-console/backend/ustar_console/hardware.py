"""Live NTP and separate low-frequency SMART reader; no tests or device writes."""
import ipaddress
import json
import math
import os
from pathlib import Path
import time

from .data import safe_json,stamp,epoch

SMART=Path('/run/ustar-console-smart/status.json')


def parse_clock(text):
    selected=[line.split() for line in text.splitlines() if line.startswith('*')]
    if len(selected)!=1 or len(selected[0])!=10:
        raise ValueError('No selected NTP peer')
    row=selected[0]
    source=row[0][1:]
    ipaddress.ip_address(source)
    offset,jitter=float(row[-2]),float(row[-1])
    if not all(math.isfinite(v) for v in (offset,jitter)) or jitter<0:
        raise ValueError('Invalid NTP offsets')
    return dict(source=source,offset_ms=offset,jitter_ms=jitter,reach_octal=row[6],
                status='critical' if abs(offset)>5000 else 'ok')


def clock():
    from .collector import command
    return parse_clock(command(['/usr/bin/ntpq','-pn']))


def parse_smart(value):
    exit_code=value['smartctl']['exit_status']
    if type(exit_code) is not int or not 0<=exit_code<=255 or exit_code&7:
        raise ValueError('SMART command failed/standby/unsupported')
    passed=value.get('smart_status',{}).get('passed')
    if type(passed) is not bool:
        raise ValueError('SMART health not measured')
    attributes=value.get('ata_smart_attributes',{}).get('table',[])
    counts={str(row['id']):row['raw']['value'] for row in attributes if row.get('id') in (5,196,197,198,199)}
    if any(type(n) is not int or n<0 for n in counts.values()):
        raise ValueError('Invalid SMART counters')
    critical=not passed or any(counts.get(key,0)>0 for key in ('197','198'))
    missing=any(key not in counts for key in ('5','197','198','199'))
    return dict(health_passed=passed,temperature_c=value.get('temperature',{}).get('current'),
        sectors=counts,historical_error_log=bool(exit_code&64),selftest_history_failure=bool(exit_code&128),
        status='critical' if critical else 'no_data' if missing else 'warning' if counts.get('5',0)>0 else 'ok',
        vendor_wear='not_interpreted')


def smart_receipt():
    value=safe_json(SMART,owner=0,limit=16384)
    if not -5<=time.time()-epoch(value['observed_at'])<=960:
        raise ValueError('SMART receipt stale')
    return value


def main():
    from .collector import command,write_snapshot
    if os.geteuid()!=0: raise SystemExit('Restricted root SMART service only')
    rows=[]
    for device in ('/dev/sda','/dev/sdb'):
        try:
            raw=command(['/usr/sbin/smartctl','-j','-n','standby,0','-H','-A',device],timeout=4,limit=65536,
                        accepted=tuple(range(256)))
            rows.append(dict(device=device,**parse_smart(json.loads(raw))))
        except (OSError,ValueError,KeyError,TypeError,TimeoutError):
            rows.append(dict(device=device,status='no_data'))
    rank={'ok':0,'warning':1,'no_data':2,'critical':3}
    value=dict(observed_at=stamp(),disks=rows,status=max((r['status'] for r in rows),key=rank.get))
    write_snapshot(value,SMART)
