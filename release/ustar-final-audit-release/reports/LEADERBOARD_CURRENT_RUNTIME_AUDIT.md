# Leaderboard — CURRENT isolated runtime, privacy and fairness audit

Date: 2026-08-23

Status: **CURRENT / TEST IMPLEMENTATION — NOT TARGET, NOT PRODUCTION**

Constitution scope: B054–B057 and B074–B085.

## What exists now

- `/local/ustar/achievements.php` is available to every authenticated participant with the common `local/ustar:use` capability.
- `economy::leaderboard()` calculates a deterministic engagement score rather than a competition score:

```text
XP = completed courses × 100
   + completed course modules × 10
   + game mastery XP
```

- The three UI filters are `Общий`, `Моя команда` and `Этот месяц`.
- `Общий` ranks all active participating employee accounts.
- `Этот месяц` is the current calendar month, not a versioned season. Course-module rows are selected by mutable `timemodified`; course completion uses `timecompleted`; game mastery uses `timecreated`.
- `Моя команда` is calculated after the global top-200 payload is built. It combines the viewer, same-position `org::horizon()` peers and explicit direct reports.
- The page renders the top three, ranks 4–50, the viewer's position, full name, declared position, XP and current USCOIN balance.
- Moodle badges are shown as achievements, but there is no permanent/seasonal achievement classification or separate competition lifecycle.

## Isolated runtime snapshot

The read-only probe used only the loopback isolated Moodle and emitted no real names or IDs.

| Fact | Value |
|---|---:|
| Participating accounts in global payload | 90 |
| Other people disclosed to a synthetic employee | 89 |
| Distinct position names disclosed | 29 |
| Zero-XP rows | 70 |
| Non-zero USCOIN balance rows | 2 |
| Maximum XP | 140 |
| Equal-score groups | 5 |
| People belonging to an equal-score group | 87 |
| Largest equal-score group | 70 |
| Current-month zero-XP rows | 84 |
| Competition/season/league/ranking tables | 0 |
| Explicit reporting rows | 0 |
| Moodle badges / issued badges | 4 / 21 |
| Completed courses / users | 1 / 1 |
| Completed modules / users | 46 / 18 |
| Game mastery rows / users / XP | 9 / 4 / 225 |

The aggregate XP sources therefore contain `100 + 460 + 225 = 785` points across the isolated participant population. This is activity/mastery telemetry, not a separately governed competition ledger.

## Privacy boundary

Result: **FAIL for an approved competition audience**.

A synthetic employee with only the common USTAR access capability received the leaderboard payload for 89 other people. Each row contains:

- full name;
- position name;
- XP;
- current USCOIN balance;
- earned USCOIN total internally, even though the template currently shows balance only.

There is no separate leaderboard-view capability, audience entity, consent/privacy policy, group membership snapshot or field-level disclosure contract. The global employee list is therefore a CURRENT implementation detail, not an approved TARGET audience.

## Tie and rank fairness

Result: **FAIL against B083**.

Runtime contained 87 people in equal-score groups, but every person received a distinct sequential rank. The global comparator breaks equal XP alphabetically by full name. It does not use the time the score was reached, and it cannot represent a shared place.

The team re-sort compares XP only. When XP is equal, PHP `usort()` has no declared stable or business tie-break rule. This makes the displayed order technically incidental.

## Team-scope correctness

Result: **FAIL as a canonical team leaderboard**.

`local_ustar_reporting` contained zero rows, so the isolated `Моя команда` scopes were built entirely from same-position peers:

| Synthetic viewer | Global rank | Team-local rank | Rows in team view | Direct reports |
|---|---:|---:|---:|---:|
| Employee | 10 | 2 | 3 | 0 |
| Retail head | 23 | 1 | 2 | 0 |
| HR | 22 | 2 | 2 | 0 |
| CEO | 21 | 2 | 3 | 0 |

The page passes the global `currentrank` into the team view. For the synthetic employee it therefore says `#10` although the filtered team table places the employee at `#2`.

Because filtering happens only after `leaderboard(..., 200)`, a future team member outside the global first 200 would disappear from the team table. Current isolated population is 90, so this is a proven source boundary rather than a currently triggered omission.

## Season and competition lifecycle

Result: **NOT IMPLEMENTED**.

No first-class table or service exists for:

- competition or season;
- versioned rules before start;
- participant/group snapshot;
- separate competition points;
- entry date and newcomer formula;
- team-size normalisation or personal contribution threshold;
- prize approval;
- close/freeze/archive event;
- correction/reversal history;
- league or comparable cohort;
- published final result.

The `Этот месяц` filter is a calendar-time query over operational completion/mastery tables. It cannot implement B075–B084 historical fairness.

## Score and abuse boundaries

1. Mandatory Moodle learning automatically increases rank. There is no competition rule deciding whether it should count, conflicting with B081.
2. Course completion and its completed modules can both contribute, so the same learning path may produce both the per-course and per-module terms.
3. Positions with different assigned course/module volumes have different scoring opportunities; no comparability or normalisation policy exists.
4. Updating `course_modules_completion.timemodified` can move an existing completion into the current-month query; the month result is not an immutable event snapshot.
5. Game mastery is protected by a unique `(userid, questionid)` row and the editor caps a single question reward at 500 XP. This contains replay of one question but does not constitute a competition-wide award budget, rate limit or anomaly policy.
6. USCOIN spending does not reduce ranking XP, which correctly avoids balance-based rank manipulation, but the UI still discloses spendable balance beside competitors.
7. There is no audit event explaining why a person moved rank or which rule version produced a point.

## Probe integrity and cleanup

- Database mutations: `0`.
- No real name, email or user ID was written to the report or terminal evidence.
- Both temporary `/tmp` probe files and the container copy were removed: `TEMP_CLEANUP=PASS`.
- Post-probe USCOIN baseline remained `8 rows / total +40`.
- Production code, data, accounts, roles and sessions were unchanged.

## TARGET decisions required before implementation

- Is the persistent Academy XP display separate from formal competitions?
- What first-class `Competition`, `Season`, `RuleVersion`, `ParticipantSnapshot`, `ScoreEvent`, `Result` and `Reward` entities are required?
- Which comparable groups/leagues may compete, and how are team size, entry date and transfers normalised?
- Which actions may award separate competition points, with caps and anomaly review?
- What is the exact tie policy and timestamp source?
- Who can create, standardise, approve, launch, close and correct competitions?
- Which identities and fields are visible to an employee, manager, HR/HRD and CEO?
- Should USCOIN balance be hidden from competitor rows?
- How are mandatory learning, games and business results kept from silently affecting HR decisions?

Until these decisions are approved, the current leaderboard is an engagement display only. It must not be labelled as a Constitution-compliant TARGET competition mechanism.
