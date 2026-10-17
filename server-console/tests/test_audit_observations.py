"""Audit regressions: real receipt shape, failure history, freshness and capacity."""
import io
import json
from pathlib import Path
from types import SimpleNamespace
from urllib.error import HTTPError

import pytest

from ustar_console import collector, cron, data

NOW = 1792242600


@pytest.fixture
def receipts(tmp_path, monkeypatch):
    example = Path(__file__).resolve().parents[2]/'context/runtime/production_patch_post_switch_status_result_20261013.json'
    value = json.loads(example.read_text())['cron']['last_run']
    value['started_utc'] = data.stamp(NOW-61)
    value['finished_utc'] = data.stamp(NOW-60)
    source, history = tmp_path/'last-run.json', tmp_path/'state/cron-success.json'
    history.parent.mkdir(mode=0o700)
    monkeypatch.setattr(cron, 'RECEIPT', source)
    monkeypatch.setattr(cron, 'SUCCESS', history)
    original = data.safe_json
    monkeypatch.setattr(cron, 'safe_json', lambda path, owner: original(path))
    def save(new=None):
        source.write_text(json.dumps(value if new is None else new))
    save()
    return value, save, history


def test_cron_existing_historical_failures_do_not_become_new_errors(receipts):
    result = cron.collect_cron(NOW)
    assert result['status'] == 'ok' and result['success_age_seconds'] == 60
    assert result['scheduled_failed'] == 2 and result['scheduled_overdue_10m'] == 17
    assert result['new_task_failures'] == 0 and result['effective_enabled_overdue'] is None
    assert result['maintenance_window_verified'] is False


@pytest.mark.parametrize('age,status', [(180, 'ok'), (181, 'warning'), (600, 'warning'), (601, 'critical')])
def test_cron_success_age_thresholds(receipts, age, status):
    value, save, _ = receipts
    value.update(started_utc=data.stamp(NOW-age-1), finished_utc=data.stamp(NOW-age))
    save()
    assert cron.collect_cron(NOW)['status'] == status


def test_worker_exit_zero_does_not_hide_task_failure_or_leak_details(receipts):
    value, save, _ = receipts
    value['after']['new_failures'] = [{'private_error': 'SENSITIVE_TASK_CONTENT'}]
    save()
    result = cron.collect_cron(NOW)
    assert result['status'] == 'warning' and result['last_success_at'] is None
    assert result['new_task_failures'] == 1
    assert 'SENSITIVE' not in json.dumps(result)


def test_cron_success_survives_later_failure_and_new_reader(receipts):
    value, save, history = receipts
    first = cron.collect_cron(NOW)
    assert history.stat().st_mode & 0o777 == 0o600
    value.update(cron_exit_code=1, started_utc=data.stamp(NOW-6), finished_utc=data.stamp(NOW-5))
    save()
    failed = cron.collect_cron(NOW)
    assert failed['status'] == 'critical' and failed['worker_exit_code'] == 1
    assert failed['last_success_at'] == first['last_success_at']
    assert json.loads(history.read_text())['last_success_at'] == first['last_success_at']


def test_cron_no_success_is_invented_on_first_failed_run(receipts):
    value, save, history = receipts
    value['cron_exit_code'] = 1
    save()
    result = cron.collect_cron(NOW)
    assert result['status'] == 'critical' and result['last_success_at'] is None
    assert not history.exists()


@pytest.mark.parametrize('mutation', ['truncated', 'bool_counter', 'future', 'missing', 'naive', 'reversed'])
def test_invalid_or_incomplete_cron_cannot_be_healthy(receipts, mutation):
    value, save, history = receipts
    if mutation == 'truncated':
        value['log_truncated'] = True
    if mutation == 'bool_counter':
        value['after']['adhoc_queued'] = False
    if mutation == 'future':
        value['finished_utc'] = data.stamp(NOW+60)
    if mutation == 'missing':
        del value['after']['new_failures']
    if mutation == 'naive':
        value['finished_utc'] = '2026-10-17T13:00:00'
    if mutation == 'reversed':
        value['started_utc'] = data.stamp(NOW-10)
    save()
    if mutation == 'truncated':
        assert cron.collect_cron(NOW)['status'] == 'no_data'
    else:
        with pytest.raises((KeyError, ValueError)):
            cron.collect_cron(NOW)
    assert not history.exists()


def test_cron_receipt_regression_is_explicit(receipts):
    value, save, _ = receipts
    cron.collect_cron(NOW)
    value.update(started_utc=data.stamp(NOW-121), finished_utc=data.stamp(NOW-120))
    save()
    assert cron.collect_cron(NOW)['reason_code'] == 'cron_receipt_regressed'


@pytest.mark.parametrize('free_bytes,free_inodes,expected', [
    (40*2**30, 90, ('ok', 'ok')),
    (15*2**30, 90, ('warning', 'ok')),
    (7*2**30, 90, ('critical', 'ok')),
    (3*2**30, 90, ('critical', 'ok')),
    (40*2**30, 9, ('ok', 'warning')),
    (40*2**30, 4, ('ok', 'critical')),
    (40*2**30, None, ('ok', 'no_data')),
])
def test_disk_free_space_and_inode_polarity(free_bytes, free_inodes, expected):
    result = collector.capacity_checks('ssd', dict(available_bytes=free_bytes,
               total_bytes=100*2**30, free_inode_percent=free_inodes), 'server', root=True)
    assert tuple(row['status'] for row in result) == expected


@pytest.mark.parametrize('available,total,status', [(7, 30, 'warning'), (3, 20, 'critical')])
def test_absolute_root_reserve_applies_even_above_relative_threshold(available, total, status):
    result = collector.capacity_checks('ssd', dict(available_bytes=available*2**30,
               total_bytes=total*2**30, free_inode_percent=90), 'server', root=True)
    assert result[0]['status'] == status


def test_filesystem_uses_available_space_and_total_inodes(monkeypatch):
    monkeypatch.setattr(collector.os, 'statvfs', lambda _: SimpleNamespace(
        f_blocks=100, f_bavail=40, f_frsize=4096, f_favail=25, f_files=100))
    result = collector.filesystem(Path('/'))
    assert result['used_percent'] == 60 and result['free_inode_percent'] == 25
    assert result['available_bytes'] == 40*4096


def test_http_failure_keeps_actual_status_without_reading_body(monkeypatch):
    class Body(io.BytesIO):
        def read(self, *args):
            raise AssertionError('Probe read an application body')
    class Opener:
        def open(self, *args, **kwargs):
            raise HTTPError('http://127.0.0.1:8082/login/index.php', 503, 'unavailable', {}, Body())
    monkeypatch.setattr(collector.urllib.request, 'build_opener', lambda *args: Opener())
    result = collector.http_probe()
    assert result['status'] == 'critical' and result['code'] == 503
    assert result['total_ms'] is not None


def test_partial_stale_source_does_not_hide_behind_fresh_snapshot(tmp_path, monkeypatch):
    path = tmp_path/'snapshot.json'
    original = data.safe_json
    monkeypatch.setattr(data, 'safe_json', lambda path, owner: original(path))
    rows = [data.check('cron', 'cron', 'academy', 'ok', source='cron'),
            data.check('backup', 'backup', 'data', 'warning', source='backup')]
    rows[0]['observed_at'] = data.stamp(NOW-60)
    rows[1].update(observed_at=data.stamp(NOW), source_observed_at=data.stamp(NOW-100000))
    value = dict(version=1, observed_at=data.stamp(NOW), checks=rows,
                 host={}, containers=[], backup={}, cron={}, lab={})
    path.write_text(json.dumps(value))
    result = data.snapshot(path, NOW)
    cron_rows = [row for row in result['checks'] if row['id'] == 'cron']
    assert len(cron_rows) == 1 and cron_rows[0]['reason_code'] == 'stale'
    backup = next(row for row in result['checks'] if row['id'] == 'backup')
    assert backup['status'] == 'warning' and backup['source_age_seconds'] == 100000
    assert result['stale'] is False


def test_collect_sanitizes_cron_failure_and_distinguishes_disabled_http(monkeypatch):
    # Isolate all host commands; no Docker, Moodle bootstrap or production probes.
    for name in ('host', 'containers', 'mount', 'backup', 'lab', 'units', 'recovery'):
        monkeypatch.setattr(collector, name, lambda: {})
    monkeypatch.setattr(collector, 'collect_cron', lambda: (_ for _ in ()).throw(ValueError('PRIVATE_SOURCE')))
    result = collector.collect()
    row = next(c for c in result['checks'] if c['id'] == 'cron')
    assert row['status'] == 'no_data' and row['reason_code'] == 'probe_failed'
    assert next(c for c in result['checks'] if c['id'] == 'http_local')['reason_code'] == 'disabled'
    assert 'PRIVATE_SOURCE' not in json.dumps(result)
    ids = [c['id'] for c in result['checks']]
    assert len(ids) == len(set(ids))
