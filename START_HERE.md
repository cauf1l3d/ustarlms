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

Владелец28Oct принял пакет Studio2801/81118e39: «отлично всё работает» и поручил PR109.
[Owner-квитанция](context/runtime/studio_viewport_owner_acceptance_20261028.json).
Новой точной terminal-квитанции нет; прежняя2600 остаётся последним точным status/source.
App a3df5783/metadata9332829c прошли все четыре CI; архив выдан. STUDIO-01 завершён.

Текущая задача PERF-01/PR109: batch RSS statistics и operator season names. Приложение8d456031
интегрировано поверх принятой Studio2801; source PHP8.2/8.3, context/infra/console PASS.
Полный native RC ffc43884 PASS:354/1695 на обоих стендах.
[Квитанция](context/runtime/perf_query_batching_review_20261028.json).
Main содержит6c56/local2300, PR107 открыт; автоматическое слияние не разрешено.
Принятую студию и её замороженные операторы сохранять. SSH/субагенты запрещены.
Исходники: moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
[Индекс](context/CONTEXT_INDEX.md), [история входов](context/archive/reconciliation_20261026).
