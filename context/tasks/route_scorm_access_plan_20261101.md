# Route review access implementation plan

> Execute inline with superpowers:executing-plans; no subagents per owner/AGENTS.

**Goal:** Imported SCORM completion advances correctly and every point can configure review, manual/timed closure and finish navigation.
**Architecture:** Version-bound policy; one small access-window table, separate from canonical completion/progress/reward. Existing Moodle owns SCORM evidence. Server time and protected commands own closure.
**Tech stack:** Existing Moodle PHP/JS/PostgreSQL and stdlib Python operator.
**Spec:** route_scorm_access_20261101.md, approved in chat.

## Global constraints

Preserve published versions, attempt history, single credit/reward and current actor/scope/revision contracts. No subagents/SSH/provider sends/merge. Old archives/operators frozen; inline server instructions. Existing points default to immediate advancement. Timer starts after verified learning, never passes an assessment. Core/app schema upgrades need cron version guard and parity.

## Review focus

- Course requirement must not pass on the first SCO of an arbitrary multi-activity course.
- Old native player/file URLs must observe closure without revoking independent applicable assignments.
- Cancelled confirmation and stale publication/cycle must not close a different window.
- Timer and manual POST racing must produce one closure and one credit.
- Repeated completed SCORM must remain navigable without creating another attempt/award.

## Task 1: policy, completion and server window

Files: classes/route_point_access.php, classes/route_model.php, classes/scorm_route_launch.php, classes/studio_scorm_runtime.php, db/install.xml, db/upgrade.php, version.php; tests/route_point_access_test.php and standalone policy test.
Interfaces: policy(version):array; project(user,point,version,fact,persist):array; advance(user,point,version,window):void; review_url(version):string; allowed_cmid(user,cm):bool|null.
- [x] Add failing pure policy and native course/CM lifecycle cases; observe policy RED.
- [x] Add policy column and access table with install/upgrade parity; implement strict validated defaults and immutable copying.
- [x] Repair imported-course evidence; hold only advancement while preserving canonical completion. Test actual native completion and one reward across review/closure.
- [x] Verify standalone/source/native; commit.

## Task 2: Studio, learner actions, finish and deadline

Files: route_studio.php/template, route.php/template, route_access.php, route_access.js, scorm_launch.php, course_launch.php, scorm_route_status.php, scorm_route_return.js, lib.php, task/reconcile_route_access.php, db/tasks.php, privacy provider/language strings.
Interfaces: Task1 services; own-actor POST with sesskey and exact point/version/window; server-derived deadline.
- [x] Add failing browser tests for cancel/confirm, finish/revisit and timer refresh.
- [x] Expose policy fields and state; verify save/reload/override immutability, direct launches and protected old URL paths.
- [x] Add cron reconciliation, server confirmation and retry-safe generic finish controls for old packages.
- [x] Verify boundary/native/browser regressions; commit.

## Task 3: guarded owner release and qualification

Files: new route access operator/builder and transaction tests; source-only catalog, infra workflow, current context/manifest.
Interfaces: immutable exact app/tool commits; accepts fixed2900 baseline and installed51332 overlay; preserves worker267 and egress.
- [x] Add failing transaction cases for unknown baseline, crash/recovery, real owner metadata and cron guard.
- [x] Implement exact-source deployment, private backup, maintenance/native upgrade, forward recovery and updated cron guard. Never restore business DB automatically.
- [x] Commit/push candidate and PR; run applicable source/context/native/infra on exact release.
- [x] Compare CI ZIP CRC/bytes and saved/downloaded artifact; deliver archive and inline commands.

## Execution ledger

Owner approved design and existing independent inline execution. No further plan approval loop: user instruction takes precedence over skill handoff suggestions. Final review performed by author because owner forbids subagents. Runtime unchanged at plan creation.

Task1/2 implemented locally; policy RED -> GREEN, completed-package UI RED -> GREEN.
Source lint422PHP/34JS/104tables/58templates passes. Full local browser suite reached
material source fixture but local CLI lacks mbstring; native/source CI remains authoritative.
Full Python run ended without final summary; not recorded as passing. New operator8
contract/transaction tests pass with actualUID33 fixture reserved for mandatory sudo CI.
Ruling: old2900/100 validators run on explicit historical projections and frozen2900
source; current104 schema gets its own real-PG contract and CLI upgrade/repeat checks.
No source merge or production access. Current appb9f170d8, operator still being qualified.

First CI9ade: console/rollback/new installer with actualUID33 passed; source/context
found root-required ownership fixtures in unprivileged discovery and an old frozen
package test pointed at HEAD. Ruling: root transactions run in mandatory sudo job;
unprivileged discovery skips them, never fakes chown. Frozen overlay test pins51332.
Native fixture now includes its department. FullRC rerun required before delivery.

Final review: self-review, no subagents per owner/AGENTS. The review found the
finally handler replaced the intended15s idle polling with1.2s on every response.
Important fix: idle SCORM polling RED1200!=15000 -> GREEN15s; acknowledged finish
keeps its short confirmation/retry interval. Run both browser suites and repeat
exact-candidate source/native qualification; the previous8ad archive is superseded.

Final important fix: an explicitly configured automatic point has no access window.
Archiving it could therefore revive its saved native URL. Reproducer RED (null
instead of denied); history now also reads its canonical progress, preserving
legacy unconfigured access and independent grants/cancellation. Standalone
history/policy and both SCORM browser suites GREEN; native archival case added.
Wait for the existing native attempt before publishing the final combined fix,
so any actual fixture or SQL errors are resolved in the same candidate.

Native131: actualPG104 fresh/legacy parity, exact2900->1100 CLI/repeat and
4 SQL denials PASS. Whole suite386/1798 reported one invalid fixture provider
quiz (factory only supports moodle_quiz), one old103 count assertion and one
XMLDB empty CHAR default notice. Use a real native Quiz in the independent
assignment fixture, exact104 expectation in the current schema test, and no
CHAR empty default. Frozen103/100 validators stay unchanged. No business data
rollback or production access occurred; full native rerun must confirm GREEN.

Task1/2 complete: frozen releasef879/appb9f170d8 passed both actual Moodle
suites387/1812, Connect172/875, source PHP8.2/8.3 1509/243, context and infra.
The final host-side old installer probe rejected the one new route task.
Ruling: keep its operator frozen; validate successor1100/task0,0 explicitly and
project only that declared task for this historical contract. Three RED->GREEN
unit cases preserve all other flags; the adapted probe passes exact captured
native SQL snapshots. Application/worker/operator source remains byte-identical
to f879. CI builds are pinned to that same immutable owner archive while the
qualification-only follow-up receives a complete gate. No owner delivery yet.

Task3 complete: FullRC52c30f08 all4CI SUCCESS. Both Moodle suites387/1812;
Connect172/875; PHP8.2/8.3 source1512/243. ActualPG104/upgrade/repeat/4 denials,
10 operator cases with mandatory realUID33/root group, tar CRC/member bytes and
saved downloaded copy PASS. Release archive remains f879 and appb9f170d8;
application/worker/operator trees are identical across qualification.
Finishing follows existing owner option2: PR113 stays open, branch/worktree kept,
no merge or production action. Instructions are inline. Owner apply/acceptance
is the remaining operational step; the2900 observer needs adaptation after1100.
