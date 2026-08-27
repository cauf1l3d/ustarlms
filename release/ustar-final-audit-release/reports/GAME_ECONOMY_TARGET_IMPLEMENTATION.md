# Game Economy — TARGET implementation checkpoint

Статус: **isolated candidate only; CURRENT данные не переименованы в бизнес-эталон**.

## Implemented

- Competition score отделён от spendable USCOIN.
- Competition создаётся как сезон с периодом, фиксированной department-аудиторией и immutable rule version.
- Публикация фиксирует participant snapshot; новые переводы не меняют историческую сравнимую группу.
- В participant-facing рейтинге используются псевдонимы, не ФИО/должности/балансы.
- Ничьи получают shared place; закрытие сезона сохраняет immutable results.
- USCOIN получил locked per-user balance projection, idempotency key, atomic debit и запрет отрицательного остатка.
- Reversal сохраняется отдельной положительной ledger-записью с `reversalofid`; операторская корректировка требует accountable actor.
- Старый `sync_uscoin` переведён в report-only режим: обучение/игры не чеканят USCOIN скрытым побочным эффектом.

## Source of truth

- `local_ustar_comp_score_events` — append-only competition points.
- `local_ustar_comp_results` — закрытый результат сезона.
- `local_ustar_coin_ledger` — полный USCOIN audit history.
- `local_ustar_coin_balance` — locked projection, проверяемая ledger-суммой.
- `local_ustar_comp_rules` — версия правил, выбранная при публикации.

## Remaining gate

Изолированный runtime probe должен подтвердить migration, duplicate/idempotency, overspend, reversal, pseudonymous audience, shared place, close и baseline rollback. Production не менялся; XP→USCOIN автоматически не считается TARGET-правилом.

Локальная фиксация: `8e55778` (checkpoint `fe40e5b`). Push review-ветки в GitHub из этой среды не выполнен: Git credential helper вернул `SEC_E_NO_CREDENTIALS`; production release не запускался.
