# USTAR Academy — начало работы

Актуально29.10.2026. Канонический код и контекст — main.

1. [STATE](context/roadmap/STATE.yaml): версии, SHA и квитанции.
2. [ACTIVE](context/tasks/ACTIVE.md): работа сейчас; [BACKLOG](context/roadmap/BACKLOG.yaml): задачи.
3. [Ревью26Oct](context/reviews/20261026/VERIFIED_REVIEW_RU.md),
   [риски](context/roadmap/ACCEPTED_RISKS.yaml), [архитектура](context/architecture/overview.md).

## Подтверждённый production

Владелец29Oct установил2802: payload22607c69, local2026102802/theme2026102700;
app_ready/DB available, receiver+notify active/enabled, worker6c56 без rollout.
[Установка/status/cron](context/runtime/perf2802_owner_deploy_20261029.json),
[evidence](context/runtime/evidence_20261029.yaml).590файлов/100таблиц — payload,
не новая whole-host инвентаризация. Cron00:42MSK exit0/34.311s, новых ошибок нет;
adhoc1→0, overdue63→19, failed2. Состав остаточных заданий не показан.
Moodle5.1.8/PHP8.3.33/PostgreSQL16.15 — прежние датированные platform receipts.

Владелец принял Материалы/настройки Ленты/Соревнования/Студию и сообщил о прежних
повторах RSS фото: [приёмка](context/runtime/perf2802_owner_browser_acceptance_20261029.json).
Это basic browser acceptance, не вся ролевая/security/performance матрица.

## Текущая работа

RSS-01 + PERF-01: исправить изображения и подготовить passive workday capture
одним комплектом2803. [План](docs/superpowers/plans/2026-10-29-feed-media-workday.md),
[ledger](context/runtime/feed_media_workday_progress_20261029.md).
Кандидат2803 app ef903b7d/operator36be3f10 прошёл4CI/native357/1706.
[Архив/квалификация](context/runtime/feed2803_qualification_20261029.json),
[owner установка/рабочий день](docs/FEED_2803_INSTALL_RU.md).
Он отделён от main/production2802, не слит и не установлен.
Реальный рабочий день, SQL/page, Web-SAPI OPcache и concurrency/SLO ещё открыты.
Нового автоматического merge нет. Агент работает сам, без SSH и provider sends.

## Границы

Исходники — moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
Логи/health/recreate отложены до планового Moodle upgrade; SSH/firewall — перед
внешним доступом. Копии переданы, внешний restore unknown. Принятые SSD/core/OS/
DB-role/code/HTTPS/Connect проверки не повторять. ИБП есть, shutdown не проверен.
[Индекс](context/CONTEXT_INDEX.md), [история](context/archive/reconciliation_20261026).
