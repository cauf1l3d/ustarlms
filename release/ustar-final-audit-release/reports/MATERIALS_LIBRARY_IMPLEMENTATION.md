# Materials / Personal Library — isolated implementation evidence

Status: **REVIEW BRANCH ONLY — NOT DEPLOYED TO PRODUCTION**

Constitution anchors: **B020–B028, B045–B046, B104, B107**

Owner supplement: `CODEX_RELEASE_SUPPLEMENT_HR_MATERIALS_UX_FINAL.md`

## Implemented scope

- `Materials` remains a full-workspace file manager with folders, breadcrumbs and editor.
- Files and folders can be moved by drag & drop onto a folder/breadcrumb.
- Every row also has an explicit context action, so moving works without a mouse.
- The workspace uses the full available width; it no longer stops at a `1480px` content cap.
- Breadcrumbs expose the current folder through `aria-current`, keep every ancestor directly reachable and preserve the explicit one-level-up action.
- Empty folder, filtered no-results and empty Personal Library are separate states with the next valid action.
- Drag move exposes `aria-busy`, a live status and an in-progress workspace state before the server redirect.
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

## Executed isolated verification — 2026-08-23

Deployment target was restricted to `/opt/ustar/test-env/ustar-final-audit-release`, mounted only by `ustar_audit_moodle` on server loopback `127.0.0.1:18080`.

- Pre-change code + DB backup: `release-backups/materials-library-before-2026-08-23_13-54-24/`; both SHA-256 checks PASS.
- Review archive SHA-256: `167662d4b26597eafaf93106d7e336bdad51f36afd69b531cc5b4e06cea0bcab`.
- Moodle upgrade to plugin/schema `2026082301`: PASS.
- Moodle database schema, Route v2 schema and Materials/Library schema verifiers: PASS.
- Initial event/library counts: `0 / 0`; no CURRENT backfill occurred.
- Synthetic service smoke: **15/15 PASS** — current-point gateway, idempotent open event, personal unlock, direct-ACL negative, acknowledgement separation, employee move denial, admin move, immutable audit, stale-write rejection and cycle rejection.
- Reproducible screenshot fixture lifecycle: create PASS, route unlock PASS, cleanup PASS (`1` route point + `4` content objects removed), then a fresh before-state recreated in the same isolated stack. The fixture CLI refuses non-loopback/non-isolated Moodle.
- Cleanup after smoke: content events `0`, library rows `0`, synthetic route points `0`, synthetic content rows `0`.
- Entry-point roles: `audit_hr` Materials allow PASS; `audit_superadmin` bulk allow PASS; `audit_employee` Materials and bulk denial PASS.
- Cache purge, maintenance disable, login HTTP `200` and final Moodle schema: PASS; recent container log contained no PHP fatal/error from this block.
- Independent rollback restore root: `/opt/ustar/test-env/ustar-materials-rollback-verify-2026-08-23_13-54-24`.
- Restored code version `2026082002`; `learning_events.php` absent; restored DB version `2026082002`; both new tables absent. Rollback PostgreSQL container stopped with retained root/volume. **ROLLBACK_REHEARSAL=PASS**.

### Final UX acceptance overlay — review commit `db6095c`

- GitHub review gate run `32638327627`: PHP, JavaScript, XMLDB/Mustache, UX invariants and 87-file Git-blob manifest all PASS.
- Overlay archive: `materials-ux-db6095c.tar.gz`, SHA-256 `cea7baac9950fe851df16b3e44c87872f441250dc796e74a8d09b111bb64f120`.
- Destination was revalidated as the isolated mount only: `/opt/ustar/test-env/ustar-final-audit-release/moodle/public`; production code was not targeted.
- Seven deployed hashes exactly matched the local review sources: `materials.php`, `knowledge.php`, two Mustache templates, AMD source/build and `_materials.scss`.
- Pre-overlay isolated rollback archive: `/opt/ustar/test-env/ustar-final-audit-release/release-backups/materials-ux-before-db6095c/files-before.tar.gz`, SHA-256 `3b4557e3e0019eb7a39cbab97fdae1851a1b7ebb3b438ceb4dbe6d442b34cb2e`.
- Post-overlay PHP lint: PASS; route/schema verifiers: PASS; login HTTP `200`; recent container fatal/parse/unhandled scan: NONE.
- The first service run correctly exposed fixture interference: the prepared visual before-state and the service verifier both own a synthetic route for the same synthetic employee. The guarded UI fixture was cleaned, service smoke passed **15/15**, and a fresh before-state was recreated (`content 90–93`, route point/version `17`). No real account or production data participated.
- Fresh before-state had no unlock event and was used for the authenticated employee Library before/after capture.
- Overlay rollback round-trip: previous seven files restored from the pinned archive and linted, then `db6095c` reapplied under an emergency recovery trap; all seven final hashes, schema and login HTTP `200` PASS. The locked screenshot fixture remained `4` content rows + `1` route point with `0` events and `0` Library rows. **MATERIALS_UX_ROLLBACK_ROUNDTRIP=PASS**.

The executable synthetic verifier is `local_ustar/cli/test_materials_library.php`; it refuses non-loopback/non-isolated Moodle and deletes every test fixture in `finally`. Authenticated visual evidence used `local_ustar/cli/materials_library_ui_fixture.php` with explicit `create`, `unlock` and `cleanup` actions. The fixture is now cleaned.

## Authenticated browser verification

Completed in the loopback isolated Moodle with synthetic accounts only:

1. HR Materials desktop before/after context move: PASS. The action changed disabled → enabled after destination selection, POST/redirect succeeded and the success notification appeared.
2. Breadcrumb current location and one-level-up action after move: PASS.
3. Employee Materials denial and HR allow: PASS.
4. Employee Personal Library before `0` → after route-open event `1`: PASS.
5. Cross-user isolation: PASS (`audit_employee=1`, `audit_hr=0`, `audit_superadmin=0`).
6. 390×844 Materials and Personal Library captures: PASS; the mobile context menu remains reachable.
7. Native HTML5 drag through the in-app browser driver: **NOT PROVEN**. Two coordinate-driven attempts produced no move, so no false browser-PASS is recorded. Context move is browser-PASS; drag endpoint/audit/stale/cycle behavior remains service-PASS in the 15/15 isolated verifier.

Eight PNGs plus the executed matrix and cleanup record are stored under `evidence/materials-library/`. After capture, temporary credentials were randomised, sessions changed `1 → 0`, browser reload returned to login, fixture cleanup removed `1` point + `4` content rows, and final synthetic content/points/events/Library counts were `0/0/0/0`.

## Rollback boundary

Rollback requires the pre-deployment database and Moodle-code backup. Reverting code alone is insufficient after learning events have been written. Before rollback, export both new tables for audit preservation. The migration intentionally performs no destructive backfill or mutation of existing content, users, roles, routes or acknowledgements.

Production deployment remains blocked until the owner separately authorizes it. Browser/mobile screenshot gates are complete except for native drag automation, which remains explicitly unproven rather than inferred.

## Files changed by the final UX acceptance pass

- `local_ustar/materials.php`
- `local_ustar/knowledge.php`
- `local_ustar/templates/materials.mustache`
- `local_ustar/templates/knowledge.mustache`
- `local_ustar/amd/src/materials.js`
- `local_ustar/amd/build/materials.min.js`
- `theme_ustar/scss/_materials.scss`

The Checklist Design source audit is recorded item-by-item in `UX_UI_AUDIT.md`; authenticated rendered evidence is now attached under `evidence/materials-library/`.
