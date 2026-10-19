# Активные задачи USTAR

## Текущий CON01C.2 — owner19Oct12:55МСК

CON01C.2: управление собственными messenger links в native Moodle и signed uc1_ claim в Mini App. Личный кабинет → Мессенджеры → персональная ссылка → Telegram → второй Academy confirm в той же сессии; owner-only revoke/list. RAM receipt не onboarding/employee grant; timeout uncertain без автоповтора. Candidate2026101903/546files/99tables, production e1d57f5b/526/93 unchanged. 8new WSGI tests,31web/full647r16 OK/30environment skips. Native RED d9114daa/39234234916: exactly6missing-class errors,78previous tests pass. Browser RED2c971f93/39235237752: missing confirmation screen; prior20onboarding/60mobile pass. Final new native/browser CI receipt — PR106; читать exact final receipt перед продолжением, не переоткрывать C2 по старой pre-CI записи. Новый business bot @ustarcorp_bot, секрет из чата не использован/не сохранён, требуется перевыпуск владельцем. CrazeDNS em2025.netcraze.pro предложен; rule/cert/availability ещё не приняты. Full canonical remote login/recovery/MFA/actor step-up, canonical notification leases/delivery/preferences/ACK и role screens — остаток.0subagents/no main merge/no prod/router/network changes.

[План](../proposals/USTAR_CONNECT_CON01C2_PLAN_20261019.md), [ledger](../proposals/USTAR_CONNECT_CON01C2_PROGRESS_20261019.md).

## Завершённый C1 — датированная история / CON01C.1 —19Oct10:33МСК

Telegram polling receiver/private startapp: [план](../proposals/USTAR_CONNECT_CON01C1_PLAN_20261019.md)/[ledger](../proposals/USTAR_CONNECT_CON01C1_PROGRESS_20261019.md). SQLite metadata cursor/dedup/exclusive lock, no message bodies; uncertain после interrupted external call, no automatic resend; cooldown/freshness/ownership/startup webhook checks; opt-in executable runtime.41new tests/local639r16/30skips PASS, exact new CI PR106. Prior B4 completed26219dac Context39214124458/Review39214124509 SUCCESS,20browser/78native399. Launch issue/exchange должны иметь один bridge key ID. Bot/relay/credentials/службы не активировались,0subagents, no server1/main/network changes. Следом canonical login/recovery/link/actor step-up, notification outbox leases/delivery/preferences/ACK и approved external relay/live acceptance.

## Завершённый срез CON01B.4 — история09:55МСК

Canonical signup CON01B.3 d4f7c1bd завершён: Context39192666105/Review39192666066 SUCCESS,78native tests/399assertions,4concurrency/schema parity PASS. Следующий срез [browser plan](../proposals/USTAR_CONNECT_CON01B4_PLAN_20261019.md)/[ledger](../proposals/USTAR_CONNECT_CON01B4_PROGRESS_20261019.md): private WSGI BFF, exact browser routes, Telegram verified launch, rotated HttpOnly cookie+CSRF, bounded volatile sessions, six-field mobile form, current own status, sanitized canonical validation. No new schema, candidate542/99 unchanged. Local598r16/30skips PASS; exact browser/new CI receipt PR106. Local Chromium download failed, не заявлять local browser PASS. Не включены receiver/polling/delivery, relay/external origin, remote login/step-up.0subagents/no deploy/main merge. Далее trusted receiver+outbox и внешняя доступность/actor gates; ролевые экраны и Bitrix/WhatsApp отдельными срезами. Ниже предыдущий dated checkpoint.

## Завершённый срез CON01B.3 — история

Owner19Oct05:30 продолжил после81a1be76: Context39188208182/Review39188208139 SUCCESS,22native tests/87assertions и concurrent entry PASS. [План CON01B.3](../proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md), [ledger](../proposals/USTAR_CONNECT_CON01B3_PROGRESS_20261019.md): canonical register completion сохраняет first link/receipt/own entry в своей transaction и email lock; private options/submit/status; receipt HMAC без plaintext/быстрого password digest. Same command/form replay не создаёт дубликат, email/login collision не auto-link. Candidate2026101902/542files/99tables, не production baseline526/93. Test-only57111574 дал2expected native failures; implementation written after RED, локально575r16/19skips PASS. Новый native CI ожидается; exact final receipt в PR106. Existing HRD review/notifications сохраняются; remote HRD actor/step-up, public BFF/форма и реальный Telegram delivery ещё впереди. Endpoint disabled,0субагентов, main/network/server1 не менялись.

Без субагентов по требованию владельца. Journal/heartbeat пульта отложены; accounts/link ранее проверены владельцем, не полная приёмка. INF07/full Moodle HTTPS и внешняя onboarding Mini App остаются отдельными gates. Legacy handler source не получен; не запускать для аудита.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
