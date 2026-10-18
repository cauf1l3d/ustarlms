# Активные задачи USTAR

## Текущая работа INTEGRATIONS-01 / CON-01A

Owner19Oct01:28: CRM=задачи Академии, регистрация первого касания из бота с pending/approval и общая привязка каналов; review и начало разработки разрешены. [Результаты review](../proposals/USTAR_CONNECT_REVIEW_20261019.md), [план](../proposals/USTAR_CONNECT_CON01A_PLAN_20261019.md). CON01A — Python identity, Telegram HMAC proof и формат одноразового кода, offline tests. Нет работающей регистрации/API, persistent consume/replay, schema/network/server changes. Следующий CON01B — canonical registration/HRD writer + identity/challenge/session DB и интеграционные проверки. Manager не получает hrmanage по названию роли.

Без субагентов по требованию владельца. Journal/heartbeat пульта отложены; accounts/link ранее проверены владельцем, не полная приёмка. INF07/full Moodle HTTPS и внешняя onboarding Mini App остаются отдельными gates. Legacy handler source не получен; не запускать для аудита.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
