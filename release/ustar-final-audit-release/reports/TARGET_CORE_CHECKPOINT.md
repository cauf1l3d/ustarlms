# TARGET Core checkpoint — A+B+C

Дата: 2026-08-23

Статус: **isolated PASS; production не изменён**

## Changed

- Team scope: explicit direct reports instead of position/department projection; superadmin retains company projection.
- Added 10 TARGET tables for immutable Evidence/events, human Gate decisions, mirrored checklist submissions, separate official/personal tasks, workflow history and canonical USTAR notifications/outbox.
- Added `target_core` service with shared manager/HR ACL, idempotent facts, append-only correction/revocation, task lifecycle and notification delivery state.
- USTAR notification UI now reads the canonical USTAR store; Moodle chat remains Moodle-owned.
- Plugin schema/version: `2026082302`, release candidate `1.6.0-rc`.

## Tested

- PHP lint: 6/6 executable candidate files PASS; XML parsed and Moodle upgrade PASS.
- Runtime matrix: 12/12 PASS.
- Direct-report privacy: manager sees synthetic direct report and not same-department non-report.
- Evidence idempotency + append-only revocation PASS.
- Employee self-grant of critical Gate denied; manager decision with valid Evidence PASS.
- Employee/manager mirrored checklist rows remain separate PASS.
- Official-task lifecycle has immutable events; personal task manager mutation denied PASS.
- USTAR notification idempotency/read state PASS; normal has local delivery, action-required has local + Bitrix outbox PASS.
- Every synthetic row cleaned; all 10 TARGET tables returned exactly to baseline `0`.
- Full DB/file rollback `2026082302 → 2026082301 → 2026082302` PASS.

## Known release dependency

- Bitrix delivery credential/adapter is not available in the candidate environment. Action-required and critical notifications are durably queued; external delivery is a production gate, not silently reported as delivered.

## Result

`TARGET_CORE_ISOLATED_DEPLOY=PASS`

`TARGET_CORE_ROLLBACK_ROUNDTRIP=PASS`
