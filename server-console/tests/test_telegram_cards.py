"""Readable, bounded PNG overviews from real snapshot-shaped fixture values."""
from copy import deepcopy
from datetime import datetime, timezone
from io import BytesIO
import importlib
import importlib.util
from pathlib import Path
import subprocess
import sys
import zipfile

import pytest
from PIL import Image, ImageDraw


AT = int(datetime(2026, 10, 18, 1, 10, tzinfo=timezone.utc).timestamp())


def snapshot():
    def row(key, domain='server', status='ok', reason='measured'):
        return dict(id=key, domain=domain, status=status, reason_code=reason)
    return dict(stale=False, observed_at='2026-10-18T01:10:00+00:00', age_seconds=5,
        checks=[row('host'), row('containers', 'academy'), row('http_local', 'academy'),
                row('database', 'academy'), row('cron', 'academy'), row('hdd', 'data'),
                row('backup', 'data'), row('ssd_space'), row('hdd_space', 'data'), row('collector', 'monitoring'),
                row('http_external', 'academy', 'no_data', 'not_configured')],
        host=dict(cpu_percent=12.5, memory=dict(total_bytes=8*2**30, available_bytes=3*2**30),
                  disk=dict(total_bytes=80*2**30, available_bytes=20*2**30)),
        hdd=dict(total_bytes=2*2**40, available_bytes=2**40),
        http_local=dict(code=200, total_ms=38),
        database=dict(connections=8, max_connections=100, active=1, waiting=0),
        cron=dict(success_age_seconds=15, adhoc_queued=2, new_task_failures=0),
        containers=[dict(state='running', running=True, restarts=0)],
        backup=dict(file='fixture.age', bytes=2**30, completed_at='2026-10-18T00:00:00Z',
                    age_seconds=4200, status='ok', verification='creation_receipt_only',
                    restore='not_accepted'),
        lab=dict(phase='stopped', ready=False, unlimited=False, expires_at=None),
        notifications=dict(pending=3, delivery_failures=1, status='warning', rules=[]))


def history(metric='cpu', **changes):
    values = [10, 20, None, 30, 40] if metric != 'http' else [25, 35, None, 50, 38]
    value = dict(metric=metric, period='1h', status='ok', reason_code='measured',
                 points=[[AT-3600+i*900, v] for i, v in enumerate(values)],
                 step_seconds=900, observed_at=AT)
    return dict(value, **changes)


def test_renderer_module_exists():
    assert importlib.util.find_spec('ustar_console.telegram_cards') is not None, 'PNG renderer is missing'


@pytest.fixture
def cards(monkeypatch):
    # A missing module is an assertion failure for the first RED run.
    assert importlib.util.find_spec('ustar_console.telegram_cards') is not None, 'PNG renderer is missing'
    module = importlib.import_module('ustar_console.telegram_cards')
    monkeypatch.setattr(module.time, 'time', lambda: AT)
    return module


@pytest.fixture
def drawn(monkeypatch):
    texts, lines = [], []
    original_text = ImageDraw.ImageDraw.text
    original_line = ImageDraw.ImageDraw.line
    def text(draw, xy, value, *args, **kwargs):
        font = kwargs.get('font')
        texts.append((str(value), getattr(font, 'size', None)))
        return original_text(draw, xy, value, *args, **kwargs)
    def line(draw, xy, *args, **kwargs):
        lines.append((deepcopy(xy), kwargs.get('fill'), kwargs.get('width')))
        return original_line(draw, xy, *args, **kwargs)
    monkeypatch.setattr(ImageDraw.ImageDraw, 'text', text)
    monkeypatch.setattr(ImageDraw.ImageDraw, 'line', line)
    return texts, lines


def rendered_text(drawn):
    return '\n'.join(value for value, _ in drawn[0])


@pytest.mark.parametrize('command', ['/status', '/metrics', '/academy', '/backup latest', '/backup', '/labs', '/alerts'])
def test_supported_cards_are_readable_actual_pngs(cards, drawn, command):
    value = cards.render_card(command, snapshot(), {key: history(key) for key in ('cpu', 'ram', 'http')})
    assert isinstance(value, bytes) and len(value) <= 262144
    with Image.open(BytesIO(value)) as image:
        image.load()
        assert image.format == 'PNG' and image.width == 960
        assert 800 <= image.height <= 2400
        assert len(image.getcolors(960*image.height) or []) > 20
    assert all(size is not None and size >= 36 for _, size in drawn[0])
    assert 'USTAR' in rendered_text(drawn)


def test_metrics_draw_the_actual_current_values_and_timestamp(cards, drawn):
    cards.render_card('/metrics', snapshot(), {key: history(key) for key in ('cpu', 'ram', 'http')})
    text = rendered_text(drawn)
    for value in ('12,5%', '62,5%', '38 мс', '20,0 ГиБ', '1,0 ТиБ', '18.10.2026', 'МСК'):
        assert value in text
    assert '04:10' in text and '03:10' in text


@pytest.mark.parametrize('key', ['host', 'http_local'])
def test_no_data_probe_values_are_not_drawn_as_current(cards, drawn, key):
    data = snapshot()
    next(row for row in data['checks'] if row['id'] == key).update(status='no_data', reason_code='stale')
    cards.render_card('/metrics', data, {metric: history(metric) for metric in ('cpu', 'ram', 'http')})
    text = rendered_text(drawn)
    if key == 'host':
        assert '12,5%' not in text and '62,5%' not in text and '20,0 ГиБ' not in text
    else:
        assert '38 мс' not in text
    assert 'Нет данных' in text or 'Нет свежих данных' in text


def test_stale_snapshot_suppresses_values_and_history(cards, drawn):
    data = snapshot(); data['stale'] = True
    cards.render_card('/metrics', data, {metric: history(metric) for metric in ('cpu', 'ram', 'http')})
    text = rendered_text(drawn)
    assert '12,5%' not in text and '62,5%' not in text and '38 мс' not in text
    assert 'устарел' in text.lower()
    assert not [line for line in drawn[1] if line[2] == 5]


@pytest.mark.parametrize('value', [None, True, -1, float('nan'), float('inf'), '88', 10**1000])
def test_invalid_measurements_never_become_a_fake_zero(cards, drawn, value):
    data = snapshot()
    data['host']['cpu_percent'] = value
    data['host']['memory']['available_bytes'] = value
    data['http_local']['total_ms'] = value
    cards.render_card('/metrics', data)
    text = rendered_text(drawn)
    assert '0,0%' not in text and '0 мс' not in text
    assert 'Нет данных' in text
    assert 'nan' not in text.lower() and 'inf' not in text.lower() and 'True' not in text


def test_impossible_percentages_are_unknown(cards, drawn):
    data = snapshot(); data['host']['cpu_percent'] = 150
    data['host']['memory']['available_bytes'] = 9*2**30
    cards.render_card('/metrics', data)
    text = rendered_text(drawn)
    assert '150,0%' not in text and '-12,5%' not in text
    assert text.count('Нет данных') >= 2


def test_history_missing_samples_produce_separate_lines(cards, drawn):
    cards.render_card('/metrics', snapshot(), {'cpu': history()})
    segments = [xy for xy, color, width in drawn[1] if width == 5]
    assert len(segments) == 2
    assert all(len(segment) == 2 for segment in segments)
    assert max(x for x, _ in segments[0]) < min(x for x, _ in segments[1])
    assert 'Разрывы' in rendered_text(drawn)


def test_implicit_missing_buckets_are_gaps_too(cards, drawn):
    points = [[AT-3600, 10], [AT-3585, 20], [AT-15, 30], [AT, 40]]
    cards.render_card('/metrics', snapshot(), {'cpu': history(points=points, step_seconds=15)})
    segments = [xy for xy, color, width in drawn[1] if width == 5]
    assert len(segments) == 2 and all(len(segment) == 2 for segment in segments)


@pytest.mark.parametrize('changes', [dict(status='no_data'), dict(observed_at=AT-46),
    dict(observed_at=AT+6), dict(reason_code='stale'), dict(points=[]),
    dict(points=[[AT-10, None], [AT, None]]),
    dict(points=[[AT-3600+i*5, 10] for i in range(722)])])
def test_unavailable_or_oversized_history_has_no_fabricated_curve(cards, drawn, changes):
    cards.render_card('/metrics', snapshot(), {'cpu': history(**changes)})
    assert not [line for line in drawn[1] if line[2] == 5]
    assert 'Нет свежей истории' in rendered_text(drawn)


def test_history_invalid_values_break_the_line(cards, drawn):
    points = [[AT-60, 10], [AT-45, 20], [AT-30, float('inf')], [AT-15, 30], [AT, 40]]
    cards.render_card('/metrics', snapshot(), {'cpu': history(points=points, step_seconds=15)})
    assert len([line for line in drawn[1] if line[2] == 5]) == 2


def test_academy_contains_only_actual_pg_cron_and_http_figures(cards, drawn):
    cards.render_card('/academy', snapshot())
    text = rendered_text(drawn)
    for value in ('38 мс', 'HTTP 200', '8 / 100', '15 с', 'Очередь задач: 2', 'Новых ошибок: 0'):
        assert value in text


def test_backup_is_an_overview_with_honest_verification_scope(cards, drawn):
    cards.render_card('/backup latest', snapshot())
    text = rendered_text(drawn)
    assert '1,0 ГиБ' in text and '1 ч 10 мин' in text
    assert 'при создании' in text and 'ещё не принято' in text


def test_labs_preserve_controller_scope_and_stopped_expiry(cards, drawn):
    cards.render_card('/labs', snapshot())
    text = rendered_text(drawn)
    assert 'Остановлен' in text and 'не действует' in text and 'контроллера' in text
    assert 'Ошибка' not in text


def test_stale_lab_receipt_cannot_show_ready(cards, drawn):
    data = snapshot(); data['lab'].update(phase='ready', ready=True, unlimited=True)
    data['checks'].append(dict(id='lab', status='no_data', reason_code='stale'))
    cards.render_card('/labs', data)
    assert 'Готов' not in rendered_text(drawn) and 'Нет свежих данных' in rendered_text(drawn)


def test_alerts_show_real_queue_counters_and_known_rule_names(cards, drawn):
    data = snapshot()
    data['checks'].append(dict(id='cpu_sustained', domain='server', status='warning', reason_code='sustained_rules'))
    cards.render_card('/alerts', data)
    text = rendered_text(drawn)
    assert 'В очереди: 3' in text and 'Со сбоями: 1' in text
    assert 'Длительная нагрузка CPU' in text and 'Внимание' in text


def test_unselected_fields_and_unknown_check_labels_never_leave_the_host(cards, drawn):
    data = snapshot(); secret = 'SENSITIVE-DO-NOT-DRAW'
    data['token'] = secret; data['host']['private_command'] = secret
    data['backup']['file'] = secret; data['backup']['sha256'] = secret
    data['database']['query'] = secret
    data['containers'][0]['name'] = secret
    data['checks'].append(dict(id=secret, domain='server', status='critical', label=secret))
    data['notifications']['rules'].append(dict(key=secret, state='critical'))
    for command in ('/status', '/metrics', '/academy', '/backup latest', '/labs', '/alerts'):
        assert cards.render_card(command, data) is not None
    assert secret not in rendered_text(drawn)


@pytest.mark.parametrize('command', ['/start', '/help', '/panel', '/shell reboot', ''])
def test_unsupported_commands_stay_text_only(cards, command):
    assert cards.render_card(command, snapshot()) is None


def test_independent_observer_stays_text_only(cards):
    data = snapshot(); data['scope'] = 'independent_observer'
    assert cards.render_card('/status', data) is None


def test_missing_font_falls_back_to_text_only(cards, monkeypatch):
    def missing(name):
        raise OSError('font unavailable')
    monkeypatch.setattr(cards, '_font_bytes', missing)
    assert cards.render_card('/status', snapshot()) is None


def test_module_imports_from_zip_without_pillow_and_fonts_load_from_zip(cards, tmp_path):
    package = Path(cards.__file__).parent
    archive = tmp_path/'renderer.zip'
    with zipfile.ZipFile(archive, 'w') as zipped:
        for path in (package/'__init__.py', package/'telegram_views.py', package/'telegram_cards.py',
                     *sorted((package/'fonts').glob('*.ttf'))):
            zipped.write(path, str(Path('ustar_console')/path.relative_to(package)))
    code = 'import sys; sys.path.insert(0, sys.argv[1]); from ustar_console.telegram_cards import render_card; '
    no_pillow = subprocess.run([sys.executable, '-S', '-c', code+
        'assert render_card("/status", {}) is None; print("text-only")', str(archive)],
        capture_output=True, text=True)
    assert no_pillow.returncode == 0, no_pillow.stderr
    with_pillow = subprocess.run([sys.executable, '-c', code+
        'assert render_card("/status", {}).startswith(bytes.fromhex("89504e47")); print("zip-fonts")', str(archive)],
        capture_output=True, text=True)
    assert with_pillow.returncode == 0, with_pillow.stderr


@pytest.mark.parametrize('key,value', [('host', '12,5%'), ('http_local', '38 мс'), ('database', '8 / 100'), ('cron', '15 с')])
def test_absent_probe_check_cannot_authorize_current_figures(cards, drawn, key, value):
    data = snapshot(); data['checks'] = [row for row in data['checks'] if row['id'] != key]
    cards.render_card('/metrics' if key in ('host', 'http_local') else '/academy', data)
    assert value not in rendered_text(drawn)
    assert 'Нет данных' in rendered_text(drawn)


@pytest.mark.parametrize('key,value', [('ssd_space', '20,0 ГиБ'), ('hdd_space', '1,0 ТиБ')])
@pytest.mark.parametrize('absent', [False, True])
def test_capacity_requires_its_own_fresh_check(cards, drawn, key, value, absent):
    data = snapshot()
    if absent:
        data['checks'] = [row for row in data['checks'] if row['id'] != key]
    else:
        next(row for row in data['checks'] if row['id'] == key).update(status='no_data', reason_code='stale')
    cards.render_card('/metrics', data)
    assert value not in rendered_text(drawn)
    assert '12,5%' in rendered_text(drawn)  # The host check remains fresh.


def test_measured_zero_is_preserved_when_it_is_an_actual_sample(cards, drawn):
    data = snapshot(); data['host']['cpu_percent'] = 0
    data['host']['memory']['available_bytes'] = data['host']['memory']['total_bytes']
    data['http_local']['total_ms'] = 0
    cards.render_card('/metrics', data, {'cpu': history(points=[[AT-15, 0], [AT, 0]], step_seconds=15)})
    text = rendered_text(drawn)
    assert text.count('0,0%') == 2 and '0 мс' in text
    assert len([line for line in drawn[1] if line[2] == 5]) == 1


def test_maximum_export_is_bounded_without_dropping_the_last_real_sample(cards, drawn):
    points = [[AT-3600+i*5, (i*17)%100] for i in range(721)]
    payload = cards.render_card('/metrics', snapshot(), {'cpu': history(points=points, step_seconds=5)})
    segments = [xy for xy, color, width in drawn[1] if width == 5]
    assert isinstance(payload, bytes) and len(payload) <= 262144
    assert len(segments) == 1 and len(segments[0]) == 721
    assert segments[0][-1][0] == 864
