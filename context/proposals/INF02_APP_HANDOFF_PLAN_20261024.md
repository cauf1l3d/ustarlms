# INF02 app connection handoff implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans.
> Owner's existing normalization authorization applies; self implementation and
> author review,0subagents. No new approval loop, agent SSH or production execution.

**Goal:** deliver one ordinary Python operator tool that switches current2300 from
bootstrap moodle to the already verified ustar_app, with recoverable metadata changes.
**Architecture:** load the exact accepted Telegram controller read-only, reuse its
locks/current runtime guards, preserve its journals, and journal the DB/config/HTTPS
handoff separately. Keep administrative POSTGRES_USER and backup helper pins unchanged.
**Tech Stack:** existing Python3.12/std library, PostgreSQL16, Docker, frozen2300
controller and canonical/private HTTPS owner. No new packages or archives.
**Spec:** [consumer constraints and qualified CI](../runtime/infra_db_privilege_consumers_20261023.md),
[owner facts](../runtime/infra_readonly_owner_20261023.json), original INF02/03 remediation.

## Design and approaches

In-place bootstrap demotion is ruled out by actual PostgreSQL16 failure and owner
OID10. Bare config editing leaves stale HTTPS authority and old runtime recovery
snapshots. Selected engineering approach: separate ordinary app connection and an
explicit coordinator preserving the old journals. This is the agent's implementation
choice within the approved normalization, not a newly invented owner preference.

HTTPS active state may update only the config hash/metadata, with its original bytes
saved privately. Old Connect app/runtime journals and before-images stay byte-exact
during the handoff. A compatibility view may expose their original HTTPS snapshot
only after verifying the exact recorded post-handoff owner snapshot. Old direct
runtime mutation commands cannot silently bypass this boundary; the new tool must
provide coordinated runtime enable/disable and metadata undo paths before release.
Application rollback through a frozen old controller requires metadata undo first.

Only reviewed public application objects transfer; pg_catalog, extension-owned and
unrelated monitoring objects remain unchanged. Inventory custom routines and definer
grants first. Existence of a definer is not a vulnerability proof. Do not silently
transfer/revoke it or assume flags0 precludes delegated privileges. Full code-tree
hardening and HBA/container changes remain separate INF03/05 work.

## Global constraints

- Production moodle/OID10 remains superuser; admin/OID109344 and app/OID110395 accepted.
- App login/five flags0/owner0/memberships0 is owner-verified; do not recreate roles.
- App/source/worker remain6c56/local2300/theme0202; existing manual stages reused.
- Frozen tool3d1938ae SHA6f7729459e1ef25271fd86324e441c823a6d39763c7fec84baf7cf640a41eb31.
- Secrets only private tty/RAM and root-private journal images; no stdout/argv/env dumps.
- No broad REASSIGN OWNED, business-row restore, provider call, full owner stand or main merge.
- No-argument CLI is offline PLAN_ONLY; read-only check requires no outage acknowledgement.
- Apply/undo require explicit outage acknowledgement and existing ordered nonblocking locks.

## Review focus

- A privilege-bearing definer/unknown public object must refuse before any mutation.
- Wrong password, elevated/mismatched role or app password authenticating bootstrap must refuse.
- Crash between SQL COMMIT and file/state saves must recover from observed exact before/after identity.
- A changed config/HTTPS/legacy journal outside the accepted delta must refuse, not rebaseline drift.
- Owner changes must preserve existing grants, source/data, backup globals and Telegram fences/keys/cursor.

### Task1: accept current private owner/control and SQL inventory

**Files:** existing infra_readonly_owner_20261023.json, infra_readonly_baseline_20261023.md.
**Consumes:** accepted role receipts and frozen2300 --check.
**Produces:** owner/controller and routine/public-owner metadata; no secrets/function bodies.

- [x] Accept ordinary app creation/SCRAM OID110395 receipt without inventing a timestamp.
- [x] Prepare one bounded read-only operator batch; Bash syntax/source review required.
- [ ] Receive native output; classify public non-application objects and custom definers.
- [ ] Record exact allowed scope or a specific unresolved dependency, without repeating old stands.

### Task2: implement recoverable coordinator and compatibility

**Files:** create scripts/infra/postgres_app_handoff.py and tests/r16/test_postgres_app_handoff.py.
**Consumes:** task1 accepted scope, frozen controller/role identities and existing ordered locks.
**Produces:** ordinary-file CLI --check/--apply/--recover/--undo/--status and coordinated runtime
enable/disable; check(ctx) -> sanitized dict, apply(ctx) -> dict, recover(ctx) -> dict.

- [ ] Observe RED for wrong/elevated/member role, definer exposure, unknown object, drift and unsafe parent.
- [ ] Implement strict frozen loading, private capture/readback and original/desired config parsing.
- [ ] Add private app credential/SCRAM and wrong-bootstrap-auth controls before outage or ownership writes.
- [ ] Implement scoped public ownership transaction, retaining ACLs and bootstrap/system catalogs.
- [ ] Add durable before/after DB/config/HTTPS journal and exact before/after recovery/undo.
- [ ] Add root:33/0640 config acceptance with explicit ACL handling/read-no-write check; leave code trees unchanged.
- [ ] Add verified HTTPS compatibility view and runtime delegates; old journal images remain immutable.
- [ ] Prove interruption at every boundary, unknown drift refusal, no business-data rollback and no secret output.
- [ ] Run focused tests and commit; do not present model-only tests as native production proof.

### Task3: qualify targeted native changes in existing CI and deliver

**Files:** modify existing native DB/runtime runners and their evidence verifier;
current context/proof/PR106, plus one ordinary operator .py build if embedding is needed.
**Consumes:** coordinator from task2 and accepted existing PG16/Moodle518/runtime fixtures.
**Produces:** immutable code/run/artifact receipts and guarded owner commands.

- [ ] Add missing SQL/definer/ownership and config/HTTPS/rollback scenarios to the existing fixtures.
- [ ] Verify real app connection, unchanged admin globals command, selected cron and current native owner guards.
- [ ] Inspect/download only sanitized evidence and byte-match the delivered ordinary script to qualified Git.
- [ ] Update context/PR/runbook with apply/recover/undo commands; no archive or new full owner stand.
- [ ] Owner apply/status/effective PHP app-role acceptance remains a distinct deployment receipt.

Current status: ordinary-role creation accepted; task1 native preflight pending.
Coordinator implementation/native release qualification and production cutover are not complete.
