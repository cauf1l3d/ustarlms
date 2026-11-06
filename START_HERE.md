# USTAR Academy — начало работы

Актуально06.11.2026. Канонический код — main: app2900/source5cc02944, theme2700.
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
## Библиотека и Студия0200

Последняя полная terminal receipt: app719bf47c/toolaa61d595 установлен5Nov,
[квитанция](context/runtime/library_discovery_owner_deploy_20261105.json).
Marketplace скрыт по умолчанию; admin выбирает отделы. Исходники editor-only.
[Правила доступа](docs/MARKETPLACE_DEPARTMENTS_INSTALL_RU.md). Main2900 отдельно;
PR112/113/114 открыты без разрешения merge.

Предыдущий cumulative app901/toolf4d включает Route Studio и YUI при строгой CSP:
[квалификация](context/runtime/studio_csp_qualification_20261105.json).
Owner6Nov: точки скрываются, но изображения Preview не видны; точный installed SHA не сообщён.
STUDIO-PREVIEW-01 app2cb233d9/tool ed292313: собственные ресурсы opaque iframe,
[реализация](context/reviews/20261106/STUDIO_PREVIEW_IMPLEMENTATION.md),
[квалификация](context/runtime/studio_preview_qualification_20261106.json),
[установка](docs/STUDIO_PREVIEW_INSTALL_RU.md). 4CI/11FullRC PASS на ed292313.
Пакет требует source901 и active CSP journal до outage; deployment ждёт owner receipt.

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
