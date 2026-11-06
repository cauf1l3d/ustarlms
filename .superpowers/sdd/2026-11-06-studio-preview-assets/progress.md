# SDD ledger — plan: docs/superpowers/plans/2026-11-06-studio-preview-assets.md
Pre-flight: expected owner profile901/toolf4d from dialogue; only hiding/preview symptom accepted, no terminal receipt. Current root isolation and immutable source baseline retained. No subagents/SSH/merge.
Ruling: one minimal preview-only data URL representation using existing M.toBase64; preserve sandbox/CSP, model storage and SCORM/PDF exports. Cache app.js explicitly. No new architecture; user continuing the authorized repair/package workflow.
Task1 in_progress: browser test first, app source unchanged.

Baseline real browser suite passed locally with official pinned Chromium151. CDN initially served Site Unavailable HTML; direct official Google CFT URL provided a CRC-verified ZIP. Workspace resets executable mode; runtime installed in task-private /tmp.
Initial image regression reproduced exact blocked parent blob URLs and width0 (editor width1), but the hand-copied PNG had invalid IDAT CRC; replaced with independently generated valid1x1 RGB PNG and repeated before any product fix.

Valid RED: preview-browser-red-valid.log, actual uploaded CRC-verified1x1 PNG decoded in editor; opaque preview naturalWidth0 and exact Not allowed to load local resource blob... errors (also custom font). No product file changed before RED.
Ruling: validate MIME token before interpolating data URL; malformed metadata cannot break generated JS/CSS. Cache uses model+app content hashes (not mtime, which metadata-preserving deployment may retain). One extra bounded file hash at Studio request only.
Minimal app preview-only data URLs and PHP cache identity now implemented; existing parent/preview CSP and sandbox bytes unchanged.

06Nov2026 implementation review: data URLs passed image/font/reopen checks, but a valid PNG with64KiB tEXt repeated24times produced a RED on preview size (<64KiB growth required). Revised to create blob URLs inside the opaque child, embed each unique resource once, inert JSON serialization with < escaping, document-owned automatic unload cleanup. Keep exact CSP/sandbox. Removed inaccessible parent onload; actual first-page behavior retained. No new server or messaging layer. Local npm first run stopped at missing php PATH; rerun with existing qualified PHP runtime.

Additional RED: ordinary lesson text containing JS replacement tokens $&/$`/$' corrupted HTML assembly (two aside elements). Callback-based script replacements preserve the literal serialized payload and trusted runtime. Includes a regression with literal closing-script/style text, plus < escaping; no eval or parent access added.
