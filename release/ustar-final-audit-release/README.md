# USTAR final audit release bundle

Branch: `ustar-final-audit-release`  
Status: isolated-test validated; production release not authorized

This bundle contains only reviewed deltas and repeatable audit/test helpers. It does not contain production data, database dumps, secrets or personal data.

## Theme delta

- `theme_ustar/lib.php` — registers the final login and Academy icon SCSS layers.
- `theme_ustar/scss/_login.scss` — responsive/login polish while keeping Moodle-native authentication.
- `theme_ustar/scss/_academy_icons.scss` — feature-illustration sizing, restrained shadows and reduced-motion-safe hover.
- `local_ustar/classes/ui.php` — strict semantic Academy feature-icon registry; navigation/actions retain SVG.
- `local_ustar/pix/academy/` — 12 selected unchanged source PNGs from the supplied 29-asset pack.
- `local_ustar/classes/game_media.php` and game providers — current-host Moodle File API resolution instead of persisted host-bound question media.
- `local_ustar/classes/external/get_games.php` and `templates/games.mustache` — prevent learner-facing dead `0 / 0` game links and correct the empty-state copy.

## Materials / Personal Library review delta

- `local_ustar/classes/learning_events.php` and schema `2026082301` — immutable route material/admin events plus rebuildable personal Library read model.
- `local_ustar/open.php` and `classes/route_model.php` — guarded route-only material launch and explicit `open` / `ack` requirements.
- `local_ustar/materials.php`, `material_bulk.php`, `classes/content_admin.php`, templates, AMD and `_materials.scss` — Explorer-style move with hierarchy lock, optimistic stale-write rejection, cycle protection, drag & drop and accessible context action.
- `local_ustar/knowledge.php` and `templates/knowledge.mustache` — personal history only; CURRENT ACL/ack rows are not silently treated as TARGET learning evidence.
- `local_ustar/cli/check_materials_library_schema.php` — isolated post-upgrade schema/invariant verifier.
- `local_ustar/cli/test_materials_library.php` — self-cleaning, isolated-only 15-check service/ACL/idempotency/stale/cycle smoke.
- `review-gate/ustar-review-gate.yml` — copy of the GitHub review-only static gate; it contains no deployment job.
- `reports/MATERIALS_LIBRARY_IMPLEMENTATION.md` — exact source scope, authenticated runtime checks, cleanup and rollback boundary.
- `reports/USCOIN_CURRENT_RUNTIME_AUDIT.md` — isolated ledger, concurrency/idempotency, overspend, store/reversal and cleanup evidence.
- `reports/LEADERBOARD_CURRENT_RUNTIME_AUDIT.md` — isolated audience, score, tie, team-rank, season/fairness and cleanup evidence.
- `reports/BOARDS_CURRENT_RUNTIME_AUDIT.md` — production-CURRENT lost-update reproduction plus isolated atomic-save containment, validation/ACL, exact 1/23 acceptance and rollback roundtrip evidence.
- `reports/WORKFLOW_COMMUNICATION_CURRENT_RUNTIME_AUDIT.md` — Moodle notifications/messages, USTAR goals/reviews/HR actions and task-lifecycle aggregate/ACL/Checklist Design evidence plus exact cleanup.
- `reports/EVIDENCE_CHECKLIST_GATE_CURRENT_RUNTIME_AUDIT.md` — Evidence semantics, checklist definition/history integrity, current Gate lifecycle, isolated fail-closed containment and rollback roundtrip.
- `reports/ORGANIZATION_REPORTING_TEAM_CURRENT_RUNTIME_AUDIT.md` — Organization/reporting truthfulness, Team capability/workforce boundaries, referential integrity, browser evidence and rollback roundtrip.
- `evidence/organization-reporting/` — aggregate CEO screen and a PII-free cropped manager warning; no real employee list is stored.
- `evidence/materials-library/` — eight synthetic desktop/mobile PNGs plus the executed role, Library and cleanup matrix.

This delta passed local static gates and isolated Moodle upgrade/schema, 15/15 synthetic service checks, role allow/deny, authenticated desktop/mobile context move and Personal Library `0 → 1`, cleanup and independent rollback restore. Native HTML5 drag remains unproven by the in-app driver; the delta is not production-authorized.

Login was validated at desktop 1440×900, tablet 768×1024 and mobile 390×844. Academy icons were browser-validated on Achievements, Games, Knowledge and Profile, including dark theme and lazy loading. Production asset use still requires licence/provenance confirmation.

## Operations helpers

- `p0_containment.sh` — checksum-guarded P0 isolation of the two historical public HR mappings.
- `create_isolated_test_env.sh` — isolated restore environment with no public route.
- `audit_default_user_role.php` — read-only archetype diff.
- `reset_default_user_role_test.php` — guarded test-only reset.
- `create_synthetic_test_users.php` — guarded test-only role fixtures; password must be supplied through `USTAR_AUDIT_PASSWORD`.
- `deploy_login_polish_to_test.sh` — checksum-guarded test deployment helper.
- `deploy_academy_icons_to_test.sh` — checksum-guarded Academy icon deployment to the exact isolated environment.
- `deploy_game_media_fix_to_test.sh` — checksum-guarded same-origin game media fix for isolated validation.
- `deploy_game_catalog_fix_to_test.sh` — checksum-guarded learner catalog filtering for empty game drafts.
- `harden_moodle_code_test.sh` — isolated permission-hardening rehearsal.
- `p0_harden_moodle_code.sh` — production permission hardening; included for review only and requires a separate exact approval.
- `restore_moodle_permissions.sh` — manifest-guarded rollback for that permission change.
- `test_role_boundaries.php` — isolated five-persona capability matrix plus reversible role-revocation rehearsal.
- `test_page_denial.php` / `run_page_denials_test.sh` — guarded protected-entry denial tests.
- `test_page_allow.php` / `run_page_allows_test.sh` — guarded read-only positive entry tests.
- `toggle_test_manager_role.php` / `revoke_test_user_sessions.php` — isolated active-session revocation fixtures.
- `create_isolated_dr_snapshot.sh` / `restore_isolated_dr_snapshot.sh` — checksum-verified full test-only snapshot and independent restore drill.
- `restore_isolated_release_candidate.sh` — second independent final-candidate restore on loopback `18082`, retaining the earlier DR evidence.
- `deploy_boards_atomic_isolated.sh` — hard-allowlisted isolated installation of the transactional Boards save path with automatic failure rollback.
- `test_boards_atomic_save.sh` / `boards_atomic_probe.php` — self-cleaning validation, ACL and deterministic 24-worker concurrency acceptance.
- `verify_boards_atomic_roundtrip.sh` — restores the exact old class, reproduces 24/0, reapplies the candidate and requires 1/23; isolated containers only.
- `workflow_communication_probe.php` / `workflow_communication_runtime_probe.php` — PII-free aggregate snapshot and self-cleaning synthetic notification/conversation/goal/review boundary verifier.
- `deploy_workflow_goal_guard_isolated.sh` / `verify_workflow_goal_guard_roundtrip.sh` — guarded isolated unknown-action rejection and old→new rollback proof for the personal goal service.
- `evidence_checklist_gate_runtime_probe.php` — PII-free inventory plus self-cleaning assessment/checklist/ACL/Gate boundary probe.
- `deploy_evidence_checklist_guard_isolated.sh` — exact isolated candidate→old→candidate verifier with backup hashes and runtime probes.
- `organization_reporting_runtime_probe.php` — PII-free inventory plus self-cleaning reporting/access/account-type boundary probe.
- `deploy_organization_reporting_guard_isolated.sh` / `verify_organization_reporting_guard_roundtrip.sh` — isolated-only guarded install and exact CURRENT→candidate rollback proof for Organization/Team integrity.
- `target_core_runtime_probe.php` — self-cleaning A+B+C TARGET runtime matrix for direct-report ACL, Evidence/Gate/Checklist history, separate task types and canonical notifications.
- `deploy_target_core_isolated.sh` / `verify_target_core_roundtrip.sh` — full isolated DB/file backup, schema upgrade and exact pre-Core→candidate rollback proof.
- `employee_lifecycle_runtime_probe.php` — self-cleaning HR import/bulk-position access synchronization boundary matrix.
- `deploy_employee_lifecycle_guard_isolated.sh` / `verify_employee_lifecycle_guard_roundtrip.sh` — isolated CURRENT→candidate lifecycle guard proof.

The operational scripts record the exact 2026-08-23 audit paths/hashes. Role fixtures and entry tests allow only the explicit isolated roots `18080`, `18081` and `18082`. Review and update their guards for any later snapshot; never bypass a mismatch.

## Release gate

No file in this bundle authorizes production deployment. Production requires a separate owner confirmation, a fresh snapshot and final production rollback rehearsal. Isolated negative/positive capability tests and full isolated DR restore have passed.

The canonical private repository is `https://github.com/cauf1l3d/ustarlms`; this bundle is published only on the review branch `ustar-final-audit-release`. The default branch and production deployment remain outside this publication.

The production permission script was executed only after a narrower explicit approval: `config.php` is `root:www-data|640`, and exactly `public/local/ustar` plus `public/theme/ustar` were normalised to deploy-owner read-only code. This permission containment did not deploy the login or icon deltas.

Requirement-by-requirement closure is in `reports/MASTER_TASK_COMPLETION_MATRIX.md`. Detailed evidence is in `reports/UX_UI_AUDIT.md`, `reports/ICON_DESIGN_MAPPING.md`, `reports/GAME_RUNTIME_VALIDATION.md`, `reports/HEALTH_CHECK_DIAGNOSIS.md`, `reports/ROLE_TEST_REPORT.md`, `reports/BACKUP_INFO.md` and `reports/FINAL_STATUS.md`.
