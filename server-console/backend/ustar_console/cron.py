"""Read the existing cron v3 receipt; never execute Moodle or reset task history."""
import json
import math
import os
from pathlib import Path
import tempfile
import time

from .data import epoch, safe_json

RECEIPT = Path('/var/lib/ustar-cron/last-run.json')
SUCCESS = Path('/var/lib/ustar-console-collector/cron-success.json')


def counter(value):
    if type(value) is not int or not 0 <= value <= 2**53-1:
        raise ValueError('Unsupported cron counter')
    return value


def timestamp(value, now):
    result = epoch(value)
    if not math.isfinite(result) or not 0 <= result <= now+5:
        raise ValueError('Invalid cron time')
    return result


def remember_success(value):
    # systemd creates this private root-owned StateDirectory, separate from web state.
    descriptor, temporary = tempfile.mkstemp(prefix='.cron-', dir=SUCCESS.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump({'version': 1, 'last_success_at': value}, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, SUCCESS)
        directory = os.open(SUCCESS.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def collect_cron(now=None):
    now = time.time() if now is None else now
    saved = safe_json(RECEIPT, owner=0)
    started = timestamp(saved['started_utc'], now)
    finished = timestamp(saved['finished_utc'], now)
    if finished < started or type(saved['cron_exit_code']) is not int:
        raise ValueError('Unsupported cron result')
    after, ustar = saved['after'], saved['ustar']
    failures = after['new_failures']
    if not isinstance(failures, list) or type(saved['log_truncated']) is not bool:
        raise ValueError('Incomplete cron result')
    errors = counter(ustar['error_lines'])
    counts = {name: counter(after[name]) for name in
              ('scheduled_failed', 'scheduled_overdue_10m', 'adhoc_queued')}
    last_success = None
    try:
        history = safe_json(SUCCESS, owner=0)
    except FileNotFoundError:
        history = None
    if history is not None:
        if history.get('version') != 1 or set(history) != {'version', 'last_success_at'}:
            raise ValueError('Unsupported cron success state')
        timestamp(history['last_success_at'], now)
        last_success = history['last_success_at']
    complete_success = (saved['cron_exit_code'] == 0 and not failures and errors == 0
                        and saved['log_truncated'] is False)
    if complete_success and (last_success is None or finished > epoch(last_success)):
        remember_success(saved['finished_utc'])
        last_success = saved['finished_utc']
    success_age = max(0, now-epoch(last_success)) if last_success else None
    if saved['cron_exit_code'] != 0:
        status, reason = 'critical', 'cron_worker_failed'
    elif last_success and finished < epoch(last_success):
        status, reason = 'no_data', 'cron_receipt_regressed'
    elif success_age is not None and success_age > 600:
        status, reason = 'critical', 'cron_success_overdue'
    elif failures or errors:
        status, reason = 'warning', 'cron_new_errors'
    elif saved['log_truncated']:
        status, reason = 'no_data', 'cron_result_incomplete'
    elif success_age is not None and success_age > 180:
        status, reason = 'warning', 'cron_success_delayed'
    else:
        status, reason = 'ok', 'cron_receipt'
    return dict(status=status, reason_code=reason, last_attempt_at=saved['finished_utc'],
                last_success_at=last_success, success_age_seconds=success_age,
                worker_exit_code=saved['cron_exit_code'], new_task_failures=len(failures),
                ustar_error_lines=errors, log_truncated=saved['log_truncated'],
                elapsed_seconds=round(finished-started, 3), **counts,
                effective_enabled_overdue=None, maintenance_window_verified=False,
                success_history='observed_by_console_only', source='cron_v3_receipt')
