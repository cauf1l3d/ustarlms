"""Bounded local resource history for the first LAN installation.

Root collector owns SQLite. API reads only the generated sanitized JSON, never
the database. Missing samples remain gaps. Fifteen days, <=721 points per series.
"""
import json
import math
import os
from pathlib import Path
import sqlite3
import time

PERIODS = {'1h':3600,'24h':86400,'7d':604800,'15d':1296000}
DATABASE = Path('/var/lib/ustar-console-collector/history.sqlite3')
EXPORT = Path('/run/ustar-console-data/history.json')


def record(snapshot, database=DATABASE, export=EXPORT, now=None):
    now = int(time.time() if now is None else now)
    host = snapshot.get('host', {})
    memory = host.get('memory', {})
    ram = 100*(1-memory['available_bytes']/memory['total_bytes']) if memory.get('total_bytes') else None
    numbers = [host.get('cpu_percent'), ram, snapshot.get('http_local', {}).get('total_ms')]
    if any(n is not None and (type(n) not in (int,float) or not math.isfinite(n) or n < 0) for n in numbers):
        raise ValueError('Invalid history sample')
    if database.exists() and (database.is_symlink() or database.stat().st_mode & 0o077
                              or database.stat().st_uid != os.geteuid()):
        raise ValueError('Untrusted history database')
    with sqlite3.connect(database, timeout=1) as db:
        os.chmod(database,0o600)
        db.execute('PRAGMA journal_mode=DELETE')
        db.execute('CREATE TABLE IF NOT EXISTS samples(at INTEGER PRIMARY KEY,cpu REAL,ram REAL,http REAL)')
        db.execute('DELETE FROM samples WHERE at<? OR at>?',(now-PERIODS['15d']-1800, now+5))
        db.execute('INSERT OR REPLACE INTO samples VALUES(?,?,?,?)',(now,*numbers))
        data = {}
        for period,duration in PERIODS.items():
            step = max(15, math.ceil(duration/720))
            start = (now-duration)//step*step
            for metric in ('cpu','ram','http'):
                # metric is a fixed identifier above, never a request parameter.
                rows = dict(db.execute(f'SELECT (at/?)*?,avg({metric}) FROM samples WHERE at>=? AND at<=? GROUP BY at/?',
                                       (step,step,start,now,step)))
                points = [[at,rows.get(at)] for at in range(start,now+1,step)]
                present = any(n is not None for _,n in points)
                data[metric+':'+period] = dict(metric=metric, period=period, points=points,
                    status='ok' if present else 'no_data', reason_code='measured' if present else 'no_samples',
                    step_seconds=step, source='native_collector', observed_at=now)
    from .collector import write_snapshot
    write_snapshot({'version':1,'observed_at':now,'series':data},export)


def read(metric, period, path=EXPORT, now=None):
    from .data import safe_json
    if metric not in ('cpu','ram','http') or period not in PERIODS:
        raise ValueError('Unsupported history')
    empty = dict(metric=metric,period=period,status='no_data',points=[],reason_code='source_unavailable')
    try:
        value = safe_json(path, owner=0, limit=1500000)
        age = (time.time() if now is None else now)-value['observed_at']
        if value['version'] != 1 or not -5 <= age <= 45:
            return {**empty,'reason_code':'stale'}
        return value['series'][metric+':'+period]
    except (OSError,ValueError,KeyError,TypeError):
        return empty
