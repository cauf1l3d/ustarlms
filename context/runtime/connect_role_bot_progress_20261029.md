# Ledger — plan: docs/superpowers/plans/2026-10-29-connect-role-bot.md

29Oct2026 Europe/Moscow. Base receipt9d10d441; branch feat/connect-role-bot-20261029.
Owner2803 receipt published, reminder30Oct11:00MSK created. Self/no subagents.

Ruling: owner explicitly ordered full implementation after visual discussion and asks rational skills;
no repeated approval/method menu. Spec/plan capture concrete work and independent-confirmation boundary.
Ruling: sensitive commands use existing protected native Academy confirmation, not a new TOTP store.
Cost: such commands wait for LAN access until later external HTTPS; ordinary bot work remains remote.
Ruling: random unpublished password + canonical pending registration, first password setup in LAN.
Cost: newly approved staff need native account recovery/setup before browser login.
Pre-flight: Task2 consumes Task1 bounded view/action receipt; Task3 consumes same view, no business writer.
Task4 must freeze actual application and worker, not current mutable HEAD. Old6c56 runtime unchanged.

Task1/2/3 implementation candidate written, not yet qualified/deployed:
- Fixed bot.update/bot.upload scope, current actor/role/link stamp,900s opaque actions and7d event receipts.
- Canonical task/checklist/report/photos/review, own/team learning, CEO dashboard, staff requests/review,
  registration, grading/retrain/adaptation, personal notification prefs and revoke. Sensitive decisions
  require independent current native session POST/sesskey. User/group fields never come from caller.
- Python button cards/progress/tables, private personal updates and upload protocol; old link/ACK retained.
- Native row serialization added where channel receipt creates an outer transaction; canonical history retained.
- Privacy export/delete for new dialog/action metadata and private drafts; existing domain privacy remains separate.
Observed local evidence: purePHP request3 RED before implementation then GREEN; Telegram interface6 RED
before implementation then GREEN; navigation2 RED then GREEN. Latest local14 tests PASS/0.096s including
actualSQLite duplicate/uncertain transport receipts and photo authorization-before-download.
Earlier affected139 tests PASS before latest role/guard/privacy changes; must not substitute for final run.
Native tests written (no localMoodle/PG/Docker available); CI not started yet. Task4 operator/archive pending.
No provider sends, SSH, activation, merge or independent review claimed.

First candidate b57679db published in draftPR111 (no merge). Local fullR16:1414 tests/303.092s PASS,
50 environment skips. Source416PHP/33JS/103tables/57templates, context/secret guard PASS.
CI review40817085586 bothPHP source PASS; infra40817085547/context40817085548/console40817085555 PASS.
Connect-native170/850: migrations/fresh/repeat and new task/photo/registration flows passed, three checks failed:
- old ACK schema lifecycle still asserted100 instead of103;
- revoked-link button assertion used wrong letter case;
- positive native confirmation attempted in NO_MOODLE_COOKIES CLI and correctly refused.
Ruling: preserve no-cookie guard; replace that positive CLI case with genuine native HTTP login/GET/invalidCSRF/
POST/repeat/actor test, retain CLI denial. Added103 schema expectation, clearer first-password menu,
fresh read navigation, full paged report/question/form review, all learning pages, scoped adaptation assignment/HRD.
New successor operator/profile/builder in progress; pureprofile/contract5 RED(missing module) ->GREEN;
reference/envelope2 GREEN against first candidate before later app freeze. Not qualified for deployment.

App/worker freeze3935779fd432a1a057f5f24e41d93f38a651a1e2 (component trees only; not a deployment).
New coordinated successor retains old operators unchanged; separate bot-only key, old link/ACK/v3 keys retained.
Recipient profile becomes explicit academy_linked_opt_in after future cutoff; personal prefs untouched.
Local successor13 tests PASS (7reference/profile/contract13.889s +6real-files/SQLite recovery20.261s),
plus updated3profile tests PASS/2.229s. ActualUID33/package/complete native qualification still pending.
Native gate now exports exact2803, installs legacy pair in separate botold_ prefix, upgrades2900/repeats,
checks actual103 schema and SQL denials; frozen100 validators run on labeled historical projection.
Old2100→2300 callback/presentation drills now pin6c56 instead of accidentally installing newer schema.
Additional actual systemd/LoadCredential/privateTLS role-menu roundtrip and actual HTTPS-owner preservation
are prepared for CI. API/store synthetic there; actual Moodle/PG/cookie/CSRF verification stays separate.
Author review before candidate publication: dialog/actor replay/current scope, nested transaction row locks,
private draft retention/bounds, root credential separation, uncertain transport and paired/public recovery checked.
This is author self-review, not independent or CodeRabbit review. No subagents per owner instruction.
No archive is marked installable until exact candidate CI/bytes/retained copy qualification.

Pre-publication review of62773f82 operator snapshot found a material pilot boundary:
notification_action::recipient_allowed required the pilot UID-list property, which new role profile unsets.
RealPHP policy test observed RED (broad explicit profile false); fixed via server-only explicit
ustar_connect_role_delivery_profile + bot_enabled, keeping absent/unconfigured and empty-list denials.
New15 local bot tests PASS/0.110s; native old-token/new-recipient ACK lifecycle added, not yet executed.
393 app/627 operator snapshots are superseded candidates, never installed/delivered and not published to PR head.
Refresh app/worker freeze before qualifying final operator. No protocol downgrade or personal opt-in change.
Also bounded photo-draft lifecycle: completed/cancelled/replaced forms remove only their private
FileAPI drafts (canonical report images remain). Stale partial uploads from replaced dialog revisions
no longer consume the next form's unfinished-upload quota. Native photo test checks canonical bytes
still readable and private area empty. Independent native confirmation preserves a newer bot form;
the real HTTP fixture now retains an unrelated unsent photo/form across POST/repeat. Native pending.

### Final application freeze refresh

- Optional Telegram `PhotoSize.file_size` / `File.file_size` no longer silently discard an otherwise valid photo. Actual download is still bounded to512KiB, each supplied size must match actual bytes, and the canonical private upload authorization precedes downloading. Three new tests reproduced2failures/1error before the change; complete focused bot suite18tests passes afterwards. Reference: official Telegram Bot API PhotoSize/File optional fields,29Oct2026.
- The staged application follow-up also preserves a newer bot form/private photo while an older independent native command is confirmed, clears abandoned private photo drafts and keeps canonical report files, and authorizes ACK only under the explicit linked/opt-in profile. Native cases added; qualification remains pending the new immutable candidate.

Application and worker frozen at `5cc02944e1059ba015afcc7c5e8068ca56c34a92`. Coordinated operator now pins that source, including ACK profile/draft lifecycle/optional photo size fixes. Candidate tool commit and all final CI remain pending; production stays2803/worker6c56.

### Qualification candidate d36b6540 — isolated HTTP fixture correction

- On d36b6540, source8.2/source8.3, rollback, console, context and infrastructure all passed. Actual systemd/UID33/credentials/privateTLS role bot activation and recovery passed. ActualPG frozen2803->2900 upgrade/repeat and103-table metadata passed. Connect172tests/875assertions and both full Moodle suites371tests/1751assertions passed.
- The overall review gate correctly remained red: the new positive nativeHTTP fixture tried linking the Moodle site administrator. Canonical accounts::is_business_account intentionally excludes site administrators, so identity_service refused inactive_actor. This is a fixture error; production account/authorization rules are unchanged.
- Corrected fixture creates an active synthetic non-admin HRD through existing account/employment/role APIs, links that identity, logs in through a separate real HTTP cookie, asserts another logged-in actor cannot see/confirm the prepared command, and retains anonymous/GET/invalid-CSRF/valid-POST/repeat/newer-draft assertions. PHP syntax and shell parsing pass; new immutable candidate native rerun pending.

### Frozen privilege regression source selection

- Candidate99dbecaa passed both PHP source matrices, infrastructure/systemd/UID33/TLS, console, context, rollback, actual2803->2900/repeat, Connect172/875 and the new real native cookie/CSRF/replay/actor/newer-draft scenario. Historical full Moodle371/1751 passed. Patch full Moodle result is being collected.
- The remaining Connect-job failure occurs later, in the historical ordinary-DB-role regression: run-db-privileges.sh copied current/source2900 while its frozen probe correctly requires2300. Pin that regression to the existing immutable6c56/2300 fixture, like the callback/presentation regressions; do not loosen or edit its SQL/role/version assertions. Actual2900 proof stays separate.
- The deployable controller/app/worker bytes do not change. Infrastructure packaging now explicitly rebuilds fixed tool99dbecaa so documentation and historical-harness follow-ups cannot silently create a different owner archive. Its CI artifact12884197508 already matches the local verified archive (ZIP CRC, SHA25613333646…,21081981bytes). Whole-gate qualification is still pending the fixed regression; no owner deploy/provider sends.
- Patch5.1.8 full Moodle also passed371/1751 and genuine nativeHTTP confirmation. Its following role-upgrade hook then stopped at its cwd guard because a child shell's cd does not change run-patch.sh's /stage cwd. Add explicit cd /stage/moodle before that hook and a fixed failure-line diagnostic. This does not change migration, permission or source assertions.

### Historical projection SQL width

Static follow-through caught a remaining fixture-only defect before accepting the gate: replacing fresh_ by the shorter hist_ retained db_query's numeric substring/left widths. Reproduced that the transformed SQL differed from a real hist_ query; use equal-width histx_ and assert the width. The transformed SQL now equals db_query(histx_) byte-for-byte. Frozen100 validators and actual103 release checks remain unchanged. No application/controller/worker/archive bytes change.

### be0d19f0 native results and historical sequence correction

All new release-native checks passed on be0d19f0, including the complete Connect job (ordinary DB role/handoff restored), actual2803->2900/repeat/strict103 metadata, native cookie/CSRF/actor/newer-draft confirmation, and full Moodle371/1751 on both runtimes. Only the following historical100 projection failed: PostgreSQL LIKE copies serial defaults as fresh_* sequences, while the unchanged strict validator correctly requires histx_* sequence names. The projection now creates/owns correctly named sequences for historical plugin columns and preserves the next value; source fresh103 and all frozen validators remain untouched. This corrects the test adapter, not application/controller/worker/archive bytes. Source1436/235-skips eachPHP and infrastructure already passed. Final gate remains red until the corrected historical adapter passes.
