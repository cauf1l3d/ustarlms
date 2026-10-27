# Execution ledger — plan: docs/superpowers/plans/2026-10-27-materials-studio-polish.md

Base:4aa92556; worktree codex/materials-studio-polish-20261027. AGENTS forbids subagents.
User explicitly requested implementation; native execution and routine technical choices are delegated.
Spec and plan self-reviewed. No production mutation, deployment or automatic merge.

Pre-flight: Tasks1/2 share normalized course/assets and the existing CloudSync manifest.
Task3 preserves editor IDs and scopes CSS; Task4 supplies material actions to Task2.
Ruling: immutable origin workflow events bind imports; client catalogue fields never authorize a binding.
Ruling: published sources open as linked drafts to preserve active content and learner attempts.
Ruling: local Word processing uses existing JSZip; no new application dependency.

Baseline: source and all Studio codec/sync/DOM/PDF checks PASS. Initial full Python
run failed on missing local MCP/PHP PDO SQLite; test environment repaired. Repeat
1254tests/45environment skips PASS (106.112s), unchanged production source.

## Task1 — Word codec and UI

RED observed: missing codec; unique drawing ids; empty document rejection; chapter
boundary and highlight preservation; missing Word import action. All corrected.
GREEN: six Studio JS suites, actual Chromium PDF+DOCX import/edit/download/re-import,
invalid-file preservation and desktop/mobile PASS. Independent python-docx reads
two screens, table grid and embedded image; valid default paragraph style.
Word ZIP central directory/stream sizes, CRC, DTD/entities, active objects, external
images, Unicode and resources checked. Safe hyperlink relationships retained.
Ruling: JSDOM uses the real vendored JSZip Node loader with host typed arrays so
its event scheduler/cross-realm checks match Node; actual browser runs unmodified JSZip.
Task1 code complete; catalogue/material integration and shell remain Tasks2–4.


## Task2 — Material source bridge

Pure PHP source conversion RED→GREEN: rich text/tables/images, active HTML removal,
bounds and unsupported media. DOM direct-open and authorized file conversion PASS:
exact selected draft, no phantom empty course, inherited revision/content timestamp,
source token and CSRF. PHP→browser manifest test caught and fixed canonical property
ordering before delivery. Existing CSRF/session guards still PASS.
Native Moodle tests added for copy/retry, source scope/runtime preservation, learner
refusal, assessment separation, stale file tokens and module File API images; these
require the CI Moodle runtime and are not claimed run locally yet.
Ruling: source copy events belong to the draft entity; reason links the original id,
so lookup is indexed without database-specific JSON functions or new schema.
Ruling: source copy, audience and event share an outer transaction under the existing
hierarchy lock; the standard save core is reused, avoiding nested lock acquisition.
Ruling: split pure source codec from File API/DB adapter; the former can be tested
locally without a fake Moodle database. No network fetch of imported image URLs.

Ruling for Task3: native shell does not register material.php/course_studio.php as
Materials routes. Add only those two paths and bump theme2700, preserving global
styles. Keeping theme0202 would leave the new page in the wrong navigation context.

## Task3 — Native Academy shell and usable editor

Studio now renders the same editor fragment inside native ustar header/navigation.
Only material.php/course_studio.php navigation registration changes in the theme.
All editor selectors are scoped; controls use actual Academy tokens and Inter.
Container-width drawers preserve the full mobile canvas, Escape/focus return works,
menu keyboard navigation and responsive geometry verified at390/768/1440.
Browser regression caught a wrong id lookup in the new menu focus handler; corrected
and covered by DOM plus actual Chromium. Shell controls retain their own CSS.
Save indicators distinguish local copy from Academy synchronization; revoked account
locks survive asynchronous busy cleanup. View-as page explains why editing is disabled.
GREEN: full npm, source397PHP/33JS/100tables/57templates, existing CSRF fixture.
Native actual header/nav/one-main landmark assertions added to stage HTTP smoke; CI pending.
Local visual fixture tests shell geometry only, not claimed actual native rendering.

## Task4 — Catalogue views and bounded reader

One DOM now supports list/blocks/cards with per-user/site preference for readers and
authors. Menus close on Escape/outside click and keep focus; drag retains revision
preconditions in every view. Source actions stay separate from the detail link.
Extracted material_catalog reader pages60 rows with deterministic folder/status/title/id
order and a separate detail lookup independent of filters/page. Metadata requires at
most2 queries; current native version and actual Moodle resource main file are selected.
Duplicate warnings now concern repeated published titles; editing drafts are intentional.
Removed Windows-like gradients and tiny metadata; native tokens, visible actions,
search labels, breadcrumbs/filter preservation and pagination use the same shell.
GREEN: full npm including reader/author view/drag/keyboard/storage tests; real SQL
fixture checks paging/current files/mainfile/duplicates. Actual Chromium checks all
3views at390/768/1440, menu bounds and phone folder creation, no horizontal overflow.
Four native Moodle reader/File API/query-count cases added; CI remains pending.

## Task5 — Integration qualification in progress

Owner's post-deploy status and scheduled cron recorded separately from the immutable
installation receipt; production remains2600/theme0202 and worker6c56. Basic browser
opening/operation/import reported; individual PDF/conflict/employee checks not invented.
Candidate local/theme2700 contains589files/100tables. Accepted2600 operator remains
frozen; its package fixture now pins62b0 instead of inheriting future HEAD application.
Native Connect pair probe now checks candidate theme version against exact source,
while historical baseline0202 remains fixed. Code source uploaded as exact tree
3f258df670dbb180971bd19847a05ffa1d35194e / commit259d9d1b65d904b67aa3852b96ff92ed9fe234ee.
First R16 run1254/45skips had only3 missing-local-MCP errors; environment dependency
installed, complete rerun in progress. Source/context/secrets pass locally.
No branch merge or production mutation; remote PR qualification follows.

Self-review: fixed native generated packages becoming uneditable after catalogue-only
title changes. RED reproduced with a real ZIP; GREEN accepts exact generated pages
under renamed metadata and rejects changed/stale/active package bodies. Additional
native File API regression added. Candidate source now d569644ac3d364732f6b41141a047cf7e3b75957;
old intermediate Git source259d remains history, not production.

Final source correction: existing native course/assessment types now remain effective
filters instead of falling back to all. Reviewed application commit2aac8c49d4cf1278734ce0cded770c06c8cfa2d1;
production2d95137a and frozen operator62b0 remain unchanged. Full PR107 gate pending.
