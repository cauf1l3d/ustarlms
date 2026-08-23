# Materials / Personal Library — isolated implementation evidence

Status: **REVIEW BRANCH ONLY — NOT DEPLOYED TO PRODUCTION**

Constitution anchors: **B020–B028, B045–B046, B104, B107**

Owner supplement: `CODEX_RELEASE_SUPPLEMENT_HR_MATERIALS_UX_FINAL.md`

## Implemented scope

- `Materials` remains a full-workspace file manager with folders, breadcrumbs and editor.
- Files and folders can be moved by drag & drop onto a folder/breadcrumb.
- Every row also has an explicit context action, so moving works without a mouse.
- Move writes use `timemodified` optimistic locking and reject stale forms.
- Folder cycles, self-parenting and nonexistent targets are rejected server-side.
- Successful moves create an immutable `content_moved` event.
- A route point may now use a USTAR material as its requirement.
- Completion mode is explicit: `open` or `ack`.
- Route material URLs go through a server-side gateway that checks the employee position, current published route version, point order and the exact first launchable requirement.
- Opening through that gateway creates an immutable learning event and unlocks the personal Library read model.
- Direct ACL access, an old acknowledgement or a guessed URL does **not** unlock the Library.
- The employee screen is now `Моя библиотека` and reads only route-unlocked items; access rules are checked again on every read.

## Data model

- `local_ustar_content_events`: immutable source events (`route_material_opened`, `route_material_studied`, `content_moved`) with idempotency key and provenance.
- `local_ustar_library`: rebuildable per-user read model derived from the first route learning event.
- No CURRENT ACL, acknowledgement or content record is automatically backfilled. This is intentional because `CURRENT != TARGET`.

## Verification commands after installing the review build in an isolated Moodle

```text
php admin/cli/upgrade.php --non-interactive
php local/ustar/cli/check_learning_route_v2_schema.php
php local/ustar/cli/check_materials_library_schema.php
```

Role/browser verification still required in the isolated environment:

1. HR/HRD/system admin can move a file through context action and drag & drop.
2. Employee cannot call the move POST action.
3. A stale move form is rejected and does not write an audit event.
4. A folder cannot be moved into itself or a descendant.
5. A locked/later route point cannot unlock a material through a guessed gateway URL.
6. `open` unlocks the material once and creates one idempotent event.
7. `ack` unlocks on open but completes the route requirement only after version-specific acknowledgement.
8. Direct Knowledge/ACL access cannot populate the Library.
9. Mobile layout keeps context actions available; drag & drop is optional enhancement.

## Rollback boundary

Rollback requires the pre-deployment database and Moodle-code backup. Reverting code alone is insufficient after learning events have been written. Before rollback, export both new tables for audit preservation. The migration intentionally performs no destructive backfill or mutation of existing content, users, roles, routes or acknowledgements.

Production deployment remains blocked until the owner separately authorizes it and the isolated checks above pass with before/after screenshots.
