# Docker runtime successor implementation plan

> For agentic workers: use executing-plans inline. Owner forbids subagents, including reviewers.

**Goal:** qualify one reversible runtime transition that preserves the current writable layers and enables bounded logs.
**Architecture:** one stdlib coordinator reuses pinned installed5067163f private-file primitives, native Docker snapshots and existing backup/cron/HTTPS consumers. Retained old objects and before images support recovery without DB/file rollback.
**Tech stack:** Python3, Docker/Compose, existing age/systemd/PG tooling.
**Spec:** [design](DOCKER_RUNTIME_SUCCESSOR_DESIGN_20261025.md).

## Global constraints

0agents/no agent SSH/provider sends/main merge. No app/schema/business-row mutation,
unknown-drift adoption, registry/build action, container/image/log/archive deletion.
Keep owner SSH/restore/UPS decisions and accepted old stands; new native CI delta only.
CLI production profile fixed; fixture constructors do not become arbitrary host flags.
No production-ready claim before root/native recovery and saved artifact checks pass.

## Review focus

- Crash between a Docker mutation and journal response must resolve only owned generation objects.
- Environment values/credentials/private control bytes must never appear in public output or artifacts.
- Source recovery must preserve original inode and UID1000/0600, not only its bytes.
- HostConfig/mount/process differences outside the accepted logging/health/image/project delta must refuse before start.
- Explicit recovery after successor traffic must preserve business writes and old logs.

## Task1 — pure successor and source-patch contracts

Files: scripts/infra/docker_lifecycle.py; tests/r16/test_docker_lifecycle.py.
Interfaces: patch_images(raw,before,after), snapshot_lineage(parent,child,container,generation,service),
compose_model(containers,images,project,generation,source_model), verify_successor(before,after,images,project,generation).
- [x] Write failing tests for typed literal-only helper patching, malformed/duplicate/computed maps, immutable-image Compose and no secret public errors, executable/Env/bind/HostConfig drift and snapshot lineage.
- [x] Run python3 -m unittest tests.r16.test_docker_lifecycle -v; observe missing implementation failures.
- [x] Implement the smallest pure contracts; rerun all named tests and source checks.

## Task2 — durable reversible transaction

Files: same module/test; progress ledger below.
Interfaces: Journal/private before images and Transaction.check/apply/recover with injected host boundary.
- [ ] Failing real-file tests: source inode parking/recovery, drift refusal, interrupted intent, policy preservation, no business-row/file restore, owned object recovery after each Docker mutation.
- [ ] Implement journal/transaction on pinned private primitives; verify red→green and meaningful failure matrix.

## Task3 — production host boundary

Files: same module/test; scripts/infra/build_docker_lifecycle.py if packaging needs a separate builder.
Interfaces: fixed production Host; local Docker/Compose only, existing backup/cron/HTTPS bindings.
- [ ] Failing tests for source/helper/source-image guards, stdout sanitization, archive authenticated readback and root/UID1000 ownership, separate-key/no-delete scope.
- [ ] Implement native snapshot/retention/compose/start/consumer handoff and idempotent current check/recover.
- [ ] Default PLAN_ONLY performs no host access. Check is read-only, no standby containers or extra owner stand.

## Task4 — existing native CI delta

Files: tests/stage/docker_lifecycle_native.py; existing ustar-review-gate.yml runtime/native steps.
- [ ] Exercise real Docker snapshot/rename/network/current-layer metadata, exact image lineage, actual UID/config access, unchanged DB writes across recovery, same current image snapshots and actual log rotation.
- [ ] Attach only sanitized proof to existing CI artifacts. All raw configs/logs/images remain private and disposable within owned CI fixtures.
- [ ] Run complete existing applicable source/context/runtime/PG gates, inspect results; author self-review (owner0agents override).

## Task5 — owner artifact and receipt

Files: owner runbook/qualification/entrypoints and verified ZIP.
- [ ] Save artifact and verify byte SHA/ZIP CRC/member hashes/extraction/default launch plus saved download, as owner requires.
- [ ] Present check/apply outage-ack and precise old-controller retirement/current recovery scope. Owner executes production, agent never SSHs.
- [ ] After actual receipt, record IDs/images/hashes/data preservation/logging and explicit remaining evidence.

**Ledger:** context/runtime/docker_runtime_successor_progress_20261025.md.
