# SDD ledger — plan: context/proposals/INF02_APP_HANDOFF_PLAN_20261024.md

Execution: existing linked worktree, self implementation/author review,0subagents.
Base:3958939bafd765fae4cd252a7a97a4e28c4cdc9f. Existing owner authorization applies.

Pre-flight Task1→Task2: accepted owner role/controller/definer metadata defines the
allowed public scope; exact ACL/schema/type/extension checks remain automatic pre-write
guards. No extra owner probe needed. Task2→Task3: generated SQL and ordinary Python
entrypoint reused directly by existing PG fixture and mandatory UID33 runtime test.

Task1: complete — owner24Oct current2300/ACK check and public3479/monitor metadata
recorded in infra_readonly_owner_20261023.json. No new server changes.

Task2: implemented, qualification pending.23focused tests:22PASS/1local UID33 map skip.
Observed missing module/API RED→GREEN. Archive collision after a wrong reapply password
observed RED→GREEN.7durable checkpoint and3post-COMMIT/rename interruption windows
recover using real files without restoring business data. Exact pinned31MB controller
loaded read-only locally without invoking its CLI. No embedded artifact reconstruction.

Task3: in progress — existing native SQL fixture/run-connect and strict runtime UID33
test extended. Full production coordinator has not been run by this CI fixture;
the artifact states that limit explicitly. Production apply remains pending.

Ruling: preserve original HTTPS before-image uid/gid/mode and change only current
config.after digest — those fields describe its old HTTP rollback image. Cost if
wrong: HTTPS rollback metadata changes meaning; actual current file is checked separately.

Ruling: keep frozen tool/journals unchanged during cutover, use exact real HTTPS
snapshot verification for the runtime compatibility view — no new blocking wrapper
is installed. Cost if wrong: direct old application restore may stop at protected-config
drift; metadata undo is required before that explicitly destructive old operation.

Ruling: refuse later unknown metadata/HTTPS/catalog changes instead of automatic
rebaseline — preserves administrator edits and stops before outage. Cost if wrong:
a legitimate later TLS renewal may require a reviewed refresh before metadata undo.

Ruling: retain targeted qualification, reuse old14native privilege checks and accepted
owner stands — no new server1 lab or full restore. Cost if wrong: production orchestration
integration not covered by native SQL components remains for the guarded owner apply.

Final review: author-only pass completed; no independent reviewer per owner instructions.
Important recovery gap found: restart=no after a reboot may leave PG stopped before
catalogue validation. New regression observed RED→GREEN; guarded known-file checks
now precede restarting only the exact PG container, before metadata rollback. Unknown
control drift refuses before any restart. This is modeled recovery coverage, not a
claim of a new full-host reboot acceptance.

Full local r16:1106tests/5errors/56skips; all5 failures are outside new handoff tests.
Three missing-MCP imports and two legacy shared-clone commit errors caused by missing
promisor blobs are listed in infra_app_handoff_local_checks_20261024.json. Minimal
empty Git commit alone passes; legacy builder retries reproduce missing-object errors.
No tests removed or weakened. Final focused31tests:30PASS/1UID33 namespace skip.

Publication: git push was twice rejected by automatic approval review. Authenticated
GitHub profile232103065 matches PR106 owner and the exact user-provided repo URL;
second review still required trusted user authorization. Do not bypass by a connector,
proxy or alternate write path. No remote update/new CI run is claimed. Ask for explicit
permission to publish prepared commits to existing design/ustar-connect-20261018 only.
Production commands remain gated on targeted native/UID33 CI qualification.


## 24Oct — owner explicit publication approval and connected GitHub publication

Owner replied «да разрешаю» to the exact existing-branch publication/CI question.
Git push then reached the technical credential error (could not read Username), not
an approval rejection. Connected GitHub published e634672c with tree db013f8978a008f5eceb033735c6575e2bc417eb,
identical to prepared94d68e4. Ref moved from3958939b with an expected-head lease;
no force/main merge. Historical rejection instructions no longer block this authorized write.
Review40105702762: both PHP1108tests/155environment skips, browser/runtime PASS;
mandatory root UID33 test passed1/1 without skips. Context40105702723 PASS.
Native120907521149 still executing; no new SQL PASS or production release claim yet.


## 24Oct — first native RED and OID JSON boundary fix published

Review40105702762 native120907521149 passed prior158/830 and all14ordinary-role
checks, then new script refused profile_role_OIDs before custom monitor setup or
new ownership changes. oid serializes as JSON string; local integer fixture hid the
wire-format mismatch. Added explicit bigint casts in SQL metadata/ACLs/memberships,
SCRAM receipt and native role query. Added native raw_oid:string/cast_bigint:number
characterization; do not weaken strict identity checks by guessing conversions.
Native RED observed;31focused tests/1local skip remain GREEN.

Published fix7e35795f (tree1c26d77e matches local1c2586e), parent e634672c, leased ref.
Review40107134974/Context40107134982; latter SUCCESS. Native retry pending.
Failed-run artifact12564911695/769547B downloaded and SHA2c595380 verified; selected
14check proof PASS, no new handoff receipt yet, no SQL/dumps/env in uploaded inventory.
Production unchanged; no role/OID/manual stand repetition or new permission loop.


## 24Oct — qualification GREEN and sanitized evidence verified

Code7e35795f, Review40107134974/Context40107134982 SUCCESS. Both PHP1108/155skips,
browser/runtime and actual UID33 read/no-write/no-unlink1/1 PASS. Native120912015950:
158/830 before role handoff, then old14privilege checks and new9handoff checks PASS.
Generated SQL app→bootstrap→app, exposed-definer/member/stale-inventory refusals,
100USTAR-schema/selected data retention and actual PHP literal decoding verified.
Raw OID JSON:string vs explicit bigint:number assertion confirms root cause and fix.
Artifact12565387807/771320B/SHA63a89d62 downloaded/hash-verified;133files, no SQL/dumps/
env/private images. Exact new and old JSON verified; qualified operator blob8906e373
matches fetched immutable GitHub source and delivered fe7c7d9b SHA256.
Task2/source and Task3/targeted qualification complete; owner --check/deployment pending.
Author review,0subagents; full production coordinator execution is NOT claimed.
