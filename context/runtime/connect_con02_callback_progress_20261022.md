# CON-02A.2 implementation progress —22Oct

**Final source checkpoint —22Oct:** A.2 source534764821c4f65437ab50b3c7f25578d538e9504
is CI verified, not deployed. Context39782650830/Review39782650814 SUCCESS;
154native/800assertions,3callback races, actual2100→2200/repeat/paired restore,
v1/v2 wire and UID33/systemd/private TLS handback PASS. Exact final evidence:
[report](connect_con02_callback_20261022.md), [receipt](connect_con02_callback_ci_20261022.json).
The entries below are dated execution history; earlier pending/RED states are superseded.

Owner approved design, then the written9-task plan, then «начинай выполнение».
Self implementation/author review,0subagents. All product source is written;
**final native/runtime GREEN is still pending**, not production acceptance.
Current inventory: local_ustar2026102200/theme0202,557files/100tables.

- Task4 typed transport6RED→GREEN; Task5 sender5RED→GREEN;
  Task6 parser/journal/handler12RED→GREEN; Task7 config5RED→GREEN.
  Whole local974r16/54environment skips PASS before PHP became available.
- Native schema RED f02e5bba/job119744993678:140tests/687assertions,
  exactly2 missing-table failures;138 old tests passed. New DDL then implemented.
- Service RED eb720b1b/job119752033195:146tests/704assertions,
  six missing-service failures, plus two test assumptions corrected: driver
  context IDs need integer comparison; repeat migration must pass actual DB version.
- Router RED987f6828/job119764694424:150tests/756assertions; missing begin_v2,
  two forbidden ACK routes, and one capability test removing a redundant grant.
  Explicit CAP_PROHIBIT now proves current denial, like A.1; authenticated-user
  default permission is not silently removed by unassign_capability.
  New scoped routes and shared transactional v2 begin implemented after that RED.
- Source987f6828 Context39760456252/sourcePHP8.2+8.3/old runtime SUCCESS.
  Earlier36b source guard correctly rejected new policy copied into a frozen
  legacy package fixture. Positive fixture now uses exact reviewed38 policy;
  new negative retains rejection of2200. No frozen hash/guard weakened.
- Actual2100→2200/repeat/history/paired restore, PHP↔Python v1/v2/HMAC wire,
  callback/native/read/revoke barriers, and actual UID33/systemd/LoadCredential/
  private TLS/same-journal old→new→disabled→a86 fixtures integrated. Still pending
  completed GREEN; synthetic Telegram is separate from actual Moodle dispatch.
- New host fixture refusals were test setup: sudo did not preserve CI guard,
  reset-failed named an unloaded service, then frozen profile validation refused.
  Explicit CI environment/direct start added. Metadata-only cdff diagnosis proves
  profile/token valid, state UID33/GID33 but mode0755; fixture now explicitly sets
  StateDirectoryMode0700. Private-file guard is preserved.
  Archive construction moved before sudo; root only validates exact package bytes.
- Author review found an existing privacy user discovery API mismatch extended by
  the new table: two add_from_sql calls omitted the required array parameter.
  New native user discovery/bulk/context erase regression written before fix.
  Pinned official Moodle cc0bf17d userlist/base + actual isolated PHP8.3.6 reproduce
  ArgumentCountError255, then PASS after adding empty parameter arrays; DB adapter
  in that local contract is synthetic, not native PostgreSQL. Actual native
  discovery/erase regression remains a final CI gate.
- Local isolated PHP CLI downloaded from official Ubuntu security index, SHA checked,
  extracted without a host package install. Product/fixture PHP syntax PASS.
  First full PHP-enabled974/35skips run found4 missing-PDO environment failures;
  private PDO/sqlite dependency setup and repeat are pending. No PHP unit PASS claim.

Decisions: reuse existing dedicated checkout by repo rule; unavailable skill helper
scripts replaced by equivalent private briefs/ledger. Independent Python tasks ran
while native RED ran. ua1 already names actor-read: ACK has its own hash domain/table,
with real cross-purpose denial tests. Explicit per-service probe config paths added.
A native migration repeat test now uses DB version without removing downgrade guard.
Author-only review follows repository no-subagents instruction; no independent review.

Server1 unchanged: application2000 plus accepted single-file policy overlay and worker
/tool a86. UID130 last revoked; accepted one-user Tasks pilot not repeated. Manual
live group acceptance deferred until full launch, not passed. No agent SSH or provider
messages. No guarded2100/2200 deployment installer exists in this source slice.
Next after final source acceptance: separate guarded deployment preserving fresh pair,
cron target and HTTPS ownership/renewal; product B routing/preferences then C Bitrix
(actual portal/API unknown). MiniApp/SSO/role actions remain separate.

## Resume from shared chat and PR head —22Oct

Shared chat6ada0312 read through its last messages, including local974/35skips and
actual systemd success. PR f8c12767 source8.2/source8.3/runtime SUCCESS, context
SUCCESS; native154tests/796assertions had exactly one failure in the capability
revocation test. The last agent stopped before seeing this failure.

Root cause confirmed against pinned Moodle cc0bf17d public/lib/accesslib.php:
accesslib_clear_all_caches resets ACCESSLIB_PRIVATE but explicitly leaves USER
unchanged. The callback test retained recipient USER->access after CAP_PROHIBIT.
A fresh cookieless worker request now setsUser(null) and explicitly asserts current
has_capability is false before asserting ACK denial. Production guard unchanged.
Source496b79ea under CI; generated inventory update follows in the same resume.
Author-only continuation,0subagents. No repeated owner pilot/group tests or server
changes; native/privacy/lifecycle/wire/races final GREEN still pending.

Native737e483c/job119833914164 remained154tests/796assertions with one failure, now
at the explicit has_capability premise. This exposed the full setup cause:
Moodle assign_capability defaults overwrite=false, and this fixture role already
has CAP_ALLOW. CAP_PROHIBIT therefore was never written. The corrected test passes
overwrite=true, asserts the DB permission is CAP_PROHIBIT, then uses a fresh
cookieless worker and asserts has_capability=false before ACK denial. Production
checks are unchanged. The earlier cache-only diagnosis was incomplete.
Source PHP8.2/8.3 each974/152skips, Chromium5ACK widths and actual callback systemd
fixture passed737; native integration remains blocked until the corrected run.

## Final acceptance evidence —22Oct

Corrected source53476482 now passes154native/800assertions on actual
Moodle5.1.8/PHP8.3.33/PostgreSQL16.15, job119842668543. Capability loss is established
by stored CAP_PROHIBIT and a fresh worker's current permission check before ACK denial.
No production guard was weakened. Privacy discovery/erase/prune regressions pass.
New callback wire v1/v2,3 PG races and2100→2200/repeat/paired restore PASS;
actual schemas99→100→100→99 verified from downloaded JSON. Prior native A.1 and
frozen a86 gates remain green. Actual runtime UID33/systemd/credentials/private TLS
handoff PASS separately with synthetic provider/store, schema1/cursor104.

PHP8.2/source job119841055292 and PHP8.3/source119841057246 each974tests/152explicit
environment skips; mobile60/ACK5/onboarding20/link28/actor100 Chromium scenarios PASS.
The first8.2 mobile profile-image readiness check failed after its fixed100ms wait;
failed-jobs-only rerun on exact same source passed. No product/fixture edit for that.
Context39782650830 and Review39782650814 SUCCESS; historical FullRC jobs skipped by
label condition, not passed. Source ZIP12427453205 and557 addon hashes, runtime
ZIP12427960385 and native ZIP12428312678 verified.84 local worker/stage/workflow
files match the published source. This continuation also passed93 focused Python
checks/0skips; the prior agent's974/35skips local run was not rerun in this environment.

All9 plan tasks are complete as a source slice. Final documentation commits preserve
validated product trees. No server deployment, live Telegram/group test, new recipient,
agent SSH or main merge. Next is the separate guarded release/fresh pair/cron+HTTPS
ownership handoff; accepted pilot stays closed, UID130 last revoked. Then B/C as scoped.
