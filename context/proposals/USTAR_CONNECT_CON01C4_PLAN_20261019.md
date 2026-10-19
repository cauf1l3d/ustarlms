# Connect actor session Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline. Owner forbids subagents. Steps use checkbox syntax; the dated ledger is the continuation record.

**Goal:** A confirmed active employee can open their real Academy profile through a fresh verified Telegram launch; pending/unlinked users retain onboarding only.
**Architecture:** Reuse existing conn_sessions with distinct ua1_ purpose/scope actor_read. Private bridge owns current canonical user/link/credential/authorization checks; BFF stores opaque secrets only server-side and exposes a fixed self-profile. This is the provider-session part of the approved RFC, not Moodle cookie handoff or sensitive actor step-up.
**Tech Stack:** Moodle5.1.8/PHP8.3/PostgreSQL16; Python standard-library WSGI and existing JS/CSS, no new dependencies.
**Spec:** USTAR_CONNECT_RFC_20261018.md sections4/5/8; USTAR_CONNECT_CONTRACTS_20261019.md session/provider/read contracts; owner19Oct15:31MSK explicitly requested next implementation after C3 receipt941a853/106native530.

## Global Constraints

- Same Academy identity/permissions; no caller userid/role, no second personnel store; no admin session or password collection by worker.
- Provider-read activation explicit `$CFG->ustar_connect_provider_read_enabled === true`, default OFF. Only canonical manual-auth users; native tool_mfa enabled, alternate login URL, force password change or another auth plugin → native_auth_required. This deliberately does not attest MFA compatibility.
- Only own username/fullname/current position/department/status. Features=['profile']; no advertised role actions without an implemented permission-checked API. No email/password/HR answers/grades/files/Moodle session cookie exported.
- Fresh verified proof<=300s AND linked-active at exchange; old/pending/onboarding-after-approval cannot silently promote. New /start required. Personal-link receipt cannot open actor.
- ua1_256bit hash-at-rest; exact provider/tenant/key/browser/link revision; idle900s/absolute28800s. New token and cookie/CSRF rotation. No automatic retry after unknown activation outcome.
- On every read: current flags/business/employment/link, credential fingerprint and fixed role/current-position authorization fingerprint. Role/own primary/ACTING/manager change invalidates this session; future object commands still require current domain scope. Never cache a role grant in a JWT.
- No production/main/router/network/token/live provider operations. Existing C1/C2/C3 closed; native Academy HTTPS/external relay/full handoff/recovery/MFA/step-up remain separate gates.

## Review Focus

1. Approval between old pending entry and activation must require a fresh proof; test both pending exchange and first-link signup entry.
2. Unknown activation response/crash must not mint repeated actor sessions; durable entry consumption, volatile uncertain marker, no automatic retry.
3. Role/credential/ACTING changes after issue must deny reads despite a valid token; private guest request context, current canonical queries.
4. ua1_ must fail all onboarding/signup endpoints, ue1_ must fail actor reads, and logout must remove browser secret even if upstream unavailable.
5. UI reload, escaped profile strings, source outage, idle/absolute expiry and theme/mobile layouts must not show cached authority or stale data as current.

## Task1 — canonical provider-read session

Files: create classes/connect/actor_service.php, tests/connect_actor_test.php; modify entry_service.php, opaque_token.php, bridge_auth.php, bridge_service.php, db/install.xml/upgrade.php, version.php, privacy/provider.php, stage/run-connect.sh/concurrency.php. Candidate2026101905; same99tables. Existing conn_sessions adds scope(default onboarding),authdate,actorready,lastseenat,authstamp,scopestamp. Existing entries migrate actorready=0, cannot gain access.

Interfaces: actor_service::open(entry,browser,kid,provider,tenant):array → {session:ua1_,scope:actor_read,expires_at,idle_expires_at}; actor_service::status(session,browser,kid,provider,tenant):array → {scope,expires_at,idle_expires_at,profile:{username,fullname,position,department,account_state:active},features:[profile]}; actor_service::revoke(...same binding):void. No caller time/actor arguments. entry_service::require_session enforces scope onboarding. Fresh native exchange stores authdate and actorready from current active link; signup completion cannot set actorready.

- [ ] Write native tests defaults/freshness/token separation/current user+link+password+roles+organization/MFA/force-password gates/idle+absolute/replay/privacy. Publish test-only snapshot and observe exact native RED before product implementation.
- [ ] Implement service/schema/bridge after RED; open owns identity transaction, consumes entry and inserts actor together; max5live actor sessions/user. Status validates under identity lock/transaction and renews lastseen only after successful current checks. Invalidated sessions must never revive if flags/role/password later return to prior values: persist revokedat before returning fixed rejection.
- [ ] Add independent open/old-entry race: one actor grant, one invalid_entry. Schema fresh/upgrade/repeat parity. Privacy covers new metadata; no fingerprints/secrets in export. Existing generic prune/revoke lifecycle remains authoritative.
- [ ] Verify exact native CI and prior C3 regression suite, commit/ledger.

## Task2 — BFF and real profile UI

Files: create connect-worker/ustar_connect/actor_web.py validator; modify onboarding.py,web_sessions.py,bridge.py,web/index.html,onboarding.js,onboarding.css; tests/r16/test_connect_actor_web.py and tests/mobile/connect-actor.cjs (workflow runs alongside existing checks).

Interfaces: POST /api/activate {} uses only server entry+browser; /api/status on actor session calls actor.status; logout actor.revoke; no public arbitrary bridge. Exact ua1_ result stays private. Session actor/activation_uncertain/absolute expires; success extends private RAM only to canonical idle expiry, bootstrap never extends it. Own profile string fields<=256, exact schema/features; reject secret/extra fields. Signed-entry token consumed only by explicit profile button for linked_active, not automatically on GET/refresh.

- [ ] Write real WSGI boundary tests; observe missing-activation RED, then implement and run complete r16.
- [ ] Explicit profile button; loading/error/expired/MFA-required/activation-unknown instructions; real self-profile via textContent, no mock metrics, no pretend full Moodle login. /api/status failures clear displayed profile and forbid viewing stale authority. Cookie/CSRF rotate; old cookie fails. No local/session storage or auto activation retry.
- [ ] Browser actual assets test mobile390/desktop1280 + dark/light + reload/logout/error states. Existing link/onboarding/mobile checks remain green.

## Task3 — verification and continuation

- [ ] Update START_HERE/AGENTS/project/STATE/ACTIVE/BACKLOG/SESSION_LOG and context/source inventories to current C3 complete/C4 scope; retain dated originals and exact receipts.
- [ ] Personal security/diff review under owner no-agent rule; fix material findings with RED→GREEN. Run source/r16/context + exact native/browser/schema/concurrency. Record exact commit/tree/artifact and limits in PR106; no main merge or installation.
- [ ] Handoff explicitly distinguishes provider-read profile from full Moodle login, recovery/MFA/step-up, role business screens, relay/deployment gates. Duplicate development commands in chat.
