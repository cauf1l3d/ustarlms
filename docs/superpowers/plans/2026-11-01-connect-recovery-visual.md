# Connect recovery and visual successor implementation plan

Execution: inline, user/AGENTS prohibit subagents. Existing task checkout retained.
Spec: ../specs/2026-11-01-connect-recovery-visual-design.md

Goal: recoverable worker-only delivery from observed5cc + cb7128/rolled_back.
Architecture: frozen9a/v1 guards; separate commit-scoped successor journal; verified
renderer source, standard-library PNG; existing egress refresh remains manual.

Review focus: palette alpha at reduced levels; HR create buttons; shortened lists
with hidden choices; legacy journal drift/crashes; provider failure before switch.
Pre-flight: renderer→builder consumes worker source; controller→builder consumes
frozen v1 envelope; package verifier consumes exact tar inventory and hashes.

## Task1 — Renderer
- [x] Pin external regression cases before importing source; observe RED.
- [x] Port source, fix alpha/HR/list captions and maintain full fallback.
- [x] Run graphics/transport/receiver tests and visually inspect real PNG.

## Task2 — Successor
- [x] Reproduce old rolled_back identity failure; pin successor acceptance,
  legacy immutability, active/unknown/drift refusal and no-stop network failure.
- [x] Implement new controller and builder without editing frozen controllers.
- [ ] Verify check/upgrade/repeat/recover and interruption boundaries on real
  filesystem/locks/SQLite with modeled service/probe, then isolated artifact smoke.

## Task3 — Handoff
- [ ] Update CI, source-only catalog and concise context/dated evidence/runbook.
- [ ] Self-review (subagents forbidden), relevant verification, publish same PR111.
- [ ] Build exact published source; compare downloaded CI artifact and saved copy;
  deliver archive, SHA and concrete restoration/install/recover commands.

Production acceptance remains pending until owner returns fresh probe/bot evidence.

Task1: complete; imported regressions RED→GREEN, graphics and receiver focused126 pass/4skips.
Task2: controller10tests including inherited crash boundaries pass; artifact pending.
Ruling: existing task checkout, inline implementation and self-review per owner/AGENTS; no subagents.
