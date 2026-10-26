"""Fixed, read-only host sources. Only numeric aggregates and classified events escape.

The access log is the host Academy vhost, not the console's synthetic HEAD timing.
`rps` always divides the actual retained count by 300; partial coverage is no_data.
An older valid row proves coverage only in a non-truncated source with fresh writes.
Old/empty/new files remain no_data; idle and stopped logging are indistinguishable.
"""
import json
import math
import os
from pathlib import Path
import re
import selectors
import signal
import subprocess
import time

from .data import open_regular, stamp

ACCESS_LOG = Path('/var/lib/ustar-console-collector/requests.log')
WINDOW_SECONDS = 300
TAIL_LIMIT = 4*1024**2
MAX_DURATION_US = 600_000_000
JOURNAL_LIMIT = 512*1024
JOURNAL_ROWS = 2000
EVENT_LIMIT = 100
_SAMPLE = re.compile(rb'([0-9]{1,12}) ([1-5][0-9]{2}) ([0-9]{1,9})')
_CONSOLE_UNIT = re.compile(r'ustar-console(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?\.service')


def _empty(reason):
    return dict(status='no_data', reason_code=reason, window_seconds=WINDOW_SECONDS,
                requests=None, rps=None, p95_ms=None, errors_5xx=None,
                errors_4xx=None, coverage_seconds=0)


def _access(now):
    descriptor, before = open_regular(ACCESS_LOG, owner=0)
    with os.fdopen(descriptor, 'rb') as stream:
        offset = max(0, before.st_size-TAIL_LIMIT)
        stream.seek(offset)
        raw = stream.read(min(before.st_size, TAIL_LIMIT))
        after = os.fstat(stream.fileno())
        if after.st_size < before.st_size or len(raw) != min(before.st_size, TAIL_LIMIT):
            raise ValueError('Access source changed')
    if offset:
        # The first retained byte may be inside a record; never parse that fragment.
        first = raw.find(b'\n')
        raw = raw[first+1:] if first >= 0 else b''
    if not raw:
        return _empty('source_empty')
    # A trailing write fragment is not a sample. Invalid complete rows fail closed.
    lines = raw.split(b'\n')
    if lines[-1]:
        lines = lines[:-1]
        if not lines:
            raise ValueError('Incomplete access source')
    else:
        lines.pop()
    earliest = now
    latest = 0
    durations = []
    errors_4xx = errors_5xx = 0
    for line in lines:
        match = _SAMPLE.fullmatch(line)
        if not match:
            raise ValueError('Invalid numeric access sample')
        at, status, duration = map(int, match.groups())
        if at <= 0 or at > now or duration > MAX_DURATION_US:
            raise ValueError('Out-of-bounds access sample')
        earliest = min(earliest, at)
        latest = max(latest, at)
        if now-WINDOW_SECONDS < at <= now:
            durations.append(duration)
            errors_4xx += 400 <= status <= 499
            errors_5xx += 500 <= status <= 599
    if not lines:
        return _empty('source_empty')
    if now-latest>WINDOW_SECONDS:
        # An idle server and a stopped/disabled log writer are indistinguishable.
        # Do not refresh an old log into an apparently measured zero request rate.
        return _empty('source_stale')
    coverage = min(WINDOW_SECONDS, int(now-earliest))
    if offset:
        # Completion-order writes or clock movement can leave an old row in the
        # retained tail after other in-window records were dropped.
        coverage=min(coverage,WINDOW_SECONDS-1)
    durations.sort()
    count = len(durations)
    return dict(status='ok' if coverage == WINDOW_SECONDS else 'no_data',
                reason_code='measured' if coverage == WINDOW_SECONDS else 'insufficient_coverage',
                window_seconds=WINDOW_SECONDS, requests=count, rps=count/WINDOW_SECONDS,
                p95_ms=durations[math.ceil(.95*count)-1]/1000 if count else None,
                errors_5xx=errors_5xx, errors_4xx=errors_4xx, coverage_seconds=coverage)


def _journal(now):
    """Only a fixed journalctl invocation; no shell, inherited env, or raw stderr."""
    argv = ['/usr/bin/journalctl', '--no-pager', '--quiet', '--output=json',
            '--output-fields=__REALTIME_TIMESTAMP,_SYSTEMD_UNIT,UNIT,_PID,PRIORITY,MESSAGE,RESULT',
            '--unit=ustar-hdd-backup.service', '--unit=ustar-console*.service',
            '--unit=apache2.service', '--since=@'+str(int(now-WINDOW_SECONDS)),
            '--until=@'+str(int(now)), '--lines='+str(JOURNAL_ROWS)]
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               env={'PATH':'/usr/bin:/bin','LC_ALL':'C'}, start_new_session=True)
    output = bytearray()
    deadline = time.monotonic()+2
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                remaining = deadline-time.monotonic()
                if remaining <= 0 or not selector.select(remaining):
                    raise TimeoutError('Journal source timeout')
                chunk = os.read(process.stdout.fileno(), 8192)
                if not chunk:
                    break
                output.extend(chunk)
                if len(output) > JOURNAL_LIMIT:
                    raise ValueError('Journal source limit')
        if process.wait(timeout=max(.01, deadline-time.monotonic())) != 0:
            raise OSError('Journal source unavailable')
        return output.decode('utf-8')
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        process.stdout.close()


def _source(unit):
    if unit in ('ustar-hdd-backup.service', 'apache2.service'):
        return unit
    if isinstance(unit, str) and _CONSOLE_UNIT.fullmatch(unit):
        return 'ustar-console.service'
    return None


def _events(now):
    raw = _journal(now)
    if len(raw.encode('utf-8')) > JOURNAL_LIMIT:
        raise ValueError('Journal source limit')
    lines = raw.splitlines()
    if len(lines) >= JOURNAL_ROWS:
        # journalctl's line cap can omit failures; do not silently imply completeness.
        raise ValueError('Journal source limit')
    groups = {}
    for line in lines:
        row = json.loads(line)
        if not isinstance(row, dict):
            raise ValueError('Invalid journal row')
        value = row.get('__REALTIME_TIMESTAMP')
        if not isinstance(value, str) or not re.fullmatch(r'[0-9]{1,18}', value):
            raise ValueError('Invalid journal time')
        at = int(value)/1_000_000
        if at > now:
            raise ValueError('Future journal row')
        if at < now-WINDOW_SECONDS:
            continue
        source = _source(row.get('_SYSTEMD_UNIT'))
        manager = row.get('_PID') == '1'
        if manager:
            source = _source(row.get('UNIT')) or source
        if source is None:
            continue
        priority, message = row.get('PRIORITY'), row.get('MESSAGE')
        if (not isinstance(priority, str) or not re.fullmatch('[0-7]', priority)
                or not isinstance(message, str) or len(message) > 8192):
            raise ValueError('Invalid journal classification input')
        if int(priority) > 4:
            continue
        message = message.lower()
        code = 'service_warning' if priority == '4' else 'service_error'
        if manager and ('failed to start' in message or 'failed with result' in message):
            code = 'unit_failed'
        elif 'no space left on device' in message:
            code = 'disk_full'
        elif 'out of memory' in message or 'oom-kill' in message:
            code = 'out_of_memory'
        elif 'permission denied' in message:
            code = 'permission_denied'
        level = 'warning' if priority == '4' else 'error'
        key = source, level, code
        previous = groups.get(key)
        groups[key] = dict(at=stamp(at), source=source, level=level, code=code,
                           count=previous['count']+1 if previous else 1)
        if previous and previous['at'] > groups[key]['at']:
            groups[key]['at'] = previous['at']
    return sorted(groups.values(), key=lambda row: row['at'], reverse=True)[:EVENT_LIMIT]


def collect(now=None):
    """Root collector contract; never invoke this from an HTTP request handler."""
    now = time.time() if now is None else now
    if not isinstance(now, (int, float)) or not math.isfinite(now) or now <= 0:
        raise ValueError('Invalid observation time')
    try:
        result = _access(now)
    except OSError:
        result = _empty('source_unavailable')
    except (ValueError, UnicodeError):
        result = _empty('source_invalid')
    result.update(observed_at=stamp(now), events=[], events_status='no_data',
                  events_reason_code='source_unavailable')
    try:
        result['events'] = _events(now)
        result.update(events_status='ok', events_reason_code='measured')
    except (OSError, TimeoutError, subprocess.SubprocessError):
        pass
    except (ValueError, TypeError, UnicodeError):
        result['events_reason_code'] = 'source_invalid'
    return result
