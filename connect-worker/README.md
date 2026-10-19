# USTAR Connect — начало разработки

## Текущий CON01B.4 — браузерная регистрация, без внешней установки

Owner19Oct09:55МСК. [План/точный контракт](../context/proposals/USTAR_CONNECT_CON01B4_PLAN_20261019.md), [ledger](../context/proposals/USTAR_CONNECT_CON01B4_PROGRESS_20261019.md). `ustar_connect.onboarding.OnboardingApp(origin, bot_token, bridge, clock=...)` — приватный WSGI callable и allowlisted assets `web/`, **не production listener/установщик**. Не запускать через публичный wsgiref. Bot token и bridge key остаются только у private worker, не edge/браузера/репозитория. UI — отдельная небольшая dependency-free форма, legacy frontend не используется. Более ранние разделы ниже — датированные checkpoints.

Форма проверяет Telegram signed initData на worker и расходует уже выданный ul1_ launch через existing bridge. Нет public launch.create/универсального proxy. Реальный bot receiver, выпускающий персональный launch, и outbound relay ещё не написаны. Поэтому кнопка настоящего бота/внешний доступ данным коммитом не включаются.

`GET /api/bootstrap` выдаёт CSRF с Secure/HttpOnly/SameSite=Strict __Host-cookie; API требует X-Ustar-Connect:1, все POST — exact Origin+CSRF. HTTPS/Host проверяются по trusted WSGI metadata, forwarded headers игнорируются. POST /api/launch принимает только init_data; /api/options и /api/status — {}; /api/register — только6canonical полей; /api/logout закрывает локальную сессию даже при сбое upstream revoke. Service keys/session/browser/command не возвращаются браузеру. TTL120с до launch и максимум600с после; cookie/CSRF ротируются. Не сохраняются password/form/initData в store или web storage.

Временное состояние только в ограниченной памяти одного процесса:500sessions, bootstrap30/min aggregate,60API/min/session,3launch и5submit/session,4upstream slots. Restart/expiry требует нового подтверждённого запуска; неизвестная cookie bootstrap заменяется новой preauth-cookie, не выдаёт старый access. Нет гарантии multi-process/session affinity или достаточной production ёмкости. Canonical30registration/15min не отключён. Продакшен needs отдельный capacity/abuse gate.

ur1_ command выбирает сервер и сохраняет для retries. Нет automatic mutation retries. При обрыве после commit сначала свой статус; при ручном retry пароль вводится повторно, тот же command+exact form возвращает canonical receipt, изменённый payload — conflict. Форма не показывает «уведомление в TG доставлено»: canonical HRD notice и actual Telegram receiver ACK различаются. Approved status не является учебной Moodle session.

Deployment gate: утверждённый внешний HTTPS origin, bounded authenticated outbound-only relay с metadata/header policy, request/body/cookie logging off, private worker UID/secrets, TLS CA checks, single-process operational model/restart, safe error telemetry и ограничения на ingress. Офисные inbound порты/default routes/DNS не меняются. Совместимость cookie в native WebView и Telegram Web iframe ещё проверяется отдельно: Strict cookie может блокироваться third-party browser policy; frame CSP alone не доказывает вход в Web Telegram. CSP разрешает официальный SDK telegram.org и frame origin web.telegram.org; никаких CORS wildcard, unsafe-inline или публичных SSH/пульта/LAN.

Проверки разработки из корня checkout, **не команды установки на server1**:
```bash
python -m unittest discover -s tests/r16 -p test_connect_web.py -v
python -m unittest discover -s tests/r16 -v
node tests/mobile/connect.cjs
python scripts/check_context.py
```
Browser acceptance использует реальные assets и synthetic API: это не живой Telegram, Moodle или доказательство внешней доступности. Exact CI receipt — PR106. Production addon manifest/version2026100204/theme2026100202 и candidate2026101902/542files/99tables не менялись.

CON-01A: offline Python3.12 standard-library primitives, без службы/HTTP API/установщика. Это не готовый бот или работающая регистрация.

- `ProviderIdentity`: стабильный tuple provider/tenant/subject, без Moodle userid/roles. Конструктор не доказывает аутентификацию.
- `validate_init_data`: Telegram HMAC, configured bot tenant, freshness300с/skew30с, bounded parser, signed **user**, не receiver/chat/username. Raw profile/token не возвращается. `proof_id` годится для будущего durable replay index, но пока не расходуется.
- `issue_code`/`code_digest`: общий формат uc1_,256бит randomness и hash-at-rest. Новый код для каждой связи. Это не session token. Moodle должен атомарно хранить/проверять TTL600с, target channel/tenant, proof обеих сторон, consume/revoke и uniqueness; helpers этого не делают.

Публичный edge не получает Bot API token. HMAC verifier размещается в приватном worker; endpoint/nonce/session integration ещё отсутствуют. Подпись подтверждает аккаунт Telegram, не должность/личность сотрудника или факт HRD approval.

Проверки из корня репозитория (команды разработки, не установки на server1):

```bash
python -m unittest discover -s tests/r16 -p test_connect_identity.py -v
python -m unittest discover -s tests/r16 -v
```

[Результаты ревью/регистрация](../context/proposals/USTAR_CONNECT_REVIEW_20261019.md) · [план](../context/proposals/USTAR_CONNECT_CON01A_PLAN_20261019.md).

CON01B.1: внутренний PHP identity_service и3metadata tables. CON01B.2: подписанный worker→facade bridge и2временные metadata tables входа. [План и границы](../context/proposals/USTAR_CONNECT_CON01B2_PLAN_20261019.md). Реальные bot receivers, signup/HRD/forms и публичный edge ещё отсутствуют.

`bridge.sign_request` подписывает canonical JSON отдельным256битным service key. `BridgeClient` допускает только HTTPS fixed path `/local/ustar/connect_bridge.php`, CA+hostname verification, без redirects/ambient proxies/retries. Конфигурация создаётся только runtime, не в Git: `$CFG->ustar_connect_bridge_enabled = true`, `$CFG->ustar_connect_bridge_peers` — exact peer IPs; `$CFG->ustar_connect_bridge_keys` — map key ID → `secret` (64lowercase hex), `provider`, `tenant`, `operations` allowlist. По умолчанию endpoint выключен. Это описание контракта, не команда активации на production. Отдельный loopback/private HTTPS listener должен выставлять серверный HTTPS и exact REMOTE_ADDR; forwarded client headers не считаются доказательством TLS/IP.

Не использовать bot token/Moodle admin token вместо service key и не включать global proxy. Worker service credential доверен утверждать identity своего provider/tenant, но не выбирать Moodle userid/роль. Поэтому key должен храниться private, endpoint ограничиваться private listener/peer, а будущий public backend — проверять signed initData до вызова signer. `telegram_exchange_payload` принимает только проверенный signed start_param; browser secret создаёт trusted backend, не query string. Entry token — не Moodle session:600с, только onboarding status/logout. Запуск/отправка сообщений ещё не реализованы.

Дополнительная локальная проверка из корня репозитория, не из production installation:
```bash
python -m unittest discover -s tests/r16 -p test_connect_bridge.py -v
```

## CON01B.3: canonical signup, ещё без public формы/receiver

Candidate2026101902. Private ops `registration.options`, `registration.submit`, `registration.status` используют ту же browser-bound entry. Submit payload плоский: `browser,command,departmentid,email,firstname,lastname,password,session,username`. `command` — random256бит ur1_, переиспользуется при retry той же exact формы; порядок JSON полей значения не имеет. Не слать пароль в Telegram/URL/logs. Same command с другим form/password → idempotency_conflict, existing email/login → validation_failed с whitelisted field, **не** auto-link. Outcome registered + canonical current registration status не означает employee grant.

Canonical registration_service создаёт user/pending/request/HRD notification, затем до commit и release email lock выполняет internal completion для Connect first link/receipt/own entry. Только отправившая форму entry получает snapshot pending account; остальные старые unlinked entries требуют нового запуска. Receipt хранит keyed HMAC формы, не plaintext или быстрый unkeyed password hash; private service key не хранится в Moodle DB. TTL30дней и privacy/cron, после удаления receipt existing link/email guards всё ещё запрещают дубль. Key rotation может прервать старый command retry; own status остаётся способом увидеть canonical result, повторно регистрировать пользователя нельзя.

Runtime operations allowlist должен быть **явным списком**, не ссылкой на растущую константу всех operations. Не включать registration.submit для недоверенного polling/webhook consumer. Existing30attempts/15min registration throttle сохраняется; private peer — общий bucket, capacity/abuse policy нужно принять до activation. Никаких forwarded IP spoofing или отключения throttle ради нового контура.

HRD решения и native notifications остаются staffing_requests/target_core. Telegram delivery ещё не включён, новые role grants/удалённый HRD actor не создаются. [План и границы](../context/proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md).

Дополнительная команда разработки из корня checkout, не production:
```bash
python -m unittest discover -s tests/r16 -p test_connect_signup.py -v
```
