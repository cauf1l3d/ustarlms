# Активные задачи USTAR

## Текущая работа INTEGRATIONS-01 / CON-01B.3

Owner19Oct05:30 продолжил после81a1be76: Context39188208182/Review39188208139 SUCCESS,22native tests/87assertions и concurrent entry PASS. [План CON01B.3](../proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md), [ledger](../proposals/USTAR_CONNECT_CON01B3_PROGRESS_20261019.md): canonical register completion сохраняет first link/receipt/own entry в своей transaction и email lock; private options/submit/status; receipt HMAC без plaintext/быстрого password digest. Same command/form replay не создаёт дубликат, email/login collision не auto-link. Candidate2026101902/542files/99tables, не production baseline526/93. Test-only57111574 дал2expected native failures; implementation written after RED, локально574r16/19skips PASS. Новый native CI ожидается; exact final receipt в PR106. Existing HRD review/notifications сохраняются; remote HRD actor/step-up, public BFF/форма и реальный Telegram delivery ещё впереди. Endpoint disabled,0субагентов, main/network/server1 не менялись.

Без субагентов по требованию владельца. Journal/heartbeat пульта отложены; accounts/link ранее проверены владельцем, не полная приёмка. INF07/full Moodle HTTPS и внешняя onboarding Mini App остаются отдельными gates. Legacy handler source не получен; не запускать для аудита.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
