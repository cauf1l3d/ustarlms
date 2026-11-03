# USTAR Academy — начало работы

Актуально03.11.2026. Канонический код — main: app2900/source5cc02944, theme2700.
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

Merge не меняет production.
Старые контроллеры/архивы не переписывать и не применять повторно по датированной истории.

## Текущее состояние — Библиотека0200

Owner подтвердил установку6ee71123: active/local0200, Библиотека открывается.
[Owner report и границы](context/runtime/library_owner_navigation_20261103.json).
Комплект0200:649файлов/108таблиц, не whole-host census.
Установку Библиотеки не повторять.
«Центр управления» ведёт в «Материалы».
Owner установилf89dde49/tool5131: active/exit0/postcheck active.
[Квитанция](context/runtime/library_navigation_owner_deploy_20261103.json).
Повтор установки не нужен; browser и подробная ролевая приёмка ожидаются. [Работа и приёмка](docs/LIBRARY_GUIDE_ACCEPTANCE_RU.md).
Owner03Nov: скрыть Маркетплейс, открывать отделам из админки; убрать исходники.
[Текущий fix](context/runtime/marketplace_department_fix_20261103.json), qualification pending.
SCORM/Quiz приняты; main2900, PR113/114 открыты, merge не разрешён.

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
