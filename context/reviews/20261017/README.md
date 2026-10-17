# Проверка приложенного review и сбора контекста — 17 октября 2026

**Обновление04:16MSK:** оба server1 archives получены, проверены и импортированы. [Свежая сверка](runtime_reconciliation.md) описывает526 совпавших source files, реальные risks и остающиеся пробелы. Упоминания ожидания ниже относятся к этапу до получения архивов.

Проверены все восемь приложений: сначала `fable.md`, затем Markdown/PDF review, collector, importer, README, proposed current YAML и CI guard. [Оригиналы и SHA256](submission_manifest.json); текстовые оригиналы сохранены в [submitted](submitted/) без исправлений, shell/YAML proposals имеют суффикс `.txt` и **не являются действующими инструкциями**. PDF не дублируется в Git: его hash учтён, при экспорте обнаружены повторы, буквальные `<br/>` и ошибочный footer2025; команды брать из проверенного исходника.

Проверенная база: main `d9655074db9a06281fe16f378dbdbe1d279104a0`; пульт — открытый [PR103](https://github.com/cauf1l3d/ustarlms/pull/103), head `98559017eef887d2a87955cc55ee947b5babe0a0`. Этот review — проверка исходников, датированных receipts и тестов. Нового подключения к server1 и production snapshot ещё не было.

## Ошибки предложенных скриптов

| Предложение / место | Подтверждённая проблема | Принятое исправление |
|---|---|---|
| `server_facts.sh`, H3–H5 | `/opt/ustar/data/moodle/public` — внешний mount. Проверки `local/ustar`/theme по нему промахиваются: документированный public root — `/opt/ustar/data/moodle/public/public`, в CT `/var/www/html/public` | Root определяется по mount и обоим USTAR version files; config отдельно ищется на текущем и родительском уровне. `/var/www/html/config.php` сам по себе не ошибка |
| D3/D8, Docker/cron/systemd/log output | Все PG settings, `archive_command`, произвольные log options, crontab/unit/log snippets могут содержать credentials/PII | Закрытые поля, PG allowlist, только факт наличия archive command, выбранные свойства вместо сырых текстов |
| D7 | Экспортируется текст SQL, способный содержать PII и секреты | Только query_id, calls и длительности |
| E6 | GET страницы входа может создать Moodle session; `Set-Cookie` содержит её значение, Location тоже не всегда безопасен | HTTP к приложению и cookie export исключены |
| D-блоки PostgreSQL | Роль `postgres` не гарантирована при `POSTGRES_USER=moodle`; без `-d` запросы могут попасть не в БД Moodle | Роль — из окружения работающего PG CT; БД приложения — только из однозначного config literal. Неизвестная БД означает unavailable, без fallback на другую БД |
| D9/D10 | `GROUP BY 1` ссылается на агрегат внутри единственного concatenated expression; в D10 `GROUP BY 1,2` дополнительно ссылается на несуществующий второй output column | Фиксированные SELECT с именованными колонками и JSON aggregate; prefix проверяется, `mdl_` не предполагается |
| Manifest example | Вызову `production_manifest.py` не хватает обязательных `--moodle-root` и `--output-dir`; docs HEAD не равен application baseline | Отдельный bounded hash-only manifest plugin/theme; сравнение при импорте с e1d57f5b/526, core отдельно |
| Сбор и OUT | Нет private umask/защиты ссылок; HDD path может оказаться SSD directory при отсутствующем mount; временный файл создаётся вне OUT | Новый private каталог в `/var/tmp`, 0700/0600, отказ от существующего target/links; HDD проверяется как mount. Никаких backups/dumps в отчёте |
| Обходы и subprocesses | `du /`, recursive find/ACL и команды без budget могут быть дорогими или зависнуть | Ограниченные команды/вывод, выбранные paths, bounded plugin/theme pass; без `du /` и сырых logs |
| `_errors.txt` | stderr может раскрывать приватные значения; importer затем удаляет файл, скрывая неполный сбор | Сохраняются block/status/reason без raw stderr; errors — обязательная проверяемая часть снимка |
| Import/redaction | Keyword grep даёт ложные срабатывания на password policy/`passwordauthentication`; IPv4 regex неверно покрывает 172.16/12 и может менять version-like strings, IPv6 не покрыт | Не редактировать наблюдения regexp. Закрытая schema, проверка значений и сильных credential patterns, отклонение входа с запрещёнными данными |
| Manifest/import | SHA256 не проверяется до записи и устаревает после redaction/удаления; arbitrary directories/links и tar paths не ограничены | Проверка всех payload checksums до repo writes; только четыре members, без filesystem extraction, links/traversal/duplicates/oversize и gzip expansion отвергаются |
| STATE/current | `20261017` не ISO-date; новый manual current YAML создаёт ещё один конкурирующий источник | STATE/BACKLOG остаются canonical; current/checkpoint генерируются, date ISO, drift проверяется CI |
| Root/SMART | Root нужен не только SMART: receipts, ACL, sshd/firewall/PG/Docker имеют отдельные ограничения | Root для полного сбора; SMART opt-in, без self-test и запуска спящего диска |

[`server_facts.py`](../../../scripts/infra/server_facts.py), [wrappers/runbook](../../../scripts/infra/README_server_facts.md) заменяют shell proposals. Сам collector требует только stdlib Python3, importer — pinned repo PyYAML. Ошибки источников оставляют partial report, а не фиктивный ноль/зелёный статус. `complete` означает полноту наблюдений, не здоровье сервера.

Существующий `scripts/collect_runtime.py` не удалён: он выполняет Moodle bootstrap до read-only SQL mode и имеет другую область использования. Автоматически вызывать его на production как якобы безусловно read-only нельзя. PHP CLI probe новой версии читает PHP_VERSION/extensions/ini без подключения config.php и bootstrap Moodle.

## Какие выводы внешнего review принимаются

- Устаревший checkpoint02Oct и несколько reading lists действительно мешают входу. Старый checkpoint сохранён [в архиве](../../archive/context_review_20261017/CURRENT_20261002.md); теперь порядок только START_HERE, current/checkpoint — generated views. Исторические receipts не переписаны датой review.
- Изолированный venv в CI полезен. Сохранён весь `unittest discover -s tests/r16 -v`, добавлена проверка установленной версии MCP. Предложение заменить suite только harness-тестами сократило бы покрытие и не принято. Transitive requirements ещё не превращены в полный hash lock.
- Design/navigation и доменные сценарии нуждались в описании. Добавлены [design map](../../design/README.md), source-derived tokens/layer order и [capability references](../../architecture/access_matrix.yaml), углублены selected grades/routes/evidence/work/mobile paths. Это не полная role matrix и не computed UI kit.
- INF-02/03/07 и прочие остатки аудита сохраняются открытыми. Сначала собираются факты; затем изменения ролей/прав/TLS требуют отдельного проверенного implementation и native acceptance. Snapshot не закрывает P0 или clean-host DR.
- Проверки приёмки должны иметь воспроизводимые evidence. Для repository gates есть команды ниже; для native шагов остаются runbooks/owner receipts. Не добавляем к каждой задаче искусственный `verify_cmd`, который обещает доказать вход/SCORM/RTO одним локальным тестом.

## Что не подтверждено или требует уточнения

| Утверждение | Проверка и вывод |
|---|---|
| CI «пропускает» harness из-за отсутствия `__init__.py` | Исходная команда discovery в pinned venv успешно выполнила545 тестов,19 environment skips, включая реальный MCP stdio. Добавлять init-файлы и менять package semantics оснований нет |
| Sandbox с MCP2.3 доказывает сломанный repo CI | Repo pins MCP1.30.0/PyYAML6.0.3; несовместимый внешний environment не является фактом о GitHub runner. Дополнительно изолировали CI environment |
| Три PromQL запроса означают отсутствие disk/container/backup metrics | В PR103 эти текущие facts уже есть в collector/API/UI; CPU/RAM/HTTP history — отдельный слой. Полная cron/business freshness и alerts acceptance остаются открытыми |
| У пульта нет свежести | TTL45s и connection/session expiry уже реализованы. Не заменять их предлагаемым stale5min как улучшением |
| Cron daily | Принятая схема — один worker каждую минуту; backup — ежедневно03:30MSK. `cron_exit_code=0` не отменяет `after.new_failures`; в новом receipt они раздельны |
| Нет дизайн-системы вообще | В коде есть `_tokens.scss`, foundation и слои темы. Не хватало контекстной карты; declared tokens ещё не доказывают все computed states/контрасты |
| Notion/GitHub отключены | `.mcp/config.yaml` — purpose map без transport, не доказательство соединений. GitHub connector доступен в этой сессии; Notion status не измерен. Файл это явно поясняет |
| Нет pg_stat_statements ⇒ PERF-01 не начат | Отсутствие одного extension не доказывает историю выполнения performance work |
| Same-host мониторинг бесполезен, observer можно оставить низким приоритетом | Same-host полезен для диагностики, но потерю всего хоста должен обнаруживать независимый наблюдатель; он остаётся условием INF-06 |
| Свободные inodes >80/90% — warning/critical | Перепутана полярность: предупреждение относится к высокой занятости или низкому остатку, не высокому свободному месту |
| p95/SLA из одного blackbox probe | Нет достаточного ряда и определённого error budget. Это latency probe, не доказательство SLA |
| Privacy gate и deadline15Jan2027 уже согласованы | Это новые рекомендации автора review. Privacy ранее отложен владельцем; hard gate не вводится. Jan15 записан только как planning recommendation для reassessment |
| Новый сбор даст «100% контекст» | Не доказывает RTO/restore, full core manifest, effective actor/scope/file access, external TLS/mail, offsite custody и длительную нагрузку. [Пробелы](../../coverage.yaml) явно сохранены |

Security support Moodle5.1 до19April2027 проверен по [официальному lifecycle](https://moodledev.io/docs/5.1); minimum PG17 для5.3 — по [официальным requirements](https://moodledev.io/general/releases/5.3). Это не снимает команду владельца отложить5.2/PG17 и не разрешает обновлять production.

Review не заменяет последнее требование владельца к UI: читаемый шрифт, цветные акценты и Grafana-style charts. Прототип уже в [PR103](https://github.com/cauf1l3d/ustarlms/pull/103/files); к прежнему мелкому тексту возвращаться не нужно.

## Проверки и порядок продолжения

На исходной базе:545 tests PASS,19 skips; MCP handshake реально выполнен. У PR103/head98559017 три проверенных CI run успешны: [context38897769023](https://github.com/cauf1l3d/ustarlms/actions/runs/38897769023), [review gate38897769044](https://github.com/cauf1l3d/ustarlms/actions/runs/38897769044), [console38897769155](https://github.com/cauf1l3d/ustarlms/actions/runs/38897769155). Это не native server acceptance.

Команды gate новой поставки:

```sh
python3 -m venv /tmp/ustar-context-review
/tmp/ustar-context-review/bin/python -m pip install -r harness/ustar-contextd/requirements.txt
/tmp/ustar-context-review/bin/python scripts/build_current_context.py --check
/tmp/ustar-context-review/bin/python scripts/build_agent_inventory.py --check
/tmp/ustar-context-review/bin/python scripts/check_context_secrets.py
/tmp/ustar-context-review/bin/python scripts/check_context.py
/tmp/ustar-context-review/bin/python -m unittest discover -s tests/r16 -v
```

1. Опубликовать исправленный collector/import/guard и context maps отдельным PR от main; пульт остаётся в PR103.
2. Владелец выполняет pinned collector на server1, передаёт один `report.tar.gz`, в том числе partial.
3. Проверить schema/checksums, импортировать immutable observations и сравнение526, разобрать расхождения с датированными receipts; не менять статусы инфраструктурных задач по наличию отчёта.
4. Передать актуализированный контекст владельцу на повторный аудит, затем продолжить repo/пульт/Telegram и согласованный backlog. Работа на сервере, отправка Telegram messages и platform migration в этой поставке не выполнялись.
