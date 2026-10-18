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
