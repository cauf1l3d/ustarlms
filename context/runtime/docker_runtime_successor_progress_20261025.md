# Ledger — plan: context/proposals/DOCKER_RUNTIME_SUCCESSOR_PLAN_20261025.md

25Oct2026. Current stage Task1 contracts implemented; Task2 transaction boundary in progress; no qualified host or owner apply.
Existing feature worktree verified (.git/worktrees/ustar-pr106-con02), main unchanged.
Owner classification accepted85491712B/2663paths; no more grouping probes.

Pre-flight interfaces: pure snapshot/profile contracts feed Journal/Host; Host creates
only same-source snapshots; transaction compares actual successors before starting;
consumer patcher accepts typed image literal maps only. No arbitrary global rewrite.

Ruling: retain predecessor containers/images/logs and publish a new runtime baseline,
not rewrite old controller pins/journals — permits rollback while source state is
explicit. Cost: predecessor manual controllers are historical; current lifecycle
recovery is required, full-host cold profile handoff remains open.
Ruling: snapshot current stopped runtimes through native Docker commit, with explicit
new image IDs — preserves writable-layer metadata instead of guessing packages or
rebuilding. Cost: canonical Compose uses local snapshot digests and distinct project;
future image changes need a qualified release, not old build defaults.
Ruling: fresh existing HDD logical backup plus encrypted runtime images; no duplicate
full physical pair/restore stand for a runtime-only transition. Cost: external/whole-
host DR remains unknown, as owner explicitly requested; no new DR PASS claimed.
Ruling: author self-review only — owner forbids agents; no independent-review claim.

Pinned backup engine was privately materialized from exact Library source and matches
0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f (13689B).
Its preflight/save use actual inspected image IDs. Repo HDD producera604fbcd matches
the accepted installed source. Library cronfe94ac6c (23776B,v2) is NOT activev3;
current cron must be verified against the frozen current app journal after-image.
Ordinary HTTPS renew only compares runtime ID in transaction=True verification;
source/state/leaf need not be normalized blindly for ordinary renewal.

## Current implementation checkpoint

Task1: initial9 tests RED (missing contract) →9GREEN. Task2 file primitives:5expected
missing-store assertions RED →GREEN;3 later regression cases found read-created
store/parent-race/Compose-dollar gaps, then fixed.5 contract edge tests exposed
missing image-config/default/network checks and were fixed. Docker API boundary
4missing-interface assertions RED; local Unix sockets denied, so4cases await strict
CI, not claimed tested locally. Offline CLI2RED →GREEN: default explicit PLAN_ONLY,
any action refuses runtime_transition_not_qualified. Final extra UID1000/33 case
is native verification added after the file implementation, not a test-first claim.
Current module has pure/persistent-file/Unix API boundaries only; the transaction,
production hooks, encrypted archive and owner apply are NOT implemented or qualified.

Local full regression before API tests:1226tests/61skips,5errors. New22tests passed.
Existing test errors (not waived/changed):
- test_connect_bridge_activation.Build.test_exact_bundle_is_repeatable_plan_only_and_rejects_tampering: shared fixture at38d81ef8 lacks old Git blobs.
- test_connect_private_bridge.BuildPackage.test_exact_git_package_is_deterministic_plan_only_and_detects_corruption: same old Git-object availability.
- test_harness.Boundary.test_all_tools_use_reader: mcp absent locally.
- test_harness.Boundary.test_audit_pointer_cannot_escape_boundary: mcp absent locally.
- unittest.loader._FailedTest.test_stdio: mcp absent locally.
A git cat-file metadata check was automatically rejected because lazy lookup could
contact github.com. No workaround/CLI fetch attempted; authorized GitHub connector
read one old blob, no cache rewrite. Use complete authorized CI checkout/dependencies
for global regression rather than changing accepted old tests or claiming local green.

Ruling: reserve existing endpoint IPs explicitly for successor and recovery. This
preserves local console/DB consumers that may use existing IPs; automatic allocation
may become explicit IPAM while addresses/network IDs/aliases remain unchanged. No
external port/SSH/firewall policy is changed. Cost: future network changes need a
qualified profile; source Compose records these exact existing endpoints.

Task2 durable coordinator5expected missing-interface failures RED→GREEN. Failure
injection after every stage returns predecessor without business-data restore;
active repeat verifies only, interrupted intent requires recovery, failed recovery
retains recovery_required/intent. Combined34tests/5local environment skips. Production
host and real Docker transition are still pending, not covered by this fake boundary.
Existing strict root infrastructure CI now includes UnixAPI and UID1000/33 contracts.

Checkpoint98ffdd9c: Context40241333813SUCCESS; Review40241333815 still running.
Actual root job121313568098:34/34/no skips, Unix API server/realUID1000+33 PASS.
Both source jobs121313568177/121313568303 SUCCESS; local missing SDK/Git blobs did
not recur in complete CI. Native Docker transition was not part of that checkpoint.

Further missing runtime API/staging/cron binding tests4RED→GREEN; parked-successor
recovery regression RED→GREEN. Image archive3RED→GREEN (actual age locally skipped);
stream has authenticated readback, no plaintext image staging, original key excluded.
Current local42lifecycle tests/6environment skips; fixed host command/profile boundary
5RED→5GREEN. New controls/native fixture uses the already-built PHP/PG16 images and
adds runtime-only checks, no production/owner full stand. Native Docker pending.
Production frozen guard and CLI orchestration remain unfinished; actions still refuse.
The encrypted runtime recovery set uses two authenticated files (Docker-save stream
plus private control tar) with one receipt; this avoids plaintext images or a custom
chunk/reassembly archive format. Core companion later captures final control state.

Checkpoint98ffdd9c full Review40241333815 completed SUCCESS, including native
Connect/PG16. Later82f8db43 Context40242747014SUCCESS/both source SUCCESS. Its
runtime job121317872541 failed an unchanged callback sender assertion before new
infrastructure steps; first rerun request403(run still active), then single failed-job
rerun accepted after completion. Possible fixture10s clock-boundary race inferred
from source, not proven; no Connect business code/test waiver. Native Docker job
121318736241 reached the new delta after existing app checks and failed first SQL
on newly initialized PG; private stderr was not retained as a public artifact.
Do not claim a proven cause. Fresh-fixture readiness now waits TCP127.0.0.1 to avoid
the entrypoint's temporary Unix-only server. Production PG uses existing bind data.
New native failure output is bounded stage/type/reason codes, no private commands.

Production adapter now reuses exact installed6447code guard/976a DB reader/6f77
frozen bundle and verifieda604HDD hardware reader. Original primitive journal has
only Id/Image(+runtime metadata), not Mounts: regression RED→GREEN, use its actual
frozen bind reader instead of inventing a missing field. Interrupted control-write
recovery RED→GREEN, acquires cold lock and pauses units without rereading transient
files before restoring them. Current49boundary tests +4builder tests=53/6local skips.
Ordinary.py builder4RED→GREEN; exact module hashes, default offline, tamper refusal,
no-ack and non-echoing argument guards. Fixed production check/apply/recover/status
implemented; source is STILL unqualified and no owner artifact/delivery yet.
Native encrypted image pair, true rotation and actual data-preserving recovery must
pass on the final source before delivery. Private current control after-bytes are
captured under the same child scope so the existing companion includes actual
successor Compose/cron/HDD/installation contents as well as original inodes/bytes.

Single old runtime-job rerun121321578281 failed the same v2 sender assertion. No
additional blind rerun. Added only bounded phase/status/provider-call diagnostic to
the existing callback fixture assertion; its behavior/expected result and worker/
provider/business code stay unchanged. This is for determining the failure, not
claiming its cause or weakening it.53local focused tests/6environment skips retained.
