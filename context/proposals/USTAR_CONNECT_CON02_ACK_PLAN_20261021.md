# CON-02A.1 Native acknowledgement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. User forbids subagents; preserve independent execution in the existing feature checkout.

**Goal:** Сотрудник подтверждает собственное локальное уведомление кнопкой «Принял» в native Академии; первое время подтверждения устойчиво к повторам и чтению.

**Architecture:** Существующие `local_ustar_notifications.status/ackat` и `target_core::acknowledge_notification` остаются каноническими. Узкий own-session adapter проверяет реального actor; transport delivery и бизнес-задача не меняются. Это первый самостоятельный срез CON-02; callback Telegram, preferences routing и Bitrix идут отдельными срезами.

**Tech Stack:** Moodle5.1.8, PHP8.3.33, PostgreSQL16.15; Mustache/native theme; existing PHPUnit, Python r16 and disposable Docker CI. No new package dependency.

**Spec:** [RFC §§6,10](USTAR_CONNECT_RFC_20261018.md), [контракты inbox/ack](USTAR_CONNECT_CONTRACTS_20261019.md), [source inventory и границы CON-02](USTAR_CONNECT_CON02_SCOPE_20261021.md).

## Global Constraints

- Одна каноническая история из `local_ustar_notifications` и per-channel `local_ustar_notify_delivery`; не новый inbox.
- `provider_accepted / delivered / read / acknowledged / business_resolved` — разные факты. «Принял» не завершает задачу.
- Source в первом ACK — только `local`; `moodle`/неизвестный source отклоняется, одинаковые числовые IDs не смешиваются.
- Native POST + sesskey, реальный current actor, active employment, `local/ustar:use`, no view-as; caller userid/role не принимается.
- Новых колонок/таблиц нет; existing statuses/history/preferences/links сохраняются. Plugin candidate version2026102100, theme2026100202; Moodle/PG major не меняются.
- Ручная live-приёмка группы deferred по владельцу; автоматические/native CI gates сохраняются. Работы на server1/provider sends и production deployment в этот план не входят.
- Старые a86 журналы, frozen helpers и их hash guards не изменять ради зелёного CI. Main unmerged, no subagents.

## Review Focus

1. Чтение той же записи одновременно с ACK не должно вернуть status=read или стереть ackat — задачи1,4.
2. Два подтверждения в разных запросах сохраняют первое ackat — задачи1,4.
3. Совпадающие local/core IDs, произвольный source или чужой userid не дают ACK чужого уведомления — задача2.
4. Увольнение, suspension и view-as после открытия страницы не дают записи — задачи2,3.
5. Старый Telegram sender и source-bound инструменты должны остаться совместимыми; новая native кнопка не является transport activation — задача4.

## Файлы и ответственность

| Файл | Изменение |
|---|---|
| `moodle/local/ustar/classes/target_core.php` | Существующий ACK writer: атомарное первое ackat, прежняя сигнатура |
| `moodle/local/ustar/classes/communication.php` | Условное unread→read без перезаписи ACK; отдельные поля presentation |
| `moodle/local/ustar/classes/connect/notification_acknowledgement.php` | Новый own-session adapter; никаких provider/admin grants |
| `moodle/local/ustar/notifications.php` | POST action=ack, source/id validation, existing session/sesskey boundary |
| `moodle/local/ustar/templates/notifications.mustache` | «Принял» и отдельное «Подтверждено»; read не переименовывается в ACK |
| `moodle/local/ustar/lang/{ru,en}/local_ustar.php` | Новые строки интерфейса |
| `moodle/local/ustar/tests/connect_notification_ack_test.php` | Новый native PHPUnit self/ID/state/race contract |
| `tests/stage/connect_concurrency.php` | Два независимых DB requests: ACK/ACK и read/ACK |
| `tests/stage/run-connect.sh` | Включить новый PHPUnit class в существующий filter |
| `tests/stage/con02_ack_probe.php` | Новый узкий native fixture:2000→2100, history/old sender preservation |
| `.github/workflows/ustar-review-gate.yml` | Новый fixture; historical installers проверяются на frozen source |
| `moodle/local/ustar/version.php`, `context/code_map/development_source.json` | Candidate version и actual generated file hashes, не фиктивный deployment |

## Команды проверки

Native focused command внутри подготовленного disposable runner `/stage/moodle`:

```bash
php vendor/bin/phpunit --testsuite local_ustar_testsuite --filter connect_notification_ack_test --fail-on-notice --display-notices
```

Полный native lifecycle использует существующие `patch-compose.yaml` и
`connect-compose.yaml` после подготовки `.stage-input`, описанной в CI job
`connect-db`; это тестовый проект `ustar-connect-identity`, не production:

```bash
docker compose -f tests/stage/patch-compose.yaml -f tests/stage/connect-compose.yaml build
docker compose -f tests/stage/patch-compose.yaml -f tests/stage/connect-compose.yaml up --abort-on-container-exit --exit-code-from runner
```

Ожидаются нулевой exit, PHPUnit failures/errors=0, существующий
`CONNECT_SCHEMA_UPGRADE_REPEAT_FRESH_AND_DB=PASS` и новый `CON02_ACK_COMPAT=PASS`
из `php /source/tests/stage/con02_ack_probe.php`. Новый probe вызывается из
`run-connect.sh` до paired restore. RED/отказ на неподготовленном fixture не
считается воспроизведением продуктового дефекта. После теста `down --volumes`
применяется только к этому отдельному compose project, как в текущем CI.

### Task 1: Устойчивый канонический receipt

**Interfaces:** Preserve `target_core::acknowledge_notification(int $notificationid, int $userid): void`. Preserve `communication::mark_notification(int $userid, int $notificationid, string $source='local'): void` and bulk-read. No caller changes needed yet.

- [ ] Добавить `connect_notification_ack_test::test_first_ack_timestamp_survives_repeated_ack_and_read`: создать своё local notice, ACK, сохранить ackat, read и повтор ACK; assert status=acknowledged, ackat прежнее. Зафиксировать контрольный timestamp без sleep: время первого receipt задаёт fixture.
- [ ] Добавить тест `test_mark_all_read_does_not_acknowledge_or_clear_existing_ack`: непрочитанная запись становится read/ackat=null; ранее подтверждённая остаётся acknowledged/с прежним ackat.
- [ ] Запустить focused native PHPUnit на disposable БД. RED должен выявить реальную недостающую гарантию; для race сначала запустить независимые запросы задачи4. Не объявлять обычный последовательный test воспроизведением гонки.
- [ ] Сохранить сигнатуру writer; заменить stale whole-record write атомарной условной записью status/ackat/timemodified. Первое время сохраняется через `COALESCE(ackat, :now)` в одном UPDATE по id+userid. Read обновляет только status/timemodified при текущем status=unread, не отправляет в UPDATE старое ackat.
- [ ] Запустить focused tests и existing notification/communication regressions; expected PASS. ACK и read меняют только разрешённые notification fields, не delivery или task state.
- [ ] Commit `fix: preserve first notification acknowledgement across reads`.

### Task 2: Проверенный own-session adapter

**Interfaces:** Create `notification_acknowledgement::own(int $id, string $source='local'): array` returning exactly `{source:'local', id:int, status:'acknowledged', acknowledged_at:int}`. It consumes Task1 writer, accepts no userid parameter, and is not callable as a delegated provider actor.

- [ ] Добавить `test_own_ack_requires_current_active_actor`: чужая запись, guest, deleted/suspended/terminated actor и view-as отвергаются без изменений. Активный собственный actor успешен, в том числе если notice уже read.
- [ ] Добавить `test_core_and_local_ids_never_share_ack`: core ID совпадает с local ID; source=moodle/unknown отвергнут, timeread core не меняется. Caller-selected userid отсутствует в native adapter contract.
- [ ] Запустить новый класс, expected RED из-за отсутствующего adapter.
- [ ] Реализовать adapter в новом классе: current actor через существующую owner boundary `identity_service::owned_links()` (наличие связанного Telegram не требуется), strict positive id/source=local, owner row lookup, Task1 writer и canonical receipt. Не использовать actor_read session или временную подмену `$USER`.
- [ ] Запустить focused tests, expected GREEN; проверить, что delivery `provider_accepted` сам по себе не меняет ACK и не принимает provider/user IDs.
- [ ] Commit `feat: add own-session notification acknowledgement`.

### Task 3: Native inbox flow

**Interfaces:** Existing page accepts POST `{action:'ack', id, source:'local', sesskey}` and calls Task2. GET remains read-only. Add local-only presentation fields `acknowledged`, `canack`, `acktime` without exposing internal delivery/lease hashes.

- [ ] Добавить fixture/browser regression `test_native_ack_is_post_only_and_not_task_completion`: GET не пишет; valid own POST показывает «Подтверждено» и время; task status/checklist/grade и delivery statuses неизменны.
- [ ] Добавить `test_stale_page_does_not_authorize_ack`: неправильный sesskey, view-as или suspension после GET дают отказ. Наличие уже открытой страницы не предоставляет долгоживущий grant.
- [ ] Запустить эти сценарии, expected RED: action/button ещё отсутствует.
- [ ] Добавить ветку ack в `notifications.php` после existing session/sesskey/view-as checks; передать source+id adapter, затем existing redirect. Явный ACK не вызывается из read/all/GET.
- [ ] Добавить отдельные подпись/кнопку в template и ru/en strings. Core Moodle rows не показывают «Принял». «Прочитано» и «Подтверждено» остаются разными состояниями; unread count корректно уменьшается после ACK.
- [ ] Запустить native browser regressions в существующем suite; проверить мобильный viewport и клавиатурный submit. Expected PASS, видимая подпись без server traceback/токенов.
- [ ] Commit `feat: expose personal acknowledgement in Academy inbox`.

### Task 4: Native concurrency, version and compatibility gate

**Interfaces:** Candidate local_ustar2026102100/theme2026100202, unchanged XMLDB table/column set. `con02_ack_probe.php` emits only sanitized status/hash assertions; no production credentials or content.

- [ ] Добавить два независимых PostgreSQL requests с controlled barrier: ACK/ACK и read/ACK. Assert один стабильный ackat, итог acknowledged; Task1 fixes должны проходить, old source должен воспроизводить гонку при требуемом ordering.
- [ ] Поднять отдельный disposable fixture с accepted app2000 и pilot policy overlay; сохранить synthetic task/notice/delivery/preferences/link counts и hashes, применить candidate2100, повторить upgrade. Новая live server collection не нужна.
- [ ] Проверить XMLDB fresh=upgrade=repeat,99tables/no column delta, existing unread/read/acknowledged/disabled/pending/provider_accepted/uncertain history без backfill. Включить новый class в `run-connect.sh` PHPUnit filter.
- [ ] Установить candidate version2100 и пересобрать development manifest из staged Git tree. Точное число файлов брать из генератора; не переносить552 как новый measured count.
- [ ] Проверить v1 lease/begin/finish и frozen a86 worker на fixture: wire keys, key scopes, cursor и default flags не изменены. Старые source-bound installer/overlay fixtures запускать на их frozen exports; не менять их reviewed hashes и не выдавать старый package за новый2100 installer.
- [ ] Запустить `python -m unittest discover -s tests/r16 -v` в exact-deps venv; source PHP8.2/8.3 jobs, native Connect DB и browser fixture в GitHub CI. Env skips записать отдельно; modeled fixture не считается native restore.
- [ ] Выполнить `python3 scripts/build_context_index.py`, `python3 scripts/check_context.py`, `git diff --check`; публиковать только exact verified tree в текущий PR с expected-head lease.
- [ ] Commit `test: verify native acknowledgement concurrency and legacy compatibility` и опубликовать точную CI-квитанцию. Не запускать production helpers и не считать этот source RC поставкой на server1.

## Проверка плана и handoff

Пять Review Focus распределены по задачам1–4; schema/source compatibility явно
отделена от live acceptance. Source review завершён; тесты/код этого плана ещё не
написаны. Scope/план подлежат review владельцем; метод исполнения уже задан —
самостоятельно, без субагентов. После review исполнить задачи по порядку.

Telegram callback, новые channel preferences и Bitrix не считаются реализованными
этим срезом. После A.1 идёт отдельный план A.2, затем B и C из scope-документа;
для C требуется фактический portal type/API, а не предположение по public docs.
