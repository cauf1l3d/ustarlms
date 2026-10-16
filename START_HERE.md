# USTAR вход в проект

## Текущий этап 16 октября 2026

Рабочий продукт — Moodle + `local_ustar` + `theme_ustar`, parent boost. `frontend/` — legacy Next.js, исключённый из активного CI/выпуска. Проверки native UI темы и плагина сохранены.

- **Сделано на сервере:** SSD расширен11Oct; Moodle5.1.8 upgrade/repeat и краткая браузерная/SCORM приёмка13Oct; AppArmor,63 пакета, reboot/kernel147/Docker29.9.0 приняты14Oct. Не повторять.
- **Ожидание:** владелец16Oct подтвердил получение архива и передачу инструкции сисадмину. Отдельная копия, её вход/SCORM/reboot и полный host DR ещё не приняты.
- **Текущая задача:** INF-06 — [архитектура пульта](server-console/ARCHITECTURE.md), [health/алерты](server-console/OBSERVABILITY.md) и [UI/UX](server-console/DESIGN.md) подготовлены в PR103. Далее защищённый read-only backend/UI; deployment ещё не выполнен. [Границы](context/runtime/control_panel_telegram_20261016.md).
- **Отложено:** Moodle5.2 и PostgreSQL17. Прежняя подготовка5.3/PR101 сохранена как история, не следующий запуск.

## Порядок чтения

1. [project](context/project.yaml), [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md).
2. [Сверка выполненного и остатка с аудитом](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
3. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [ADR](context/decisions/README.md), [domain maps](context/CONTEXT_INDEX.md).
4. [Runtime](context/runtime/README.md), [карта кода](context/code_map/README.md), [протокол](context/roadmap/AGENT_PROTOCOL.md).

## Источники истины

`main` — код и текущий контекст. Точный plugin/theme baseline PR76/e1d57f5b,526 файлов, local2026100204/theme2026100202 сохранён; это не номер нового core и не текущий repository head. [Датированные runtime evidence](context/runtime/evidence_20261016.yaml) отделяют версии/установку от статического source и CI.

[Ветки](context/runtime/repository_state_20261016.json): PR98/99/100/102 — источники консолидации, PR101 — отложенная историческая подготовка, PR103 — прототип и архитектура текущего пульта. Старые release/integration/prod-sync branches не использовать как актуальную базу.

[Инструкция сисадмину](context/runtime/production_copy_20261015.md) использует существующий HDD-архив и не останавливает прод. Не путать `--pack`, `--pack-production`, постоянную parallel copy и временный lab. Бэкап с ключом нельзя помещать в Git/чат/Telegram.

Нет прямого подключения агента к server1. После сверки репозитория не запускать native update/capture/reboot/restore без самостоятельного основания. [CI scope](context/runtime/CI_SCOPE_20261016.md) описывает реально выполняемые и отдельные опциональные проверки.
