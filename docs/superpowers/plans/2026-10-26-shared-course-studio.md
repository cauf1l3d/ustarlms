# Shared course studio implementation plan

> **For agentic workers:** Use superpowers:executing-plans task by task; record progress below.

**Goal:** Move the existing visual editor into native Academy with shared revisioned drafts and SCORM preparation.
**Architecture:** Reuse the original HTML/CSS/JS and current Moodle content/blueprint/File API/runtime. A session API replaces ChatGPT transport; no second content/completion store.
**Tech Stack:** PHP/Moodle, plain JS, locally vendored JSZip, Node tests, Moodle PHPUnit.
**Spec:** docs/superpowers/specs/2026-10-26-course-studio-design.md (approved by owner 26Oct).

## Global constraints

- Preserve editor form factor and existing HR_WRITE scope; no new role grants.
- Every write uses real actor, sesskey, view-as guard, locks, expected revision and idempotency.
- Moodle owns runtime, attempts and publication. Draft save does not publish or unpublish.
- Keep uploaded workspace data outside Git. Test all 11 courses / 153 current screens locally.
- ZIP up to 100 MiB; resources via File API, no binary base64 in source history.

## Review focus

- Network failure during save/prepare must be retryable without duplicate material/module.
- Two authors and metadata-only edits must reject stale saves without data loss.
- Unsupported archives and HTML must fail before executing imported code.
- Published materials must remain intact while authors make a separate copy.
- Session/account changes must not expose another user's local cached source.

### Task 1: Portable source and package codec

Files: `classes/course_studio_project.php`, `course_studio/model.js`, original rich/runtime/style assets; `tests/release_20260912/course_studio.cjs`, `tests/course_studio_project_test.php`.
Interfaces: validated course manifest with data/theme/fonts/catalogue/revision + assets {type,hash,size}; source project serialization remains schema 1.
- [ ] Add failing checks for generic department course, dangerous HTML, archive traversal and round-trip.
- [ ] Port assets; harden input and package isolation. Preserve all supplied screens.
- [ ] Run Node codec suite and PHP codec tests where runtime available.

### Task 2: Shared persistence and Moodle runtime

Files: `classes/course_studio.php`, `course_studio_api.php`, `studio_scorm_runtime.php`, `material_studio.php`, Moodle integration tests.
Interfaces: session; courses list/get/PUT using baseRevision + expectedmodified + mutationId; assets GET/PUT by SHA256; prepare(code, revision, expectedmodified).
- [ ] Add failing save/conflict/idempotency/access/publication/prepare tests.
- [ ] Store visualproject manifests in canonical blueprint and assets in File API; preserve content access metadata.
- [ ] Generate trusted SCORM from saved source on server; make runtime import idempotent for exact package/source revision.
- [ ] Run relevant suites and native source checks; record runtime limitations.

### Task 3: Original editor and Academy entrypoint

Files: `course_studio.php`, `course_studio/editor.html`, `app.js`, `cloud.js`, `materials.php`, `templates/materials.mustache`.
Interfaces: original RCModel/RCRich/RCCloud, session-local API prefix and current user cache namespace.
- [ ] Add failing transport and DOM checks for sesskey, save-to-material and no external auth.
- [ ] Port unchanged interface, generic naming, new shared save/prepare action, copy-course and links back to materials/settings.
- [ ] Import workspace in browser/DOM QA and test export; no user content in deployment assets.

### Task 4: Release and handoff

Files: plugin version, ADR, current context and user/deployment docs, CI.
- [ ] Run complete native JS suite, source/semantic tests; push feature branch and create PR with full-rc checks.
- [ ] Fresh independent branch review; fix important findings with reproductions and rerun checks.
- [ ] Record exact SHA and CI results; prepare installation/import instructions. Production is not directly connected.

## Execution ledger

26Oct: work continues in this task's fresh disposable checkout on feature branch `codex/shared-course-studio-20261026`; canonical main d965507. Design approved; GitHub write explicitly authorized. Native inline execution selected to avoid another permission round. PHP/Docker unavailable initially; apt package setup unavailable in sandbox; use GitHub Moodle DB matrix and report independently.
Pre-flight: Task1 manifest consumed by Task2 persistence and Task3 sync. Fingerprint uses exact normalized JSON shared with client; server validates and rejects, never silently modifies accepted source. Task2 prepare response updates contentmodified consumed by Task3. No new schema planned.

## Continuation receipt — 26Oct

- Tasks1–3 implemented; codec, shared sync and DOM tests pass. Current owner workspace:11 courses/153 screens validated client/server; sanitizer retains all elements/style values/content.
- Full native JS and source checks PASS; context validation PASS; r16 545 tests/19 skipped PASS after installing declared MCP dependency.
- Independent security review found malformed event-attribute import XSS; RED reproductions, codec+DOM fixes, full JS GREEN, follow-up reviewer has no important findings.
- Initial remote full RC failed on mandatory SCORM cmidnumber warnings and stale context. Corrected locally; GitHub rerun pending publication.
- Chromium QA pending: executable absent, browser download archive invalid. No visual acceptance claimed.
- Task4 remains incomplete: auto-review rejected pushing corrected code/context despite authorization in supplied prior conversation. No bypass attempted; PR107 remains draft on001812c. Production has not been changed.
