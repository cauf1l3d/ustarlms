# Library management implementation plan

> Native execution: user repeatedly authorizes next audit package and prohibits subagents; preserve that method without renewed process-choice prompts. Required executing-plans workflow is inline, author review explicitly scoped.

**Goal:** U03/U04/U09/U10 existing Library author/admin workflows work beyond initial caps and preserve entered data.
**Architecture:** existing Moodle controllers/canonical writers, two focused read/form helpers and a small AMD question editor; no schema or new API.
**Tech Stack:** PHP8.3, Moodle5.1.1/5.1.8, Mustache/AMD, PostgreSQL16, existing Python owner source overlay.
**Spec:** ../specs/2026-11-06-library-management-design.md

## Constraints / review focus

No core/schema/versions/worker/provider changes; no agents/SSH/new merge. Keep actor/scope/view-as/sesskey/locks/revision and attempts/ledger unchanged. Preserve scalar input safely; bound malformed arrays; no stale-token rebasing. ACL filtering precedes visible page and hasnext. Existing subject/gate selection survives search. Question option indices stay stable when rows move/remove; limit100 and strict middle-blank rejection. Expected predecessor2cb verified by exact source and private active Preview journal, not assumed from owner general acceptance.

### Task 1: bank editor and form preservation

Files: classes/library_manage_form.php; library_manage.php; templates/library_manage.mustache + library_question_fields.mustache; amd/src/library_manage.js + build/library_manage.min.js; styles/library.css; tests/release_20260912/library_management_controller.php + library_management_dom.cjs; native library_management_test.php; native browser flow.

- [ ] Add real controller regression for new check six questions/title/correct retained after invalid threshold, stale revision retained; observe current RED. Add DOM add6/remove/move correct-answer test and observe RED.
- [ ] Implement pure form projection, preserve posted identity and expected tokens; keep immutable subject tied to saved record. AMD stable keys, max100, remove/move, live counter, dirty navigation guard; exact compiled build.
- [ ] GREEN focused PHP/DOM and original assessment strict validator tests including middle blank. Add actual Moodle browser create/edit/error/fresh reload to existing native Studio browser job, not a mock of backend.
- [ ] Author boundary review and app commit; no archive handoff before native qualification.

### Task 2: searchable selectors/history and accurate statuses

Files: classes/library_manage_reader.php; same controller/template; native/library_management_test.php; tests/release_20260912/library_management_controller.php; actual native HTTP/browser fixture.

Interfaces: subjects(actor,type,q,page,selected) -> items/hasmore; targets(actor,q,page) -> eligible items/hasmore; history(actor,q,page,now) -> allowed rows/hasmore; grant_status(grant,now) -> statuskey/status. All readers deny unauthorized role before reads. Per-page20, bounded SQL windows100, source selector50; no exact total unless computed under ACL.

- [ ] RED selected source beyond500 and101st history; future/expired/refunded boundaries. Native test fixture includes current-scope/transfer/view-as and query escaping; original writers and exact653-file predecessor remain unchanged.
- [ ] Replace hard caps with server GET searches/paging; pin current immutable subject and chosen gate; history batches hydrate offer/user records. Do not change canonical writers or claim all global N+1 resolved.
- [ ] GREEN PHP/DOM/browser, native PostgreSQL permission/page parity; confirm create/edit/check fields persist at boundaries and archived source label remains honest. Add author guide and commit fixed app.

### Task 3: qualified immutable owner successor and handoff

Files: new scripts/infra/library_management_patch.py + build_library_management_patch.py; focused operator tests; infra job + source-only registry; context state/receipt/runbook.

- [ ] New successor baseline2cb, before653/108/0200, exact changed/added inventory derived from app diff. Preserve all old journal controls plus active private Preview journal; PHP lint only PHP; shared locks, metadata/xattrs/source recovery, cache purge retained.
- [ ] Operator RED/GREEN, actual PHP/UID33, interruption across changed/added files, foreign source/journal drift, recovery and repeat tests. Pin exact app/tool and build deterministic3member archive.
- [ ] Relevant source/DOM/browser + native5.1.1/5.1.8 gates, single qualification at final app/tool. No repeated full RC on docs-only trees. CI artifact = local archive = retained downloaded copy. Record owner preview acceptance separately from exact terminal evidence.
- [ ] Update GitHub/context/author guide/qualification and provide direct archive plus root install/postcheck commands. No production execution or merge.
