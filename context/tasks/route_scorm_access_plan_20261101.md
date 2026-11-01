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
- [ ] Add failing pure policy and native course/CM lifecycle cases; observe policy RED.
- [ ] Add policy column and access table with install/upgrade parity; implement strict validated defaults and immutable copying.
- [ ] Repair imported-course evidence; hold only advancement while preserving canonical completion. Test actual native completion and one reward across review/closure.
- [ ] Verify standalone/source/native; commit.

## Task 2: Studio, learner actions, finish and deadline

Files: route_studio.php/template, route.php/template, route_access.php, route_access.js, scorm_launch.php, course_launch.php, scorm_route_status.php, scorm_route_return.js, lib.php, task/reconcile_route_access.php, db/tasks.php, privacy provider/language strings.
Interfaces: Task1 services; own-actor POST with sesskey and exact point/version/window; server-derived deadline.
- [ ] Add failing browser tests for cancel/confirm, finish/revisit and timer refresh.
- [ ] Expose policy fields and state; verify save/reload/override immutability, direct launches and protected old URL paths.
- [ ] Add cron reconciliation, server confirmation and retry-safe generic finish controls for old packages.
- [ ] Verify boundary/native/browser regressions; commit.

## Task 3: guarded owner release and qualification

Files: new route access operator/builder and transaction tests; source-only catalog, infra workflow, current context/manifest.
Interfaces: immutable exact app/tool commits; accepts fixed2900 baseline and installed51332 overlay; preserves worker267 and egress.
- [ ] Add failing transaction cases for unknown baseline, crash/recovery, real owner metadata and cron guard.
- [ ] Implement exact-source deployment, private backup, maintenance/native upgrade, forward recovery and updated cron guard. Never restore business DB automatically.
- [ ] Commit/push candidate and PR; run applicable source/context/native/infra on exact release.
- [ ] Compare CI ZIP CRC/bytes and saved/downloaded artifact; deliver archive and inline commands.

## Execution ledger

Owner approved design and existing independent inline execution. No further plan approval loop: user instruction takes precedence over skill handoff suggestions. Final review performed by author because owner forbids subagents. Runtime unchanged at plan creation.

Task1/2 implemented locally; policy RED -> GREEN, completed-package UI RED -> GREEN.
Source lint422PHP/34JS/104tables/58templates passes. Full local browser suite reached
material source fixture but local CLI lacks mbstring; native/source CI remains authoritative.
Full Python run ended without final summary; not recorded as passing. New operator8
contract/transaction tests pass with actualUID33 fixture reserved for mandatory sudo CI.
Ruling: old2900/100 validators run on explicit historical projections and frozen2900
source; current104 schema gets its own real-PG contract and CLI upgrade/repeat checks.
No source merge or production access. Current app757dca12, operator still being qualified.

First CI9ade: console/rollback/new installer with actualUID33 passed; source/context
found root-required ownership fixtures in unprivileged discovery and an old frozen
package test pointed at HEAD. Ruling: root transactions run in mandatory sudo job;
unprivileged discovery skips them, never fakes chown. Frozen overlay test pins51332.
Native fixture now includes its department. FullRC rerun required before delivery.
