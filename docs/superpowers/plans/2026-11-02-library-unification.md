# Unified Library implementation plan —2026-11-02

> Execution: the owner requested independent integration/fixes and one deploy archive. Work inline, no subagents, SSH, provider sends or merge. Review the supplied specification against real interfaces before preserving each part.

**Goal:** one Library entry for product reference, personal/supplemental learning, access purchase/assignment and optional knowledge checks, without changing mandatory route facts.
**Architecture:** keep content/catalog/learning_events/economy as canonical writers. Library stores offers, historical grants, optional check definitions and immutable attempt snapshots. A shared entitlement decision guards the actual body/file/native activity as well as the storefront. Baseline is owner-accepted app2150affe (local1100/theme2700), not main2900.
**Stack:** existing Moodle5.1.8/PHP8.3/PostgreSQL16, PHP/Mustache/theme tokens; no frontend rebuild or external services.
**Specification:** supplied lib01_patch SHA256752883b26619e71b51e1be3835b7bdf881f2e67a9f88eb79e486cef3921ca97d plus the current owner request; imported ADR0019/0020 reconciled with the installed source. Owner-approved defaults in handoff: permanent purchases, employment gate, admin refunds, configurable prices, mark-only checks and store disabled initially.

## Decisions and limits

- Implement within local_ustar; one Library rail item replaces Knowledge/Catalog for employees and remains reachable from management shells. Preserve author editors, old deep links, feed saves and file ACL.
- Library home is My materials; catalog reference/search are available to active staff, while paid/assigned/gated advanced bodies are closed until entitled. The mandatory route remains its existing command surface; Library shows a read-only shortcut.
- Keep deny-by-default content audiences. Do not grant company-wide access to the110 items reported without ACL. A commercial grant does not publish a draft or override a content audience.
- Purchases use the existing wallet/ledger under locks, with real-actor, CSRF at HTTP, scope and view-as checks. Replays do not debit again. Revoke/refund preserve history and exact ledger ownership; refund and re-purchase are explicit serialised events.
- Native launch and pluginfile checks must enforce entitlement; legitimate mandatory-route access remains independent of optional purchases. No duplicate completion/reward model.
- Optional product/group/topic/skill checks record only marks. Snapshot questions, pass threshold and attempt policy; start/submit are POST commands with locks, replay protection and current authorisation. Product mastery/point70 is a separate existing exam, never silently substituted for a per-product quick check.
- Quick checks use real product/group/attribute values; deterministic distractors of the same attribute, no guessing. Missing data skips a question safely. Reconciliation covers create/change/archive/revive and import paths, preserves prior attempts, and is no-op on unchanged inputs.
- New upgrade step local2026110200 (>installed1100); combine all four final schemas directly, no manual fragments. Theme version advances only if the theme runtime changes. Upgrade/repeat/fresh/accepted legacy schema parity must be proved in CI.
- Use actual _tokens.scss colors/spacing/type, functional light/dark/mobile states. No invented logos/photos. One brand CTA; fallback product art when absent.

## Task1 — safe domain/schema and commands

Files: classes/library_access.php, library_offer.php, library_grant.php, library_store.php; db/install.xml, upgrade.php, access.php, version.php; tests/library_access_test.php, library_store_test.php.

- [ ] Reproduce supplied defects: bulk grant bypass, gated-zero bypass, actor/scope substitution, duplicate debit/refund/re-buy and same-second revision overwrite.
- [ ] Implement one resolver path for single/bulk, source validation and policy decisions; make bulk safe before optimising reads.
- [ ] Serialise commands with one documented lock order; preserve native wallet transactions and ACL writers, enforce eligible real targets and org scope inside the service.
- [ ] Append final108-table schema to the current104-table baseline, add writer capabilities and explicit default flags with fresh/upgrade parity.
- [ ] Run native positive/negative/concurrency/replay tests; commit self-contained domain change.

## Task2 — end-to-end body access and unified UI

Files: library.php, library_manage.php, content.php, open.php/view/file/native hooks as needed, catalog.php/knowledge.php compatibility, theme layout, templates/library*.mustache, styles/library.css, DOM/native access tests.

- [ ] Test direct URL and file/native bypass, current mandatory-route access, revoked employment/grant, old knowledge/feed file paths and browser view-as.
- [ ] Use existing content/native and catalog presenters for real body/detail links, personal history plus active grants, feed saves and read-only route projection.
- [ ] Provide real management forms for offers, settings, scoped assignments/revokes/refunds and material publication through content_admin. No arbitrary user-ID authorisation in controllers.
- [ ] Replace duplicated Library navigation with one entry while preserving editors and legacy GET parameters/POST writers.
- [ ] Render all states in light/dark at390 and1440; verify contrast, overflow, focus and reduced motion; commit.

## Task3 — optional checks and product sync

Files: library_assessment.php, library_quick_generator.php, library_quick_sync.php, task/sync_library_quick_checks.php, library_check.php, catalog.php save/archive hook, CLI seed/audit/sync; native tests.

- [ ] Test POST-only start, same-attempt resume, submitted replay, ownership/access loss, frozen pass threshold and revision, no coins/evidence/route writes.
- [ ] Implement safe validated bank authoring for group/topic/skill plus generated product quick checks; keep catalog_mastery separate.
- [ ] Reconcile deterministic product sources including inactive ancestors, input changes, reactivation and imports under one lock. Avoid whole-catalog synchronous work inside a catalog transaction.
- [ ] Register scheduled fallback and immediate bounded after-commit product trigger. Prove unchanged rerun performs no writes; commit.

## Task4 — qualification and guarded deploy

Files: new scripts/infra/library_update.py and build_library_update.py, tests/r16/test_library_update.py, existing CI stage/native schema contracts, context receipts/inventories.

- [ ] Build a new immutable owner updater from qualified full-deploy mechanics, accepting only app2150affe/1100 and fixed worker267, with private code/DB recovery copy, metadata/xattrs/control preservation and loopback8082 smoke.
- [ ] Test missing/busy managed locks, UID33/root metadata, foreign source/schema, interruption/resume, no provider calls, and all new migrations twice on actual PostgreSQL.
- [ ] Run source/context/console/infra and both native Moodle suites on an exact application/tool SHA; independently inspect all review results.
- [ ] Compare archive/all members with exact CI artifact, verify ZIP CRC and a downloaded saved copy; freeze the delivered tool and leave PR open.
- [ ] Deliver one archive and simple root commands inline. Owner installs; no agent server mutation. Record preceding SCORM/Quiz acceptance and Library acceptance limits.

## Review focus

Suspended target with old grant; paid body/native file URL versus public teaser; mandatory-route entitlement without paying; refund/re-purchase and multiple manager grants; catalog changes during an in-flight attempt. Each belongs to a native test above. Supplied tests are not treated as passing until executed.
