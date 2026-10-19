# Активные задачи USTAR

## Текущая работа INTEGRATIONS-01 / CON-01B.1

Owner19Oct04:09 поручил продолжить с последнего агента. PR106 a627f241 и обеCI SUCCESS проверены; дублей новых работ не обнаружено. [План CON01B.1](../proposals/USTAR_CONNECT_CON01B_PLAN_20261019.md): внутренние PHP identity/link/challenge/proof,600с TTL и browser sesskey binding, uniqueness, replay, revoke/relink, pending/current employment, privacy новых metadata. Candidate2026101900/531files/96tables отдельно от accepted baseline e1d57f5b/526/93. Локально561r16 PASS/19skips; Native7aff1a99 CI39185254555 PASS/12tests/42assertions + concurrency; финальная retention/replay правка требует повторного CI. Не объявлять рабочую регистрацию/бота. Следом trusted worker bridge, nonce/session/signup receipt+canonical writers и формы. Public endpoints/production/network не подключались.

Без субагентов по требованию владельца. Journal/heartbeat пульта отложены; accounts/link ранее проверены владельцем, не полная приёмка. INF07/full Moodle HTTPS и внешняя onboarding Mini App остаются отдельными gates. Legacy handler source не получен; не запускать для аудита.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
