# USTAR вход в проект

## Текущий этап 19 октября 2026

Приоритет владельца01:28МСК — **INTEGRATIONS-01**, продолжение05:30 — **CON01B.3: canonical signup/pending/first link**, [PR106/RFC](context/proposals/USTAR_CONNECT_RFC_20261018.md), [контракты](context/proposals/USTAR_CONNECT_CONTRACTS_20261019.md). В00:41 владелец подтвердил учётные записи пульта и личную Telegram-привязку; журнал всё ещё показывает прежнее сообщение. Журнал и внешний heartbeat явно отложены. [Датированный receipt](context/runtime/connect_owner_priority_20261019.json).

Рабочий продукт — Moodle + `local_ustar` + `theme_ustar`, parent boost. `frontend/` — legacy Next.js, исключённый из активного CI/выпуска. Проверки native UI темы и плагина сохранены.

- **Сделано на сервере:** SSD расширен11Oct; Moodle5.1.8 upgrade/repeat и краткая браузерная/SCORM приёмка13Oct; AppArmor,63 пакета, reboot/kernel147/Docker29.9.0 приняты14Oct. Не повторять.
- **Ожидание:** владелец16Oct подтвердил получение архива и передачу инструкции сисадмину. Отдельная копия, её вход/SCORM/reboot и полный host DR ещё не приняты.
- **Текущая задача:** проектирование USTAR Connect: Academy identity/capabilities, Telegram/Bitrix24/WhatsApp adapters, Mini Apps и внешний HTTPS edge без открытия офисных портов. Review регистрации и CRM=задачи выполнен; реализованы Python primitives и внутренний PHP link/challenge/proof service с candidate schema; публичный registration API отсутствует; INF-06 остаток отложен, INF-07 HTTPS Академии сохраняется отдельным gate полного LMS.
- **Отложено:** Moodle5.2 и PostgreSQL17. Прежняя подготовка5.3/PR101 сохранена как история, не следующий запуск.

[Review и первый вход](context/proposals/USTAR_CONNECT_REVIEW_20261019.md), [план CON01B.3](context/proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md). Base81a1be76 bridge/entry native22tests/87assertions PASS. Candidate теперь добавляет atomic canonical signup+receipt и собственный status, переиспользует HRD review/notifications. Endpoint выключен; внешние формы, actor/step-up remote HRD commands и receiver ACK ещё впереди. Exact final CI receipt — в PR106, не production acceptance.

## Порядок чтения

1. [project](context/project.yaml), [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md).
2. [Сверка выполненного и остатка с аудитом](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
3. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [ADR](context/decisions/README.md), [domain maps](context/CONTEXT_INDEX.md).
4. [Runtime](context/runtime/README.md), [карта кода](context/code_map/README.md), [протокол](context/roadmap/AGENT_PROTOCOL.md).

## Источники истины

`main` — код и текущий контекст. Точный plugin/theme baseline PR76/e1d57f5b,526 файлов, local2026100204/theme2026100202 сохранён; это не номер нового core и не текущий repository head. [Датированные runtime evidence](context/runtime/evidence_20261016.yaml) отделяют версии/установку от статического source и CI.

[Ветки16Oct](context/runtime/repository_state_20261016.json) — история консолидации. Текущие unmerged работы: PR103, рабочая поставка пульта sourcee5b87818/contextde4544d9; PR105, датированные server facts02fd394f; PR106, текущий Connect architecture + offline foundation. Их актуальные границы/точные refs — в новом receipt. Старые release/integration/prod-sync branches не использовать как базу. Не считать PR103 только прототипом и не считать unmerged work уже частью main.

[Инструкция сисадмину](context/runtime/production_copy_20261015.md) использует существующий HDD-архив и не останавливает прод. Не путать `--pack`, `--pack-production`, постоянную parallel copy и временный lab. Бэкап с ключом нельзя помещать в Git/чат/Telegram.

Нет прямого подключения агента к server1. После сверки репозитория не запускать native update/capture/reboot/restore без самостоятельного основания. [CI scope](context/runtime/CI_SCOPE_20261016.md) описывает реально выполняемые и отдельные опциональные проверки.

Candidate2026101902/542source files/99tables хранится в [отдельном manifest](context/code_map/development_source.json). Это unmerged разработка, не новый production receipt. Accepted source inventory526/93 остаётся датированным baseline.
