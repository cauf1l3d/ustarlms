# Organization resolution: stage 2

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

`access_context` is the single read-only boundary for organization consumers.
Business operations map to explicit Moodle capabilities in `capabilities`; position
names and `ishead` are presentation metadata and never grant a role or operation.
Company reads use explicit admin/HR/HR-manage/executive capabilities. Managers
need both `viewteam` and a valid assigned subtree. Remediation additionally checks
the explicit operation. Pending, suspended, terminated, deleted or Moodle-suspended
actors lose authority. HRD development analytics remains a separate capability.
Staffing commands bind the supplied actor to the current user and reject preview writes.

## Reconciliation and release

Run `php local/ustar/cli/reconcile_organization.php` from the Moodle public root.
This emits a read-only JSON report of numeric employee IDs, source, assignment,
position/manager differences and conflicts; it deliberately has no apply mode.
Review every conflict before release: this package can remove stale or ambiguous
learning/manager access. Repeated reads do not repair profile fields or reporting.
No employee evidence, history, role assignments or Moodle suspension state is migrated.
Rollback restores the prior source and plugin metadata using the stage-1 backup
procedure; there is no relational schema change in this package.

## Employment and approval state

`local_ustar_employment` is the explicit USTAR lifecycle record. Its states are
`pending`, `active`, `suspended` and `terminated`; they do not mirror or mutate
Moodle `user.confirmed`, `user.suspended` or `user.deleted`.

- A missing row preserves legacy behaviour during the reviewed migration window.
- Once a row exists, it is authoritative; no fallback to Moodle flags is allowed.
- Only `active` employees may receive learning assignments, route rewards or
  organization authority. Moodle account guards still apply independently.
- No automatic backfill runs during upgrade. HR create/import, approved hiring,
  suspension and termination write the lifecycle through an authorized command.
- `source`, approver and timestamps make an activation auditable. Position and
  department remain resolved through StaffPlace/Assignment, not this table.

## Reviewed access migration

From the Moodle public root, run:

```bash
php local/ustar/cli/migrate_stage2_access.php > /secure/path/stage2-report.json
```

The default is a read-only JSON report. Review every `review_access_mapping` row
and create a separate plan containing the expected position, employment state and
legacy projected roles plus the desired explicit roles/employment state. Apply only
that reviewed plan:

```bash
php local/ustar/cli/migrate_stage2_access.php \
  --plan=/secure/path/reviewed-plan.json --apply --confirm=APPLY_STAGE2_ACCESS
```

Apply is transactional, rejects stale identity/state/role inputs and is repeatable.
It removes only role assignments owned by the former `local_ustar` projection and
creates reviewed assignments under `local_ustar_migration`; unrelated/manual roles
are preserved. Position edits and scheduled jobs never synchronize access roles.

## Stage-2 acceptance

Stage 2 is complete in source when fresh install, upgrade, repeat-upgrade and rollback
checks pass together with the organization PHPUnit suite. The suite covers employee,
manager, HR and HRD positive/negative boundaries, inactive employment, absence of
position-derived access, read-only reports, stale-plan protection and repeatable apply.
Signup and the HR approval console remain stage 3 scope.
