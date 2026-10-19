# Активные задачи USTAR

## Текущая работа INTEGRATIONS-01 / CON-01B.2

Owner19Oct04:47 продолжил работу после5f9aa3fb: Context39185823445/Review39185823458 SUCCESS; native13tests/47assertions и concurrent confirm/replay PASS. [План CON01B.2](../proposals/USTAR_CONNECT_CON01B2_PLAN_20261019.md): private scoped HMAC bridge, persistent transport nonce, one-use launch и browser-bound entry TTL600с со scope onboarding/status/logout only. Endpoint выключен по умолчанию, TLS и exact peer allowlist; никаких роли/userid из JSON. Current link revision/flags, privacy и bounded cleanup. Candidate2026101901/539files/98tables отдельно от accepted baseline e1d57f5b/526/93. Локально569r16 PASS/19skips; новый native CI ожидается, exact финальный receipt в PR106. Следом canonical signup receipt + pending provider link, HRD decision/outbox и формы. Публичный edge/реальные bot receiver/production/network не подключались; не объявлять рабочую регистрацию или бота.

Без субагентов по требованию владельца. Journal/heartbeat пульта отложены; accounts/link ранее проверены владельцем, не полная приёмка. INF07/full Moodle HTTPS и внешняя onboarding Mini App остаются отдельными gates. Legacy handler source не получен; не запускать для аудита.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
