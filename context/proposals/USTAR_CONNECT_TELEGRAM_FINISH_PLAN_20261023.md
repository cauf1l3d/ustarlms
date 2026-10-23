# Telegram Notification Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Self implementation,
> author review,0subagents by owner instruction. Steps use checkbox syntax.

**Goal:** завершить Telegram notification/ACK presentation без резервного канала.
**Architecture:** прежняя canonical очередь и ACK, versioned begin_v3 для bounded event;
старые v1/v2 и preferences сохраняются. Новый application/worker release поставляется отдельно.
**Tech Stack:** Moodle5.1.8/PHP8.3/PostgreSQL16.15, Python3.12, Mustache, Chromium/systemd fixtures.
**Spec:** [approved narrowed scope](USTAR_CONNECT_TELEGRAM_FINISH_DESIGN_20261023.md).

## Global Constraints

- Server1 application/worker534/local2200,100tables; controller4394 immutable.
- No fallback/new routing store, no fan-out/second provider; existing v1 preferences retained.
- No agent SSH/provider sends/main merge;0subagents; live group deferred, current UID/subject unchanged.
- Read/provider receipt/ACK/task resolution separate; unknown send outcome never auto replayed.
- No arbitrary private titles/bodies/URLs in worker; event enum only with explicit contract3 and scoped key.
- New feature OFF by default; source/native/owner evidence distinct.

## Review Focus

- Same canonical ACK time after restart/repeated callbacks; unknown UI edit does not erase receipt (Task1).
- Wrong event/category, duplicate JSON/type confusion/version downgrade never sends (Task2).
- Own history may not expose another recipient, token/lease/provider reference/private metadata (Task3).
- Existing v1 opt-in/settings and old100 schema survive upgrade/repeat/paired restore (Task4).
- New deployment must preserve HTTPS AutoReport9443, journals/cursor/keys/cutoffs and recover interrupted handoff (Task5).

### Task1: постоянная отметка ACK
**Files:** telegram_api.py,notification_callback.py; tests/r16/test_connect_callback_transport.py,
test_connect_notification_callback.py; tests/stage/con02_callback_wire.py.
**Interfaces:** edit_notification_ack(subject:int,message_id:int,category:str,*,acknowledged_at:int|None=None)
использует существующий editMessageReplyMarkup; optional None сохраняет старый internal caller.
- [x] Add failing serialization+callback timestamp assertions; run2tests →2expected failures.
- [x] Pass canonical acknowledged_at to edit and add fixed-URL visible timestamp row; validate int/time.
- [x] Run full callback/transport tests; preserve unknown UI and canonical receipt semantics.
- [x] Commit source+tests checkpoint after diff review.

### Task2: bounded event presentation v3
**Files:** classes/connect/{notification_delivery,notification_policy,bridge_auth,bridge_service}.php;
ustar_connect/{notification_worker,telegram_api,bridge,receiver_runtime,notification_runtime}.py;
native connect_notification_action_test.php; new tests/r16/test_connect_telegram_presentation.py.
**Interfaces:** begin_v3(id,lease,kid,provider,tenant,?now):array → v2 permit with version3/event enum;
worker contract3 validates exact keys/category/event before API send_notification(...,event=event).
- [x] RED tests exact v1/v2 absence of event, v3 enum matching/category/type/flag/key denial.
- [x] Add atomic v3 begin path without changing v1/v2 dictionaries, separate feature check and scope.
- [x] Add sender rendering for all current bounded event labels; config/probe explicit3, v1 default.
- [ ] Run real worker/serialization tests and native scoped dispatch, no send for malformed permit.
- [ ] Commit feature checkpoint; app version2300, schema remains100tables, repeat savepoint idempotent.

### Task3: личные настройки и история
**Files:** classes/connect/notification_delivery.php own_summary; connect.php; templates/connect.mustache;
native connect_notification_test.php; tests/mobile/connect-link.cjs.
**Interfaces:** own_summary():array retains fields and adds event,notification_status,acknowledged_at;
all metadata bounded/own-only. Current preference JSON keys/types unchanged.
- [x] Native RED assertions notice ACK and provider acceptance separate, own-only, no sensitive fields.
- [x] Show event label, canonical ACK+time, no task completion implication; keep current preference form.
- [ ] Native tests/Chromium mobile check real Mustache/CSS at existing5widths, no view-as/CSRF regression.
- [ ] Commit UI/history checkpoint.

### Task4: native upgrade, wire, runtime and regression acceptance
**Files:** tests/stage/con02_callback_probe.php,con02_callback_wire.py,run-con02-callback.sh;
run-connect.sh; .github/workflows/ustar-review-gate.yml as needed.
- [x] Extend actual native wire for v3 and two bounded event labels, v1/v2 regression retained.
- [ ] Check2200→2300/repeat/paired restore2200, legacy99/profile retained and table100unchanged.
- [ ] Run full r16 suite, exact-source PHP8.2/8.3/native/browser/UID33/systemd/privateTLS CI.
- [ ] Download relevant artifacts, verify exact source/archive identities, report skips/limits honestly.

### Task5: coordinated successor delivery and handoff
**Files:** separate versioned successor controller/builder and focused transaction tests; existing4394
embedded release remains frozen. Final exact files/interfaces follow Task4 immutable source identity.
- [ ] Bind exact deployed534/local2200 and current CON02 journals as predecessor; reject unrelated profiles.
- [ ] Capture fresh pair/managed control before-images; new app/cron and explicit v3 runtime generation;
  operator check/status/activate/recover/disable with original fence/cutoffs/ACK keys/cursor retained.
- [ ] Prove interrupted pre/post-public recovery, actual owner metadata/HTTPS AutoReport, v2 fallback safe.
- [ ] Build ordinary.py from exact Git after tests, verify SHA/embedded files; provide owner commands.
- [ ] Update STATE/ACTIVE/BACKLOG/SESSION_LOG/index/PR receipts. Group deferred, no automatic expansion.

Task5 is a deployment gate, not satisfied by mocked CLI or Task1-4 success. No claim of full production
completion until owner executes the new controller and reports expected status. New routing/digest/Bitrix
remain deferred. Plan follows existing self execution method; no new execution-mode permission question.
