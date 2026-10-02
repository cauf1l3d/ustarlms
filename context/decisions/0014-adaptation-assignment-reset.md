# ADR 0014 — Adaptation assignment cancellation and retained cycles

2026-10-02. Extends ADR-0006; implementation for review, deployment separate.

HRD Control gains an explicit assignment reset for active/escalated adaptations.
It requires the real active session actor, local/ustar:use and
local/ustar:manageadaptation, writable mode, POST, sesskey, confirmation and reason.
Ordinary HR, managers and employees receive no new cancellation authority.
Completed cycles are not reset. Repeating the same cancellation is idempotent.

Reset moves the existing cycle to cancelled, appends its actor/reason/prior state
to the existing workflow and HR audit stores, and closes its outstanding cases.
Daily submissions, final reports, staffing approval and organization assignment
remain intact. The cancelled cycle no longer blocks learning or renders active
employee actions. A stale form or reconciliation must not revive it.

The existing approved staffing request can be assigned again by its authorized
manager. A new cycle receives a new ID and independent daily rows; the prior cycle
remains in HRD history. The latest cycle is used for staffing presentation.
The unique staffingrequestid index therefore becomes a normal lookup index in an
explicit XMLDB upgrade. No rows, fields or domain stores are deleted or duplicated.
The request command lock prevents duplicate live cycles for that request;
completed/live cycles still reject re-assignment. Existing cycles are not cancelled
by upgrade. This does not introduce a new rule about multiple different requests
for the same employee.

Cycle writes, escalation reconciliation and reset use the same existing cycle lock
and delegated transaction. Nested reconciliation shares the outer transaction;
state, case closure and audit commit together. Request assignment has its own lock.
Release acceptance includes preserved pre-upgrade history, fresh/upgrade/repeat
schema parity, authority and stale-write regressions, actual HTTP CSRF/confirmation
and repeated POSTs, plus real-template browser checks. Production acceptance is
separate; rollback after schema upgrade uses the coordinated backup.
