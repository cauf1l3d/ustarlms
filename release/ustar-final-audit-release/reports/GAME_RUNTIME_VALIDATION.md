# USTAR game runtime validation

Дата: 2026-08-23  
Статус: **isolated fix validated / production unchanged**

## CURRENT defect reproduced

Synthetic employee opened active game `id=1`. The question renderer returned:

`https://158-160-29-94.nip.io/pluginfile.php/1/local_ustar/game_question_image/3/question-3.jpg`

The authenticated Academy session runs on another host. Browser result: `complete=true`, `naturalWidth=0`, `naturalHeight=0`; server returned HTTP 303 for the media URL. Production DB contains the same absolute stored URL. This is a host-bound media reference, not a portable Moodle file reference.

The active game `id=2` also had zero active questions but was published as a clickable `0 / 0` card. Opening it cannot produce a playable question.

## Test-only remediation

1. `classes/game_media.php` resolves a question image from Moodle File API and generates a URL from the current `$CFG->wwwroot`; an intentional external URL remains the fallback only when no Moodle-owned file exists.
2. Employee and Game Studio data providers use the same resolver.
3. Employee game catalog omits active shells with zero active questions; those drafts remain available to administrators in Game Studio.
4. Empty-state Russian text was corrected.

Deployment used exact baseline/final SHA-256 guards, isolated path allowlisting, backups, PHP lint, Moodle cache purge and login check. No production file was changed.

## Browser and persistence evidence

| Check | Result |
|-|-|
| Question image after fix | PASS — same-origin `127.0.0.1:18080/pluginfile.php/...`, 1320×1329 decoded |
| Four answer options and submit control | PASS |
| Wrong-answer feedback | PASS — `+0 XP`, attempt persisted |
| First correct answer | PASS — `+25 XP`, total Game XP 25 |
| Persistence | PASS — 2 attempts, 1 mastery row, mastery XP sum 25 |
| USCOIN posting | PASS — one ledger row, amount 5, key `game_mastery:92:3` |
| Replay constraints | PASS at database contract level — unique `(userid, questionid)` mastery index and unique `idempotencykey` ledger index |
| Empty active game card | PASS — no `0 / 0` dead card after isolated filter; one playable game remains |
| Responsive rendering | PASS — no horizontal overflow on checked game list/runtime viewport |
| Synthetic cleanup | PASS — credentials randomised and sessions revoked after validation |

## Remaining TARGET/business gates

- Game `id=1` is titled «Угадай инструмент», while current question content asks «Кто это?»; content ownership and editorial QA are not resolved.
- XP automatically posts USCOIN on first mastery. The code is idempotent, but `XP → USCOIN` remains a CURRENT / TEST IMPLEMENTATION conflict until the economy owner approves the TARGET rule.
- One wrong and one correct flow do not replace concurrency/load, accessibility, multi-browser or all-role privacy tests.
- Production deployment requires separate confirmation and a fresh release/rollback gate.
