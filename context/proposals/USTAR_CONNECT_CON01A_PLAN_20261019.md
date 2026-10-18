# CON-01A: основание идентичности — план реализации

**Goal:** начать разработку Connect с проверяемого доказательства provider identity и общего формата одноразовых кодов, не открывая незавершённый auth endpoint.

**Architecture:** Python worker проверяет provider proof, Moodle остаётся владельцем аккаунтов/approval. Значение identity не содержит Moodle userid или ролей. Код привязки — одноразовый секрет; хранение, подтверждение и consumption реализуются только в следующем PHP/DB срезе.

**Tech Stack:** Python3.12 standard library; unittest; существующий r16 CI.

**Spec:** [review и onboarding](USTAR_CONNECT_REVIEW_20261019.md), [RFC](USTAR_CONNECT_RFC_20261018.md), [contracts](USTAR_CONNECT_CONTRACTS_20261019.md).

**Execution:** лично в существующем isolated worktree connect-design, без субагентов по прямой инструкции владельца. Начало реализации авторизовано вместе с ревью. Review/implementation не означает merge/deploy.

## Global constraints

- Не менять Moodle/domain/schema/network/production в CON-01A.
- Token provider не является общим cross-provider login secret. No provider names/phones→Moodle matching.
- Минимум256бит randomness для нового кода, uc1_ +43base64url chars; hash-at-rest. TTL600с/atomic consume относится к будущему storage, не изображается реализованным pure helper.
- HMAC launch verifier: fixed300с freshness,30с future tolerance,16KiB UTF-8 bytes,32query fields. All received fields кроме hash входят в HMAC, включая signature если есть.
- Verified proof ещё требует nonce/replay/Academy mapping/current ACL. Не выдавать session или токены входа.

## Review focus

Duplicate decoded keys/JSON id, boolean/float user IDs, подмена tenant/bot, malformed UTF-8/percent escapes и signed oversized/deep JSON должны отклоняться без возврата raw secrets. Stale/future подпись не становится fresh при повторной сериализации. Каждый случай проверяется в tests/r16/test_connect_identity.py.

## Task 1: provider identity и launch proof

Files: connect-worker/ustar_connect/__init__.py, identity.py, telegram.py; tests/r16/test_connect_identity.py.

Interfaces: ProviderIdentity(provider:str, tenant:str, subject:str), key()->str; validate_init_data(raw:str, *, bot_token:str, now:int)->TelegramLaunch(identity, issued_at, proof_id). Только trusted verifier создаёт launch; dataclass не заменяет аутентификацию.

- [x] Написать failing behavioral tests: known signed fixture, tamper, wrong bot, user not receiver, same subject different tenant, timestamps, repeated keys, invalid IDs/JSON/percent/size.
- [x] Запустить unittest discover -s tests/r16 -p test_connect_identity.py, подтвердить отказ из-за отсутствующей реализации.
- [x] Реализовать immutable identity и HMAC parser; ошибки — фиксированные reason codes без raw input/token.
- [x] Прогнать targeted suite, затем полный r16; проверить отсутствие network calls/session grant.

## Task 2: общий код привязки

Files: connect-worker/ustar_connect/link_code.py; tests/r16/test_connect_identity.py; connect-worker/README.md.

Interfaces: issue_code()->str; code_digest(code:str)->str. SHA256 domain separation,256бит random; один формат для всех каналов, но database policy всегда bind+consume.

- [x] Тесты длины, canonical encoding, uniqueness выборки, malformed input и stable digest; проверить RED.
- [x] Реализовать helpers и описать storage/replay ограничения в README.
- [x] Targeted/full tests, context regeneration, diff review; commit+публикация в draft PR106. Не менять main.

## После CON-01A

CON-01B требует своего детального schema/API плана: Moodle integration/locks/transactions, rate limits на provider+tenant+subject и IP edge (не доверять forwarded headers от клиента), challenge consume, current employment и pending access, registration/HRD approval и outbox. Внешняя форма password отправляет данные в Academy facade, не хранит их в Python. Native DB/concurrency tests обязательны до объявления working signup.

## Execution record

Baseline545 r16 tests PASS/19environment skips. RED: отсутствовал пакет ustar_connect; GREEN:11 behavioral tests PASS. Tasks1/2 выполнены единым TDD срезом. Self-review лично, не независимое ревью. Spec/plan approval workflow применён в рамках прямого поручения владельца review→начать разработку; нового разрешения не запрашивали. Публикация/полный regression result фиксируются в PR; server activation отсутствует.

Финальная локальная проверка CON01A:556 r16 tests PASS/19environment skips, включая11новых; CONTEXT_OK526source files/93tables/34tasks. Native registration/DB/provider acceptance не выполнялась.
