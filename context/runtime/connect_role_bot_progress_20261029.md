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
