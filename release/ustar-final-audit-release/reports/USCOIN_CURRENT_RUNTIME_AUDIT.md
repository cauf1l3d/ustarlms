# USCOIN — CURRENT isolated runtime and abuse audit

Date: 2026-08-23

Status: **CURRENT / TEST IMPLEMENTATION — NOT TARGET, NOT PRODUCTION**

Constitution scope: B049–B053 and the related fairness/anti-manipulation requirements B078–B085.

## What exists now

- `local_ustar_coin_ledger` is an append-oriented transaction table with user, signed amount, type, source, globally unique idempotency key, comment, optional actor and timestamp.
- `economy::post()` rejects zero user, zero amount, blank key and a duplicate key. The database has a unique index on `idempotencykey`, and the code handles the concurrent unique-key race.
- Course sync hard-codes `+50`; first game mastery hard-codes `max(1, floor(XP / 5))`.
- `adjust_uscoin.php` can post a signed manual correction with a reason and unique key.
- The employee UI shows balance, earned, spent and the latest amount/comment/date rows.
- Leaderboard score is XP-based rather than spendable balance, although coin balance is disclosed next to each person.

## Isolated data snapshot

Before probes, the loopback isolated restore contained:

| Fact | Value |
|---|---:|
| Ledger rows | 8 |
| Distinct idempotency keys | 8 |
| Total amount | +40 |
| Negative rows | 0 |
| Rows with null actor | 0 |
| Transaction types | `game_mastery` only |
| Store/shop/purchase/order/redemption tables | 0 |

`sync_uscoin.php --dry-run` returned `Pending awards: 2`: one completed-course award and one historical game-mastery award were not present in the ledger. This means the CURRENT ledger is not a complete automatic projection unless the separate sync command is run.

## Abuse and consistency probes

All probes used only `audit_employee` in the loopback isolated Moodle.

### Duplicate/concurrency delivery

Twelve concurrent invocations submitted the exact same manual idempotency key:

```text
attempts=12
posted=1
duplicates=11
persisted rows=1
persisted amount=1
```

Result: **PASS for duplicate-award containment**. The global unique key and duplicate handling prevented a replay/race multiplier.

Audit caveat: that manual CLI row had `actorid=NULL`. The reason text exists, but the identity of the shell operator is not represented by a USTAR actor record.

### Overspend / negative-balance invariant

A temporary `-9999` manual correction was accepted while the synthetic employee balance was `+5`:

```text
USCOIN_ADJUST=OK
resulting balance=-9994
actorid=NULL
```

Result: **FAIL for a spend/overspend boundary**. `economy::post()` accepts any non-zero signed amount and has no atomic available-balance check. This may be acceptable for a privileged correction ledger, but it is not safe to reuse as a store purchase command.

### Cleanup

Both exact synthetic keys were deleted immediately after their probes. Final verification:

```text
synthetic probe rows=0
audit_employee balance=5
ledger rows=8
ledger total=40
```

No production or real-account transaction was created or changed.

## Missing CURRENT mechanisms

1. No store catalog, SKU, price version, stock, purchase, reservation, fulfilment, cancellation or redemption entity.
2. No first-class reversal link (`reverses_transaction_id`), reversal reason policy or immutable correction chain.
3. No non-negative balance invariant or atomic debit command.
4. Manual CLI corrections do not identify the operator in `actorid`.
5. No approval threshold, dual control or capability contract for high-value corrections.
6. No rate limit, per-event award ceiling, daily cap or anomaly detection.
7. The employee history hides transaction type, source, actor and idempotency/audit reference.
8. Award formulas are hard-coded and mix learning/game mastery with a spendable reward ledger.
9. Historical awards depend on an out-of-band sync command; no scheduled-task ownership or reconciliation alert is defined.

## TARGET decisions required before implementation

- Is USCOIN spendable, redeemable or only a non-cash recognition counter?
- May XP ever convert to USCOIN? If yes: owner, formula version, caps, effective dates and rollback policy.
- Is negative balance forbidden for purchases but allowed for privileged corrections?
- What is the canonical reversal model and who may approve it?
- What products/rewards, price versions, stock/limits, delivery and cancellation lifecycle constitute the store?
- Which roles may see another person's balance or transaction history?
- Which anti-abuse thresholds, alerts and investigation workflow apply?

Until those decisions are approved, the existing ledger is evidence of CURRENT mechanics only and must not be presented as the TARGET economy.
