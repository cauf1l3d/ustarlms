# Studio materials2700 deployment Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline. AGENTS forbids subagents. Owner already authorized execution/push and manual production acceptance.

**Goal:** Deliver a verified, separate2600→2700 archive and exact server commands.

**Architecture:** A fixed adapter reuses the accepted paired application transaction, with a new journal and read-only predecessor verification. The builder embeds exact qualified Git source and frozen dependencies; it never accesses production.

**Tech Stack:** Python3.12 standard library, Git, existing Moodle CLI/transaction primitives, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-28-studio-materials-deploy-design.md`

## Global Constraints

- Baseline2d95137a/local2600/theme0202; target20ed966e/local2700/theme2700.
- Worker6c56 unchanged.589target files,100unchanged tables/fields/indexes.
- Frozen operator62b0 and previous archives remain byte-identical.
- No SSH, provider send, automatic PR merge, Compose or container recreation.
- Runtime journal controls/fence/keys/SQLite/HTTPS and existing source ownership remain guarded.
- Fresh paired snapshot, durable public boundary and explicit late restore flags use accepted engine.

## Review Focus

- Current task flags may differ from23Oct: preserve them while refusing schema drift.
- Both plugin versions change: cron patch and repeated DB upgrade must include theme2700.
- Old operator cannot verify a newer live source: predecessor must inspect its target2600, not2300.
- A process can die after public release: recover must retain newer business writes.
- Local or embedded payload drift: refuse before any host action; offline invocation stays inert.

### Task1: fixed reference, cron and standalone package

**Files:** create `scripts/infra/studio_materials_update.py`, `scripts/infra/build_studio_materials_update.py`, `tests/r16/test_studio_materials_update.py`.

**Interfaces:** `reference_from_git(repo, tool_commit) -> Bundle`; `validate_reference(ref)`; `patch_cron(raw, installation, accepted_sha=...) -> (bytes, dict)`; `load_embedded(manifest,sources,assets) -> Bundle`; `build(tool_commit, output, repo=...) -> Path`.

- [x] Write/run RED contract tests for exact582→589profile, unchanged schema, unrelated-source denial, cron2600/0202→2700/2700, dirty payload denial, reproducible archive and isolated offline CLI.
- [x] Implement adapter reference/decoder/CLI and builder with frozen predecessor modules and exact Git verification.
- [x] Run `python3 -m unittest tests.r16.test_studio_materials_update.Contract tests.r16.test_studio_materials_update.Reference tests.r16.test_studio_materials_update.Package -q`; expected PASS. Commit.

### Task2: accepted predecessor and transaction/recovery

**Files:** same adapter/tests. Reuse `connect_con02_application.Application`, `Journal`, `Locks` without edits.

**Interfaces:** `Host.predecessor()` returns guarded controls; `verify_predecessor_database(current, baseline, reference)` preserves current tasks; `make_context(bundle, root=..., uid=..., host=...) -> Context`; `check(ctx)` read-only.

- [x] Write/run RED successor tests using actual journals/source/cron images and physical pair copies, with modeled Docker/SQL boundaries. Cover stale source/cron, current tasks, both target versions, repeat, pre-release failure, process loss, explicit late restore and actual UID33.
- [x] Implement read-only2600journal/runtime/worker and derivable-cron guard; use new journal path and baseline historical2600index profile.
- [x] Run whole new suite and existing Studio/CON02 transaction suites; expected PASS. Commit.

### Task3: CI delivery and owner handoff

**Files:** `.github/workflows/ustar-infra.yml`, `server-console/tools/build_knowledge.py` internal source classification, `docs/COURSE_STUDIO_RU.md`, current context/dated delivery receipt and generated indexes.

**Interfaces:** CI artifact `studio-materials-production-deploy`; archive `ustar-studio-materials-install-<tool8>.tar.gz`; separate newroot directory and systemd unit, `--check`, `--upgrade --acknowledge-outage`, `--status`, `--recover`.

- [ ] Add build/offline/mandatory UID33 CI steps. Run full R16, source/context/secrets and immutable component comparisons.
- [ ] Self-review spec/diff/failure modes separately (subagents forbidden), fix material findings with regression tests. Push using expected-SHA lease; preserve application20ed tree and PR107 open.
- [ ] Wait for applicable CI, download exact artifact, compare independently rebuilt bytes and all members/SHA/CRC; record receipt and commands.
- [ ] Deliver archive and commands. State qualification versus owner installation separately. No agent server action.
