# Studio2800 production handoff Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline. AGENTS forbids subagents; owner has authorized push and requested installation instructions. No additional permission gate for preparing a reviewable package.

**Goal:** Deliver a fixed2700→2800 installer and exact owner commands.

**Architecture:** New small successor adapter/builder reuse the accepted transaction and predecessor chain. Freeze all older operators and application4c48dce7, qualify source and package separately from production.

**Tech Stack:** Python stdlib, existing R16 physical-pair/Journal/Locks fixtures and GitHub CI.

**Spec:** `docs/superpowers/specs/2026-10-28-studio-colors-deploy-design.md`

## Global Constraints

- Predecessor01e1cef2/app20ed966e/local2700/theme2700, target4c48dce7/local2800/theme2700.
- 589files/100tables, no schema delta, worker6c56 unchanged; old scripts unchanged.
- No SSH, subagents or automatic merge; read-only refusal on2600/drift.
- New journal `/var/lib/ustar-studio-colors-update`; same shared locks/recovery semantics.

## Review Focus

- Rewritten predecessor cron/journal cannot bless arbitrary live code.
- Current scheduled task policy is retained across predecessor admission and update.
- Process loss after public release preserves newer writes; late restore is explicit.
- Root-owned code denies actual UID33 writes; artifact import ignores sibling modules.
- Old2700 delivery remains reproducible; current application files match qualified4c48dce7.

### Task1: exact reference, cron and package

**Files:** new `scripts/infra/studio_colors_update.py`, `scripts/infra/build_studio_colors_update.py`, `tests/r16/test_studio_colors_update.py`.

**Interfaces:** `reference_from_git(repo,tool_commit)->Bundle`, `validate_reference(ref)`, `patch_cron(raw,record,accepted_sha=...)`, `load_embedded(manifest,sources,assets)`, `build(tool_commit,output,repo=...)`.

- [x] RED `Contract`, `Reference`, `Package`: exact589→589/scoped delta, frozen predecessor, schema/worker denial, strict cron2700→2800, offline flags, deterministic tar/hashes and tamper refusal.
- [x] Implement successor/builder with pinned source and predecessor modules, no old-script edits.
- [x] Run focused contracts/packages GREEN and commit.

### Task2: predecessor and recovery

**Files:** same adapter/test; reuse existing application transaction unchanged.

**Interfaces:** `Host.predecessor()`, `Host.accepted_cron()`, `verify_predecessor_database()`, `make_context(bundle,root=...,uid=...,host=...)`, `check(ctx)`.

- [x] RED/GREEN physical2700pair/journal tests: task policy, source/cron/DB drift, repeat, early failure, actual process loss, late restore, UID33 and runtime admission.
- [x] Separate self-review (subagents forbidden), fix material findings and commit evidence.

### Task3: qualified delivery

**Files:** infra CI artifact/UID33 step, server-console source-only inventory, dated deployment receipt/progress, STATE/ACTIVE/BACKLOG/docs and generated views.

- [ ] Run full R16/source/context/secrets and fixed-component comparisons; push with expected-SHA lease, PR107 stays open.
- [ ] Wait for CI; download2800 artifact, verify ZIP/hash/members and independently rebuilt bytes; save owner archive.
- [ ] Give SHA/extraction/check/systemd-run/status/recover commands and separate source qualification from owner production result.
