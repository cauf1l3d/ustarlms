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
- [x] Verify check/upgrade/repeat/recover and interruption boundaries on real
  filesystem/locks/SQLite with modeled service/probe, then isolated artifact smoke.

## Task3 — Handoff
- [x] Update CI, source-only catalog and concise context/dated evidence/runbook.
- [x] Self-review (subagents forbidden), relevant verification, publish same PR111.
- [x] Build exact published source; compare downloaded CI artifact and saved copy;
  deliver archive, SHA and concrete restoration/install/recover commands.

Production acceptance remains pending until owner returns fresh probe/bot evidence.

Task1: complete; imported regressions RED→GREEN, graphics and receiver focused126 pass/4skips.
Task2: controller10tests including inherited crash boundaries pass; artifact pending.
Ruling: existing task checkout, inline implementation and self-review per owner/AGENTS; no subagents.

Task2: complete; CI124660577099 PASS, isolated -I -S PASS,8hashes/3ZIP CRC.
Task3: source a1bc5dda published in PR111; CI tar equals local and saved download.
Final review: self-review, no subagents per owner/AGENTS. No critical/important
findings left in reviewed scope. No deferred minors. Shared egress still requires
owner result; network/host acceptance is not claimed by modeled tests.

Final review follow-up: mandatory TLS in inherited recovery could leave workers
stopped during a continuing outage. Fixed in successor.restore only; frozen v1
unchanged. Two persistent-outage tests RED→GREEN, focused suite128/128 (4local skips).
Previous a1 archive superseded; final follow-up package qualification pending.
