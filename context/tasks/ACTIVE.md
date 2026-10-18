# Активные задачи USTAR

## Текущая работа INTEGRATIONS-01 / CON-00

По запросу19Oct00:49МСК доработать [PR106/RFC Connect](../proposals/USTAR_CONNECT_RFC_20261018.md) и [контракты](../proposals/USTAR_CONNECT_CONTRACTS_20261019.md): одна Academy identity и текущие capabilities/scope, Telegram/Bitrix24/WhatsApp adapters, роли/эскалации и внешний Mini App без входящих офисных портов. Это design-only; реализация и provisioning ещё не начаты. Субагенты запрещены владельцем без предварительного согласования и обоснования.

[Owner00:41](../runtime/connect_owner_priority_20261019.json): учётные записи пульта и личная Telegram-привязка работают; journal всё ещё показывает прежнее сообщение. Journal/heartbeat явно отложены и не исправляются в новой задаче. PR103 уже содержит проверенную поставку sourcee5b87818/contextde4544d9, а не только прототип. Полную приёмку или exact native SOURCE_COMMIT это сообщение не подтверждает. INF-07 HTTPS Академии сохраняется отдельным gate полного Quiz/SCORM; не путать с исправным HTTPS пульта.

Legacy handler22630байт найден владельцем; PR105 содержит dated hash17Oct. Source ещё не получен. Стратегия — сохранить доказанные сценарии и домены, [проверить перенос](../proposals/USTAR_CONNECT_LEGACY_BOT_REUSE_20261018.md), не выполнять handler и не копировать секреты в Git. Следующий engineering шаг после review — implementation plan CON-01; требуется уточнить Bitrix edition/CRM meaning/edge constraints и terminal CEO policy.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
