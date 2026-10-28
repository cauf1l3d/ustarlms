# Studio colors and canvas Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline; AGENTS forbids subagents. Execute the owner's concrete UI request and existing push authorization without another permission gate.

**Goal:** Configurable final course navigation and a taller editor with collapsible title/sync blocks.

**Architecture:** Optional theme fields share the existing project/revision model and both local/PHP package builders. A persistent toolbar toggle controls editor chrome and exposes compact sync status, independent of side-panel focus mode.

**Tech Stack:** Native Moodle PHP, browser JavaScript/CSS, existing codec/DOM/Chromium/native Moodle tests.

**Spec:** `docs/superpowers/specs/2026-10-28-studio-colors-canvas-design.md`

## Global Constraints

- Base01e1cef2, qualified previous application20ed966e/local2700/theme2700.
- New local2800, unchanged theme2700 and100table schema.
- No SSH/subagents/automatic merge; no changes to fixed2600/2700 operators.
- Optional navBackground/navText/navActive/navAccent are six-digit HEX strings.
- Legacy theme/project manifests and fingerprints are unchanged on read.

## Review Focus

- Legacy server manifests must still download without a fingerprint mismatch.
- Both browser and PHP SCORM must render identical custom/default navigation colors.
- Compact mode must expose sync/account/conflict failures and their recovery controls.
- Collapsing while editing must preserve text, selection, scroll and keyboard focus.
- Short phone screens and native bottom navigation must retain usable canvas/tools.

### Task1: portable final course palette

**Files:** model.js, player.css, runtime.js; classes/course_studio_project.php and course_studio_package.php; editor.html/app.js scoped style.css; codec/sync/PHP/native tests.

**Interfaces:** optional theme nav fields; `RCModel.navigationTheme(course)` resolves display defaults without mutating source; both builders emit the same --nav-* and contrast variables.

- [x] RED tests: custom/default CSS, legacy fingerprint, unsafe optional colors, server-built ZIP and project/SCORM roundtrip.
- [x] Implement optional validated palette and player CSS variables; expose labeled controls and a live menu sample through existing theme/history/save flows.
- [x] Run Node codec/sync, PHP fixture and native theme validation; expected PASS. Commit with ledger evidence.

### Task2: collapsible chrome and canvas geometry

**Files:** editor.html, app.js, style.css; course_studio_dom.cjs and course_studio_browser.cjs.

**Interfaces:** `#chrome-toggle` aria-expanded/controls; per-user `ui.chromeCollapsed`; `#compact-status` opens sync details; existing focus/drawer APIs remain independent.

- [x] RED DOM/browser tests for toggle/persistence, error disclosure, editing preservation and increased canvas height.
- [x] Implement collapse and compact status; trim excess canvas header spacing without reducing touch targets; verify desktop/tablet/short phone and keyboard.
- [x] Run full npm + Chromium, preserve external Academy controls and original project state; expected PASS. Commit with evidence.

### Task3: integration and qualified source handoff

**Files:** local version.php; CI invokes new PHP fixture and freezes existing2700 builder on01e1cef2; dated evidence and STATE/ACTIVE/BACKLOG/docs/generated context.

- [x] Run source/context/secrets, full R16 and native/browser CI; frozen ops/app identities stay separate.
- [x] Self-review against spec and failure cases (subagents forbidden), fix material findings with regressions.
- [x] Push using expected-SHA lease to PR107; qualify exact source and record evidence, no merge/server operation. Deliver concise usage and remaining deployment boundary.
