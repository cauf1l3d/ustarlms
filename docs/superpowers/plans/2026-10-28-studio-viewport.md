# Studio viewport implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline; AGENTS forbids subagents.

**Goal:** Scale only material pages and independently collapse both editor panels.
**Architecture:** Extend existing app.js UI state, editor.html controls and scoped CSS.
**Tech Stack:** Vanilla JavaScript/CSS; Moodle PHP; Node/Chromium test fixtures.
**Spec:** docs/superpowers/specs/2026-10-28-studio-viewport-design.md

## Global constraints

Existing user/site UI key; no model/schema/API changes; unchanged exported courses.
50–150%,5% steps,100% default/reset. No SSH, subagents, provider sends or PR107 merge.

## Review focus

- Long lessons at150%: scroll footprint and both edges remain reachable.
- Selected and unsaved text: zoom/panel actions preserve DOM and formatting.
- Focus mode: restore the individual preferences, and make either panel reachable.
- Narrow/resized layouts: drawer Escape/focus and inline preferences remain consistent.
- Invalid/blocked persisted storage: safe100% default and no startup failure.

### Task1: Viewport controls

Files: editor.html, app.js, style.css; existing course_studio_dom.cjs and
course_studio_browser.cjs fixtures under tests/release_20260912.

- [x] Add behavior assertions first; observe missing control failure.
- [x] Implement zoom, persistent independent panel controls and responsive integration.
- [x] Run Node DOM suite and real Chromium including delayed resize; inspect screenshots.

### Task2: Release and delivery

- [x] Bump only local_ustar patch version; keep schema, theme and worker unchanged.
- [x] Prepare a separate exact-baseline2800 successor using the qualified transaction
      primitives; preserve both accepted DB index profiles and task policy.
- [x] Verify the operator/package guards and source/context inventories; push authorized
      changes to PR107 and obtain applicable CI results.
- [x] Build/check/save deployment archive and give the owner concrete install commands.

### Task3: Review

- [x] Perform a separate whole-change self-review (AGENTS prohibits subagents).
- [x] Record actual tests, version boundaries and owner basic acceptance separately from
      exact terminal inventory; leave PR107 open.
