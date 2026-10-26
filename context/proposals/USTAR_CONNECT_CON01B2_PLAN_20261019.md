# CON01B.2 — подписанный bridge и сессии первого входа

19Oct2026, owner04:47МСК «ок продолжай». Base5f9aa3fb: Context39185823445/Review39185823458 SUCCESS; native13tests/47assertions и concurrency PASS. Исполнитель лично, без субагентов. Разработка следующего среза прямо разрешена продолжением; merge/deploy не разрешены.

## Контракт

Worker проверяет Telegram initData и передаёт минимальную assertion. Facade не принимает Moodle userid/роль из JSON. Отдельный256бит HMAC service key ограничен provider/tenant/operation; это не Moodle/admin/bot token. Endpoint POST /local/ustar/connect_bridge.php выключен по умолчанию; включение требует явного private key config, TLS и точного peer IP allowlist, без доверия X-Forwarded-*.

Подпись: HMAC-SHA256 по строкам USTAR-CONNECT-BRIDGE-v1, POST, fixed path, key ID, Unix time, random request nonce, SHA256 exact body. Окно30с; nonce хранится в existing conn_proofs до окончания окна. Body≤16KiB, strict canonical JSON/allowlisted fields. Подмена body/op/path/key/provider, replay и неизвестный ключ отвергаются. Fresh service nonce для повтора запроса не даёт повторить бизнес-proof.

Операции этого среза: launch.create, launch.exchange, entry.status, entry.revoke, link.claim. Никаких grade/HR/root/login-as методов. HTTPS worker client проверяет CA/hostname, не следует redirects, не использует ambient proxy; fixed path, timeout10с/response≤16KiB. Secrets не печатаются и не входят в URL.

## Первый вход

1. После private bot event worker получает у facade ticket ul1_256бит, bound к verified provider/tenant/subject, TTL600с. Максимум5живых tickets/identity. Бот будет передавать его как Telegram startapp; отправка сообщений/полный bot loop ещё не входят в срез.
2. Mini App signed initData.start_param обязан содержать этот ticket. Python verifier сохраняет только проверенный start_param; незаверенный query parameter не подменяет его. launch.exchange передаёт verified identity/proof/auth_date и browser secret ub1_256бит из будущего trusted web backend.
3. Facade проверяет target/freshness, атомарно потребляет ticket+provider proof и выдаёт random ue1_256бит entry-session TTL600с, храня только hash токена и browser binding. Ticket одноразовый. Если ответ exchange потерян, старый ticket не оживляет сессию: нужно получить новый запуск. Никаких business writes в exchange нет.
4. Сессия разрешает только entry.status/revoke. Это ещё не Moodle login, не employee/HR capability и не доступ к материалам. Existing link+revision и current user flags читаются заново для статуса. Unlink/privacy erase/блокировка инвалидируют привязанный вход; unlinked entry не может незаметно стать связанной сессией при чужом новом link.
5. Новый launch/session metadata живёт в local_ustar_conn_launches/sessions, с hash и TTL; ссылки/пользователи не дублируются. Privacy/cron покрывают новые metadata. Existing conn_proofs общий для namespaced transport/provider replay, без userid или raw payload.

## План и проверки

- [x] Python/PHP behavioral RED: отсутствующий signer/verifier; затем8bridge tests PASS: cross-language signature fixtures, tamper/time/key/op/duplicates/limits/redirect/TLS defaults. Provider scope — native test pending CI.
- [ ] Native DB tests: одноразовый exchange, target mismatch, replay, wrong browser, expiry, revoke/revision/current actor, no session/role on reject; concurrent exchange даёт только один результат.
- [ ] XMLDB2tables + upgrade/fresh/repeat parity; candidate2026101901, accepted production baseline неизменен.
- [x] Disabled-by-default endpoint и explicit config guidance в connect-worker/README, без production secrets/автоматического открытия порта или изменений сети.
- [ ] Native gate включает все connect_*_test suites и independent concurrency; r16/source/context; обновить candidate manifest и handoff. Сначала review diff лично, затем публикация в existing draft PR106 и ожидание CI.

## Следующий срез

Canonical signup receipt вокруг registration_service::register, pending provider link в одной транзакции, HRD approval/outbox и формы. Формы/публичный edge/real receiver ACK отсутствуют до отдельной интеграции; данный срез не объявляет регистрацию/бота целиком готовыми.

Локальный pre-publication receipt:569r16/19environment skips PASS, PHP8.3 syntax всех plugin/theme PHP PASS, context539files/98tables PASS. Новые native tests ещё не запускались локально (Docker отсутствует); не считать их passing до GitHub connect-db. Личный review проверил scope/TTL/replay и privacy: erasure удаляет также pre-link transient entries по identityhash, но не снимает live proof replay barrier. Предыдущие thirteen native tests не заменяют проверку нового кода. Exact final SHA/CI receipt публикуется в PR106.
