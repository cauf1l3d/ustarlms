# USTAR Academy — начало работы

Актуально29.10.2026. Канонический код — main; PR110 source2803 не слит.

1. [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
2. [Поручение Connect29Oct](context/runtime/connect_bot_owner_order_20261029.json).
3. [Owner2803](context/runtime/feed2803_owner_deploy_20261029.json), [ADR Connect](context/decisions/0015-ustar-connect.md).

## Подтверждённый production

Owner29Oct установил2803: payload ef903b7d/operator36be3f10, local2026102803/theme2026102700.
App_ready/DB available, receiver+notify active/enabled; worker6c56 без rollout.
RSS принят: одна обложка, вложения через «Ещё». Payload590/100 не является новой
whole-host инвентаризацией. Последний завершённый cron03:37MSK exit0/1.282s без новых
ошибок; текущий срез adhoc1/overdue56/failed2 ещё не доказывает разгрузку после upgrade.
Исторический wrapper20Oct и остаточные failures не сбрасывать.

## Текущая работа

Исправленный комплект2900: app/worker5cc02944 + tool9a68246b. Ошибка owner preflight99
устранена, новый cron guard и actual systemd/UID33/TLS/recovery CI прошли.
[Квитанция](context/runtime/connect_role_bot_cron_preflight_fix_20261029.json),
[установка](docs/CONNECT_BOT_2900_INSTALL_RU.md). Архив0860a867 совпал с CI и скачанной
копией. App/worker не менялись и сохраняют предыдущую FullRC квалификацию.
Production ещё2803/worker6c56; установка и живая приёмка ролей владельцем впереди.

Owner29Oct возобновил полный рабочий Telegram-бот по ролям: русские кнопки,
пошаговые формы, короткие пояснения, визуальные сводки/таблицы/графики. Мини App
и публичный HTTPS Академии — в конце. Существующие учётки/права/writers/история
остаются едиными. Работать самому без субагентов; один согласованный выпуск,
проверки по изменениям и обязательному gate без повторения принятых циклов.
PERF-01 report ожидается; напоминание владельцу30Oct11:00МСК создано. Сборщик не
запускался агентом, SQL/page/Web-SAPI OPcache/concurrency/SLO не измерены.
Нового автоматического merge, SSH и provider sends нет.

Логи/health/recreate — плановый Moodle window; SSH/firewall — перед внешним доступом.
Копии переданы, внешний restore unknown. [Риски](context/roadmap/ACCEPTED_RISKS.yaml),
[ревью26Oct](context/reviews/20261026/VERIFIED_REVIEW_RU.md), [индекс](context/CONTEXT_INDEX.md).
