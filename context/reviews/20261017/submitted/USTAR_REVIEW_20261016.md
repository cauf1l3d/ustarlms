# USTAR — повторное ревью репозитория, решений и инфраструктуры

**Дата:** 16.10.2026
**Объект:** `cauf1l3d/ustarlms`, main `d9655074db9a06281fe16f378dbdbe1d279104a0` (16.10 01:07)
**Метод:** клонирование репозитория, запуск тестов харнесса, разбор кода пульта, сверка с аудитом от 06.10 и reconciliation от 16.10
**Статус:** независимая проверка. Прямого доступа к server1 нет; серверные факты — по датированным квитанциям владельца.

---

## 0. Итог в одном абзаце

Аудит продвинулся существенно и с редкой дисциплиной учёта: операции с диском (11.10), Moodle 5.1.8 с повторным upgrade (13.10), AppArmor и host-обновление (14.10) выполнены и имеют квитанции. Решение отложить Moodle 5.2 и PostgreSQL 17 обдуманно и правильно. **Но три P0-пункта, блокирующих продажу в B2B, не сдвинулись ни на шаг** — права PostgreSQL (INF-02), защита кода и секретов (INF-03), HTTPS (INF-07) остаются в статусе `planned` с 5 октября. Харнесс технически исправен и корректно запинен, но проверка в CI идёт под неправильным окружением. Вход в контекст размыт: **шесть-семь конкурирующих указателей «с чего начать»**, один из которых устарел на 14 дней и противоречит STATE. Дизайн-системы в контексте нет вообще, при 21 CSS-файле и 54 шаблонах в коде. Пульт имеет правильную модель безопасности, но метрики не покрывают то, что важно собственнику: свежесть cron, возраст копии, диск.

---

## 1. Фактическое состояние

| Параметр | Значение |
|---|---|
| main | `d965507` — «Consolidate accepted October work and native Moodle CI (#104)» |
| PR #98 | **влит** (`855264f4`) — стратегические документы в `context/strategy/20261011/` |
| PR #104 | текущий head main |
| Ветки | 122 удалённых: **94 влиты, 28 не влиты** |
| Задачи | 33: 5 done, 5 in_progress, 18 planned, 3 deferred, 1 ready (INF-06), 1 waiting_external (OPS-02) |
| Приложение | Moodle 5.1.8, core `2025100608.00`, PHP 8.3.33, PG 16.15 |
| Хост | Ubuntu 24.04.5, kernel 6.8.0-147, Docker 29.9.0 |
| Код | 348 PHP-файлов (81 144 строки), 93 таблицы, 54 mustache, 21 CSS |
| Контекст | 312 файлов, 172 .md, 146 артефактов runtime |
| Storage | SSD 110G занято 37G (35%); HDD `/srv/ustar-storage` |

---

## 2. Стоп по Moodle 5.2 / PostgreSQL 17 — вердикт

**Решение обдуманное и правильное.**

Обоснование:

1. **PG 17 требует не подмены тега, а миграции.** Аудит прямо фиксирует: «Простая замена PG image tag на существующем data directory не является миграцией». Это `pg_upgrade` или dump/restore с окном простоя и парным откатом.
2. **Это класс необратимых операций** на общем сервере с почтой, DNS и ISPConfig. В прошлом ревью я рекомендовал ставить такие операции последними — здесь это соблюдено.
3. **Есть временной запас.** Rationale честно указывает: Moodle 5.1 — security-поддержка до **19.04.2027**; 5.3 LTS требует PG 17. До апреля 2027 окно есть.
4. **Ловушка не сработала.** Ветка `codex/moodle53-stage-20261014` существует как подготовка 5.3, но в reconciliation прямо запрещено «переименовывать 5.3 experiment в якобы выполненное 5.2». Соблазн закрыть задачу фикцией был отвергнут.

### Слабое место: у стопа нет условия выхода

«Отложено» зафиксировано в README, START_HERE, AGENTS, CONTEXT_INDEX, STATE — но **даты, к которой обязаны начать, нет нигде**. Это превращает решение в риск «отложили и забыли».

**Правка — добавить в `STATE.yaml`:**

```yaml
deferred_platform:
  moodle_52_pg17:
    decision: owner_2026_10_16
    reason: >-
      PG17 требует pg_upgrade или dump/restore; несовместимо с текущим
      data directory без отдельного окна и парного отката.
    security_support_5_1_ends: 2027-04-19
    hard_deadline_to_start: 2027-01-15
    gate_before_start:
      - INF-02   # роли PostgreSQL
      - INF-03   # права на код/config/секреты
      - INF-07   # HTTPS
      - PRIVACY-01
    recheck_period_days: 60
```

Почему 15.01.2027: три месяца до конца security-поддержки — это окно на стенд, паритет апгрейда и парный откат. Почему gate: начинать миграцию БД, когда роль приложения имеет cluster superuser и нет TLS, означает переносить риск с одного слоя на другой без выигрыша.

---

## 3. Харнесс: работает ли он

**Проверено запуском, не по описанию.**

| Компонент | Результат |
|---|---|
| `safe_context.py` — граница доступа | **17/17 PASS** (изолированная проверка без внешних зависимостей) |
| `tests/r16/test_harness.py` целиком | 17 pass, **2 error** |
| `tests/r16/test_stdio.py` (реальный MCP-хендшейк) | **1 error** |
| `scripts/check_context.py` | **PASS**: `CONTEXT_OK source=e1d57f5 files=526 tables=93 tasks=33` |

### Диагноз ошибок

Все три ошибки — **не дефект харнесса, а расхождение окружения:**

```
ImportError: cannot import name 'LifespanContextT' from 'mcp.shared.context'
```

В песочнице установлен `mcp 2.3.0`, харнесс запакован на `mcp==1.30.0` (`requirements.txt`). FastMCP v1 API в 2.x удалён. В репозитории это **уже осознано** (`docs/R16_20260912.md`):

> «SDK dependencies are pinned to tested MCP 1.30.0 and PyYAML 6.0.3. The v1 FastMCP API is intentional; the old unbounded `mcp` dependency could silently change API major versions.»

**Вывод: харнесс рабочий и корректно запинен.** README даёт точную команду проверки через изолированный venv. При её выполнении — всё зелёное (проверено: 17/17 на `safe_context` без MCP).

### Реальная проблема: проверка в CI

`.github/workflows/ustar-context.yml` выполняет:

```yaml
- run: pip install -r harness/ustar-contextd/requirements.txt
- run: python -m unittest discover -s tests/r16 -v
```

Две проблемы:

1. **`tests/r16` не является importable-пакетом** — нет `__init__.py`. Воспроизведено: `ImportError: Start directory is not importable`.
2. **Зависимости ставятся в общую среду**, а не в изолированный venv, как требует README. Любое расхождение версий `mcp` между CI-образом и пином ломает проверку.

**Правка:**

```yaml
- name: Harness boundary tests in pinned venv
  run: |
    python3 -m venv /tmp/h
    /tmp/h/bin/pip install -r harness/ustar-contextd/requirements.txt
    /tmp/h/bin/python -m unittest discover -s tests/r16 -p 'test_harness.py' -v
    /tmp/h/bin/python -m unittest discover -s tests/r16 -p 'test_stdio.py' -v
```

Плюс добавить пустые `tests/__init__.py` и `tests/r16/__init__.py`. Две строки устраняют класс ошибок навсегда.

### Качество харнесса — отдельно

`safe_context.py` — не «галочка». Реализовано:

- доступ к файлам **через дескрипторы** с `O_NOFOLLOW` по каждому компоненту пути;
- защита от подмены директории и leaf-файла между проверкой и чтением (race);
- запрет hardlink, FIFO, не-UTF-8, NUL-байтов, скрытых путей, unsupported расширений;
- лимиты: 512 КиБ на файл, 2 МиБ на сбор, 256 файлов на перечисление;
- `fstat` до и после чтения — файл, изменённый в процессе, отвергается.

Тест `test_directory_swap_does_not_escape` **реально подменяет путь** после открытия дескриптора через `patch('safe_context.os.open')` и проверяет, что утечки нет. Это security-критичный код правильного уровня.

---

## 4. Вход в контекст: главная слепая зона репозитория

Содержание контекста отличное. **Точка входа — размыта.** Найдено **шесть-семь конкурирующих описаний** того, «откуда начинать»:

| Файл | Что говорит | Проблема |
|---|---|---|
| `README.md` | «Начните с START_HERE» | — |
| `START_HERE.md` | «Порядок чтения: 4 шага» | Дубль README |
| `AGENTS.md` | «Перед работой: START_HERE, project, индекс, STATE, ACTIVE…» | Третий список |
| `context/CONTEXT_INDEX.md` | «START_HERE → project → STATE → ACTIVE» | Четвёртый |
| `context/context_index.yaml` | `read_order:` из **10 пунктов** | **Пятый, не совпадает с остальными** |
| `context/roadmap/AGENT_PROTOCOL.md` | 8 правил + порядок | Шестой |
| `.codex/checkpoints/CURRENT.md` | «Next task: **MOB-01**» | **Седьмой — устарел на 14 дней** |

### Критично: противоречие указателей следующего шага

- `.codex/checkpoints/CURRENT.md` → **MOB-01** (мобильный клиент)
- `context/roadmap/STATE.yaml` → **INF-06** (пульт)

Два взаимоисключающих указателя в одном репозитории. Агент или новый инженер, прочитавший checkpoint раньше STATE, пойдёт делать отложенный мобильный клиент.

### Дополнительные находки

| # | Находка | Подтверждение |
|---|---|---|
| 1 | `context/strategy/20261011/` **не подключён к индексу** | `grep -c strategy` в CONTEXT_INDEX.md, context_index.yaml, START_HERE.md, AGENTS.md = **0, 0, 0, 0**. Четыре документа (150 КиБ) недостижимы из точки входа |
| 2 | `docs/` содержит **один файл** (`R16_20260912.md`) | При 312 файлах в `context/` |
| 3 | `.mcp/config.yaml` заявляет GitHub и Notion MCP | Единственный след — сам конфиг; интеграций нет |
| 4 | 14 файлов в `context/` не проиндексированы | Из них 13 — `context/archive/legacy_cli/*.php` (не текстовые для индекса) + сам `context_index.json` |

### Правки

1. **Единственный источник входа — `START_HERE.md`.** В остальных файлах — одна строка-ссылка вместо собственного списка.
2. **`context_index.yaml: read_order`** — удалить как дубль или генерировать из START_HERE. Расхождение станет невозможным.
3. **`.codex/checkpoints/`** — либо удалить, либо генерировать из STATE. Вторая молча устаревающая правда — хуже отсутствия.
4. **`context/strategy/20261011/`** — либо добавить в `CONTEXT_INDEX.md`, либо перенести в `context/archive/` как историю.
5. **`.mcp/config.yaml`** — подключить GitHub/Notion либо убрать упоминания.

---

## 5. Агентские файлы: информационная полнота

Требование владельца: репозиторий отражает историю, текущую работу и план **«от конфигурации и паспорта сервера до стиля кнопки»**.

| Категория | Что есть | Статус |
|---|---|---|
| Паспорт сервера | `server_map_20261016.md`, `evidence_20261016.yaml`, `server.yaml` | ✅ |
| Конфигурация | `OPERATIONS.md`, `docker.yaml`, `moodle.yaml` | ✅ |
| История решений | 15 ADR + `decisions/README.md` | ✅ |
| Текущая работа | STATE, ACTIVE, BACKLOG, SESSION_LOG | ✅ |
| Будущий план | `USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md`, `USTAR_PRODUCT_ARCHITECTURE_PLAN_20260927_RU.md` | ✅ |
| Архитектура | `architecture/*.md` — 12 файлов, 659 строк | ✅ |
| Бизнес-логика доменов | 12 карт, **312 строк суммарно** | ⚠️ **тонкие** |
| **Дизайн-система / стиль UI** | **нет ни одного документа** | ❌ **отсутствует** |
| Мобильный веб | `domains/mobile_web.yaml` — **17 строк** | ⚠️ заглушка |

### Разбор доменных карт

Структура правильная: `purpose`, `services`, `tables`, `rules`, `entry_points`. Но объём — указатель, а не описание:

- `grades.yaml` — 25 строк. Правило «Published ladders immutable; position binding uses stable string ID» есть, а **сценария нет**: кто, когда, при каком условии может выдать грейд, что при переводе сотрудника, что при смене версии лестницы.
- `mobile_web.yaml` — 17 строк при работающем мобильном web.

Бизнес-логики в коде (`grade_ladders.php`, `grade_promotion.php`, `career_grades.php`) **больше, чем в карте**.

### Отсутствие дизайн-системы — прямое нарушение требования

Проверено поиском по `context/`: ни `design system`, ни `tokens`, ни `UI kit`, ни `typography`. При этом в коде:

- 21 CSS-файл в `moodle/local/ustar/styles/`;
- 54 mustache-шаблона;
- `theme_ustar/` с SCSS.

Стиль есть в коде и **не описан в контексте**. Когда владелец говорил «до стиля кнопки» — этого нет.

### Правки

1. **Создать `context/design/README.md` + `tokens.yaml`** — единственный источник правды: палитра, типографика (шкала, насыщенности), отступы, радиусы, состояния компонентов, поведение на **1440/1024/768/390/320** (ширины уже используются в `UI_UX_REVIEW_20261016.md`). Извлечь из существующих SCSS, а не выдумывать.
2. **Правило в AGENTS.md:** изменение UI → обновление `context/design/`. Новый компонент без описания в main не идёт.
3. **Углубить домены до сценариев.** Секция `scenarios:` в формате «кто → что → при каком условии → чем заканчивается». Начать с четырёх: `grades`, `routes`, `evidence`, `work` — они несут кадровые решения.
4. **`mobile_web.yaml`** — наполнить или убрать из индекса.

---

## 6. Прогресс по аудиту: закрыто / открыто

### Закрыто (подтверждено квитанциями)

| Задача | Когда | Квитанция |
|---|---|---|
| INF-08 — Moodle 5.1.8 patch | 13.10 | `production_patch_upgrade_result_20261013.json`; core `2025100608.00`, 57 addon versions, 5151 файл |
| INF-10-OS — AppArmor, пакеты, reboot | 14.10 | `host_apparmor_result_20261013.json`, `host_reboot_result_20261014.json`; kernel 147 |
| INF-04 — SSD, GPT, ext4, HDD | 11.10 | `ssd_ext4_resize_result_20261011.json`; SSD 110G/37used, UUID сохранён |
| DOC-01, DOC-02 | 05–06.10 | Консолидация main, публикация аудита |

### In progress

- INF-01 — baseline и core inventory (контекст сведён; полный свежий manifest отдельно)
- INF-09 — CI на production PHP/core/PG matrix
- INF-14 — регулярные копии на HDD
- OPS-01 — свежая read-only сверка production

### Waiting external

- **OPS-02** — восстановление копии силами переданного сисадмина. **Результата нет.** Правильный статус: DR **не** объявлен завершённым.

### Не начато — тревожный факт

| Задача | Что блокирует | Статус |
|---|---|---|
| **INF-02** — роли PostgreSQL | Security-ревью. Роль `moodle` — cluster superuser | `planned` |
| **INF-03** — код/config/секреты | Компрометация веба = контроль над кодом | `planned` |
| **INF-07** — HTTPS | Пароли и cookie открытым текстом | `planned` |
| **PRIVACY-01** — Privacy API | B2B-сделки | `planned` |

**Наблюдение по картине приоритетов.** Команда **сознательно** занялась обратимыми операциями (SSD, host, patch) и отодвинула необратимо-рискованные и security-блокирующие. Это защитимо как стратегия «сначала эффект с низким риском». Но:

- обратимого бэклога почти не осталось;
- P0 стоят с 5 октября;
- именно они блокируют выручку, а не качество.

**Правка по приоритетам:**

1. Следующие три — **INF-02, INF-03, INF-07**, строго в этом порядке. Не разработка, а конфигурация + прогон на копии.
2. **PRIVACY-01 — параллельно**, начиная с существующего `privacy_inventory.py` (21 персональный ключ). Провайдер пишется по этому списку.
3. **Не начинать новой обратимо-низкорисковой работы** до закрытия трёх.

**Отдельно:** PRIVACY-01 существует в BACKLOG с reference на `USTAR_PRIVACY_INVENTORY_20260928_RU.md`. `scripts/ci/privacy_inventory.py` честно называет себя «coverage aid for the Moodle Privacy API implementation, not a replacement for its export/deletion provider or a retention policy». Чёткая граница.

---

## 7. Пульт (server-console)

### 7.0. Критичный факт

**Пульт находится в ветке `codex/server-console-20261015`, head `9855901`, и НЕ влит в main** (`git merge-base --is-ancestor` → NOT MERGED). 59 файлов, 9 470 строк, 10 коммитов.

В main пульта **нет**. `context/runtime/MONITORING.md` в main описывает намерение; реализация — в ветке.

### 7.1. Сильные стороны безопасности

| Компонент | Реализация |
|---|---|
| `metrics.py` | Словарь **фиксированных** PromQL-запросов; метрика и период валидируются по белому списку. Комментарий в коде: «Neither PromQL nor upstream URLs come from the browser». Закрывает SSRF и инъекции запросов |
| `collector.py` | Запуск процессов **без shell**, `env={'PATH': '/usr/bin:/bin', 'LC_ALL': 'C'}`, `start_new_session=True`, `os.killpg` при таймауте, лимит вывода 64 КиБ, `NoRedirect` |
| `auth.py` | **argon2** (`time_cost=3, memory_cost=32768`), **`DUMMY_HASH`** против timing-атак, SQLite с проверкой `st_nlink`/`st_uid`, `0o600`, WAL. Таблицы `users/sessions/attempts/audit` — аудит и throttling |
| `data.py` | `open_regular` — чтение по дескрипторам с `O_NOFOLLOW`, проверка прав и `st_nlink`, лимиты |
| CI `ustar-console.yml` | `pip install --require-hashes`, pytest, `npm audit --audit-level=high`, Playwright на desktop/tablet/mobile, отдельная **приёмка systemd-песочницы**, артефакт с sha256 |
| `UI_UX_REVIEW_20261016.md` | Ревью на 5 ширинах; найдено **84 элемента <15px**, исправлено до ≥16px; контраст **7.73:1**; честно указано «browser fixtures, не измерения server1» |

### 7.2. Метрики: расхождение спеки и реализации

`MONITORING.md` описывает **7 контуров**. `metrics.py` реализует **3 запроса**: `cpu`, `ram`, `http`.

| Контур из спеки | В `metrics.py` | Оценка |
|---|---|---|
| Host CPU/RAM | ✅ | ок |
| Host диск/inode/growth | ❌ | пропуск |
| Academy HTTP latency | ✅ | ок |
| Academy container state | ❌ | пропуск |
| **Cron: last exit / freshness** | ❌ | **критичный пропуск** |
| **Backup: latest / age** | ❌ в метриках (есть в collector как данные) | **критичный пропуск** |
| Labs | ❌ | допустимо отложить |
| Shared services | ❌ | отложить (риск ложных тревог) |
| **Observer (внешняя точка зрения)** | ❌ | важно; признано в спеке |

### 7.3. Метрики, которых нет, но они нужны

1. **Cron freshness** — «минут с последнего успешного cron». `MONITORING.md` уже фиксирует: «exit 0 не отменяет task-level failure». Проблема понята, метрики нет. Если cron встал — обучение не начисляется, узнаёте последним.
2. **Backup age** — «часов с последней успешной копии». Таймер ежедневно 03:30, но **если копия не сделана, вы не узнаете**.
3. **Disk / inode** — 35% сейчас, но не статично. Inode важнее объёма: мелких файлов больше.
4. **Observer (NoData)** — признано: «Same-host бот/пульт не является независимым монитором потери всего host». Пока его нет, пульт **не отвечает на вопрос «сервер жив?»**, если сервер умрёт вместе с пультом.

### 7.4. Метрики, которые преждевременны

1. **Time series `1h/24h/7d/15d`** (`RANGES`). При суточном cron и ежедневной копии 15-дневный график CPU на 720 точек — декор. Приоритет: числа («cron 4 мин», «backup 9 ч», «диск 35%»), графики — под ними.
2. **Раздел Labs** — четыре контейнера и адреса стендов. Шум для владельца; свернуть в одну строку.
3. **Shared services до полной проверки** — алерты на почту/DNS, которыми управляет другой человек, дадут ложные тревоги. Ложные тревоги убивают доверие к пульту быстрее, чем его отсутствие.

### 7.5. Приоритеты метрик (готовая спецификация)

```
Приоритет 1 (без них пульт бессмыслен):
  cron_last_success_age_seconds     > 10 мин вне окна
  backup_latest_age_hours           > 26 ч
  disk_used_percent                 > 80% / 90%
  disk_free_inodes                  > 80% / 90%
  http_status + latency_p95         5xx > 1%, p95 > 2s

Приоритет 2 (полнота картины):
  container_state (ustar_moodle, ustar_postgres) — exact identity
  pg_connections / max_connections   > 80%
  php_error_rate                     15-мин окно

Приоритет 3 (после стабилизации):
  external_observer                  отдельная машина
  shared_services                    только read-only presence

Свернуть:
  15d time series → компактная sparkline
  Labs → одна строка
```

### 7.6. Слабые места пульта и правки

| # | Проблема | Решение |
|---|---|---|
| 1 | Пульт на том же server1 | Внешний blackbox на отдельной машине (достаточно Raspberry Pi в другой сети): пингует внешний URL и шлёт в Telegram при падении |
| 2 | Нет Telegram-бота, хотя заявлен | Реализовать по `control_panel_telegram_20261016.md`: числовые allowlist user/chat ID, дедупликация failure/recovery, окно ожидаемой backup-паузы с пределом, повтор доставки, явный NoData |
| 3 | Пульт не в main | Влить ветку через PR — но **после** доведения метрик приоритета 1. Сейчас там красивая графика без cron-freshness и backup-age |
| 4 | Пороги «на глаз» | `OBSERVABILITY.md` сам предупреждает: «не выдавать за гарантию SLA». Скорректировать через неделю реальной работы |
| 5 | GitHub/Notion заявлены, не подключены | Подключить или убрать |

### 7.7. Дизайн пульта — что улучшить

1. **Числа важнее графиков.** Сверху — четыре крупных числа: «cron 4 мин», «backup 9 ч», «диск 35%», «HTTP 200, 340 мс». Ответ «всё ли хорошо» за 2 секунды.
2. **Явный индикатор свежести.** Вверху: «данные от 16:47:03 (2 мин назад)». Если старше 5 минут — страница притухает и показывает плашку. Иначе пульт покажет вчерашнее как сегодняшнее.
3. **Разделить здоровье и детали.** Сверху — четыре светофора (Академия / Cron / Копии / Диск). Ниже — вкладки. Сейчас шесть разделов сразу — перегруз для ежедневного взгляда.
4. **Действия — только через подтверждение с явным scope.** Требование из `control_panel_telegram_20261016.md`: «Пользователь должен видеть, какая среда и какой архив будут затронуты». Модальное подтверждение с указанием среды и архива **в тексте кнопки**.

---

## 8. Приоритеты: очередь работ

### Немедленно (следующая неделя), строго по порядку

| # | Задача | Почему сейчас | Критерий приёмки |
|---|---|---|---|
| 1 | **INF-02** — роли PostgreSQL | Гарантированная точка отказа на security-ревью. Роль `moodle` — cluster superuser | `ustar_app` без admin-флагов; upgrade/repeat/dump-restore работают |
| 2 | **INF-03** — код/config/секреты | Связано с INF-02: `www-data` пишет в `config.php` и `local/ustar` | Код read-only; запись только в `moodledata`; deploy и cron работают |
| 3 | **INF-07** — HTTPS | Пароли и cookie открытым текстом | TLS-vhost не задевает default/ISPConfig; login/SCORM/чат/файлы проверены |

Порядок: INF-02 и INF-03 работают с одной поверхностью (права) — один прогон приёмки. INF-07 независим, но требует окна и проверки всех функций.

### Параллельно

| # | Задача | Почему независимо |
|---|---|---|
| 4 | **PRIVACY-01** — Privacy API | Инвентарь есть (`privacy_inventory.py`, 21 ключ). Долго, но не зависит от инфраструктуры |
| 5 | **INF-06** — пульт/Telegram, **в правильном объёме** | Метрики приоритета 1 (§7.5), не «красивые графики» |

### После этого

| # | Задача | Условие входа |
|---|---|---|
| 6 | INF-01 — полный свежий manifest | После закрытия прав |
| 7 | INF-05, INF-10, INF-11 — логи, SSH/MFA, SMTP/H5P | По ресурсу |
| 8 | PERF-01 — измерение latency и SQL | До тюнинга, не после |
| 9 | OPS-02 — restore сисадмина | Внешняя зависимость |

### Не начинать до закрытия P0

Никакой новой обратимо-низкорисковой работы (ещё стенд, ещё проверка диска). Паттерн «делаем приятное, откладываем блокирующее» держит INF-02/03/07 в `planned` с 5 октября.

---

## 9. Что сделано сильно

**1. Честность учёта.** `evidence_20261016.yaml` начинается с `kind: reconciled_owner_evidence_not_fresh_server_probe` и содержит `independently_measured_on_server: false`. `AUDIT_RECONCILIATION_20261016_RU.md`: «Проверяющий не подключался к server1 и не запускал stop/update/reboot/restore». Чужой вывод не выдаётся за своё измерение — редкость.

**2. Харнесс безопасности — security-критичный код.** Дескрипторный доступ с `O_NOFOLLOW`, защита от race, запрет hardlink/FIFO, лимиты. Тест реально воспроизводит подмену пути.

**3. Пульт — правильные умолчания.** Фиксированный PromQL (браузер не влияет на запрос), процессы без shell с `killpg`, argon2 с `DUMMY_HASH`, `open_regular` с проверкой прав. CI с `--require-hashes`, Playwright, приёмка systemd-песочницы.

**4. Отложенное не подменено фикцией.** Ветка `moodle53-stage` существует, но запрещено «переименовывать 5.3 experiment в якобы выполненное 5.2».

**5. Операции с необратимыми изменениями оформлены как операции.** Пошагово, с preflight, с post-check, с независимой проверкой GPT (CRC, pointers, GUID, entry array, boot preservation).

---

## 10. Итоговая таблица

| Область | Оценка | Главное действие |
|---|---|---|
| Прогресс по аудиту | Существенный, с квитанциями | INF-08, INF-10-OS, INF-04 закрыты |
| **B2B-блокеры (P0)** | **Не сдвинулись** | INF-02, INF-03, INF-07 — немедленно |
| Стоп по 5.2/PG17 | Обдуман и верен | Дать дату выхода: начать до 15.01.2027 |
| Харнесс | Рабочий, корректно запинен | Изолированный venv в CI + `__init__.py` |
| **Вход в контекст** | **6–7 конкурирующих инструкций** | Один источник — START_HERE |
| Агентские файлы | Полные по инфра, **пустые по дизайну** | `context/design/` + сценарии в доменах |
| Пульт | Сильная безопасность, неполные метрики | cron-freshness, backup-age, disk, observer |
| **Дизайн-система** | **Отсутствует** при 21 CSS и 54 шаблонах | Единственный источник правды по стилю |

**Три действия в понедельник:**

1. **INF-02** — роли PostgreSQL на копии. Полдня.
2. **Убрать или генерировать `.codex/checkpoints/CURRENT.md`.** Сейчас он говорит «MOB-01», STATE — «INF-06». Два противоречащих указателя хуже одного неполного.
3. **Дата в `deferred_platform`.** «Отложено» без дедлайна через год неотличимо от «забыто».

---

## Приложение: команды проверки

```bash
# Харнесс в изолированном окружении (как требует README)
python3 -m venv /tmp/h && /tmp/h/bin/pip install -r harness/ustar-contextd/requirements.txt
/tmp/h/bin/python -m unittest discover -s tests/r16 -v

# Граница контекста и индексы
python3 scripts/check_context.py
python3 scripts/build_context_index.py

# Поиск противоречий в указателях
grep -rn "Next task:" .codex/ contexts/ 2>/dev/null
grep -n "next_task" context/roadmap/STATE.yaml

# Сверка стратегических документов с индексом
grep -rn "strategy" context/CONTEXT_INDEX.md context/context_index.yaml START_HERE.md AGENTS.md
```

---

## Источники

- Репозиторий: https://github.com/cauf1l3d/ustarlms (main `d9655074`, 16.10.2026)
- `context/audits/AUDIT_RECONCILIATION_20261016_RU.md`
- `context/runtime/evidence_20261016.yaml`, `server_map_20261016.md`, `MONITORING.md`, `SECURITY_BASELINE.md`, `CI_SCOPE_20261016.md`, `RELEASE_LEDGER.md`
- `context/roadmap/STATE.yaml`, `BACKLOG.yaml`, `SESSION_LOG.md`, `AGENT_PROTOCOL.md`, `USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md`
- `context/runtime/moodle_target_rationale_20261011.md`, `control_panel_telegram_20261016.md`, `control_panel_scope_20261015.md`
- `harness/ustar-contextd/{safe_context,server}.py`, `harness/ustar-contextd/README.md`, `requirements.txt`
- `tests/r16/{test_harness,test_stdio}.py`
- Ветка `codex/server-console-20261015`: `server-console/{ARCHITECTURE,DESIGN,OBSERVABILITY,UI_UX_REVIEW_20261016}.md`, `backend/ustar_console/{auth,collector,config,data,metrics,server}.py`, `.github/workflows/ustar-console.yml`
- `docs/R16_20260912.md`
