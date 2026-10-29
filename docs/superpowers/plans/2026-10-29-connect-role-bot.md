# Connect role bot Implementation Plan

> For agentic workers: use superpowers:executing-plans. Owner explicitly requires self implementation, no subagents; no new approval pause.

**Goal:** Целый рабочий Telegram-бот по ролям с кнопками поверх текущей Академии.
**Architecture:** Canonical PHP dialogs/adapters and durable action receipts; thin Python receiver/cards. Existing link/notification/ACK contracts retained.
**Tech Stack:** Moodle5.1.8/PHP8.3, PostgreSQL16, Python standard library.
**Spec:** docs/superpowers/specs/2026-10-29-connect-role-bot-design.md

## Global Constraints

Base owner2803 app ef903b7d/operator36be3f10; worker6c56 until separate rollout.
One coherent release; Mini App/public HTTPS last. No subagents/SSH/provider sends/automatic merge.
Use actual actor/capability/scope, canonical writers and history. No password in Telegram.
Sensitive commands require separate native confirmation. Old operators unchanged.

## Review Focus

1. Revoked actor/link must lose access even to previously successful action receipts.
2. Two Telegram updates pressing the same button must not create two business mutations.
3. A conversation resumed after another client edits an object must preserve newer state.
4. Untrusted text/long names must not break Telegram HTML, limits, pagination or tables.
5. Interrupted registration must recover the canonical pending account, not duplicate it.

### Task 1: Canonical bot protocol, store and actor boundary

Files: classes/connect/bot_{request,store,service,actor}.php; bridge_auth/service;
db/install.xml/upgrade.php; native tests/connect_bot_test.php.
Interface: bot_service::update(identity,array):array returns bounded view/buttons; opaque callbacks.
- [x] Add and observe failing request/identity/replay/expiry tests.
- [x] Add metadata schema, fixed bot.update scope and current-actor wrapper.
- [x] Verify isolated native guards and protocol; record exact result.

### Task 2: Canonical role scenarios and independent confirmation

Files: classes/connect/bot_{work,people,learning,registration}.php; connect_bot_confirm.php.
Consumes Task1 store/view; uses existing task/checklist/staffing/grading/adaptation writers.
- [x] Add failing report/review/checklist/scoped recipient/registration tests.
- [x] Implement menu/read models, stepped forms and canonical commands with revision.
- [x] Implement native current-actor POST confirmation for sensitive prepared commands.
- [x] Verify role denials, repeat, changed version and registration recovery once.

### Task 3: Telegram buttons, visual cards and runtime

Files: connect-worker/ustar_connect/bot_{cards,handler}.py; receiver/runtime/telegram_api;
tests/r16/test_connect_role_bot.py.
Consumes bounded views; produces Telegram escaped cards/buttons without new business state.
- [x] Observe failing renderer/private-update/receiver integration tests.
- [x] Wire bot-only bridge, inline menus/forms/cancel/back, progress/tables and receipts.
- [x] Verify old link/ACK/receiver suites and new role flows; no provider sends.

### Task 4: Coordinated release, source gates and owner package

Files: scripts/infra/connect_role_bot_update.py and builder; CI/native hook; docs/CONNECT_BOT_RU.md;
STATE/ACTIVE/BACKLOG/ADR/inventories and qualification receipt.
- [x] Freeze application and operator source; retain exact2803 baseline and runtime identity.
- [x] Run proportional source/native/infra/recovery and context gates on final candidate.
- [x] Perform separate author self-review, fix material findings, publish one PR/package.
- [x] Verify archive/CI/saved downloaded copy; leave owner installation/role acceptance explicit.

Qualified source/worker5cc02944, tool99dbecaa; exact FullRC022fddee and all4CI PASS.
Evidence: context/runtime/connect_role_bot_qualification_20261029.json.
Archive/CI/saved readback checked. This completes implementation and the owner handoff;
server installation/live role acceptance and the later Mini App/public HTTPS remain owner/later-stage work.
