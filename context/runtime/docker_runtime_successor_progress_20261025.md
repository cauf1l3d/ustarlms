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

Checkpointc1f194b2: Context40244874320SUCCESS; runtime121324212553 SUCCESS,
42+7+4=53/no skips, real age readback/corruption and Unix/UID boundaries PASS; both
source jobs SUCCESS. Callback fixture passed with the added diagnostic, no prior
failure cause proved and no worker changes. Native121325094130 progressed past
fresh PG SQL and refused runtime_clean_stop_failed. Treat configured StopSignal,
not a hard-coded SIGTERM exit: regression130/SIGINT RED→GREEN, forced kill137,
segfault139 and OOM still refuse. Native now preserves bounded stop metadata.
Existing full app checks preceded that delta and passed; no actual rotation PASS yet.

Author review found and tested three concrete compatibility gaps before delivery:
retained-file rename interrupted before chown/chmod RED→GREEN (persist target intent,
finish exact inode/hash private metadata on retry); legitimate later HTTPS renewal
before explicit runtime recovery RED→GREEN (verify current owner/authority and bind
static controls, allow its authenticated leaf/vhost/state generation); HDD scheduler
also pins producer SHA. Exact scheduler sourcea97039a8/root loader tests2RED→GREEN,
patch only PRODUCER_SHA to the new producer digest, verify loader and runtime pins.
Include scheduler source and its recovery service in this handoff/policy restoration.
Plan's original two-image-only consumer assumption was incomplete; now explicit.
No production artifact had been delivered, no production state changed.

Current local58tests/6environment skips. Native also calls the actual a604 producer
validate_live against successor metadata, and uses the actual scheduler loader.
Moved this isolated Docker delta directly after the same job's image build/pinned PG
pull, before the unchanged full app checks: faster failure feedback without a new
stand or waiving the final app gates. Uses documented Compose local project-service
build tag when no image field is present. Final native qualification still pending.

26Oct native checkpoint: head3ae31378 root/source/context passed; native job121330283196 refused forced exit137 after45s in PHP CLI fixture and recovery predecessor_configuration_drift. Fixture now uses Docker init to forward signals/reap PID1; production clean-stop guard unchanged. Recovery reason split into process/labels/mounts without relaxing equality.58 local tests pass/6environment skips. Qualification remains pending; no owner apply.

26Oct recovery equality regression: reordered two identical bind records reproduced predecessor_mounts_drift locally (RED); canonical comparison of full records passes and changed Source still refuses (GREEN). No fields omitted. Latest58tests/6local skips;3ae rootCI58/no skips including real age, UnixAPI and UID1000/33. Native qualification pending.

26Oct checkpoint4a3738cd: Context and both source PHP8.2/8.3 and root runtime
passed (Review40259358327). Native121369810235 passed clean stop/snapshots/archive,
then refused compose_environment_interpolation_changed; automatic recovery reached
network reconnect and refused generic Docker HTTP. No production access. Dollar
comparison now accounts for Compose canonical $$ encoding; RED→GREEN fixture
preserves literal single/double dollars and rejects changes. Same mount-order fix
applied to production preflight, RED→GREEN with altered Source refusal. API errors
now expose bounded HTTP status/explicit-subnet code without body values.60focused
local tests pass/6environment skips. These changes need native qualification.
Production network/IPAM/version metadata is missing and needed to choose a safe
network handoff; do not assume stand refusal is a production incident, or create/
replace a production network to make the test pass. No deliverable/apply until
network handoff and recovery are proved. Existing user source/DB/code receipts
remain accepted. Draft owner runbook explicitly NOT QUALIFIED.
