# Boards — CURRENT isolated runtime, ACL and lost-update audit

Date: 2026-08-23

Status: **CURRENT / TEST IMPLEMENTATION — NOT TARGET, NOT PRODUCTION**

Constitution scope: B102, B107–B109 and the ten-point constitutional test for a new function. The Constitution does not define `Board` as an approved business entity, so the CURRENT mechanism cannot become TARGET implicitly.

## What exists now

- `local_ustar_boards` stores one mutable JSON document per board with owner, title, integer version, `sharedteam`, soft-delete flag and timestamps.
- `/local/ustar/boards.php` requires only the common `local/ustar:use` capability. Any participating employee can create an unlimited number of boards.
- Creation requires a Moodle session key and always creates a private board with version `1`.
- `board_api.php` requires login, session key and the common USTAR capability.
- Only the owner can save. A shared viewer is read-only.
- `sharedteam=1` means “same declared department”, not direct reports, explicit collaborators or a versioned team membership snapshot.
- If department resolution fails, sharing fails closed. A Moodle site administrator can read any shared board, but a technical USTAR superadmin without the site-admin identity does not bypass the department boundary.
- Save validates JSON syntax and rejects a payload larger than 10 MiB.
- The DGM runtime autosaves after a short debounce and also provides explicit save/import/export actions.

## Isolated baseline

Before and after the probe, the loopback isolated database contained:

| Fact | Value |
|---|---:|
| Board rows | 7 |
| Distinct owners | 3 |
| Shared rows | 0 |
| Soft-deleted rows | 0 |
| Maximum version | 2 |
| Total document bytes | 4,856 |
| Largest document | 3,864 bytes |
| Invalid JSON rows | 0 |

All seven existing CURRENT boards are private. Although `sharedteam` exists in schema and read logic, no current row uses it.

## Access-control probe

Two exact synthetic rows owned by `audit_employee` were created only in isolated and removed at the end.

| Boundary | Result |
|---|---|
| Owner reads private board | PASS |
| Same-department peer reads private board | DENIED as expected |
| Same-department peer reads `sharedteam=1` board | PASS |
| Cross-department HR reads shared board | DENIED as expected |
| Cross-department USTAR technical superadmin reads shared board | DENIED as expected |
| Shared peer writes owner's board | DENIED as expected |
| Sequential stale version save | DENIED as expected |
| Invalid JSON | DENIED as expected |
| Valid JSON larger than 10 MiB | DENIED as expected |

These checks prove the implemented CURRENT boundary only. “Same department” has not been approved as the TARGET collaboration audience.

## Critical optimistic-locking race

Result: **FAIL — silent lost update**.

`boards::save()` performs:

```text
SELECT board by id + owner
→ compare version in PHP
→ mutate record to version + 1
→ UPDATE by primary key
```

The version is not part of the `UPDATE ... WHERE` condition, and no row lock or transaction protects the compare-and-write sequence.

The isolated probe held the synthetic row lock long enough for 24 real PHP workers to read the same version `1`, then released the lock:

```text
attempts=24
posted=24
stale=0
persisted rows=1
final version=2
persisted document=one worker's JSON
```

All 24 clients were told that save succeeded, but 23 documents were overwritten without an error and without history. The sequential stale test passes, while actual concurrency defeats it.

This is a release-blocking data-integrity defect for any scope that includes collaborative or multi-tab Board editing.

## Missing CURRENT lifecycle and governance

1. No UI or service changes `sharedteam`; sharing is effectively unreachable through the checked product surface.
2. No explicit collaborator/audience table, read/write role or per-board access list.
3. No canonical team/reporting snapshot; later department changes silently change who can read a board.
4. No rename, owner transfer, duplicate, archive, restore or delete action in the checked Board service/UI.
5. `deleted` exists, but no lifecycle method sets it and no archive/recycle view exposes it.
6. No version-history table, immutable save events, actor audit, before/after digest or correction rationale.
7. The integer version is current-state metadata only; it cannot reconstruct a previous document.
8. JSON validation is syntactic only. The probe accepted `{"anything":true}` as a valid board document.
9. No per-user/team quota, board-count limit, rate limit or aggregate storage budget. The 10 MiB limit applies only to one save payload.
10. No content classification, retention rule, legal hold, malware/embedded-link policy or PII warning for imported JSON.
11. `list_for_user()` selects full `documentjson` for every shared board before filtering by department in PHP, although the list renders metadata only. This is unnecessary memory and query amplification.
12. No separation between personal scratch board and an official business artifact; owner, source of truth and downstream use are undefined.

## UX consequences

- The page says “Мои и командные доски”, but all seven current records are private and the interface has no share control.
- A user cannot see why a board is private/shared, who else can read it or how to stop sharing.
- A shared viewer can open a full editor-looking surface but cannot save; the UI does not explain the read-only contract before an edit attempt.
- Autosave can report “Сохранено” to multiple sessions even when earlier content was silently overwritten by the race.
- Import accepts any syntactically valid JSON; document-shape failure is deferred to the client runtime.
- There is no visible recovery path for accidental overwrite or corrupted semantic content.

## Probe integrity and cleanup

- Synthetic rows created: `2`.
- Synthetic rows deleted: `2`.
- Synthetic rows remaining: `0`.
- Baseline restored exactly to `7 rows / 3 owners / 0 shared / 0 deleted / 4,856 bytes`.
- Temporary host and container probe files removed: `TEMP_CLEANUP=PASS`.
- No real board title, document, user name, email or ID appears in this report.
- Production code, data, accounts, roles, sessions and Boards were unchanged.

## Required engineering containment before any Board release

Even before TARGET collaboration decisions, the save path must use an atomic compare-and-swap, for example a single update constrained by `id + ownerid + deleted=0 + expected version`, and treat affected-row count `0` as conflict. A transaction/row lock is an alternative but must be proven under concurrency. Acceptance must repeat the 24-worker barrier test and produce exactly `1 success / 23 conflicts / final version 2`.

Do not add this containment to production without a separately approved release scope, backup and rollback. The current audit records the defect; it does not silently choose the final Board architecture.

## TARGET decisions required

- Are Boards personal scratch documents, team collaboration artifacts, official records, or several explicitly different types?
- Who owns each type and what is its source-of-truth status?
- Is collaboration based on direct team, department, named collaborators, assignment/project or another approved audience?
- Which roles can view, edit, share, transfer, archive, restore and permanently remove?
- What immutable version/audit/retention model is required?
- What happens to access and ownership on transfer, manager change, leave and dismissal?
- What quotas, content policy, export/import controls and sensitive-data warnings apply?
- Is simultaneous editing required, or is explicit single-editor locking sufficient?

Until these decisions and the lost-update containment are approved and implemented, Boards remain a CURRENT experimental tool and must not be treated as a safe TARGET collaboration mechanism.
