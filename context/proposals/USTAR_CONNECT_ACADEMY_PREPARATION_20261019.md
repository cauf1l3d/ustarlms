# Connect Academy preparation Implementation Plan

> Inline execution with superpowers:executing-plans. Owner forbids subagents. Existing PR106 checkout; no main merge. Preparation follows the approved Connect RFC and receiver launch, not a new authentication architecture.

**Goal:** Measure the current production plugin/theme and schema baseline before preparing the existing Connect candidate for installation.
**Architecture:** A deterministic Python3.12 stdlib zipapp bundles the exact accepted/candidate source references and existing read-only file helpers. It only inventories USTAR sources, statically parses DB prefix, reads bounded PostgreSQL metadata in BEGIN READ ONLY, inspects fixed container mounts and reads existing backup receipt metadata. It never bootstraps PHP/Moodle, performs migration, changes services or enables bridge/notifications.
**Spec:** USTAR_CONNECT_RFC_20261018.md sections3/4/6/10; USTAR_CONNECT_RUNTIME_PLAN_20261019.md limits and post-launch bridge/links prerequisites; owner19Oct18:59MSK confirms receiver works and requests continuation. Mini App remains deferred until HTTPS.
**Tech:** Python3.12 stdlib, existing production_manifest.py and production_patch_readiness.py guarded read helpers, fixed docker exec psql metadata only.

## Global constraints
- server1 root /opt/ustar/data/moodle/public; select exactly one local/ustar + theme/ustar web root from that root or its public child. Expected Moodle518 layout is the public child, do not guess or rewrite it.
- Accepted addon baseline e1d57f5b526files/93tables/versions2026100204+2026100202; candidate actual Git tree552/99/local2026101905. Do not label candidate deployed based on CI.
- No token/key/password/config contents, user records or private messages in output. Source paths+hashes and version/table metadata only.
- Default plan performs no host commands. --check explicit, root, fixed scope, bounded commands and deadline. No package installs, service changes, source/config/DB writes, decrypt, restore, public listener, routes/DNS/Xray changes.
- Backup receipt is evidence of recorded success only; archive content, restore and RTO remain unverified.

## Review focus
1. Nested Moodle public layout vs ambiguous duplicate trees; refuse ambiguous and symlink paths.
2. File drift or source/database version mismatch; report discrepancy, never repair or silently approve deployment.
3. Dynamic/malicious prefix or DB output containing extra fields; fixed SQL and strict whitelists, no private stderr.
4. Partial source scan, missing DB/backup metadata; unknown stays unknown, not baseline PASS.
5. ZIP built from dirty working tree or stale reference; exact Git blobs, deterministic hashes, packaged reference schema and native limitations explicit.

## Task1: source/runtime metadata probe and deterministic bundle
Files: scripts/infra/connect_readiness.py, scripts/infra/build_connect_readiness.py, tests/r16/test_connect_readiness.py. Bundle existing scripts/production_manifest.py + scripts/infra/production_patch_readiness.py and exact Git references.
Interfaces: build(commit,output)->Path; check(reference,code,runner,backup_state)->dict; main(argv)->exit code. Output per-section status, baseline/candidate counts/delta, disk/DB version pairs, current table comparisons, container mount checks, SSD space, backup receipt summary and explicit deployment gates.
- [x] Write failing real-files tests for layout/drift/no config leak; readonly SQL/prefix/errors; default no host access; deterministic offline bundle.
- [x] Observe missing module RED, implement minimal probe and builder, verify targeted tests and full r16.
- [ ] Review command side effects and artifact source contents; run packaged plan/help smoke, context/diff; record local vs native limits.

## Task2: operator collection and context
Files: context/runtime/connect_receiver_server1_20261019.json; new Academy preflight runbook/receipt; AGENTS/START_HERE/project/STATE/ACTIVE/BACKLOG/SESSION_LOG/RELEASE_LEDGER/index; PR106.
- [x] Record owner text confirmation18:59MSK. Screenshots not read; do not invent PID/uptime/logs or boot acceptance.
- [ ] Publish hash-pinned bundle and commands for owner --check. Installation does not begin until fresh facts, complete RC/schema/rollback and paired backup acceptance.
- [ ] Follow-up order: candidate release/lab+backup, private bridge/scoped keys, account linking + controlled notification activation; Mini App/external HTTPS and native handoff/step-up/role screens remain explicit later gates.

No architecture or permissions are expanded by this preparation. Working receiver remains on b01bd942; tty/GC source patches do not imply its archive was replaced. Reviews performed personally per owner no-agent rule.

Task1 implementation verification:13tests PASS, full708r16 OK/30environment skips. Missing probe/builder RED and safe diagnostic-reason RED observed before code. Actual source reference validation:526/93 baseline,552/99 candidate,515matching/11changed/26new/0removed. No Moodle source/schema changes in this preparation. Exact release build/source checksum and owner collection follow publication.
