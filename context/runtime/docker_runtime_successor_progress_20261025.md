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
