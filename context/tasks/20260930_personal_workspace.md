# Personal workspace and gamification UX — 2026-09-30

Status: review candidate; production deployment is separate.
Branch: `codex/ustar-personal-workspace-20260930`.
Source parent: PR70 `301cc7229246e0f212733221acc4659b05418cc8`.

## Continuation evidence

The user supplied successful production installation evidence for PR70: local
2026093004, theme 2026093002; all 493 files match, no source drift, recruiter
capability checks passed and maintenance disabled. This is user-executed evidence,
not a runtime inspection performed in this session.

The shared conversation ended at a usage limit. Its last agent described local
catalog/team/login/palette changes and unfinished notebook/home work. No later
commit or PR was available when continuation started. Unpublished source files
could not be recovered. The shared text was read; the nine attachments were not
accessible through the shared page or file service, so visual comparison with
those screenshots is outstanding.

Canonical main was fetched: `99248772a91526c87c1a31f425d1a07906727152`.
Its roadmap records the historical September 20 baseline. Current user evidence
and PR70 define this task's application parent; no old deployment plan was reused.

## Implemented scope

1. Notebook: width/height, images inline, actions menu, drag/click connectors,
   named movable/resizable frames, per-user backgrounds, full available width,
   and a separate notebook action beside task creation.
2. Home: next action first, then task/checklist/note previews by default.
3. Owner reward control stays in existing navigation; home duplicate removed.
4. Catalog: uncropped image fit; separate editor mode and management button;
   full available width.
5. Team learning summary precedes organization chart.
6. Reward conditions: verified event, source, department/position/person/company,
   future period, XP/USCOIN; last matching condition replaces base reward.
7. Competition: real current organization identity, audience preview, game or
   learning mode; learning counts route points or Moodle courses, one source
   per season. Only completions after publication within season dates count.
8. Home layout: visibility, optional allowed blocks, width, style, drag order,
   accessible up/down controls and reset; settings persist per user.
9. Login: natural content height and footer spacing remove forced empty area.
10. Six additional personal color sets, recognized by settings, shell and API.

## State and ACL impact

No new database tables or Moodle core changes. Notebook/home presentation remains
in Moodle user preferences. Reward policy uses existing versioned plugin config;
new learning XP is frozen in the existing immutable grant table. Course/activity
coins stay zero. Game XP remains frozen in mastery. USCOIN uses the existing
ledger. HR capabilities and the named owner authority gate remain unchanged.

Versions: local 2026093005; theme 2026093003. Upgrade sets a future activation
boundary for frozen course/activity XP; old completions are not replayed. Moodle
upgrade registers the activity observer and competition reconciliation task.
No production learning, wallet or personnel data was changed in this session.

## Local evidence

- Existing DOM suite plus notebook resize/frame/background and home layout tests.
- PHP 8.2 syntax parsed for all current source files; this is not PHP execution.
- Changed SCSS partials compile with Dart Sass.
- Source structure, XMLDB and template section validation.
- Added Moodle DB tests for ownership, stale revisions, recipient scope,
  immutable/idempotent rewards and isolated learning/game scoring.
- Full PHP/Moodle/PostgreSQL/upgrade/rollback execution requires the GitHub gate.

Exact implementation SHA, CI run and gate result are recorded in the PR once run.
Deployment evidence: not deployed.

## Installation checks after the gate

Use this candidate SHA over the exact installed PR70 source. Before writing files,
recheck the production manifest against PR70, take code/database/moodledata backup,
check PHP lint, then enable maintenance. Install only local/ustar and theme/ustar
with readable file modes (directories 0755, files 0644; umask 022). Run Moodle
upgrade, purge caches and build USTAR CSS. Require local/theme versions above and
an exact candidate manifest match before disabling maintenance.

Signed-in acceptance: owner notebook and reward rules; employee task/checklist
submit and private notes; HR summary and original capability boundaries; game
and learning season participants; light/dark layouts and mobile login. Anonymous
303 alone does not validate the signed-in flows.

Rollback: use the verified pre-install code/database/moodledata backup as one
runtime snapshot in maintenance, with post-backup activity explicitly accounted
for. Code-only replacement with unmodified PR70 is not a valid rollback: its
plugin versions are below the upgraded database and its XP reader predates
frozen course/activity grants. A code-only rollback would need compatible
versions and the frozen-event exclusion in the XP reader. Do not delete grant
or learning rows to emulate old code.

## Independent review and release follow-up

Reviewed PR71 at `7ba6d709f2af7c32c3e006302ca6645f98f3e193` against all ten
user requests. GitHub run `36754640662` completed successfully: source, frontend,
rollback, prepare-rc, Moodle DB and gate. This is isolated validation, not a
production installation or signed-in visual acceptance.

Review fixes:
- Deleted notes are removed from the board's returned link/item metadata. A
  dangling link can no longer poison the next connection command after reload.
- Personal palettes use the server's signed-in account preference. Shared
  browser localStorage no longer overrides another account or a profile update.
- Reward sources exclude the site course and activities pending deletion, which
  are absent from Moodle modinfo and could previously break the management page.
- Dedicated `scripts/release/deploy_pr71.sh` accepts the exact reviewed SHA and
  checks its own bytes against that commit. It requires the installed PR70
  manifest and versions, backs up code/DB/moodledata, installs explicit D755/F644
  modes, checks every source file as www-data before upgrade, resets web OPcache,
  verifies plugin versions/HR boundaries/final manifest and preserves maintenance
  on any post-copy failure. It does not mutate role assignments.

Follow-up versions: local 2026093006, theme 2026093004. No additional schema or
business-policy change. Regression coverage: deleted-note connections, reward
sources pending deletion, per-account palette initialization. The final exact
SHA and full gate result will be recorded in PR71 after this follow-up completes.

A subsequent Chromium fixture pass rendered the repository's actual templates,
18 compiled theme SCSS partials and notebook/home scripts, with synthetic data
and a representative native login form (not a Moodle signed-in session). At
1720px the notebook used all 1440px of its content container; real browser
interactions verified resize, grouping, links and light-mode background changes.
The 390px fixture exposed horizontal overflow in the home settings selects;
responsive labels now wrap inside the panel. Login artwork now clips the source
raster to its intended SVG region so adjacent reference-image content cannot
appear in letterboxed margins. Desktop/mobile fixtures were rerun successfully,
including home ordering, hide/resize and catalog image containment. Production
acceptance and actual Moodle auth/SSO rendering remain separate.

Full follow-up gate `36756924576` failed one new source-selector assertion:
Moodle defines SITEID as a string in this runtime, so strict comparison with an
integer course ID retained the site course. Both operands now use integer IDs.
All other 191 DB tests passed; final follow-up re-runs the complete gate, including
the new UI clipping/mobile settings fixes. This failed run is not release evidence.
