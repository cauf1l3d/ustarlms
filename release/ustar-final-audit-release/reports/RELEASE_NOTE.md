# USTAR — release block notes

Дата: 2026-08-23  
Branch: `ustar-final-audit-release`  
Production release: **not authorized / not performed**

## Block 1 — Backup

- Fresh archive `/var/backups/ustar/2026-08-22_22-17-34.tar.gz` created.
- SHA-256 verification: PASS.
- DB, `local_ustar`, Caddy, Compose and Moodle config are present.

## Block 2 — P0 containment

- Anonymous exposure confirmed before action: both HR mappings returned HTTP 200.
- Files moved outside web-root to `/var/backups/ustar/p0-containment-2026-08-22_22-17-34/`.
- Files and manifest are `root:root`, mode `0600`; directory is root-only.
- Original SHA-256 checks: PASS.
- Anonymous verification after action: both URLs return HTTP 404.
- Production Moodle root remains 303 → login; PostgreSQL health remains healthy.

## Block 3 — Isolated test environment

- Root: `/opt/ustar/test-env/ustar-final-audit-release`.
- Dedicated network and containers; no Caddy route and no public port.
- Access is server loopback `127.0.0.1:18080` or local SSH tunnel only.
- DB restore, schema and upgrade checks: PASS.
- Synthetic employee/manager/HR/CEO smoke: PASS for intended top-level surfaces.
- Existing AI-manager health error reproduced; test cron warning is expected because no test cron runner is configured.

## Block 4 — Security simulation

- Default user role diff recorded: 4 extra capabilities and 1 changed permission.
- Full reset to Moodle `user` archetype applied only in isolated DB.
- After reset: 168/168 capabilities, no extras/changes/missing.
- Production role data unchanged.

## Block 5 — Login polish

- Separate final SCSS layer added after current theme layers.
- Native Moodle form, recovery, errors and policy flow preserved.
- Desktop grid, form width, CTA, password toggle, responsive layout and login footer noise fixed.
- Verified at 1440×900, 768×1024 and 390×844; no horizontal overflow.
- Production theme unchanged.

## Remaining gates

- Separate production approval.
- Negative capability/privacy tests and HRD/superadmin scenarios.
- Production default-role/security remediation за пределами уже выполненного permission containment.
- Dependency upgrades and full sealed DR/rollback rehearsal.
- Approved TARGET decisions; CURRENT remains CURRENT / TEST IMPLEMENTATION.

## Block 6 — Permission hardening

- Isolated rehearsal: PASS. `config.php` is readable by Apache but not writable by it; USTAR plugin/theme code is deploy-owner read-only for the web process.
- Post-hardening synthetic employee and HR landing checks: PASS.
- Owner narrow approval: received.
- Production execution: **PASS**. `config.php` is `root:www-data|640`; roots of `public/local/ustar` and `public/theme/ustar` are `adu:adu|755`; writable objects under both code trees: `0`.
- Web-process guard: PASS — config remains readable, while config/plugin/theme are not writable by `www-data`.
- Database schema: PASS; login HTTP 200; previously contained HR URLs remain HTTP 404.
- Rollback manifest: `/var/backups/ustar/p0-permission-manifests/2026-08-22_23-27-14/permissions.before`.
- This block did not deploy login polish and did not alter production DB data, users, roles or routes.

## Block 7 — Role boundaries and revocation

- Synthetic role matrix for employee, manager, HR, CEO and USTAR superadmin: PASS.
- Protected entry points: 34/34 expected denials and 18/18 expected allows PASS.
- Role assign/revoke on synthetic employee: capability and active shell changed immediately; no residual assignment.
- Explicit Moodle session revoke: 1 session → 0; active tab redirected to login with expired-session notice.
- Cleanup: synthetic passwords randomised, synthetic sessions removed, temporary manager role removed.
- CURRENT conflict retained for TARGET decision: no `ustar_hrd` role; `ustar_hr` combines `hr` and `hrmanage`.

## Block 8 — Full isolated DR

- Snapshot code + moodledata + DB: 445 MB, all SHA-256 checks PASS.
- Snapshot path: `/opt/ustar/test-env/ustar-final-audit-release/dr-snapshots/2026-08-23_00-02-30`.
- Independent restore root: `/opt/ustar/test-env/ustar-final-dr-restore-20260823`.
- Restored schema/login/HR-404 checks PASS; 96 test account rows and 8 course rows.
- Capability matrix plus 34 denial and 18 allow tests repeated on restored stack: PASS.
- DR validation containers are stopped after testing; data retained. Production rollback was not performed.

## Block 9 — Academy icon system

- Reviewed all 29 supplied transparent 3D PNGs and selected 12 semantically valid feature illustrations.
- Added a strict icon registry: feature/card contexts may render lazy decorative PNGs; navigation, toolbar and action controls remain inline SVG.
- Isolated guarded deployment: PASS; PHP lint, cache purge and login check PASS.
- Browser checks on Achievements, Games, Knowledge and Profile: all assets loaded, expected lazy-load confirmed, dark theme PASS, no horizontal overflow.
- Navigation regression check: 14 SVG icons and 0 Academy raster icons on checked shell screens.
- Production unchanged. Release gates: asset-pack licence/provenance, size optimisation and separate production approval.

## Block 10 — Game runtime and catalog polish

- Reproduced CURRENT defect: question image URL is persisted with obsolete host; authenticated browser receives redirect and renders a broken image. Production DB/code confirmed unchanged.
- Added Moodle File API resolver only in isolated code; employee and Game Studio providers now generate the current same-origin pluginfile URL.
- Isolated browser: question image decoded at 1320×1329, four options and wrong/correct feedback PASS.
- Persistence: 2 attempts, 1 mastery, 25 XP and one +5 USCOIN ledger event with unique idempotency key PASS.
- Active game with zero active questions is no longer published to learners; it remains available in Game Studio. Dead `0 / 0` card regression PASS.
- Separate production approval and TARGET economy/content decisions remain required.

## Block 11 — Moodle health diagnosis

- `core_publicpaths`: all file probes and redirect targets return 404. Five directory probes are generic Caddy 308 slash normalisation; arbitrary nonexistent slash paths behave identically. No direct disclosure reproduced.
- `local_ai_manager`: root cause confirmed. `tenantcolumn=institution`, while USTAR stores job/position labels there; 70 active non-empty values violate the plugin's Latin-only identifier rule. Alternative supported user columns are also business data, not canonical tenants.
- No production configuration was changed. Correct resolution requires an explicit single-default/dedicated-tenant/disable AI decision and privacy owner.

## Block 12 — Final isolated release-candidate snapshot

- New post-change snapshot: `/opt/ustar/test-env/ustar-final-audit-release/dr-snapshots/2026-08-23_01-00-56`, 445 MB, all checksums PASS.
- Independent restore on loopback `18082`: schema/login/HR-404 PASS, 96 test accounts and 8 courses.
- Exact login/icon/game hashes and all 12 Academy assets verified inside restored container.
- Capability matrix plus 34 denial and 18 allow tests PASS on the final restored code/data.
- RC containers stopped after validation; data retained. One interrupted no-manifest snapshot was quarantined under `failed-snapshots` and cannot pass the restore guard.

## Block 13 — Materials / Personal Library final UX acceptance

- Review payload `db6095c`: full-width workspace, accessible current-location breadcrumb, separate zero/no-results states, actionable CTA and move disabled/active/loading feedback.
- GitHub review gate `32638327627`: PASS across PHP, JavaScript, XMLDB/Mustache, UX invariants and 87-file manifest.
- Seven-file isolated overlay hash match, PHP/schema/login/log health and service smoke 15/15 PASS.
- Rollback archive `materials-ux-before-db6095c/files-before.tar.gz` pinned; fresh locked screenshot fixture recreated.
- Rollback round-trip PASS: previous files restored/linted, `db6095c` automatically reapplied, seven hashes + schema + login verified; locked fixture remained intact with no unlock event.
- Authenticated synthetic evidence completed: eight desktop/mobile PNGs cover HR Materials before/after context move, 390×844 context menu, employee Library `0 → 1`, route material card and mobile Library.
- Employee direct access to Materials denied; HR allowed. Cross-user Library check: employee `1`, HR `0`, superadmin `0`.
- Temporary credentials were randomised, sessions changed `1 → 0`, fixture cleanup removed `1` route point + `4` content objects and final synthetic content/points/events/Library counts were `0/0/0/0`.
- Native HTML5 drag could not be marked browser-PASS through the in-app driver; context move is browser-PASS and the drag endpoint/audit/stale/cycle behavior remains covered by the 15/15 isolated service verifier.
- Production was not changed by this block.

## Block 14 — USCOIN CURRENT runtime and abuse audit

- Loopback isolated ledger baseline: 8 rows, 8 unique keys, total +40, all `game_mastery`; no commerce/store tables.
- `sync_uscoin.php --dry-run`: PASS with 2 pending historical awards, proving reconciliation is not automatic/complete.
- Twelve concurrent manual posts with one exact key: 1 posted, 11 duplicates, 1 persisted row. Idempotency/race containment PASS.
- The manual row had `actorid=NULL`; operator identity is not preserved by the CLI audit path.
- Temporary `-9999` probe was accepted and produced balance `-9994`; atomic debit/non-negative balance guard is absent.
- Exact race and overspend keys were deleted; baseline restored to 8 rows / +40 and synthetic employee balance 5.
- Store, explicit reversal chain, approval/caps/anomaly rules and XP→USCOIN policy remain TARGET owner decisions. Production was unchanged.
