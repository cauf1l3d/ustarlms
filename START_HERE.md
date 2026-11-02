# USTAR Academy — начало работы

Актуально02.11.2026. Канонический код — main: app2900/source5cc02944, theme2700.
PR110 (RSS2803) и PR111 (ролевой Connect/графика) объединены после единой FullRC.
[Интеграция](context/runtime/connect_feed_integration_order_20261101.json).

1. [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
2. [Owner2900](context/runtime/connect_role_bot_owner_deploy_20261029.json).
3. [Owner267](context/runtime/connect_visual_owner_deploy_20261101.json), [архитектура](context/architecture/overview.md).

## Подтверждённое состояние

Owner29Oct установил app2900/theme2700 и включил роли tool9a68246b. Owner1Nov
восстановил общий Telegram egress и установил worker267e5587: visuals_active,
visuals_ready/recovery_required:false. Оба бота отвечают; PNG/кнопки/читаемость и
задачи/возврат/исполнение/фото приняты владельцем. Остальные процессы проверяет
руководство с2Nov; публичный HTTPS и Mini App ожидают согласования.

Payload606/103 — комплект, не whole-host инвентаризация.
Приложение и worker имеют разные SHA; merge не меняет production.
Старые контроллеры/архивы не переписывать и не применять повторно по датированной истории.

## Текущий выпуск — SCORM и повторный доступ

Owner принял прямое открытие51332; точного apply receipt нет.
Выпускf879/app1100 квалифицирован: all4CI, native387/1812; архив и сохранённая
копия сверены. [Квалификация](context/runtime/route_scorm_access_qualification_20261102.json).
Owner CM104/user132: savetrack=false, native результата нет. Исправление
app2150affe подготавливает учебную роль в доступном курсе; CI/архив ожидаются.
[Текущее состояние](context/runtime/scorm_tracking_20261102.json). Worker267 сохраняется.

## После исправления — PERF-01

Пассивный отчёт обычного рабочего дня: latency, SQL, cron lag и нагрузка.
Сборщик2900 готов; для1100 нужна адаптация. Фактический8h отчёт ещё не получен. Нет вывода о SLO,
SQL/page, Web-SAPI OPcache или производительности из одного статуса служб.
История cron не является свежим замером. [Активная работа](context/tasks/ACTIVE.md).

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
