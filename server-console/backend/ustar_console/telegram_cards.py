"""Bounded PNG overviews of the same allowlisted measurements as text cards.

Pillow and the packaged fonts are optional at runtime: independent observer
zipapps and an unavailable renderer retain the complete plain-text response.
No filesystem probes, network access, raw snapshot serialization or system fonts.
"""
from datetime import datetime, timedelta, timezone
from importlib import resources
from io import BytesIO
import math
import time

from . import telegram_views as views


WIDTH = 960
MAX_PNG_BYTES = 262144
MAX_POINTS = 721
MSK = timezone(timedelta(hours=3))
BG = '#0b1220'
PANEL = '#141f30'
BORDER = '#29374b'
TEXT = '#f1f5fa'
MUTED = '#afbed2'
ACCENT = '#79b9ff'
COLORS = {'ok': '#70dbad', 'warning': '#ffd273', 'critical': '#ff8b98',
          'no_data': '#bac7d8', 'stopped': '#bac7d8'}
LABELS = {'ok': 'В норме', 'warning': 'Внимание', 'critical': 'Авария',
          'no_data': 'Нет данных', 'stopped': 'Остановлено'}
TITLES = {'/status': 'Сводка', '/metrics': 'Ресурсы', '/academy': 'Академия',
          '/backup latest': 'Последняя копия', '/backup': 'Последняя копия',
          '/labs': 'Тестовый стенд', '/alerts': 'Автоалерты'}
HEIGHTS = {'/status': 1460, '/metrics': 2160, '/academy': 1660,
           '/backup latest': 1260, '/backup': 1260, '/labs': 1180, '/alerts': 1550}
DOMAINS = (('academy', 'Академия'), ('server', 'Сервер'),
           ('data', 'Данные и бэкапы'), ('monitoring', 'Мониторинг'))
NAMES = {**views.NAMES, 'lab': 'Тестовый стенд'}
OPTIONAL_REASONS = ('not_configured', 'disabled', 'controller_receipt_only')
PRIORITY = {'critical': 4, 'no_data': 3, 'warning': 2, 'ok': 1, 'stopped': 0}


def _font_bytes(name):
    return resources.files(__package__).joinpath('fonts', name).read_bytes()


def _number(value, maximum=10**18):
    # Compare the bounds before isfinite: arbitrarily large ints must not overflow.
    if type(value) not in (int, float) or not 0 <= value <= maximum:
        return None
    return value if math.isfinite(value) else None


def _quantity(value, suffix='', digits=1, maximum=10**18):
    value = _number(value, maximum)
    return 'Нет данных' if value is None else views.number(value, digits)+suffix


def _size(value):
    value = _number(value)
    return 'Нет данных' if value is None else views.size(value)


def _duration(value):
    value = _number(value, 10**9)
    return 'Нет данных' if value is None else views.period(value)


def _checks(data):
    rows = data.get('checks', [])
    return [row for row in rows[:128] if isinstance(row, dict) and
            row.get('id') in NAMES and row.get('status') in LABELS] if isinstance(rows, list) else []


def _check(data, key):
    return next((row for row in _checks(data) if row.get('id') == key), {})


def _source(data, key):
    # An absent check cannot establish that a retained measurement is current.
    if not _check(data, key):
        return {}
    # Reuse the text card's global/per-probe freshness gate, with selected checks.
    return views.source({'stale': data.get('stale'), 'checks': _checks(data), key: data.get(key)}, key)


def _state(data, key):
    return 'no_data' if data.get('stale') else _check(data, key).get('status', 'no_data')


def _memory_percent(data):
    memory = _source(data, 'host').get('memory', {})
    if not isinstance(memory, dict):
        return None
    total, available = _number(memory.get('total_bytes')), _number(memory.get('available_bytes'))
    if total is None or available is None or total <= 0 or available > total:
        return None
    return 100*(1-available/total)


def _disk(data, key):
    value = (_source(data, 'host').get('disk', {}) if key == 'ssd' else _source(data, 'hdd'))
    if _state(data, key+'_space') == 'no_data':
        return {}
    return value if isinstance(value, dict) else {}


def _incidents(data):
    return [row for row in _checks(data) if row['status'] in ('critical', 'warning', 'no_data') and
            row.get('reason_code') not in OPTIONAL_REASONS]


class _Canvas:
    def __init__(self, image, draw, font, height):
        self.image = image.new('RGB', (WIDTH, height), BG)
        self.draw = draw.Draw(self.image)
        self.height = height
        self.fonts = {}
        for weight in ('Regular', 'Bold'):
            payload = _font_bytes('UstarSans-'+weight+'.ttf')
            for size in (36, 40, 44, 48, 56, 64, 72):
                self.fonts[weight, size] = font.truetype(BytesIO(payload), size=size)

    def text(self, x, y, value, size=36, bold=False, color=TEXT, width=864, lines=1):
        face = self.fonts['Bold' if bold else 'Regular', size]
        words = value.split()
        rows, current = [], ''
        for word in words:
            candidate = (current+' '+word).strip()
            if current and self.draw.textlength(candidate, font=face) > width:
                rows.append(current); current = word
            else:
                current = candidate
        if current:
            rows.append(current)
        if len(rows) > lines:
            rows = rows[:lines]
            rows[-1] += '…'
        for row in rows:
            while row and self.draw.textlength(row, font=face) > width:
                row = row[:-2]+'…' if len(row) > 1 else ''
            self.draw.text((x, y), row, font=face, fill=color, anchor='lt')
            y += round(size*1.3)
        return y

    def panel(self, y, height, x=48, width=864):
        self.draw.rounded_rectangle((x, y, x+width, y+height), radius=24, fill=PANEL, outline=BORDER, width=2)

    def status(self, x, y, state, width=340, size=40):
        self.draw.ellipse((x, y+10, x+16, y+26), fill=COLORS[state])
        self.text(x+32, y, LABELS[state], size, color=COLORS[state], width=width-32)

    def header(self, command, data):
        self.text(48, 42, 'USTAR', 36, True, ACCENT)
        self.text(48, 102, TITLES[command], 64, True)
        stamp = views.when(data.get('observed_at'))
        line = ('Данные устарели · ' if data.get('stale') else 'Снимок · ')+stamp
        self.text(48, 196, line, color=COLORS['warning'] if data.get('stale') else MUTED, lines=2)

    def footer(self):
        self.draw.line(((48, self.height-108), (912, self.height-108)), fill=BORDER, width=2)
        self.text(48, self.height-78, 'Обзор · подробности в текстовом ответе', color=MUTED)


def _history(data, metric, series):
    key = 'http_local' if metric == 'http' else 'host'
    if data.get('stale') or _state(data, key) == 'no_data':
        return None
    row = series.get(metric) if isinstance(series, dict) else None
    if not isinstance(row, dict) or row.get('status') != 'ok' or row.get('reason_code') != 'measured':
        return None
    observed = _number(row.get('observed_at'), 4102444800)
    step = _number(row.get('step_seconds'), 3600)
    points = row.get('points')
    if observed is None or not -5 <= time.time()-observed <= 45 or step is None or step < 1:
        return None
    if not isinstance(points, list) or not 1 <= len(points) <= MAX_POINTS:
        return None
    segments, current, previous = [], [], None
    for point in points:
        if not isinstance(point, (list, tuple)) or len(point) != 2:
            return None
        at = _number(point[0], 4102444800)
        if at is None or (previous is not None and at <= previous):
            return None
        value = _number(point[1], 100 if metric in ('cpu', 'ram') else 10**9)
        if previous is not None and at-previous > step*1.5:
            if current:
                segments.append(current); current = []
        previous = at
        if not observed-3600 <= at <= observed or value is None:
            if current:
                segments.append(current); current = []
        else:
            current.append((at, value))
    if current:
        segments.append(current)
    return (observed, segments) if segments else None


def _chart(canvas, data, metric, series, y):
    history = _history(data, metric, series)
    if history is None:
        canvas.text(80, y+228, 'Нет свежей истории', 40, color=MUTED)
        canvas.text(80, y+346, 'Пропуски не заменены нулями', color=MUTED)
        return
    observed, segments = history
    top, bottom, left, right = y+204, y+326, 216, 864
    ceiling = 100 if metric in ('cpu', 'ram') else max(10, math.ceil(max(v for segment in segments for _, v in segment)/10)*10)
    suffix = '%' if metric in ('cpu', 'ram') else ''
    maximum = str(ceiling)+suffix if ceiling < 10000 else views.number(ceiling/1000, 0)+' тыс.'
    canvas.text(80, top-8, maximum, color=MUTED, width=130)
    canvas.text(80, bottom-20, '0'+suffix, color=MUTED, width=130)
    for level in (0, .5, 1):
        yy = bottom-(bottom-top)*level
        canvas.draw.line(((left, yy), (right, yy)), fill=BORDER, width=2)
    color = {'cpu': ACCENT, 'ram': '#bda1ff', 'http': '#70dbad'}[metric]
    for segment in segments:
        coords = [(left+(at-(observed-3600))/3600*(right-left), bottom-value/ceiling*(bottom-top))
                  for at, value in segment]
        if len(coords) > 1:
            canvas.draw.line(coords, fill=color, width=5, joint='curve')
        else:
            xx, yy = coords[0]
            canvas.draw.ellipse((xx-4, yy-4, xx+4, yy+4), fill=color)
    start = datetime.fromtimestamp(observed-3600, MSK).strftime('%H:%M')
    end = datetime.fromtimestamp(observed, MSK).strftime('%H:%M')+' МСК'
    canvas.text(left, y+346, start, color=MUTED, width=240)
    canvas.text(right-245, y+346, end, color=MUTED, width=245)
    canvas.text(80, y+390, 'Разрывы — нет замеров', color=MUTED)


def _metrics(canvas, data, series):
    host = _source(data, 'host')
    http = _source(data, 'http_local')
    measurements = (('cpu', 'CPU · сейчас', _quantity(host.get('cpu_percent'), '%', maximum=100)),
                    ('ram', 'RAM · занято сейчас', _quantity(_memory_percent(data), '%', maximum=100)),
                    ('http', 'HTTP · локально сейчас', _quantity(http.get('total_ms'), ' мс', 0, 10**9)))
    for index, (metric, label, value) in enumerate(measurements):
        y = 286+index*460
        canvas.panel(y, 438)
        canvas.text(80, y+24, label, 40, color=MUTED)
        canvas.text(80, y+86, value, 64, True)
        _chart(canvas, data, metric, series, y)
    canvas.panel(1688, 304)
    canvas.text(80, 1718, 'Свободное место', 40, True)
    for y, key, name in ((1792, 'ssd', 'SSD'), (1872, 'hdd', 'HDD')):
        disk = _disk(data, key)
        total, available = _number(disk.get('total_bytes')), _number(disk.get('available_bytes'))
        valid = total is not None and available is not None and 0 < total and available <= total
        canvas.text(80, y, name, 40, True)
        canvas.text(230, y, _size(available) if valid else 'Нет данных', 44, True, width=630)


def _status(canvas, data):
    rows = _checks(data)
    for index, (domain, label) in enumerate(DOMAINS):
        applicable = [r['status'] for r in rows if r.get('domain') == domain and r.get('reason_code') not in OPTIONAL_REASONS]
        state = 'no_data' if data.get('stale') else max(applicable, key=PRIORITY.get, default='no_data')
        y = 286+index*138
        canvas.panel(y, 116)
        canvas.text(80, y+36, label, 40, True, width=465)
        canvas.status(562, y+36, state, width=318)
    canvas.panel(880, 220)
    canvas.text(80, 910, 'CPU сейчас', 36, color=MUTED)
    canvas.text(80, 980, _quantity(_source(data, 'host').get('cpu_percent'), '%', maximum=100), 64, True, width=370)
    canvas.text(520, 910, 'RAM занято', 36, color=MUTED)
    canvas.text(520, 980, _quantity(_memory_percent(data), '%', maximum=100), 64, True, width=360)
    incidents = _incidents(data)
    canvas.text(48, 1144, 'Нет свежих данных' if data.get('stale') else 'Требуют внимания: '+str(len(incidents)), 40, True)
    optional = sum(row.get('reason_code') in ('not_configured', 'disabled') for row in rows)
    if optional:
        canvas.text(48, 1216, 'Не подключено / отключено: '+str(optional), 36, color=MUTED)
        canvas.text(48, 1270, 'Контроль неполный', color=MUTED)


def _academy(canvas, data):
    http = _source(data, 'http_local')
    canvas.panel(286, 280)
    canvas.text(80, 316, 'Локальный HTTP', 40, True)
    canvas.status(540, 316, _state(data, 'http_local'))
    canvas.text(80, 398, _quantity(http.get('total_ms'), ' мс', 0, 10**9), 72, True)
    code = _number(http.get('code'), 599)
    canvas.text(80, 502, 'HTTP '+str(int(code)) if code is not None and code >= 100 else 'Код: нет данных', 36, color=MUTED)
    db = _source(data, 'database')
    canvas.panel(590, 366)
    canvas.text(80, 620, 'PostgreSQL', 40, True)
    canvas.status(540, 620, _state(data, 'database'))
    connections, maximum = _number(db.get('connections'), 10**9), _number(db.get('max_connections'), 10**9)
    value = str(int(connections))+' / '+str(int(maximum)) if connections is not None and maximum is not None and 0 < maximum and connections <= maximum else 'Нет данных'
    canvas.text(80, 698, value, 72, True)
    canvas.text(80, 800, 'подключений', 36, color=MUTED)
    canvas.text(80, 864, 'Активных: '+_quantity(db.get('active'), digits=0, maximum=10**9)+
                ' · Блокировок: '+_quantity(db.get('waiting'), digits=0, maximum=10**9), 36)
    cron = _source(data, 'cron')
    canvas.panel(980, 350)
    canvas.text(80, 1010, 'Фоновые задания', 40, True)
    canvas.status(540, 1010, _state(data, 'cron'))
    canvas.text(80, 1088, _duration(cron.get('success_age_seconds')), 64, True)
    canvas.text(380, 1107, 'после успеха', 36, color=MUTED, width=475)
    canvas.text(80, 1194, 'Очередь задач: '+_quantity(cron.get('adhoc_queued'), digits=0, maximum=10**9), 36)
    canvas.text(80, 1252, 'Новых ошибок: '+_quantity(cron.get('new_task_failures'), digits=0, maximum=10**9), 36)
    canvas.text(48, 1376, 'Контейнеры', 40, True, width=500)
    canvas.status(560, 1376, _state(data, 'containers'))
    external = _check(data, 'http_external')
    external_label = 'Не подключён' if external.get('reason_code') in ('not_configured', 'disabled') else LABELS[_state(data, 'http_external')]
    canvas.text(48, 1452, 'Внешний HTTP: '+external_label, color=MUTED)


def _backup(canvas, data):
    value = _source(data, 'backup')
    present = bool(value.get('file'))
    canvas.panel(286, 632)
    canvas.status(80, 322, _state(data, 'backup'))
    canvas.text(80, 412, _size(value.get('bytes')) if present else 'Нет данных', 72, True)
    canvas.text(80, 518, 'размер завершённой копии', 36, color=MUTED)
    canvas.text(80, 608, 'Завершена', 36, color=MUTED)
    canvas.text(80, 664, views.when(value.get('completed_at')) if present else 'Нет данных', 40, True)
    canvas.text(80, 750, 'Возраст', 36, color=MUTED)
    canvas.text(80, 806, _duration(value.get('age_seconds')) if present else 'Нет данных', 48, True)
    verified = present and value.get('verification') == 'creation_receipt_only'
    canvas.text(48, 968, 'Проверка при создании' if verified else 'Проверка: нет данных', 40, True)
    canvas.text(48, 1032, 'Восстановление ещё не принято', 36, color=COLORS['warning'])
    canvas.text(48, 1088, 'Подробности копии и проверки — в тексте', 36, color=MUTED)


def _labs(canvas, data):
    value = _source(data, 'lab')
    check = _check(data, 'lab')
    if not data.get('stale') and check.get('reason_code') in (None, 'controller_receipt_only'):
        value = data.get('lab', {})
    if not isinstance(value, dict):
        value = {}
    phase = value.get('phase')
    names = {'extracting': 'Распаковывается', 'restoring_database': 'Восстанавливается БД',
             'ready': 'Готов по данным контроллера', 'stopped': 'Остановлен',
             'failed': 'Ошибка запуска', 'failed_stopped': 'Ошибка; остановлен',
             'cleanup_required': 'Требуется очистка'}
    canvas.panel(286, 598)
    color = COLORS['critical'] if phase in ('failed', 'failed_stopped', 'cleanup_required') else TEXT
    canvas.text(80, 332, names.get(phase, 'Нет свежих данных'), 56, True, color, width=800, lines=3)
    canvas.text(80, 566, 'Состояние контроллера', 36, color=MUTED)
    if phase in ('stopped', 'failed_stopped'):
        expiry = 'Автоостановка не действует'
    elif phase is None:
        expiry = 'Автоостановка: нет данных'
    elif value.get('unlimited') is True:
        expiry = 'Без автоостановки'
    else:
        expiry = 'Автоостановка: '+views.when(value.get('expires_at'))
    canvas.text(80, 662, expiry, 40, width=800, lines=2)
    canvas.text(48, 938, 'Доступность Moodle и HTTP стенда', 36, color=MUTED)
    canvas.text(48, 996, 'проверяется отдельно', 36, color=MUTED)


def _alerts(canvas, data):
    incidents = _incidents(data)
    canvas.panel(286, 238)
    canvas.text(80, 320, 'Нет свежих данных' if data.get('stale') else str(len(incidents)), 72, True)
    canvas.text(80, 430, 'текущих отклонений' if not data.get('stale') else 'Состояние не подтверждено', 36, color=MUTED)
    y = 566
    for row in incidents[:3]:
        canvas.status(48, y, 'no_data' if data.get('stale') else row['status'], size=36)
        canvas.text(48, y+58, NAMES[row['id']], 36, True, width=864, lines=2)
        y += 156
    if not incidents:
        canvas.text(48, y, 'Проверки и пороги — в текстовом ответе', 36, color=MUTED)
    notifications = data.get('notifications', {})
    notifications = notifications if isinstance(notifications, dict) else {}
    canvas.panel(1080, 230)
    canvas.text(80, 1112, 'Доставка уведомлений', 40, True)
    canvas.text(80, 1192, 'В очереди: '+_quantity(notifications.get('pending'), digits=0, maximum=10**9), 40)
    canvas.text(80, 1250, 'Со сбоями: '+_quantity(notifications.get('delivery_failures'), digits=0, maximum=10**9), 40)
    canvas.text(48, 1360, 'Нужен независимый наблюдатель', 36, color=MUTED)


def render_card(command: str, data: dict, series: dict | None = None) -> bytes | None:
    """Return a mobile-readable PNG overview, or None to retain text only.

    Commands are canonicalized by the caller. Input is the existing sanitized
    snapshot; history is the bounded cpu/ram/http 1h export from history_store.
    """
    if command not in TITLES or not isinstance(data, dict) or data.get('scope') == 'independent_observer':
        return None
    try:
        from PIL import Image, ImageDraw, ImageFont
        canvas = _Canvas(Image, ImageDraw, ImageFont, HEIGHTS[command])
        canvas.header(command, data)
        if command == '/metrics':
            _metrics(canvas, data, series)
        elif command == '/status':
            _status(canvas, data)
        elif command == '/academy':
            _academy(canvas, data)
        elif command in ('/backup', '/backup latest'):
            _backup(canvas, data)
        elif command == '/labs':
            _labs(canvas, data)
        elif command == '/alerts':
            _alerts(canvas, data)
        canvas.footer()
        output = BytesIO()
        canvas.image.save(output, format='PNG', optimize=True)
        payload = output.getvalue()
        return payload if len(payload) <= MAX_PNG_BYTES else None
    except (ImportError, OSError, ValueError):
        # Missing Pillow/font resources must never prevent the durable text reply.
        return None
