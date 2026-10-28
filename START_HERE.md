# USTAR Academy — начало работы

Актуально28.10.2026. Это вход в текущий проект; история находится в архиве.

1. [STATE](context/roadmap/STATE.yaml) — версии, SHA, уверенность и квитанции.
2. [ACTIVE](context/tasks/ACTIVE.md) — текущая работа и её границы.
3. [Проверенные ревью](context/reviews/20261026/VERIFIED_REVIEW_RU.md) — подтверждённые
   проблемы, опровергнутые выводы и приоритеты.
4. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md),
   [ADR](context/decisions/README.md) и [карта кода](context/code_map/README.md) — по задаче.

## Подтверждённый production

Moodle5.1.8 / PHP8.3.33 / PostgreSQL16.15. Владелец27Oct установил
Studio payload2d95137a/local_ustar2026102600, theme_ustar2026100202; app_ready.
Worker6c56a5b6 не обновлялся. [Основание](context/runtime/evidence_20261027.yaml),
[квитанция установки](context/runtime/course_studio_owner_deploy_20261027.json).
582файла/100таблиц — фиксированный payload, не новая инвентаризация хоста.
Post-deploy status: app_ready/DB available, receiver+notify active/enabled. Scheduled
cron27Oct20:22UTC:exit0, без новых ошибок. Владелец подтвердил открытие студии,
базовую работу и импорт из другой студии; отдельные PDF/conflict/employee кейсы не заявлены.
[Квитанция](context/runtime/course_studio_post_deploy_owner_20261028.json).
Приложение использует обычную роль ustar_app; права кода/config и Compose0600 применены.
Connect ACK/presentation и вход/открытие Академии подтверждены владельцем.

## Решения владельца

Логи/healthcheck/recreate — в окно планового обновления Moodle; старый Docker
successor заморожен и на сервер не поставлен. SSH/firewall — перед внешним доступом.
Копии переданы, внешний restore unknown; ИБП есть, shutdown не проверен.
[Риски и триггеры пересмотра](context/roadmap/ACCEPTED_RISKS.yaml).

## Репозиторий и текущая работа

Owner принял Studio2801/81118e39 и согласился на интеграцию/отдельную поставку PR109.
[Приёмка](context/runtime/studio_viewport_owner_acceptance_20261028.json),
[новый этап](context/runtime/perf2802_integration_20261028.json). Новой terminal-квитанции нет.
PR107 слит как84a078ef: main содержит app a3df5783/local2801/theme2700; STUDIO-01 завершён.

PERF-01/PR109: source aa2b09be прошёл все четыре CI, оба native354/1695. Теперь готовится
версионированная поставка2801→2802/app22607c69 с новым frozen оператором и журналом.
[План](docs/superpowers/plans/2026-10-28-perf2802-delivery.md). Новый релиз ещё квалифицируется.
Рабочий день/SLO остаются отдельной приёмкой. Серверную установку выполняет владелец.
SSH/субагенты запрещены; Studio/старые операторы и worker6c56 сохраняются.
Исходники: moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
[Индекс](context/CONTEXT_INDEX.md), [история](context/archive/reconciliation_20261026).
