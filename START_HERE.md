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

Owner принял Studio2801 и согласился на интеграцию/поставку PR109.
[Приёмка](context/runtime/studio_viewport_owner_acceptance_20261028.json),
[интеграция](context/runtime/perf2802_integration_20261028.json). Новой terminal-квитанции нет.
PR107/109 слиты:84a078ef/01904eda. Main содержит app22607c69/local2802/theme2700;
worker6c56 отдельно и без rollout. STUDIO-01 завершён.

PERF-01: оператор3c1ddd09 и приложение2802 прошли все четыре CI, обе PHP1363/232,
оба native354/1695, actual PG/UID33 PASS. Архив идентичен CI и скачанной сохранённой копии.
[Квитанция](context/runtime/perf2802_qualification_20261028.json),
[установка/проверка](docs/PERF_2802_INSTALL_RU.md).2802 на server1 пока не подтверждён.
Владелец выполняет установку и присылает status. Workday/SLO остаются отдельной приёмкой.
SSH/субагенты запрещены; принятые инфраструктурные проверки не повторять.
Исходники: moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
[Индекс](context/CONTEXT_INDEX.md), [история](context/archive/reconciliation_20261026).
