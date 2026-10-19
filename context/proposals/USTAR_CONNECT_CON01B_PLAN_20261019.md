# CON-01B.1 — постоянные связи каналов в Moodle

19Oct2026, продолжение по owner04:09МСК. Исполнитель работает лично, без субагентов. Предыдущая точка a627f241/PR106: CON01A; Context39173142415 и Review39173142410 SUCCESS. Новый срез использует [review регистрации](USTAR_CONNECT_REVIEW_20261019.md) и [contracts](USTAR_CONNECT_CONTRACTS_20261019.md).

## Цель и граница

Хранить link/challenge/replay в единственной Moodle БД и обеспечить двустороннюю привязку существующего аккаунта, запрет переноса чужой связи и отзыв. Это библиотека facade без публичного endpoint: worker transport authentication, signup bootstrap/session и внешняя форма будут следующими частями CON01B. Нельзя открыть claim_verified как anonymous API. Новые tables не являются копией сотрудников или HR state.

## Решения

- Таблицы local_ustar_conn_links, local_ustar_conn_challenges, local_ustar_conn_proofs. Links сохраняют provider/tenant/subject, userid, state/revision; unique identityhash и userchannelhash обеспечивают одну связь/user/channel и отсутствие silent transfer. Revoked row сохраняет ownership; автоматического переноса нет.
- Challenge:256бит secret uc1_, в БД только hash, TTL600с, userid+provider+tenant и hash текущего Moodle sesskey. Claim требует verified provider tuple и proof_id; Academy confirm из того же user/session завершает связь. Нет выдачи Moodle cookie или роли.
- Confirm повторяется идемпотентно только пока challenge жив и link не отозван; после revoke старый код не оживляет связь. Новая привязка того же владельца требует нового двухстороннего подтверждения. Чужой active/revoked provider или занятый user/channel возвращает conflict.
- Moodle distributed lock сериализует редкие identity mutations. Под lock выполняется delegated transaction; исключения откатывают proof/claim/link вместе. Вызовы должны владеть транзакцией, outer transaction запрещена, чтобы lock не освобождался до commit.
- resolve_active_user всегда заново проверяет user deleted/suspended/confirmed, business account и employment active. Pending может связать свой канал, но resolve не выдаёт employee grant. View-as/login-as не могут менять связи.
- Proof replay фиксируется в БД unique hash; срок не позволяет восстановить свежесть старого proof. Очистка истёкших подтверждений/consumedproof ограничена batch; срок хранения link ownership не ослабляет запрет silent transfer.
- Privacy API описывает/экспортирует metadata и удаляет challenges/proofs; при удалении user data links отзываются и обезличиваются, не оставляя прежнему proof возможность входа. Полное privacy решение проекта за пределами среза, metadata новых таблиц включается здесь.
- XMLDB fresh/upgrade/repeat parity, plugin2026101900; никаких автоматических backfill/send или изменений ролей. Откат после schema upgrade — парная DB/source copy, не downgrade файлов.

## План и доказательства

1. PHPunit DB tests для двухстороннего confirm, wrong user/session/tenant, expiry, proof replay, uniqueness, revoke/relink, pending/terminated/current flags, login-as/view-as и rollback. Сначала получить RED из-за отсутствующего service.
2. Создать classes/connect/identity.php и identity_service.php; schema3tables + upgrade из install.xml + pluginversion. Доказать формат code/hash совместимым с CON01A; никакого service token или пароля в таблицах.
3. Исполнить PHP syntax/local crypto tests и полный r16. В CI добавить отдельный Moodle PostgreSQL gate этого service с fresh/upgrade/repeat и PHPunit; full suite остаётся собственным gate. Результаты real DB и modeled тестов не смешивать.
4. Сохранить immutable production/application baseline e1d57f5b,526files/93tables. Новый development manifest отдельно показывает unmerged source tree + hashes/versions; check_context проверяет каждый candidate byte и не объявляет его deployed.
5. Обновить STATE/ACTIVE/BACKLOG/index/ledger и PR106; main не merge, production не обновлять. В чат продублировать команды разработчика, опубликованные в PR.

## Оставшийся CON01B

Trusted worker→facade verifier (mTLS/assertion, bounded scopes), onboarding sessions/nonce, atomic registration receipt вокруг canonical registration_service, pending link без employee grant, HRD decision/outbox, browser формы и CSRF/rate limits. Только после этих интеграций и внешнего HTTPS bootstrap можно объявить регистрацию первого касания работающей. Нельзя заменить доказательство ручной source-проверкой.
