# Активные задачи USTAR

[Проверенный read-only bundle и команды сбора](../runtime/connect_academy_preflight_20261019.md): sourceef9911f3,13targeted/708r16/30skips, zip/plan/help/source verified. Следующее действие — owner JSON, не migration.

**Owner19Oct18:59МСК:** первый запуск @ustarcorp_bot подтверждён текстом «окей работает». Receiver-only этап принят владельцем; скриншоты не прочитаны, точные PID/uptime/ответ не выдумывать. Следом — [подготовка подключения Академии](../proposals/USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md): read-only preflight текущих файлов/версий/схемы и затем отдельный выпуск candidate552/99, private bridge/links/уведомления. Mini App ждёт HTTPS; native handoff/step-up/role screens остаются отдельными gates.0subagents.


**Owner19Oct18:53МСК:** install/configure и read-only Telegram identity probe подтверждены на server1. Journal: telegram_verified/ustarcorp_bot, service Deactivated successfully. Ошибка admin start — ложный отказ после GC успешного oneshot, не отказ Telegram. Исправлен synchronous probe + проверка exact managed units/private conditions;15deploy/695r16 PASS(30skips), новый actual-host CLI gate pending. Владелец может выполнить systemctl enable --now ustar-connect-receiver.service после подтверждённого probe; service status и личный /start ACK ещё ожидаются. [Квитанция](../runtime/connect_receiver_server1_20261019.json).0subagents.


**Owner19Oct18:41МСК:** server1 install b01bd942 подтверждён, службы не запущены. configure упал до запроса токена: дефект open('/dev/tty','r+') воспроизведён и исправлен на write-only output stream.11deploy/691r16 PASS(30environment skips), реальный PTY/ECHO test RED→GREEN. Использовать [исправленную настройку](../runtime/connect_configure_tty_recovery_20261019.md); не переустанавливать пакет и не править immutable manifest. Настройка/start/live ACK ещё ожидаются. Субагенты не использовались.


Receiver-only комплект sourceb01bd942 проверен actual Ubuntu host job118145618545; archive12197369243 скачан и сверен с Git. [Команды установки](../../context/runtime/connect_receiver_delivery_20261019.md), [точная квитанция](../../context/runtime/connect_receiver_release_20261019.json). Следующее действие — owner install/configure/start и личный /start ACK; server1 ещё не установлен агентом. Общий PR native Moodle regression queued, whole PR green не заявлен; standalone пакет Академию не мигрирует.

**Текущий приоритет owner19Oct17:44МСК:** Mini App откладывается до HTTPS, token business bot уже перевыпущен. Подготовить receiver-only комплект и systemd-службы, затем запуск владельцем. Последний agent остановился перед реализацией. C4 завершён наfd9eea80 (Context39259305496/Review39259305507 SUCCESS,121native/611assertions); не переоткрывать C4 или начинать native handoff вместо этого поручения. [План первого запуска](../../context/proposals/USTAR_CONNECT_RUNTIME_PLAN_20261019.md). Новая поставка не мигрирует Академию: onboarding=false, /start,/academy,/help сообщают о неподключённом Mini App; notify подготовлен, выключен. Субагенты запрещены. Token только private tty на server1, не запрашивать в чате.


**C4,19Oct:** реализованы отдельная provider-read Connect-сессия и собственный профиль Mini App, candidate2026101905/552files/99tables. Fresh linked-active proof, явная activation, idle15min/absolute8h, current user/link/password/role/org guards, native MFA/SSO/forced-password block; defaultOFF. Проверки14WSGI/full675r16/30local skips PASS; native REDa06ed50e118tests/12expected new errors и browser RED26429064 подтверждены. Exact итоговые native/browser CI и commit/tree — в актуальной квитанции [PR106](https://github.com/cauf1l3d/ustarlms/pull/106); не переносить квитанцию C3 на новый код. Продакшен/main/сеть не менялись. Далее full native handoff/recovery/MFA/step-up и role actions, затем external HTTPS/runtime acceptance.


**Точка продолжения19Oct15:31МСК:** C3 завершён на941a853cbfd03eaefd691fdbdf413607d9a92aba: Context39251446669/Review39251446110 SUCCESS;106native/530assertions,5concurrency/schema parity,661r16/133CI skips,24link/20onboarding/60mobile PASS. Не переоткрывать C1/C2/C3 по датированным записям ниже. Текущий C4 — отдельная provider-read Connect-сессия и собственный реальный профиль в Mini App; [план](../proposals/USTAR_CONNECT_CON01C4_PLAN_20261019.md), [ledger](../proposals/USTAR_CONNECT_CON01C4_PROGRESS_20261019.md). Full Moodle handoff/recovery/MFA/step-up/role actions остаются отдельными этапами. Native MFA/forced password/alternate login блокируют provider-read; default activation OFF. Новая реализация и native/browser CI ещё pending. Продакшен e1d57f5b/526files/93tables и main не менялись.0subagents/no live provider/network operations.


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
