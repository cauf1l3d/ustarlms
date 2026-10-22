# CON-02A.2 Telegram acknowledgement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Owner forbids subagents; implementation and author review stay in this session/current feature checkout.

**Goal:** Сотрудник подтверждает собственное уведомление непосредственно в личном Telegram-чате, а Академия сохраняет первое время ACK независимо от повторов и ошибок ответа бота.

**Architecture:** Существующие inbox/delivery и race-safe canonical writer сохраняются. Отдельный begin_v2 выдаёт ограниченный opaque action, отдельный ACK-only credential принимает callback через тот же receiver. Ответ Telegram и состояние provider delivery не заменяют canonical ACK.

**Tech Stack:** Moodle5.1.8/PHP8.3.33/PostgreSQL16.15, Python stdlib/SQLite journal schema1, существующие PHPUnit/r16/Chromium/systemd/TLS fixtures; без новых продуктовых зависимостей.

**Spec:** [Согласованный письменный проект A.2](USTAR_CONNECT_CON02_TELEGRAM_ACK_DESIGN_20261022.md). Owner22Oct: «переходи к реализации» — written spec accepted. План принят22Oct ответом «начинай выполнение». Выполнение идёт самостоятельно без субагентов; текущие результаты и открытые native gates — в [progress](../runtime/connect_con02_callback_progress_20261022.md).

## Global Constraints

- Один receiver/getUpdates и один sender; никакого webhook, нового consumer, второго inbox, history replay или расширения получателей. Принятый one-user pilot не повторять; live group tests deferred до полного запуска, не passed.
- Frozen a86 v1 `notification.lease/begin/finish` и точные permit fields сохраняются. HMAC envelope v1, private TLS/peer guards, durable nonce, lease/attempt/429/uncertain fencing не ослабляются.
- `notification.begin_v2` возвращает прежние sending fields плюс `version: 2` и `ack: null|{action,expires}`. Сохраняются прежние cancelled/retry_wait краткие ответы. `connectversion=1` — хранилище доставки, не версия permit.
- `notification.ack` payload ровно action/message_id/provider/subject/tenant, все строки. Нет caller userid, notificationid, URL, task command или имитации `$USER`.
- Action47 ASCII bytes: `ua1_` + canonical base64url32 random bytes без padding; SHA256 domain separator `ustar-connect-notification-ack-v1\0`. TTL=connexpires: reminder/feed1ч, остальные24ч от события. Только current attempt/link revision/recipient.
- Callback ACK разрешён для собственных unread/read/acknowledged и sending/provider_accepted/uncertain, отказ для failed/retry_wait/cancelled. Текущее account/employment ACTIVE/capability обязательно. Первый ackat и task/delivery не меняются при повторе.
- Default `ustar_connect_ack_enabled=false`; при true нужен positive integer `ustar_connect_ack_since`. Tokens выдаются новым событиям с timecreated>=cutoff. Личный opt-out прекращает отправки, но не собственный ACK уже доставленного уведомления.
- SQLite user_version1/lock/cursor/terminal states сохраняются; новый kind ack, лимит10/identity/60с. Bridge ACK timeout5с; Telegram UI methods3с; без retry одного update. Raw action/query body/private text не сохраняются в журнале.
- Candidate после кода: local_ustar2026102200/theme2026100202/100tables; file count вычисляется manifest. Сейчас candidate2100/554files/99tables. На server1 application2000+принятый policy overlay и worker/tool a86, UID130 last revoked.
- Работа в PR #106 `design/ustar-connect-20261018`, main не сливать. Нет agent SSH/provider sends/production activation. Guarded2000→2200 installer и coordinated cron/HTTPS ownership — следующий deployment срез, не скрытая часть этой реализации.

## Review Focus

1. Старое сообщение и свежий click: original message.date не проверяется command TTL120с; это закрепляет Task6.
2. Callback раньше finish или после lost finish: допустимый human ACK не изменяет transport uncertain; Task2/8.
3. Личный opt-out после доставки отличается от revoke/relink; Task2 принимает первое, отказывает второму.
4. Canonical ACK сохранён, но UI timeout/429: повтор не меняет первое время, journal/backoff не теряются; Task6/8.
5. Новая таблица не должна подменить старую99-table A.1 проверку; Task1/8 сохраняют immutable fixture и отдельный100-table lifecycle.

## File Structure and execution

| Единица | Файлы и ответственность |
|---|---|
| Metadata lifecycle | install.xml, upgrade.php, version.php, privacy/provider.php, identity_service.php: одна token table, bounded cleanup/export/erase |
| Canonical receipt | новые `classes/connect/receipt_actor.php`, `notification_action.php`; существующие notification_acknowledgement.php и notification_delivery.php: guard, token и writer |
| Wire | PHP bridge_auth/bridge_service/connect_bridge.php и Python bridge.py: два fixed operation/scopes, typed payload/rejections |
| Telegram transport/sender | telegram_api.py, notification_worker.py: typed UI methods и opt-in v2 permit |
| Callback processing | новый `ustar_connect/notification_callback.py`; receiver.py/receiver_journal.py: parser, durable phases и текущий polling loop |
| Runtime | receiver_runtime.py/notification_runtime.py и новые opt-in example configs/drop-ins; default/v1 profiles сохраняются |
| Native evidence | новые callback PHPUnit/r16/native DB/wire/concurrency/systemd fixtures; отдельный actual2200 lifecycle, frozen A.1 fixture |

Native PHP команды ниже выполняются **в disposable runner**, из `/stage/moodle`
после PHPUnit init, не на server1. Базовый запуск из checkout:
`docker compose -f tests/stage/patch-compose.yaml -f tests/stage/connect-compose.yaml build`
и затем `up --abort-on-container-exit --exit-code-from runner` после workflow exports.
При отсутствии Docker/PG локально native RED/GREEN получают в PR CI и записывают exact SHA;
SQLite/mock/r16 не объявляют native PASS. Для Python используется существующий test env
с pinned `tests/stage/python-requirements.txt`; продуктовые пакеты не добавляются.

---

### Task 1: Token schema, bounded lifecycle and preserved A.1 fixture

**Files:** Modify `moodle/local/ustar/db/{install.xml,upgrade.php}`, `version.php`,
`classes/privacy/provider.php`, `classes/connect/identity_service.php`,
`lang/{en,ru}/local_ustar.php`, `.github/workflows/ustar-review-gate.yml`,
`tests/stage/{run-connect.sh,run-con02-ack.sh,connect_release_probe.php,connect_scope_probe.php}`.
Create `moodle/local/ustar/tests/connect_notification_action_test.php`.

**Interfaces:** Existing `identity_service::prune_expired(int $limit=500): array`
retains existing return keys and adds bounded token deletion internally.
New table exactly as spec§5, primary/FKs/unique tokenhash+deliveryid/attempt,
userid/expires indexes;100tables/version2200, no rewrites of old inbox/delivery.
Native test helper `delivery_fixture(string $state='sending', int $attempt=1): array`
produces notice/delivery/link/uid for an ACTIVE business employee with local:use,
canonical task notice and explicitly configured one-user fence; credentials synthetic.

- [ ] **Step 1 — RED tests.** Add `test_schema_and_metadata_lifecycle` and
  `test_prune_limits_and_preserves_canonical_receipt`: insert501 expired+one fresh
  synthetic token rows, assert500 removed/one old+fresh retained; export includes
  safe metadata, not tokenhash/raw; erase removes user's tokens before FK parents,
  leaves other user and canonical ACK unchanged. Assert exact100tables/indexes.
  ```php
  $this->assertTrue($DB->get_manager()->table_exists('local_ustar_conn_acktokens'));
  $this->assertSame(100, count(array_filter($DB->get_tables(), fn($t) => str_starts_with($t, 'local_ustar_'))));
  ```
- [ ] **Step 2 — Run RED.** Native `php vendor/bin/phpunit --testsuite local_ustar_testsuite --filter connect_notification_action_test`; expect missing table assertions, not an unrelated bootstrap error.
- [ ] **Step 3 — Implement schema/lifecycle.** Savepoint2200 loads the one definition
  from install.xml and creates only if absent. Privacy context/user discovery,
  export and all three erase paths include acktokens; existing prune transaction
  deletes <=500 rows with expires<=now-7days. Preserve old prune result shape.
  Export exact A.1 plugin/theme+test fixtures from4fb4cd17 into
  `.stage-input/con02-a1`; old2000→2100 runner uses those immutable sources/scripts,
  never2200 current source. Keep con02_ack_probe.php/con02_ack_http.py/con02_ack_wire.py
  at their existing absolute paths and verify their bytes against4fb4cd17; only
  the runner's candidate source path changes. Preserve probe endpoint/state and frozen worker.
  Add privacy:metadata:connect:acktokens description in both language files.
  Current-source pair/schema probes derive candidate table names from current
  install.xml; baseline93 and frozen historical99 expectations stay explicit.
  Include connect_notification_action_test in the native PHPUnit filter now,
  so Task1/2 RED and GREEN execute the new tests rather than merely collecting files.
- [ ] **Step 4 — GREEN.** Run native tests plus old connect_identity/privacy tests;
  verify actual fresh/repeat100 and old A.1 no-schema-delta99 separately in CI.
  `python scripts/ci/source_checks.py` and `git diff --check` pass.
- [ ] **Step 5 — Commit** `feat: add bounded notification action metadata lifecycle`;
  regenerate development manifest/index with new source tree at the checkpoint.

### Task 2: Current-recipient token issue and canonical callback acknowledgement

**Files:** Create `moodle/local/ustar/classes/connect/{receipt_actor,notification_action}.php`.
Modify `classes/connect/{notification_acknowledgement,notification_delivery}.php`;
expand `tests/connect_notification_action_test.php`, retain connect_notification_ack_test.php.

**Interfaces:** `receipt_actor::require_active(int $userid): void` checks current
confirmed/nondeleted/nonsuspended business account, non-guest, ACTIVE and local:use.
Native A.1 still performs real session/login-as/view-as checks before this guard.
`notification_action::issue(stdClass $notice,stdClass $delivery,stdClass $link,int $now): ?array`
requires caller transaction and returns exactly action/expires or null.
`notification_action::acknowledge(string $action,identity $sender,string $messageid,int $now): array`
owns identity atomic lock+transaction and returns exact status/acknowledged_at/category.
`notification_delivery::recipient_enabled(int $userid): bool` becomes documented
internal shared predicate; existing body/HTTP exposure unchanged.

- [ ] **Step 1 — RED tests.** Add `test_action_is_hashed_and_recipient_bound`,
  `test_current_link_attempt_and_message_fences`, `test_optout_is_not_revoke`,
  `test_read_repeat_and_callback_before_finish_keep_first_ack`.
  ```php
  $first = notification_action::acknowledge($action, $sender, '991', time());
  $this->assertSame($first, notification_action::acknowledge($action, $sender, '991', time()));
  $this->assertEquals($deliveryBefore, $DB->get_record('local_ustar_notify_delivery', ['id' => $deliveryid]));
  ```
  Denial matrix: wrong subject/tenant/user/linkrevision/attempt/providerref,
  revoked/relinked same ID, expires==now, inactive/unconfirmed/capability loss,
  outside pilot, failed/retry_wait/cancelled, malformed/noncanonical action and
  boolean/nonnumeric message id at the route. One fixed ack_unavailable reason.
- [ ] **Step 2 — Run RED.** Native filter `connect_notification_action_test|connect_notification_ack_test`; missing new class is expected RED.
- [ ] **Step 3 — Implement interfaces.** Hash domain-separated raw token; issue only
  inside sending transaction and after configured cutoff/current native eligibility.
  Acknowledgement resolves UID from token/current link and validates every fence
  in spec§6 before canonical writer. Allow read/repeat/current opt-out, no unread-only
  delivery policy reuse. Accept sending/uncertain without providerref; when known,
  require message_id match. No delivery/attempt/task update or synthetic `$USER`.
  Reject a no-longer-allowlisted category before the writer; do not commit ACK
  and then return an invalid category packet.
- [ ] **Step 4 — GREEN.** Native matrix/A.1 regression pass; raw action absent from
  DB-export/log/journal fixtures, first ackat stable; caller lacking transaction
  cannot issue. Old own native ACK still works without a messenger link.
- [ ] **Step 5 — Commit** `feat: bind Telegram acknowledgement actions to current recipients`.

### Task 3: Versioned begin and narrow bridge operations

**Files:** Modify `classes/connect/{notification_delivery,bridge_auth,bridge_service}.php`,
`moodle/local/ustar/connect_bridge.php`, `connect-worker/ustar_connect/bridge.py`,
`moodle/local/ustar/tests/{connect_bridge,connect_notification_action}_test.php`,
`tests/r16/test_connect_bridge.py`.

**Interfaces:** `notification_delivery::begin_v2` uses the same argument signature
as `begin(int $id,string $lease,string $kid,string $provider,string $tenant,?int $now=null): array`.
Shared private begin core commits sending+action atomically; v1 serialization
stays exact. BridgeClient.request adds keyword-only `timeout=10`, allowing
literal5/10 seconds (bool rejected); existing calls retain10, ACK uses5.
Python rejection allowlist gains only ack_disabled/ack_unavailable.

- [ ] **Step 1 — RED tests.** Add `test_v1_keys_unchanged_and_v2_optin_is_exact`,
  `test_ack_key_cannot_lease_link_or_open_actor`, `test_disabled_ack_has_no_business_writes`,
  `test_ack_payload_has_no_userid_or_unknown_fields`.
  ```php
  $this->assertSame(['status','subject','category','severity','occurred_at','send_before'], array_keys($v1));
  $this->assertSame(2, $v2['version']);
  $this->assertSame(['action','expires'], array_keys($v2['ack']));
  ```
- [ ] **Step 2 — Run RED.** Native bridge/action filters and
  `python -m unittest discover -s tests/r16 -p test_connect_bridge.py -v`;
  unknown new operation/missing begin_v2 is expected failure.
- [ ] **Step 3 — Implement routing/scopes.** Keep existing envelope, nonce and
  sorted exact fields; old sender/link/actor keys cannot ACK or begin_v2. ACK key
  has only notification.ack; new sender key lease/begin_v2/finish only. Disabled
  A.2 returns ack_disabled before delivery/action/ACK writes; transport replay
  guard remains separate. v2 shares lease/finish, five attempts, cooldown/quiet,
  recipient fence and uncertain handling. Lost begin never regenerates raw action.
- [ ] **Step 4 — GREEN.** Native positive/negative scope matrix, nonce replay,
  message-id bounds and existing notification DB tests pass; v1 packets match frozen
  a86 exact parser. No fallback send after malformed/unknown v2 begin.
- [ ] **Step 5 — Commit** `feat: add scoped versioned notification acknowledgement bridge`.

### Task 4: Typed Telegram callback transport and fixed keyboard

**Files:** Modify `connect-worker/ustar_connect/telegram_api.py`; create
`tests/r16/test_connect_callback_transport.py`.

**Interfaces:** `get_updates(offset,*,acknowledgements_enabled=False)` selects only
message or message+callback_query. `send_notification(subject,category,severity,occurred_at,*,ack=None)`
keeps URL-only default. Add `answer_notification_ack(query_id,outcome,acknowledged_at=0)`
and `edit_notification_ack(subject,message_id,category)`; outcome is acknowledged,
unavailable or uncertain. Private `_call` fixes new-method timeout3 and method-specific result types.

- [x] **Step 1 — RED tests.** Named tests `test_poll_filter_optin_and_original_defaults`,
  `test_callback_true_is_not_a_message_dict`, `test_private_edit_requires_matching_message`,
  `test_keyboard_keeps_fixed_url_and_hides_private_content`.
  ```python
  self.assertIs(True, api.answer_notification_ack('query-1', 'acknowledged', NOW))
  self.assertEqual(['message', 'callback_query'], poll_payload['allowed_updates'])
  self.assertEqual(0, answer_payload['cache_time'])
  ```
- [x] **Step 2 — Run RED.** `python -m unittest discover -s tests/r16 -p test_connect_callback_transport.py -v`; missing methods/opt-in signature expected.
- [x] **Step 3 — Implement exact methods.** Add only answerCallbackQuery and
  editMessageReplyMarkup to method allowlist; strict true vs private Message,
  retain old dict/list validations. Validate47-byte canonical action and expiry,
  preserve protect_content and fixed native category URLs, no raw titles/body.
  UI copy/time UTC from spec§1/8, cache_time0, no URL redirect/extra sendMessage.
- [x] **Step 4 — GREEN.** Test bool-vs-int IDs, duplicate/oversized JSON, wrong
  edit chat/message, timeout/400/401/429/5xx and no disabled TLS/redirect/ambient proxy;
  run existing test_connect_receiver.py and test_connect_notifications.py too.
- [x] **Step 5 — Commit** `feat: add typed Telegram acknowledgement buttons and replies`.

### Task 5: Explicit v2 sender without v1 negotiation or resend

**Files:** Modify `connect-worker/ustar_connect/notification_worker.py`;
expand `tests/r16/test_connect_notifications.py`.

**Interfaces:** `NotificationWorker(...,notification_contract=1)` accepts literal1/2,
not bool. V1 calls notification.begin and old four-argument API exactly as before;
v2 calls notification.begin_v2 and passes validated ack as keyword. Same lease/finish.

- [x] **Step 1 — RED tests.** Add `test_v2_requires_exact_permit_and_fresh_action`,
  `test_null_ack_keeps_url_and_never_synthesizes_token`, `test_v2_bad_begin_never_downgrades_or_sends`.
  ```python
  self.assertEqual('notification.begin_v2', bridge.request.call_args_list[1].args[0])
  self.assertEqual(action, api.send_notification.call_args.kwargs['ack'])
  self.assertEqual(1, api.send_notification.call_count)
  ```
- [x] **Step 2 — Run RED.** `python -m unittest discover -s tests/r16 -p test_connect_notifications.py -v`; constructor/new branch unavailable expected.
- [x] **Step 3 — Implement branch.** Exact set/type/TTL validation for v2,
  expires>current time and no later than occurred_at+24h, with begin send_before<=10с;
  per-category server TTL remains authoritative. ack:null still sends safely.
  Keep subject fence, startup identity, durable retry/429/uncertain/max-attempt semantics;
  missing/malformed v2 cannot call v1 or create a second send.
- [x] **Step 4 — GREEN.** Both contracts pass success/cancelled/retry_wait,
  lost begin/send/finish, wrong recipient and authorization failures; frozen a86
  positive compatibility stays separate from current sender tests.
- [x] **Step 5 — Commit** `feat: opt notification sender into the v2 action permit`.

### Task 6: Callback parser, durable handler and existing receiver loop

**Files:** Create `connect-worker/ustar_connect/notification_callback.py`,
`tests/r16/test_connect_notification_callback.py`; modify `receiver.py`, `receiver_journal.py`.

**Interfaces:** Frozen repr=False dataclass `AckQuery(update_id:int,query_id:str,subject:int,message_id:int,action:str)`;
`parse_callback(update:dict,*,bot_id:str,now:int)->AckQuery|None` implements spec§7.
`AcknowledgementHandler(api,bridge,journal,*,tenant:str,clock=time.time).handle(query:AckQuery)->None`
owns begin/rate-limit/phases/terminal for ACK only; propagates TelegramError429/401/409
after recording terminal so receiver uses existing `_delay`. `TelegramReceiver`
adds optional acknowledgements_enabled=False and ack_bridge=None, requires separate
bridge when enabled; existing command dispatch unchanged.

- [x] **Step 1 — RED tests.** Add `test_old_message_is_not_an_old_click`,
  `test_callback_origin_denial_matrix`, `test_commit_then_lost_ui_keeps_receipt`,
  `test_duplicate_restart_and_rate_limit_do_not_repeat_bridge`.
  ```python
  self.assertIsNotNone(parse_callback(callback(message_date=NOW-3600), bot_id='123456', now=NOW))
  self.assertEqual(1, ack_bridge.request.call_count)
  self.assertEqual(1, journal._db.execute('PRAGMA user_version').fetchone()[0])
  ```
- [x] **Step 2 — Run RED.** `python -m unittest discover -s tests/r16 -p test_connect_notification_callback.py -v`; missing parser/handler expected.
- [x] **Step 3 — Implement parser/handler.** Exact update envelope, printable
  ASCII query id1–256, human/private/same chat, verified bot-origin, positive typed
  IDs/date, canonical ua1. Deny forwarding/inline/game/business/guest/topic/inaccessible.
  Use ProviderIdentity hash, kind ack10/60, durable received→creating→sending and
  single5с bridge call. Validate exact acknowledged response/category/first timestamp;
  answer success only then edit URL-only markup. Bridge unknown yields uncertain UI,
  fixed refusal yields unavailable UI; neither edits keyboard. No raw action/query saved.
  The bridge call is `ack_bridge.request('notification.ack', payload, timeout=5)`;
  expected actor/token denials are normalized to ack_unavailable in the native
  service, not leaked capability exception text or success from a malformed envelope.
- [x] **Step 4 — GREEN.** Crash/recover in every phase, duplicate batch/current
  offset, original old date/future31с, bool IDs, attempted foreign sender,
  answer400/timeout/429, edit failure after success, shutdown and unprocessed batch
  leave no duplicate ACK/send. Preserve old command freshness and all old limits.
  Frozen a86 opens schema1 with ack rows and safely ignores queued callbacks.
- [x] **Step 5 — Commit** `feat: process personal acknowledgement callbacks in the existing receiver`.

### Task 7: Strict runtime opt-in and separate credential profiles

**Files:** Modify `receiver_runtime.py`, `notification_runtime.py`, `bridge.py`;
create `connect-worker/receiver-ack.example.json`, `notifications-v2.example.json`,
`deploy/ack-receiver.example.conf`, `deploy/ack-probe.example.conf`,
`tests/r16/test_connect_ack_configuration.py`.

**Interfaces:** Configuration gains acknowledgements_enabled=False,
ack_bridge=None, notification_contract=1. Receiver optional boolean flag uses paired
ack_bridge_key_id/ack_bridge_key_file only when true; shared endpoint/CA, distinct
key id from existing bridge. Notification-only profile permits notification_contract
literal1/2, no ACK credential. Missing/mixed/unknown/type-invalid groups fail closed.
`BridgeClient.probe_ack_scope()` proves fixed denial of link.claim and
notification.lease without consuming business payload; new sender probe additionally
proves notification.begin v1 denied. Positive scope is tested on actual synthetic
native actions in Task8, not inferred from a denial probe.

- [x] **Step 1 — RED tests.** Add `test_old_profiles_load_with_network_unused`,
  `test_ack_requires_distinct_private_key_pair`, `test_sender_never_loads_ack_key`,
  `test_probe_does_not_poll_lease_send_or_ack`.
  ```python
  self.assertFalse(load_config(old_receiver).acknowledgements_enabled)
  self.assertEqual(2, load_config(new_sender, notification=True).notification_contract)
  api.get_updates.assert_not_called(); bridge.request.assert_not_called()
  ```
- [x] **Step 2 — Run RED.** `python -m unittest discover -s tests/r16 -p test_connect_ack_configuration.py -v`.
- [x] **Step 3 — Implement loading/runtime wiring.** Read credentials with existing
  private_files/systemd ACL checks; receiver constructs separate ACK client
  (Task6 sets request timeout5). When a link bridge is also loaded, reject equal
  key ids or equal secret bytes; do not read the sender's private credential.
  startup probe adds narrow denial checks, sender receives selected contract.
  New example drop-ins load ack-bridge.key separately into same receiver/probe
  services; existing base units/install configure and default example profiles
  remain v1 and do not require missing credentials. No auto-enable/network in CLI default.
- [x] **Step 4 — GREEN.** Verify malformed/reused key id, missing file, unsafe
  file/ACL, repeated secret under another link key id, disabled extra secrets,
  bool contract, wrong private CA and no credential
  rendering. Existing configure/notification pilot/package tests remain green.
- [x] **Step 5 — Commit** `feat: configure acknowledgement callbacks with isolated scoped credentials`.

### Task 8: Native2200 lifecycle, races and actual systemd/private TLS evidence

**Files:** Create `tests/stage/{run-con02-callback.sh,con02_callback_probe.php,con02_callback_concurrency.php,con02_callback_wire.py,con02_callback_native.py}`;
modify `tests/stage/run-connect.sh`, `.github/workflows/ustar-review-gate.yml`.
Expand native action/bridge tests from Tasks1–3; preserve frozen A.1 scripts and old guards.

**Interfaces:** Disposable lifecycle exports2100 A.1 from4fb4cd17 and frozen a86
worker froma86e2080, upgrades to current2200, repeats/fresh-compares100tables,
verifies old rows and pairs DB+source+config+moodledata restore to2100/99.
New callback filter `connect_notification_action_test` is explicitly included.
Artifacts prefix con02-callback; sanitized assertions/counts only, no SQL dump/secrets.

- [ ] **Step 1 — RED tests.** Native differential races callback/native ACK,
  callback/read and callback/revoke: first committed ackat stable, serialized revoke
  denies a later callback. Add actual2200 new table/index/fresh/repeat/privacy/prune
  and same-schema a86 worker handback tests. Snapshot canonical history/providerref
  before/after. Treat missing2200 runtime path or omitted test filter as failure.
- [ ] **Step 2 — Run RED.** Execute disposable connect compose workflow before
  adding the new lifecycle invocation; explicit callback result/evidence is absent,
  not silently accepted as source-only PASS. Record exact RED SHA/job.
- [ ] **Step 3 — Implement fixtures.** Run old A.1 lifecycle on its pinned source
  and then new callback lifecycle separately. Feed actual PHP v1/v2 packets through
  frozen/current Python parsers and signed ACK back into native bridge_service;
  malformed/scope/nonce/disabled cases deny without business writes. Add isolated
  systemd credential/UID33/private TLS fixture with synthetic provider only,
  scoped positive/negative operations and actual old→new→disabled/a86 service
  handoff on same journal/cursor/link/preferences. No second receiver during switch,
  no drop_pending/reset/replay; known in-flight lease settles/expires.
- [ ] **Step 4 — GREEN.** New lifecycle reports CON02_CALLBACK_UPGRADE_RECOVERY=PASS
  and CON02_CALLBACK_RUNTIME=PASS with schema/count assertions; native writer races,
  actual private TLS/peer denial, scope and credentials all pass. Distinguish actual
  Moodle service dispatch from synthetic provider and separate TLS fixture;
  no fabricated forwarded-TLS or remote-production acceptance claim.
- [ ] **Step 5 — Commit** `test: verify callback upgrade races and compatible worker handoff`.

### Task 9: Whole-slice regression, exact evidence and reviewable delivery

**Files:** Update plan checkboxes, agreed design status, AGENTS/START_HERE,
context/{CONTEXT_INDEX.md,project.yaml,tasks/ACTIVE.md,roadmap/STATE.yaml,roadmap/BACKLOG.yaml,roadmap/SESSION_LOG.md},
generated development_source/context_index. Create
`context/runtime/connect_con02_callback_20261022.md` and matching `_ci_20261022.json`.
Update PR #106 title/body around final implemented behavior and exact limits.

**Interfaces:** Source2200 is an unmerged/not-deployed candidate with computed
file/tree/hash inventory. Receipt separates local/CI/native/synthetic/owner/live
and records exact SHAs/jobs/skips; no fake installer or new production state.

- [ ] **Step 1 — Review evidence gaps.** Check Tasks1–8 against every spec section;
  absence of denied sender, stable ACK race, v1 compatibility, paired recovery or
  actual credential/TLS result blocks completion. Write a failing test for each
  actual uncovered behavior before changing product code; do not add mirror tests.
- [ ] **Step 2 — Run checks.** Python r16, current PHP8.2/8.3 source checks,
  existing native/Chromium/actual-host jobs, new callbacks and prior ACK/link tests.
  Context regeneration/check and git diff --check pass. Local absent PHP/Docker/MCP
  dependencies are recorded honestly and resolved by exact CI, not counted as PASS.
- [ ] **Step 3 — Self-review final diff.** Verify no scope/actor bypass, global
  exception suppression, raw token persistence, repeated send, source-bound frozen
  helper edit or hidden activation. Record author-only review,0subagents, no
  independent reviewer. Fix findings with focused RED/GREEN and rerun affected checks.
- [ ] **Step 4 — Publish exact source and inspect CI.** Preserve expected-head lease,
  compare published/local trees, verify completed jobs/artifacts and declared skips;
  evidence must cover final source tree, not an earlier helper build. Review/Context
  success alone does not label skipped historical FullRC jobs passed.
- [ ] **Step 5 — Commit/publish final evidence and handoff.** Update actual candidate
  manifest and next state; no server commands until separate guarded installation
  package/fresh pair/cron+HTTPS handoff is prepared. Next product slices remain
  B routing/preferences then C Bitrix(actual portal/API unknown).

## Plan self-review and next gate

Coverage: spec§1–3→Tasks2/4/6; §4→Tasks3/5; §5→Tasks1/2; §6→Tasks2/3/8;
§7–8→Tasks4/6; §9→Tasks7/8 and separate deployment boundary; §10→Tasks8/9.
Review Focus1–5 each has named assertions above. Method signatures/typed payloads,
fixed timeouts/cutoffs and statuses agree across tasks. Tasks4–7 completed local
RED/GREEN; native lifecycle/service gates remain in the progress receipt. Checkboxes
track actual execution; skipped environments are not native acceptance.

Owner22Oct approved the written plan and explicitly ordered execution. Method:
**self implementation in this session, no subagents**. No further owner gate
between tasks; continue executing-plans/TDD until the source slice and evidence
are complete. Do not repeat pilot tests or ask to choose agents.
