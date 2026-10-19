# USTAR вход в проект

**Owner19Oct18:59МСК:** первый запуск @ustarcorp_bot подтверждён текстом «окей работает». Receiver-only этап принят владельцем; скриншоты не прочитаны, точные PID/uptime/ответ не выдумывать. Следом — [подготовка подключения Академии](context/proposals/USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md): read-only preflight текущих файлов/версий/схемы и затем отдельный выпуск candidate552/99, private bridge/links/уведомления. Mini App ждёт HTTPS; native handoff/step-up/role screens остаются отдельными gates.0subagents.


**Owner19Oct18:53МСК:** install/configure и read-only Telegram identity probe подтверждены на server1. Journal: telegram_verified/ustarcorp_bot, service Deactivated successfully. Ошибка admin start — ложный отказ после GC успешного oneshot, не отказ Telegram. Исправлен synchronous probe + проверка exact managed units/private conditions;15deploy/695r16 PASS(30skips), новый actual-host CLI gate pending. Владелец может выполнить systemctl enable --now ustar-connect-receiver.service после подтверждённого probe; service status и личный /start ACK ещё ожидаются. [Квитанция](context/runtime/connect_receiver_server1_20261019.json).0subagents.


**Owner19Oct18:41МСК:** server1 install b01bd942 подтверждён, службы не запущены. configure упал до запроса токена: дефект open('/dev/tty','r+') воспроизведён и исправлен на write-only output stream.11deploy/691r16 PASS(30environment skips), реальный PTY/ECHO test RED→GREEN. Использовать [исправленную настройку](context/runtime/connect_configure_tty_recovery_20261019.md); не переустанавливать пакет и не править immutable manifest. Настройка/start/live ACK ещё ожидаются. Субагенты не использовались.


Receiver-only комплект sourceb01bd942 проверен actual Ubuntu host job118145618545; archive12197369243 скачан и сверен с Git. [Команды установки](context/runtime/connect_receiver_delivery_20261019.md), [точная квитанция](context/runtime/connect_receiver_release_20261019.json). Следующее действие — owner install/configure/start и личный /start ACK; server1 ещё не установлен агентом. Общий PR native Moodle regression queued, whole PR green не заявлен; standalone пакет Академию не мигрирует.

**Текущий приоритет owner19Oct17:44МСК:** Mini App откладывается до HTTPS, token business bot уже перевыпущен. Подготовить receiver-only комплект и systemd-службы, затем запуск владельцем. Последний agent остановился перед реализацией. C4 завершён наfd9eea80 (Context39259305496/Review39259305507 SUCCESS,121native/611assertions); не переоткрывать C4 или начинать native handoff вместо этого поручения. [План первого запуска](context/proposals/USTAR_CONNECT_RUNTIME_PLAN_20261019.md). Новая поставка не мигрирует Академию: onboarding=false, /start,/academy,/help сообщают о неподключённом Mini App; notify подготовлен, выключен. Субагенты запрещены. Token только private tty на server1, не запрашивать в чате.


**C4,19Oct:** реализованы отдельная provider-read Connect-сессия и собственный профиль Mini App, candidate2026101905/552files/99tables. Fresh linked-active proof, явная activation, idle15min/absolute8h, current user/link/password/role/org guards, native MFA/SSO/forced-password block; defaultOFF. Проверки14WSGI/full675r16/30local skips PASS; native REDa06ed50e118tests/12expected new errors и browser RED26429064 подтверждены. Exact итоговые native/browser CI и commit/tree — в актуальной квитанции [PR106](https://github.com/cauf1l3d/ustarlms/pull/106); не переносить квитанцию C3 на новый код. Продакшен/main/сеть не менялись. Далее full native handoff/recovery/MFA/step-up и role actions, затем external HTTPS/runtime acceptance.


**Точка продолжения19Oct15:31МСК:** C3 завершён на941a853cbfd03eaefd691fdbdf413607d9a92aba: Context39251446669/Review39251446110 SUCCESS;106native/530assertions,5concurrency/schema parity,661r16/133CI skips,24link/20onboarding/60mobile PASS. Не переоткрывать C1/C2/C3 по датированным записям ниже. Текущий C4 — отдельная provider-read Connect-сессия и собственный реальный профиль в Mini App; [план](context/proposals/USTAR_CONNECT_CON01C4_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C4_PROGRESS_20261019.md). Full Moodle handoff/recovery/MFA/step-up/role actions остаются отдельными этапами. Native MFA/forced password/alternate login блокируют provider-read; default activation OFF. Новая реализация и native/browser CI ещё pending. Продакшен e1d57f5b/526files/93tables и main не менялись.0subagents/no live provider/network operations.


## Текущий этап 19 октября 2026

Обновление19Oct12:55МСК: CON01C.2: управление собственными messenger links в native Moodle и signed uc1_ claim в Mini App. Личный кабинет → Мессенджеры → персональная ссылка → Telegram → второй Academy confirm в той же сессии; owner-only revoke/list. RAM receipt не onboarding/employee grant; timeout uncertain без автоповтора. Candidate2026101903/546files/99tables, production e1d57f5b/526/93 unchanged. 8new WSGI tests,31web/full647r16 OK/30environment skips. Native RED d9114daa/39234234916: exactly6missing-class errors,78previous tests pass. Browser RED2c971f93/39235237752: missing confirmation screen; prior20onboarding/60mobile pass. Final new native/browser CI receipt — PR106; читать exact final receipt перед продолжением, не переоткрывать C2 по старой pre-CI записи. Новый business bot @ustarcorp_bot, секрет из чата не использован/не сохранён, требуется перевыпуск владельцем. CrazeDNS em2025.netcraze.pro предложен; rule/cert/availability ещё не приняты. Full canonical remote login/recovery/MFA/actor step-up, canonical notification leases/delivery/preferences/ACK и role screens — остаток.0subagents/no main merge/no prod/router/network changes.

[План C2](context/proposals/USTAR_CONNECT_CON01C2_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C2_PROGRESS_20261019.md). Ниже сохранены датированные предыдущие checkpoints.

Обновление10:33МСК: **CON01C.1 — Telegram polling receiver**, [план](context/proposals/USTAR_CONNECT_CON01C1_PLAN_20261019.md)/[ledger](context/proposals/USTAR_CONNECT_CON01C1_PROGRESS_20261019.md). Fixed TLS API, private commands, durable SQLite cursor/dedup/uncertainty и персональный startapp, runtime opt-in.41receiver tests/local639r16/30skips PASS; exact CI receipt PR106. B4 **завершён** на26219dac: Context39214124458/Review39214124509 SUCCESS,20browser/78native399/schema+concurrency PASS. Ниже предыдущие датированные checkpoints. Main/server1/network/пульт не менялись,0subagents. Следом login/recovery/link/step-up и canonical delivery; выбранный external HTTPS relay и реальный API ACK ещё нужны до активации.

Обновление09:55МСК: владелец попросил остаток этапов и следующую реализацию. **CON01B.3 проверен**: d4f7c1bd, Context39192666105/Review39192666066 SUCCESS,78native tests/399assertions + schema/concurrency PASS. Текущий **CON01B.4** — приватный browser BFF и мобильная форма регистрации: [план](context/proposals/USTAR_CONNECT_CON01B4_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01B4_PROGRESS_20261019.md). Без нового HR store/schema, без public listener/реального бота/установки. Local598r16/30skips PASS, exact final CI receipt — PR106. Внешний HTTPS relay, canonical login/step-up, delivery/receiver и ролевой клиент ещё впереди; далее Bitrix/WhatsApp. Предыдущие строки ниже — checkpoints, не повод переоткрывать закрытые native gates.

Приоритет владельца01:28МСК — **INTEGRATIONS-01**, продолжение05:30 — **CON01B.3: canonical signup/pending/first link**, [PR106/RFC](context/proposals/USTAR_CONNECT_RFC_20261018.md), [контракты](context/proposals/USTAR_CONNECT_CONTRACTS_20261019.md). В00:41 владелец подтвердил учётные записи пульта и личную Telegram-привязку; журнал всё ещё показывает прежнее сообщение. Журнал и внешний heartbeat явно отложены. [Датированный receipt](context/runtime/connect_owner_priority_20261019.json).

Рабочий продукт — Moodle + `local_ustar` + `theme_ustar`, parent boost. `frontend/` — legacy Next.js, исключённый из активного CI/выпуска. Проверки native UI темы и плагина сохранены.

- **Сделано на сервере:** SSD расширен11Oct; Moodle5.1.8 upgrade/repeat и краткая браузерная/SCORM приёмка13Oct; AppArmor,63 пакета, reboot/kernel147/Docker29.9.0 приняты14Oct. Не повторять.
- **Ожидание:** владелец16Oct подтвердил получение архива и передачу инструкции сисадмину. Отдельная копия, её вход/SCORM/reboot и полный host DR ещё не приняты.
- **Текущая задача:** проектирование USTAR Connect: Academy identity/capabilities, Telegram/Bitrix24/WhatsApp adapters, Mini Apps и внешний HTTPS edge без открытия офисных портов. Review регистрации и CRM=задачи выполнен; реализованы Python primitives и внутренний PHP link/challenge/proof service с candidate schema; публичный registration API отсутствует; INF-06 остаток отложен, INF-07 HTTPS Академии сохраняется отдельным gate полного LMS.
- **Отложено:** Moodle5.2 и PostgreSQL17. Прежняя подготовка5.3/PR101 сохранена как история, не следующий запуск.

[Review и первый вход](context/proposals/USTAR_CONNECT_REVIEW_20261019.md), [план CON01B.3](context/proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md). Base81a1be76 bridge/entry native22tests/87assertions PASS. Candidate теперь добавляет atomic canonical signup+receipt и собственный status, переиспользует HRD review/notifications. Endpoint выключен; внешние формы, actor/step-up remote HRD commands и receiver ACK ещё впереди. Exact final CI receipt — в PR106, не production acceptance.

## Порядок чтения

1. [project](context/project.yaml), [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md).
2. [Сверка выполненного и остатка с аудитом](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
3. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [ADR](context/decisions/README.md), [domain maps](context/CONTEXT_INDEX.md).
4. [Runtime](context/runtime/README.md), [карта кода](context/code_map/README.md), [протокол](context/roadmap/AGENT_PROTOCOL.md).

## Источники истины

`main` — код и текущий контекст. Точный plugin/theme baseline PR76/e1d57f5b,526 файлов, local2026100204/theme2026100202 сохранён; это не номер нового core и не текущий repository head. [Датированные runtime evidence](context/runtime/evidence_20261016.yaml) отделяют версии/установку от статического source и CI.

[Ветки16Oct](context/runtime/repository_state_20261016.json) — история консолидации. Текущие unmerged работы: PR103, рабочая поставка пульта sourcee5b87818/contextde4544d9; PR105, датированные server facts02fd394f; PR106, текущий Connect architecture + offline foundation. Их актуальные границы/точные refs — в новом receipt. Старые release/integration/prod-sync branches не использовать как базу. Не считать PR103 только прототипом и не считать unmerged work уже частью main.

[Инструкция сисадмину](context/runtime/production_copy_20261015.md) использует существующий HDD-архив и не останавливает прод. Не путать `--pack`, `--pack-production`, постоянную parallel copy и временный lab. Бэкап с ключом нельзя помещать в Git/чат/Telegram.

Нет прямого подключения агента к server1. После сверки репозитория не запускать native update/capture/reboot/restore без самостоятельного основания. [CI scope](context/runtime/CI_SCOPE_20261016.md) описывает реально выполняемые и отдельные опциональные проверки.

Candidate2026101902/542source files/99tables хранится в [отдельном manifest](context/code_map/development_source.json). Это unmerged разработка, не новый production receipt. Accepted source inventory526/93 остаётся датированным baseline.
