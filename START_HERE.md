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

## Репозиторий и поставка

PR105 — факты server1; PR103 — пульт; PR106 — принятый Connect и инфраструктура.
Main содержит основу6c56/local2300; PR107 пока не слит. Пакет2700 выдан владельцу,
[поставка](context/runtime/studio_materials_delivery_20261028.json). Скриншоты28Oct
показывают рабочую Академию/студию, но не точную версию. Последняя terminal-квитанция2600.
2800: цвета меню/сворачиваемая шапка; все четыре CI SUCCESS на053b8c17,
обе native матрицы352/1678. Source4c48dce7 квалифицирован, на prod не применён.
[Установка2800](docs/STUDIO_2800_INSTALL_RU.md). Исправленный5f57115d выдан; owner check/apply ожидается. Не повторять
установку2600 и диагностику старой ошибки cron20Oct; агент не подключается к серверу.
Исходники Академии: moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
[Индекс](context/CONTEXT_INDEX.md) помогает выбрать нужный домен.
[История прежних входов](context/archive/reconciliation_20261026) сохранена целиком.
