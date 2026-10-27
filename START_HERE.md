# USTAR Academy — начало работы

Актуально26.10.2026. Это вход в текущий проект; история находится в архиве.

1. [STATE](context/roadmap/STATE.yaml) — версии, SHA, уверенность и квитанции.
2. [ACTIVE](context/tasks/ACTIVE.md) — текущая работа и её границы.
3. [Проверенные ревью](context/reviews/20261026/VERIFIED_REVIEW_RU.md) — подтверждённые
   проблемы, опровергнутые выводы и приоритеты.
4. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md),
   [ADR](context/decisions/README.md) и [карта кода](context/code_map/README.md) — по задаче.

## Подтверждённый production

Moodle5.1.8 / PHP8.3.33 / PostgreSQL16.15. local_ustar2026102300,
theme_ustar2026100202; payload/worker6c56a5b6ab830b97b706a5d7028b49cfae31e7a4.
[Основание](context/runtime/evidence_20261026.yaml): квитанции владельца23–26Oct;
557файлов/100таблиц относятся к фиксированному payload. Это не новая инвентаризация хоста.
Приложение использует обычную роль ustar_app; права кода/config и Compose0600 применены.
Connect ACK/presentation и вход/открытие Академии подтверждены владельцем.

## Решения владельца

Логи/healthcheck/recreate — в окно планового обновления Moodle; старый Docker
successor заморожен и на сервер не поставлен. SSH/firewall — перед внешним доступом.
Копии переданы, внешний restore unknown; ИБП есть, shutdown не проверен.
[Риски и триггеры пересмотра](context/roadmap/ACCEPTED_RISKS.yaml).

## Репозиторий и поставка

PR105 — факты server1; PR103 — пульт; PR106 — принятый Connect и инфраструктура.
Их интеграция не является новым deploy. PR107 — отдельная неподтверждённая студия.
Исходники Академии: moodle/local/ustar и moodle/theme/ustar; frontend — legacy.
[Индекс](context/CONTEXT_INDEX.md) помогает выбрать нужный домен.
[История прежних входов](context/archive/reconciliation_20261026) сохранена целиком.
