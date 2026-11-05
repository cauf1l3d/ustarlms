# USTAR Academy — начало работы

Актуально05.11.2026. Канонический код — main: app2900/source5cc02944, theme2700.
PR110 (RSS2803) и PR111 (ролевой Connect/графика) объединены после единой FullRC.
[Интеграция](context/runtime/connect_feed_integration_order_20261101.json).

1. [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
2. [Owner2900](context/runtime/connect_role_bot_owner_deploy_20261029.json).
3. [Owner267](context/runtime/connect_visual_owner_deploy_20261101.json), [архитектура](context/architecture/overview.md).

## Подтверждённое состояние

Owner1Nov восстановил egress и установил worker267e5587: visuals_active,
visuals_ready/recovery_required:false. Оба бота отвечают; PNG/кнопки/читаемость и
задачи/возврат/исполнение/фото приняты владельцем. Остальные процессы проверяет
руководство с2Nov; публичный HTTPS и Mini App ожидают согласования.

Merge не меняет production.
## Текущее состояние Библиотеки0200

Owner3Nov установил app4715383b/tool59f: success/exit0/postcheck active и
подтвердил «Отлично работает». [Квитанция](context/runtime/library_marketplace_owner_deploy_20261103.json).
Маркетплейс скрыт по умолчанию; admin выбирает отделы. Исходники товаров доступны
редактору. «Центр управления» → «Материалы», Библиотека — читательский раздел.
[Инструкция](docs/MARKETPLACE_DEPARTMENTS_INSTALL_RU.md).
SCORM/Quiz приняты; детальные роли/URL/purchases ещё не приняты.
Main2900 отдельно; PR112/113/114 открыты без разрешения merge.
Discovery app719bf47c/toolaa61d595 установлен5Nov: exit0/postcheck active.
[Квитанция](context/runtime/library_discovery_owner_deploy_20261105.json).
ROUTE-EDITOR-01: [ревью](context/reviews/20261105/ROUTE_EDITOR_REVIEW_RU.md),
[план и остаток](docs/superpowers/plans/2026-11-05-route-editor-remediation.md).
app4c122d0a; оператор в квалификации: отдельное скрытие, требования без потерь, draft/publish.
[Установка и использование](docs/ROUTE_EDITOR_INSTALL_RU.md). Production ещё719bf47c.

## PERF-01 после Библиотеки

Пассивный отчёт обычного рабочего дня ещё не получен. Сборщик2900 требует адаптации
для0200. Нет вывода о SLO, SQL/page или Web-SAPI OPcache из одного статуса служб.

## Правила продолжения

Инструкции владельцу — прямо в чат короткими командами. Push разрешён;
субагенты/agent SSH/provider sends запрещены. Merge110/111 завершён по поручению;
новые слияния без нового поручения не делать. Публичный доступ/мобильный клиент не
начинать до согласования руководства. Moodle5.2/PG17 и Docker rotation/recreate
отложены; принятые host/storage/DB-role/code/HTTPS циклы не повторять.

[AGENTS](AGENTS.md) — границы. [Риски](context/roadmap/ACCEPTED_RISKS.yaml),
[решения](context/decisions/README.md), [код](context/code_map/README.md),
[квитанции](context/runtime/README.md). Датированные receipts сохраняют область
проверки; код/CI/owner acceptance/живое измерение различать.
