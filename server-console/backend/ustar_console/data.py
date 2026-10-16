"""Bounded, symlink-free reads; no raw receipt/log/config contents reach the UI."""
from datetime import datetime, timezone
import json
import math
import os
from pathlib import Path
import stat
import time


def stamp(value=None):
    return datetime.fromtimestamp(time.time() if value is None else value, timezone.utc).isoformat()


def epoch(value):
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        raise ValueError('Timestamp lacks timezone')
    return parsed.timestamp()


def open_regular(path: Path, owner=None, limit=None):
    if not path.is_absolute():
        raise ValueError('Absolute path required')
    directory = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in path.parts[1:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
            info = os.fstat(directory)
            if owner is not None and (info.st_uid != owner or info.st_mode & 0o022):
                raise ValueError('Untrusted data parent')
        descriptor = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        try:
            info = os.fstat(descriptor)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or (limit is not None and info.st_size > limit)
                    or info.st_mode & 0o022 or (owner is not None and info.st_uid != owner)):
                raise ValueError('Untrusted data file')
            return descriptor, info
        except BaseException:
            os.close(descriptor)
            raise
    finally:
        os.close(directory)


def safe_json(path: Path, limit=262144, owner=None):
    descriptor, _ = open_regular(path, owner=owner, limit=limit)
    with os.fdopen(descriptor, 'rb') as stream:
        raw = stream.read(limit+1)
    if len(raw) > limit:
        raise ValueError('Data limit exceeded')
    result = json.loads(raw, parse_constant=lambda _: (_ for _ in ()).throw(ValueError('Non-finite JSON')))
    if not isinstance(result, dict):
        raise ValueError('Object required')
    return result


def check(key, label, domain, status='no_data', reason='not_configured', source='not_configured', value=None):
    return dict(id=key, label=label, domain=domain, status=status, reason_code=reason,
                source=source, observed_at=stamp(), value=value)


REQUIRED = [
    ('http_external', 'Академия снаружи', 'academy'),
    ('database', 'PostgreSQL: отдельный мониторинг', 'academy'),
    ('cron', 'Успех фоновых заданий', 'academy'),
    ('rules', 'Правила и доставка алертов', 'monitoring'),
    ('observer', 'Независимый наблюдатель', 'monitoring'),
    ('restore', 'Приёмка восстановления', 'data'),
    ('resource_rules', 'Правила устойчивой нагрузки', 'server'),
]


def snapshot(path, now=None):
    now = time.time() if now is None else now
    try:
        data = safe_json(path, owner=0)
        if data.get('version') != 1 or not isinstance(data.get('checks'), list):
            raise ValueError('Unsupported snapshot')
        allowed = {'version','observed_at','checks','host','backup','lab','containers','events','hdd','units','http_local','recovery'}
        if set(data)-allowed or not all(isinstance(data.get(key), dict) for key in ('host','backup','lab')):
            raise ValueError('Unsupported snapshot fields')
        if not isinstance(data.get('containers'), list):
            raise ValueError('Unsupported container snapshot')
        for item in data['checks']:
            if (not isinstance(item,dict) or item.get('status') not in ('ok','warning','critical','no_data','stopped')
                    or item.get('domain') not in ('server','academy','data','monitoring','labs')
                    or not all(isinstance(item.get(key),str) and len(item[key])<=200
                               for key in ('id','label','reason_code','source','observed_at'))):
                raise ValueError('Unsupported check schema')
            epoch(item['observed_at'])
        observed = epoch(data['observed_at'])
        age = now-observed
        if not math.isfinite(age) or age < -5:
            raise ValueError('Future snapshot')
        if len(data['checks']) > 50:
            raise ValueError('Too many checks')
        if age > 45:
            for item in data['checks']:
                item['status'], item['reason_code'] = 'no_data', 'stale'
        data.update(age_seconds=max(0, round(age)), stale=age > 45, received_at=stamp(now))
    except (OSError, ValueError, TypeError, KeyError):
        data = dict(version=1, observed_at=None, received_at=stamp(now), stale=True,
                    age_seconds=None, checks=[check('collector', 'Сборщик', 'monitoring', reason='source_unavailable')],
                    host={}, containers=[], backup={}, lab={}, events=[])
    data['checks'] += [check(key, label, domain) for key, label, domain in REQUIRED]
    domains = {}
    priority = {'critical': 4, 'no_data': 3, 'warning': 2, 'ok': 1, 'stopped': 0}
    for domain in ('academy', 'server', 'data', 'monitoring'):
        rows = [item for item in data['checks'] if item['domain'] == domain]
        domains[domain] = max((item['status'] for item in rows), key=lambda s: priority.get(s, 3), default='no_data')
    data['domains'] = domains
    return data
