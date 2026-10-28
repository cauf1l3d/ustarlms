# USTAR Academy — начало работы

Актуально29.10.2026. Это вход в текущий проект; история находится в архиве.

1. [STATE](context/roadmap/STATE.yaml) — версии, SHA, уверенность и квитанции.
2. [ACTIVE](context/tasks/ACTIVE.md) — текущая работа и её границы.
3. [Проверенные ревью](context/reviews/20261026/VERIFIED_REVIEW_RU.md) — подтверждённые
   проблемы, опровергнутые выводы и приоритеты.
4. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md),
   [ADR](context/decisions/README.md) и [карта кода](context/code_map/README.md) — по задаче.

## Подтверждённый production

Moodle5.1.8 / PHP8.3.33 / PostgreSQL16.15 — прежние датированные platform receipts.
Владелец29Oct установил2802: payload22607c69, local_ustar2026102802,
theme_ustar2026102700; app_ready/DB available, receiver+notify active/enabled.
[Основание](context/runtime/evidence_20261029.yaml),
[квитанция установки/status](context/runtime/perf2802_owner_deploy_20261029.json).
590файлов/100таблиц — фиксированный payload, не новая инвентаризация хоста.
Worker6c56a5b6 не обновлялся. Scheduled cron29Oct00:42MSK:exit0/34.311s,
новых ошибок нет, adhoc1→0, overdue63→19, failed2. Последние19/2 совпадают
со старыми счётчиками; состав заданий не показан. Wrapper20Oct — исторический.
Общая приёмка Studio2801 сохранена; браузерные сценарии после2802 ещё не сообщены.
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
[интеграция](context/runtime/perf2802_integration_20261028.json).
PR107/109 слиты:84a078ef/01904eda. Main содержит app22607c69/local2802/theme2700;
worker6c56 отдельно и без rollout. STUDIO-01 завершён.

PERF-01: оператор3c1ddd09 и приложение2802 прошли все четыре CI, обе PHP1363/232,
оба native354/1695, actual PG/UID33 PASS. Архив идентичен CI и скачанной сохранённой копии.
[Квитанция](context/runtime/perf2802_qualification_20261028.json),
[установка/проверка](docs/PERF_2802_INSTALL_RU.md). Owner terminal подтверждает2802 на server1.
Следом — browser smoke и пассивные workday/concurrency/SLO измерения, ускорение не заявлено.
SSH/субагенты запрещены; принятые инфраструктурные проверки не повторять.
Исходники: moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
[Индекс](context/CONTEXT_INDEX.md), [история](context/archive/reconciliation_20261026).
