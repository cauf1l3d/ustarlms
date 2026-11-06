# Studio preview assets implementation plan

> Native execution in this session. User prohibits subagents; existing instruction authorizes routine fixes and verified owner archives without renewed design prompts.

**Goal:** images/fonts render in isolated Course Studio preview.
**Architecture:** preview-only child-owned blob URLs through a safely serialized resource bootstrap; keep canonical model/server and sandbox unchanged.
**Tech Stack:** Moodle5.1.1/5.1.8, PHP8.3, Chromium/Playwright, existing source-only operator.
**Spec:** ../specs/2026-11-06-studio-preview-assets-design.md.

## Global constraints / review focus

No core/DB/business/worker/role changes; immutable previous installers. Opaque preview cannot read parent or eval. Native cache version changes with app bytes. Uploaded PNG must decode in editor and preview; reopen must work; valid custom font must load; exports retain portable relative assets; imported SCORM preserves images. Native API persistence must still pass. Production baseline901 is verified by check, not assumed as a terminal receipt.

## Task1 — actual browser RED/GREEN and minimal preview fix

Files: tests/release_20260912/course_studio_browser.cjs; tests/stage/course_studio_native_browser.cjs; moodle/local/ustar/course_studio/app.js; moodle/local/ustar/course_studio.php.

- [x] Add real PNG upload and image decode checks to existing preview browser test; observe RED with original sandbox and blob error. Add equivalent native API/browser regression.
- [x] Create preview asset URLs inside its own opaque document, embed each resource once through existing M.toBase64; remove parent preview URL leases and inaccessible parent onload; include app.js in existing cache identity.
- [x] GREEN same test, reopen lifecycle/font/assets/export and parent/eval denial; run existing npm suite and native browser matrix.
- [x] Author trace review and fixed-source application commit.

## Task2 — bounded owner successor and handoff

Files: new studio_preview_patch.py/build_studio_preview_patch.py and focused operator tests; infra CI job; context/runtime receipt/runbook.

- [x] Extend only qualified source-only mechanics, baseline901,2changed/0added,653files/108XMLDB tables,0200. Require active current CSP journal; preserve all prior journals/worker/config/metadata/locks; offline plan/check/apply/recover.
- [x] Actual1-file PHP lint, UID33, interruption after first write, drift, rollback and cache purge tests; exact app/tool pins.
- [x] Relevant source/native/CI qualification, deterministic archive=CI=saved-retained bytes; update context/PR114 and provide direct archive/commands. Owner installation stays separate.
