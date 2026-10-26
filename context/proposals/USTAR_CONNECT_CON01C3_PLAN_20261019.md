# CON01C.3 — canonical notification delivery and personal controls

Owner19Oct13:43MSK requested remaining contour; continued14:50MSK. Execute personally, no subagents. Base893a2cb3/PR106: C2 Context39236186807/Review39236186755 SUCCESS,84native/436assertions,24link browser scenarios. Spec: RFC notification/identity sections, CONTRACTS delivery states and existing notification_delivery architecture.

## Contract

Use existing local_ustar_notifications and notify_delivery, never a second business queue. Both canonical notification writers enqueue only NEW eligible events in their existing transaction. Global opt-in/cutover timestamp and personal explicit opt-in default off. Existing Bitrix/legacy pending/disabled are untouched; managed rows have connectversion=1. Personal settings use Moodle user_preferences, owner session/CSRF and current eligibility, not console identity: categories registration/tasks/feed/grades, minimum normal/action/critical severity, explicit IANA timezone, quiet hours, interval1–1440minutes. Disabling takes effect before send; no bulk historical backfill.

Queue binding: current link ID/revision; expiry per event family (task reminder/feed1h, remaining supported types24h). Managed fields added to existing delivery: connectversion,connlinkid,connrevision,connexpires,connleasehash,connleaseuntil,connkeyid,connbegun. New compound ready index. Candidate2026101904, same99tables. Upgrade/fresh/repeat parity required; no role grants/config activation.

Protocol: private scoped notification.lease(limit='1') → one hash-at-rest ud1_ lease60s. notification.begin(delivery,lease) rechecks current user/employment/link revision/preferences/domain recipient scope, then commits sending before any external call and returns only generic category/severity/time plus verified numeric subject, permit10s. notification.finish(delivery,lease,outcome,provider_ref,reason,retry_after) stores provider receipt; key ID/provider/tenant/lease fence every phase. Retried same final receipt idempotent; conflicting receipt rejected. No employee/admin actor grant.

Expired leased may be reclaimed (no begin means no send). Expired sending becomes uncertain and is never auto-retried. Definite429 uses durable retry_after,401 stops worker after durable retry delay,403/400 fail, malformed/5xx/timeout uncertain. Max5send attempts and event expiry. Provider accepted is not read/acknowledged/business resolved. Late provider receipt for the same sending/uncertain lease records the actual result without restoring privileges. One per-user in-flight delivery and last-begin cooldown avoid burst. Worker metadata is ephemeral, actual authority lives in Moodle. Fixed TLS API/client, no public listener or network changes.

Telegram text is readable but privacy-minimal: fixed category/severity, event time, instruction to open Academy. No raw canonical subject/message/HR review reason/title/name/URL. No launch ticket issued by notification worker; existing receiver remains personal entry point. Supported event map explicit; unknown events stay local, never guessed from an event name.

## Tasks / verification

1. Native tests first: defaults/validation/owner-only settings, canonical atomic enqueue/no backfill/Bitrix untouched, lease exclusivity/fencing, current flags/revoke/preferences/scope, expiry/retry limits/quiet DST/cooldown, unknown outcome and late/idempotent receipts, privacy erase. Publish test-only native RED, then implement preferences/policy/delivery/schema/bridge/owner view/privacy. Native exact CI must pass.
2. Worker tests first: fixed generic text, real Telegram HTTP payload parsing, no sensitive fields, begin-before-send, no send after timeout/restart/expired permit,429/401/403/5xx mappings, invalid lease/recipient and no error reflection. Opt-in CLI uses private receiver config and no network unless --run; bounded batch/stop/pacing. No actual bot token/API traffic.
3. Actual native template/mobile controls and browser tests; whole r16/source/context/native/schema/concurrency, personal final review, exact PR106 receipt. Update continuation docs/inventories and duplicate developer commands in chat. No main merge/production/router/BotFather changes.

## Review focus / decisions

Current recipient scope at begin; strict separation provider receipt vs human ACK; queue inherits canonical transaction; no replay of old pending; uncertain external side effect must not cause duplicate sends. Existing full remote login/recovery/MFA and sensitive actor step-up/role actions follow this delivery slice. External HTTPS and live bot activation still need owner allocation/rotated credential. A posted secret is never used or stored.
