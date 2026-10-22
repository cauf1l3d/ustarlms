# CON-02A.2 implementation progress —22Oct

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
