# CON01B.3 — canonical signup Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans, лично. Субагентов владелец запретил. Продолжение19Oct05:30МСК разрешает следующий ранее описанный срез в том же PR106; не запускать новый approval loop и не включать production.

**Goal:** Verified entry создаёт ровно один Moodle account, pending employment, canonical HRD request, первый provider link и durable receipt; собственный status читает текущую заявку.

**Architecture:** Существующий registration_service остаётся единственным writer пользователя/заявки/registration_requested. Connect держит общий identity lock, но не начинает внешнюю DB transaction: canonical register выполняет internal completion callback до commit и до release email lock. Callback сохраняет только link/session/receipt metadata. Так нет окна между release email lock и commit внешней transaction при одновременной LAN/Connect регистрации.

**Tech Stack:** Moodle5.1.8 / PHP8.2–8.3 / PostgreSQL16, существующий private HMAC bridge, XMLDB, unittest + native PHPUnit.

**Spec:** [Первый вход/review](USTAR_CONNECT_REVIEW_20261019.md), [контракты](USTAR_CONNECT_CONTRACTS_20261019.md), уточнённый контракт ниже. Это продолжение существующей архитектуры и registration flow, не новый домен кадровых заявок.

## Global Constraints / уточнение контракта

- Base81a1be769654debf1ccdb8a72e1b17ae1b1f6e51, Context39188208182/Review39188208139 SUCCESS:22tests/87assertions + independent concurrency. Production e1d57f5b/526files/93tables неизменен; candidate2026101902,1новая metadata table.
- Bridge disabled default; TLS/exact peer/HMAC provider+tenant+operation scope сохраняются. Новые ops registration.options/submit/status. Никаких public edge/provider sends/HRD actor delegation/role grants.
- Submit: session/browser/command + ровно6полей username,password,email,firstname,lastname,departmentid; role/userid/positionid/actor/произвольные поля запрещены. Пароль только по HTTPS, не в чате, URL, логах или receipt.
- ur1_256бит command key. В receipt только namespaced command hash, verified identity hash, **keyed HMAC** exact typed/canonical form JSON, key ID, canonical userid/requestid/linkid/revision и timestamps. Не хранить plaintext или быстрый неключевой password hash. Ключ HMAC — тот же отдельный scoped bridge service key, не Moodle/bot token. Key rotation может прервать retry старого command, но не разрешает второй account: linked user читает собственную заявку.
- Повтор с тем же command/form/key/identity и живой bound session возвращает receipt без новых account/request/notifications. Тот же command с изменённым form → idempotency_conflict. Другая команда той же linked identity → already_registered. Email/login collision → canonical field validation, **без** автоматической привязки существующего человека.
- Создание link разрешено только completion callback для только что созданного pending account. Он обновляет snapshot ровно той entry session, из которой отправлена форма: это явный signup, не тихий upgrade других unlinked sessions. До HRD approval resolve_active_user=0/learning denied. Revoke/current flags/privacy продолжают действовать; caller не выбирает userid.
- Receipt TTL30дней; bounded prune500/таблицу/5мин через существующий cron. После expiry/prune link+canonical email/username guards не дают создать дубль. Privacy удаляет receipt и также временные pre-link записи; business user/request/notification records остаются у старого canonical privacy domain, его общий аудит не объявлять завершённым.
- Существующий anonymous registration throttle30/15мин не ослаблять и не доверять forwarded IP: private bridge peer будет общим bucket. Это явно ограничивает throughput перед production activation; отдельный per-identity abuse policy/public BFF ещё предстоит.
- HRD decision выполняется существующим staffing_requests::review с real USER+approveregistration+hrmanage. Нового remote approval API нет. Manager endorsement/полномочия обычного manager не добавлять.
- Уведомления: canonical target_core/notifications/notify_delivery в той же transaction; не создавать новую outbox и не включать старый Bitrix/Telegram consumer. Native ustAR receipt не выдавать за внешний receiver ACK.
- Status отдаёт только собственные state/status/submitted_at/reviewed_at, без raw user/request IDs/profile/материалов/пароля/HR internal data. Departments берутся из canonical directory. Entry остаётся onboarding scope, не Moodle login.

## Review Focus

1. LAN регистрация и Connect с одним email не должны обойти email lock из-за nested transaction; native cross-process race.
2. Ошибка после user/request/notification create откатывает и canonical и Connect metadata; completion failure + unique collision tests.
3. Потерянный ответ и другой browser/subject/key не дают привязать чужую identity или оживить revoke; receipt replay + current flags tests.
4. Любой исход HRD review читается из canonical request; HR/manager без approveregistration не может одобрить; native approve/reject/denial.
5. Payload/password не попадают в SQL metadata, repr/ответ/URL; HMAC, strict fields и validation envelope tests.

### Task 1: Form values и canonical transaction completion

**Files:** classes/connect/signup_request.php, classes/registration_service.php; tests/r16/test_connect_signup.py, tests/connect_signup_test.php (относительно plugin для PHP).

**Interfaces:** Produces signup_request::form(array):array, ::fingerprint(array,string):string, ::command_hash(identity,string):string; registration_service::register(array,?callable):int вызывает completion(int userid,int requestid):void в своей transaction. Existing callers без callback не меняются.

- [ ] RED: real PHP values tests — field order stable, password/form/key changes hash, unknown/typed fields denied, command namespace bound to identity. Native callback rollback test написан до callback реализации.
- [ ] Run `USTAR_TEST_PHP=/tmp/connect-php/usr/bin/php8.3 python -m unittest discover -s tests/r16 -p test_connect_signup.py -v`; Expected RED missing signup_request, затем GREEN. PHP path локальный, не production инструкция.
- [ ] Implement strict structure only; business field policy остаётся canonical register. Add optional sensitive completion hook after submit_department, before commit. Keep email lock through actual commit.
- [ ] Native tests: callback sees pending user+one request; thrown callback rolls back user/request/notices. Они впервые выполняются отдельным CI, не выдавать локальные values за DB result.

### Task 2: Atomic Connect signup/receipt/status

**Files:** classes/connect/signup_service.php, entry_service.php, identity_service.php; db/install.xml/upgrade.php/version.php; privacy/provider.php; Python bridge.py, bridge_service.php/bridge_auth.php/connect_bridge.php.

**Interfaces:** Consumes Task1 helpers; locked(callable) holds Connect lock without starting transaction; require_session(...) validates token/browser/key/provider/tenant. Produces signup_service::submit(session,browser,command,form,keyid,provider,tenant,secret):array; ::status(...):array; ::options(...):array.

- [ ] Write native tests before service: one pending creation+link, same/changed replay, collision/no auto-link, wrong browser/expired/revoke, explicit entry snapshot promotion only, private status after real HRD approve/reject. Add signed bridge end-to-end registration+strict unknown fields/field error tests.
- [ ] Implement1metadata table local_ustar_conn_signups with unique identityhash/commandhash, hash-only payload and canonical refs. Fresh/upgrade/repeat definitions candidate2026101902. Completion callback includes link+receipt+entry update.
- [ ] Extend private operation allowlists; fixed validation_failed + allowed field response, no exception input messages; browser endpoint stays disabled. Receipt cleanup/privacy.
- [ ] Run complete local r16/PHP syntax/context; Expected no failures, environment skips explicit. Native DB tests remain pending until exact CI.

### Task 3: Native concurrency, handoff и публикация

**Files:** tests/stage/run-connect.sh, connect_concurrency.php; context entrypoints/state/active/backlog/session/ledger/manifest/index; PR106.

**Interfaces:** Consumes Task2 API; produces isolated native migration/concurrency evidence, separate candidate manifest and exact final CI receipt.

- [ ] Add independent-process same-command race (one create+one replay) and LAN/Connect shared-email race (one canonical account); no test sleeps/hooks в production code.
- [ ] Existing native registration/organization tests included in DB gate because shared canonical writer changed. Unknown legacy handler not executed; no bot sends.
- [ ] Full local tests, personal final diff review, source inventory, update context; commit/publish with expected parent lease81a1be76 and preserve actual Git modes. Main remains unmerged, PR draft.
- [ ] Wait exact Context+Review/connect-db CI; read22previous + new assertions/concurrency result, update PR body with exact SHA/runs. Commands on GitHub duplicated in chat. No runtime activation instructions until UI/edge/receiver gates.

## Следующий срез

Trusted public BFF/HTTPS onboarding form, canonical login/recovery/link, actor/step-up для HRD commands и Telegram notification consumer/receiver ACK. Данный срез — backend signup, не готовая регистрация сотрудника в реальном боте.
