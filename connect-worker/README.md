# USTAR Connect — начало разработки

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
