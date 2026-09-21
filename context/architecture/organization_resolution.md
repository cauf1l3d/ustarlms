# Organization resolution: stage 2, first implementation package

This extends ADR-0002 using the existing staff_places / assignments tables.
No second organization store is introduced. No production migration is implicit.

## Read contract

- `organization_identity::resolve()` is read-only. With no assignment history it
  resolves the legacy profile position against the existing structure catalogue.
- With assignment history, exactly one current PRIMARY is required. Expired,
  ended, future, missing or conflicting assignments do not revive legacy values.
- Both assignment and staff-place intervals are half-open: `[effectivefrom,
  effectiveto)`. Zero/null upper bounds mean no end.
- A known position, matching department and active acyclic ancestry are required.
  PRIMARY controls the learning position; ACTING only controls temporary authority.
- A single ACTING occupant takes precedence over PRIMARY for a management seat.
  Ambiguous occupants never choose an arbitrary first row.
- `people`, `structure`, `position_access`, employee profile and people/team APIs
  resolve the same primary position. Explicit admin preview remains presentation-only.
- Reporting and assessment escalation use the assigned hierarchy for normalized
  employees, retaining explicit reporting as a compatibility fallback for legacy-only users.

## Access boundary

Company reads use explicit admin/HR/HR-manage/executive capabilities. Managers
need both `viewteam` and a valid assigned subtree. Suspended/deleted actors lose
both paths. An ordinary employee does not get team learning records by acquiring
a head position label. HRD development analytics keeps its separate capability.
Staffing commands bind the supplied actor to the actual current user and reject preview writes.

## Reconciliation and release

Run `php local/ustar/cli/reconcile_organization.php` from the Moodle public root.
This emits a read-only JSON report of numeric employee IDs, source, assignment,
position/manager differences and conflicts; it deliberately has no apply mode.
Review every conflict before release: this package can remove stale or ambiguous
learning/manager access. Repeated reads do not repair profile fields or reporting.
No employee evidence, history, role assignments or Moodle suspension state is migrated.
Rollback restores the prior source and plugin metadata using the stage-1 backup
procedure; there is no relational schema change in this package.

## Remaining stage-2 scope

This package does not close stage 2: explicit employment/approval state, replacing
automatic position-to-HR role projection, reviewed migration/apply commands and
the remaining organization consumers still need conversion. Signup and HR-console
UI belong to stage 3. Full stage-2 acceptance must not be inferred from this package's tests.

## Employment and approval state

`local_ustar_employment` is the explicit USTAR lifecycle record. Its states are
`pending`, `active`, `suspended` and `terminated`; they do not mirror or mutate
Moodle `user.confirmed`, `user.suspended` or `user.deleted`.

- A missing row preserves legacy behaviour during the reviewed migration window.
- Once a row exists, it is authoritative; no fallback to Moodle flags is allowed.
- Only `active` employees may receive learning assignments, route rewards or
  organization authority. Moodle account guards still apply independently.
- No automatic backfill runs during upgrade. Registration and HR approval will
  create/change records through an authorized command in the next package.
- `source`, approver and timestamps make an activation auditable. Position and
  department remain resolved through StaffPlace/Assignment, not this table.
