# Числовые запросы Apache и безопасные события хоста

Сборщик читает **реальные запросы к vhost Академии `ustar.local` на Apache хоста**.
`p95` — ближайший ранг 95-го процентиля времени Apache `%D`, в миллисекундах,
для завершённых записей с завершением запроса в окне `(сейчас − 300 секунд, сейчас]`.
RPS — фактическое число таких записей, делённое **всегда на 300 секунд**.
Это время host Apache/proxy; это не время браузера и не отдельное измерение Moodle/PHP.
HEAD-проба самого пульта исключена по точному User-Agent `USTAR-Console-Probe/1`.

Числовой журнал содержит только `%{end:sec}t %>s %D`: Unix-время, окончательный HTTP-код
и длительность в микросекундах. IP, адрес страницы, query, заголовки и cookie в него
не записываются. Старые журналы Apache остаются на хосте с прежними настройками.

## Команды владельца на server1

Нативный сервер агенту недоступен. Команды ниже выполняет владелец из корня
проверенного checkout после доставки обновлённого пульта:

```bash
sudo python3 server-console/tools/configure_observability.py --check
sudo python3 server-console/tools/configure_observability.py --install
sudo systemctl start ustar-console-collect.service
```

Без `--install` помощник только читает конфигурацию и выполняет `apache2ctl configtest`.
Установка добавляет managed Include **внутрь одного существующего** VirtualHost с
прямым `ServerName ustar.local`, сверенного через `apache2ctl -t -D DUMP_VHOSTS`.
Его исходник должен быть root-owned файлом непосредственно в
`/etc/apache2/sites-available`, активированным из `/etc/apache2/sites-enabled`.
Нужен существующий прямой `CustomLog`: иначе новый vhost-журнал мог бы отключить
наследуемое глобальное логирование. Неоднозначные, условные, косвенные или
неподдерживаемые конфигурации помощник оставляет для локального разбора.

Фиксированные создаваемые файлы:

| Путь | Назначение |
| --- | --- |
| `/etc/apache2/ustar-console-observability.conf` | Только числовой LogFormat/CustomLog и исключение HEAD-пробы |
| `/var/log/apache2/ustar-console-access.log` | Новый журнал, root:adm, 0640; чтение сборщиком root |
| `/etc/logrotate.d/ustar-console-observability` | Еженедельная ротация, `maxsize 10M`, 2 архива, 0640 root:adm |
| `/run/ustar-console-observability.lock` | Root-only блокировка параллельной установки |

Перед правкой сохраняется sibling backup vhost с именем
`*.conf.ustar-observability-backup-<UTC>-<случайный суффикс>` и режимом 0600.
JSON-результат установки сообщает точный путь. После записи выполняются configtest
и graceful reload Apache. При ошибке возвращается оригинал, выполняется configtest;
если reload уже пробовали — повторный reload оригинала. При независимом изменении
vhost помощник отказывается его перезаписывать и сообщает о необходимости локально
проверить сохранённый backup. Пути, listeners, proxy, DNS, firewall, `wwwroot`,
контейнеры и код Академии помощник не настраивает.

После установки можно вывести **только безопасный результат сборщика**:

```bash
sudo /opt/ustar-console/venv/bin/python -c 'import json; from ustar_console.web_observability import collect; print(json.dumps(collect(), ensure_ascii=False))'
```

Сырую выдачу `journalctl` и обычные access/error logs в чат или Telegram не передавать.
Пользователь веб-пульта и процесс Telegram не получают `adm`/`systemd-journal` группы
или доступ к новому журналу. Помощник установки не входит в команды веб-брокера.

## Достоверность и ограничения

- Первое время окно неполное: `status=no_data`, `reason_code=insufficient_coverage`,
  `coverage_seconds<300`. Частичные фактические counts/p95 сохраняются, RPS не
  экстраполируется. Полное окно подтверждает валидная запись не новее его левой
  границы. В новом пустом журнале значений ещё нет, даже спустя пять минут простоя.
- Отсутствующий, пустой, небезопасный или испорченный источник даёт `null`, а не
  выдуманный ноль. Если последняя запись старше300с, текущий поток неизвестен: простой и остановку логирования нельзя различить; counts/RPS остаются `null`.
- Читается только текущий фиксированный файл, не архивы, не более последних 4 MiB.
  После ротации покрытие может стать неполным. При усечении файла по пределу4MiB полное покрытие не заявляется даже при старой записи в хвосте. Будущие даты,
  нечисловые/лишние поля, HTTP-коды вне 100–599 и длительности больше 600 секунд
  делают измерение недостоверным. Незавершённый последний append не считается записью.
- `maxsize 10M` проверяется при запуске штатного logrotate: между запусками активный
  файл может превысить 10 MiB. Сохраняются максимум два ротационных архива; при чтении
  сборщик всё равно ограничен 4 MiB.
- События: `journalctl` только для `apache2.service`, `ustar-hdd-backup.service` и
  `ustar-console*.service`, последние 300 секунд, не более 2 секунд/512 KiB/2000 строк.
  Ошибки и предупреждения сворачиваются локально в фиксированные коды и counts,
  console-unit имена нормализуются до `ustar-console.service`; максимум 100 строк.
  Никакой raw `MESSAGE`, username, путь, аргументы или текст исключения не экспортируется.
  Старые события за пределами окна и завершённые успешные операции не выводятся.
- `events_status=ok` с `events=[]` означает, что доступный журнал не дал подходящих
  событий. `events_status=no_data` отличает недоступный/недостоверный журнал. Ошибка
  чтения событий не стирает доступные числовые измерения запросов.

## Контракт интеграции

`ustar_console.web_observability.collect(now=None)` вызывается только root-сборщиком,
отдельно от HTTP-запросов. Он возвращает JSON-safe dict:

```text
observed_at: ISO UTC
status: ok | no_data
reason_code: measured | insufficient_coverage | source_empty | source_invalid | source_unavailable
window_seconds: 300
requests, errors_5xx, errors_4xx: integer | null
rps, p95_ms: number | null
coverage_seconds: integer 0..300
events_status: ok | no_data
events_reason_code: measured | source_invalid | source_unavailable
events: [{at: ISO UTC, source: fixed allowlist, level: warning | error, code: fixed allowlist, count: integer}]
```

Источники событий: `apache2.service`, `ustar-hdd-backup.service`,
`ustar-console.service`. Коды: `unit_failed`, `service_error`, `service_warning`,
`disk_full`, `out_of_memory`, `permission_denied`.
API должен проверять эту схему и обслуживать уже сохранённый snapshot; браузер и
Telegram не вызывают чтение журналов и не выбирают путь/unit/команду.

Fixture-тесты проверяют числа, percentile, окно, приватность, trust checks,
ограничения, выбор vhost, идемпотентность и откат. Они не подтверждают, что новая
конфигурация уже установлена, Apache перезагружен или logrotate сработал на server1.
