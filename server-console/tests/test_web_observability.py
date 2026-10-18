"""Real numeric log fixtures and sanitized journal output; never native installation."""
import importlib
import importlib.util
import fcntl
import json
import os
from pathlib import Path

import pytest


NOW = 1_800_000_000


def reader():
    assert importlib.util.find_spec('ustar_console.web_observability'), 'Numeric Apache reader is missing'
    return importlib.import_module('ustar_console.web_observability')


def access(tmp_path, monkeypatch, text):
    module = reader()
    path = tmp_path/'access.log'
    path.write_text(text)
    monkeypatch.setattr(module, 'ACCESS_LOG', path)
    from ustar_console.data import open_regular
    monkeypatch.setattr(module, 'open_regular', lambda p, owner: open_regular(p))
    monkeypatch.setattr(module, '_journal', lambda now: '')
    return module, path


def test_real_request_nearest_rank_p95_and_fixed_window_rps(tmp_path, monkeypatch):
    lines = [f'{NOW-301} 200 599999999']
    lines += [f'{NOW-100+i} {500 if i == 20 else 404 if i == 19 else 200} {i*1000}' for i in range(1, 21)]
    module, _ = access(tmp_path, monkeypatch, '\n'.join(lines)+'\n')
    value = module.collect(NOW)
    assert value['status'] == 'ok' and value['reason_code'] == 'measured'
    assert value['requests'] == 20 and value['p95_ms'] == 19
    assert value['rps'] == pytest.approx(20/300)
    assert value['errors_5xx'] == 1 and value['errors_4xx'] == 1
    assert value['coverage_seconds'] == 300 and value['window_seconds'] == 300


def test_old_access_log_never_becomes_fresh_zero_traffic(tmp_path,monkeypatch):
    module,path=access(tmp_path,monkeypatch,f'{NOW-86400} 200 1000\n')
    os.utime(path,(NOW-86400,NOW-86400))
    value=module.collect(NOW)
    assert value['status']=='no_data' and value['reason_code']=='source_stale'
    assert value['requests'] is None and value['rps'] is None


def test_truncated_tail_cannot_prove_full_coverage_from_a_late_old_request(tmp_path,monkeypatch):
    module,_=access(tmp_path,monkeypatch,(f'{NOW-20} 500 1000\n'*50)+f'{NOW-400} 200 400000000\n{NOW} 200 1000\n')
    monkeypatch.setattr(module,'TAIL_LIMIT',80)
    value=module.collect(NOW)
    assert value['status']=='no_data' and value['reason_code']=='insufficient_coverage'
    assert value['coverage_seconds']<300


def test_partial_window_is_explicit_and_never_extrapolates(tmp_path, monkeypatch):
    module, _ = access(tmp_path, monkeypatch, f'{NOW-30} 200 1500\n')
    value = module.collect(NOW)
    assert value['status'] == 'no_data' and value['reason_code'] == 'insufficient_coverage'
    assert value['coverage_seconds'] == 30 and value['requests'] == 1
    assert value['rps'] == pytest.approx(1/300) and value['p95_ms'] == 1.5


@pytest.mark.parametrize('content,reason', [('', 'source_empty'), ('a secret IP URL', 'source_invalid'),
    (f'{NOW+1} 200 2\n', 'source_invalid'), (f'{NOW-1} 600 2\n', 'source_invalid'),
    (f'{NOW-1} 200 -2\n', 'source_invalid'), (f'{NOW-1} 200 600000001\n', 'source_invalid'),
    (f'{NOW-1} 200 2 token=SECRET\n', 'source_invalid')])
def test_missing_or_rejected_samples_have_no_fake_zero(tmp_path, monkeypatch, content, reason):
    module, path = access(tmp_path, monkeypatch, content)
    value = module.collect(NOW)
    assert value['reason_code'] == reason and value['status'] == 'no_data'
    assert all(value[k] is None for k in ('requests', 'rps', 'p95_ms', 'errors_5xx', 'errors_4xx'))
    assert 'SECRET' not in json.dumps(value)
    path.unlink()
    assert module.collect(NOW)['reason_code'] == 'source_unavailable'


@pytest.mark.parametrize('attack', ['symlink', 'parent_symlink', 'hardlink', 'writable'])
def test_access_log_filesystem_guards(tmp_path, monkeypatch, attack):
    module, path = access(tmp_path, monkeypatch, f'{NOW-301} 200 1\n')
    if attack == 'symlink':
        path.rename(tmp_path/'target'); path.symlink_to(tmp_path/'target')
    elif attack == 'parent_symlink':
        (tmp_path/'real').mkdir(); path.rename(tmp_path/'real/access.log')
        (tmp_path/'link').symlink_to(tmp_path/'real'); monkeypatch.setattr(module, 'ACCESS_LOG', tmp_path/'link/access.log')
    elif attack == 'hardlink':
        os.link(path, tmp_path/'other')
    else:
        path.chmod(0o666)
    assert module.collect(NOW)['status'] == 'no_data'


def test_old_idle_window_is_unknown_without_live_writer_evidence(tmp_path, monkeypatch):
    module, _ = access(tmp_path, monkeypatch, f'{NOW-301} 200 1\n')
    value = module.collect(NOW)
    assert value['status'] == 'no_data' and value['requests'] is None and value['rps'] is None
    assert value['p95_ms'] is None


def test_window_boundary_row_proves_coverage_but_is_not_counted_twice(tmp_path, monkeypatch):
    module, _ = access(tmp_path, monkeypatch, f'{NOW-300} 500 9000\n{NOW} 200 1000\n')
    value = module.collect(NOW)
    assert value['coverage_seconds'] == 300 and value['requests'] == 1
    assert value['p95_ms'] == 1 and value['errors_5xx'] == 0


def test_tail_reads_at_most_four_megabytes_and_rejects_incomplete_window(tmp_path, monkeypatch):
    module, path = access(tmp_path, monkeypatch, f'{NOW-301} 200 1\n')
    with path.open('a') as stream:
        stream.write(f'{NOW-20} 200 1\n' * 260000)
    assert path.stat().st_size > 4*1024**2
    value = module.collect(NOW)
    assert value['coverage_seconds'] == 20 and value['reason_code'] == 'insufficient_coverage'
    assert 0 < value['requests'] < 260000


def journal_row(unit='apache2.service', message='error SECRET /path?user=identity', priority='3', **extra):
    return dict(__REALTIME_TIMESTAMP=str((NOW-3)*1_000_000), _SYSTEMD_UNIT=unit,
                MESSAGE=message, PRIORITY=priority, **extra)


def test_journal_events_are_locally_classified_and_aggregated_without_raw_text(tmp_path, monkeypatch):
    module, _ = access(tmp_path, monkeypatch, f'{NOW-301} 200 1\n')
    rows = [journal_row(), journal_row(), journal_row('ustar-console-notify.service', 'Permission denied SECRET'),
            journal_row('ssh.service'), journal_row(priority='6'), journal_row('ustar-hdd-backup.service', priority='4')]
    monkeypatch.setattr(module, '_journal', lambda now: '\n'.join(map(json.dumps, rows)))
    value = module.collect(NOW)
    assert value['events_status'] == 'ok' and len(value['events']) == 3
    assert {e['source'] for e in value['events']} == {'apache2.service','ustar-console.service','ustar-hdd-backup.service'}
    assert next(e for e in value['events'] if e['source'] == 'apache2.service')['count'] == 2
    assert all(set(e) == {'at','source','level','code','count'} for e in value['events'])
    assert 'SECRET' not in json.dumps(value) and 'MESSAGE' not in json.dumps(value)


def test_systemd_failed_unit_is_included_but_untrusted_unit_field_is_not(tmp_path, monkeypatch):
    module, _ = access(tmp_path, monkeypatch, f'{NOW-301} 200 1\n')
    rows = [journal_row('init.scope', 'Failed to start SECRET', _PID='1', UNIT='apache2.service'),
            journal_row('unrelated.service', 'Failed to start SECRET', UNIT='ustar-hdd-backup.service')]
    monkeypatch.setattr(module, '_journal', lambda now: '\n'.join(map(json.dumps, rows)))
    events = module.collect(NOW)['events']
    assert len(events) == 1 and events[0]['code'] == 'unit_failed'


@pytest.mark.parametrize('kind', ['malformed','timeout','future','too_large'])
def test_journal_unavailability_is_distinct_from_no_events(tmp_path, monkeypatch, kind):
    module, _ = access(tmp_path, monkeypatch, f'{NOW-301} 200 1\n{NOW} 200 1\n')
    def read(now):
        if kind == 'timeout': raise TimeoutError()
        if kind == 'too_large': return 'x'*(module.JOURNAL_LIMIT+1)
        if kind == 'future':
            row = journal_row(); row['__REALTIME_TIMESTAMP'] = str((NOW+1)*1_000_000)
            return json.dumps(row)
        return '{broken'
    monkeypatch.setattr(module, '_journal', read)
    value = module.collect(NOW)
    assert value['events_status'] == 'no_data' and value['events'] == []
    assert value['status'] == 'ok'


def installer():
    path = Path(__file__).parents[1]/'tools/configure_observability.py'
    assert path.exists(), 'Root-local observability installer is missing'
    spec = importlib.util.spec_from_file_location('configure_observability_fixture', path)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    return module


def apache_fixture(tmp_path, monkeypatch, config=None):
    module = installer()
    apache = tmp_path/'apache2'
    available = apache/'sites-available'; available.mkdir(parents=True)
    enabled = apache/'sites-enabled'; enabled.mkdir()
    target = available/'academy.conf'
    target.write_text(config or '<VirtualHost *:80>\n ServerName other.local\n</VirtualHost>\n<VirtualHost *:80>\n ServerName ustar.local\n ProxyPass / http://127.0.0.1:8082/\n CustomLog ${APACHE_LOG_DIR}/academy-access.log combined\n</VirtualHost>\n')
    (enabled/'academy.conf').symlink_to(target)
    logs = tmp_path/'logs'; logs.mkdir()
    rotate = tmp_path/'logrotate.d'; rotate.mkdir()
    for name, value in [('APACHE', apache), ('ENABLED', enabled), ('AVAILABLE', available),
                        ('INCLUDE', apache/'ustar-console-observability.conf'),
                        ('LOG', logs/'ustar-console-access.log'), ('ROTATE', rotate/'ustar-console-observability'),
                        ('LOCK', tmp_path/'installer.lock')]:
        monkeypatch.setattr(module, name, value, raising=False)
    # Fixture root is trusted explicitly; native helper always checks uid0 and every parent.
    monkeypatch.setattr(module, 'ROOT_UID', os.getuid())
    monkeypatch.setattr(module, 'ADM_GID', os.getgid())
    monkeypatch.setattr(module, '_parents', lambda p: None)
    calls = []
    def run(argv):
        calls.append(tuple(argv))
        if 'DUMP_VHOSTS' in argv:
            return f'*:80 ustar.local ({enabled}/academy.conf:4)\n'
        return ''
    monkeypatch.setattr(module, '_run', run)
    return module, target, calls


def test_installer_selects_exact_existing_vhost_and_keeps_other_directives(tmp_path, monkeypatch):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    before = target.read_text()
    result = module.install()
    after = target.read_text()
    assert after.split('</VirtualHost>')[0] == before.split('</VirtualHost>')[0]
    assert after.count(str(module.INCLUDE)) == 1 and 'ProxyPass / http://127.0.0.1:8082/' in after
    assert Path(result['backup']).read_text() == before
    numeric = module.INCLUDE.read_text()
    assert '%{end:sec}t %>s %D' in numeric and 'env=!ustar_console_probe' in numeric
    assert '^USTAR-Console-Probe/1$' in numeric
    assert not any(s in numeric for s in ('%h', '%r', '%u', '%{Cookie}', '%{Referer}'))
    assert (os.stat(module.LOG).st_mode & 0o777) == 0o640
    assert 'rotate 2' in module.ROTATE.read_text() and 'maxsize 10M' in module.ROTATE.read_text()
    assert calls[-2:] == [module.CONFIGTEST, module.RELOAD]
    assert module.install()['changed'] is False


@pytest.mark.parametrize('config', [
    '<VirtualHost *:80>\n ServerName other.local\n</VirtualHost>\n',
    '<VirtualHost *:80>\n ServerName ustar.local\n</VirtualHost>\n'*2,
    '<VirtualHost *:80>\n ServerName ustar.local\n ServerName other.local\n</VirtualHost>\n',
    '<VirtualHost *:80>\n Include /somewhere/other.conf\n ServerName ustar.local\n</VirtualHost>\n'])
def test_installer_rejects_missing_or_ambiguous_or_indirect_vhost(tmp_path, monkeypatch, config):
    module, target, calls = apache_fixture(tmp_path, monkeypatch, config)
    with pytest.raises(module.ObservabilityError): module.install()
    assert target.read_text() == config and not module.INCLUDE.exists()
    assert module.RELOAD not in calls


@pytest.mark.parametrize('fail_at', ['configtest','reload'])
def test_installer_rolls_back_and_configtests_original_on_failure(tmp_path, monkeypatch, fail_at):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    original = target.read_bytes(); original_run = module._run
    failed = False
    def run(argv):
        nonlocal failed
        result = original_run(argv)
        if not failed and argv == (module.CONFIGTEST if fail_at == 'configtest' else module.RELOAD):
            failed = True; raise module.ObservabilityError('fixture failure')
        return result
    monkeypatch.setattr(module, '_run', run)
    with pytest.raises(module.ObservabilityError): module.install()
    assert target.read_bytes() == original and not module.INCLUDE.exists() and not module.ROTATE.exists()
    assert calls[-1 if fail_at == 'configtest' else -2] == module.CONFIGTEST
    if fail_at == 'reload': assert calls[-1] == module.RELOAD


def test_installer_check_never_mutates(tmp_path, monkeypatch):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    original = target.read_bytes()
    assert module.check()['changed'] is False
    assert target.read_bytes() == original and not module.INCLUDE.exists() and not module.LOG.exists()
    assert module.RELOAD not in calls


def test_installer_does_not_overwrite_concurrently_changed_source(tmp_path, monkeypatch):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    original_write = module._write
    changed = b'# changed independently\n'+target.read_bytes()
    def write(path, *args, **kwargs):
        original_write(path, *args, **kwargs)
        if path == module.ROTATE:
            target.write_bytes(changed)
    monkeypatch.setattr(module, '_write', write)
    with pytest.raises(module.ObservabilityError): module.install()
    assert target.read_bytes() == changed
    assert module.RELOAD not in calls


def test_installer_refuses_concurrent_local_run(tmp_path, monkeypatch):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    with module.LOCK.open('wb') as stream:
        module.LOCK.chmod(0o600)
        fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with pytest.raises(module.ObservabilityError): module.install()
    assert not module.INCLUDE.exists() and module.RELOAD not in calls


@pytest.mark.parametrize('attack', ['conflicting_include','symlink_include','unexpected_effective_vhost'])
def test_installer_preflight_refuses_untrusted_or_conflicting_configuration(tmp_path, monkeypatch, attack):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    original = target.read_bytes()
    if attack == 'conflicting_include':
        module.INCLUDE.write_text('existing independent configuration\n')
    elif attack == 'symlink_include':
        module.INCLUDE.symlink_to(target)
    else:
        monkeypatch.setattr(module, '_run', lambda argv: f'*:80 ustar.local ({target}:1)\n')
    with pytest.raises(module.ObservabilityError): module.install()
    assert target.read_bytes() == original and module.RELOAD not in calls


def test_installer_refuses_to_suppress_inherited_access_logging(tmp_path, monkeypatch):
    module, target, calls = apache_fixture(tmp_path, monkeypatch)
    target.write_text(target.read_text().replace(' CustomLog ${APACHE_LOG_DIR}/academy-access.log combined\n', ''))
    original = target.read_bytes()
    with pytest.raises(module.ObservabilityError, match='inherited access logging'):
        module.install()
    assert target.read_bytes() == original and module.RELOAD not in calls
