# CON01B.4 — browser onboarding Implementation Plan

> Executor personally via executing-plans. Owner19Oct09:55MSK requested remaining stages and immediate next development. No subagents; same draft PR106; no merge/deploy/credentials/network changes. Existing RFC/contracts and previous handoff supply the approved product design; do not restart an approval loop.

**Goal:** A narrow private-worker browser BFF and readable mobile registration form use the existing verified entry/canonical signup, without exposing bridge credentials or claiming working external transport.

**Architecture:** Browser → same-origin HTTPS relay → private worker WSGI application → existing HTTPS BridgeClient → Moodle. Edge never receives bot/service keys. Only exact browser routes are callable; no public launch.create/generic bridge/URL proxy. Launch requires signed Telegram initData.start_param issued by the future trusted bot receiver. This slice does not implement that receiver or the outbound relay.

**Tech Stack:** Python3.12 standard library, dependency-free HTML/CSS/JS onboarding client, existing pinned Playwright acceptance tooling. No new SQL schema, Moodle version or persistent business store.

**Spec:** RFC sections2–4,8–10 and USTAR_CONNECT_CONTRACTS_20261019.md; concrete browser contract below.

## Global constraints and design decisions

- Base d4f7c1bdfbb67a8afe5783ef7ab9c7da57b07356: Context39192666105/Review39192666066 SUCCESS,78native tests/399assertions, all4concurrency markers/schema parity PASS. Production e1d57f5b/526/93 unchanged; candidate2026101902/542/99 unchanged by this worker-only slice.
- RFC's React/TS/Vite was proposed, not required. For this single six-field entry form choose native JS/CSS and no new build dependencies; future wider Mini App may adopt a bundle without changing API. Cost: no component framework yet, smaller supply chain now. Legacy frontend stays inactive.
- WSGI callable is private-worker code, not a production HTTP server/installer. HTTPS scheme and Host must match configured single HTTPS origin; never trust forwarded headers. Integration must supply authenticated bounded relay/local TLS, disable payload/cookie logs, and pass request metadata safely. No wsgiref exposure/port opening or tunnel provider selection.
- Exact routes: GET / (static shell), /onboarding.js, /onboarding.css; GET /api/bootstrap with X-Ustar-Connect:1; POST /api/launch {init_data}; POST /api/options {}; POST /api/status {}; POST /api/register {six canonical form fields}; POST /api/logout {}. API query strings, unknown keys, non-JSON, duplicate JSON keys, >24KiB/declared-length mismatch rejected. No CORS.
- API custom header, same-origin Origin for every POST, synchronizer CSRF token and __Host-ustar_connect Secure/HttpOnly/SameSite=Strict cookie. Bootstrap rejects cross-site fetch and requires custom header. No tokens in URL/localStorage/sessionStorage. Telegram SDK only supplies raw signed initData, never trust initDataUnsafe.
- Cookie256bit stored as hash; entry/browser secrets only in private worker RAM. Preauth120s, authenticated maximum600s and upstream expiry. Rotate cookie+CSRF at authentication. Bounded single-process store max500 sessions; restart/expiry fails closed and asks new bot launch. No affinity/multiworker claim. Moodle remains durable owner of signup receipts. No passwords/forms/initData in session records.
- Global bootstrap30/min; per-session API60/min, launch3 and submit5 attempts per session; max4 concurrent bridge calls, serialize session mutations via bounded nonblocking lock. Aggregate canonical30attempts/15min remains unchanged. Production capacity is an explicit activation gate, not DoS resilience claimed by these limits.
- Private BridgeClient must preserve only whitelisted validation/conflict/expiry reason codes, never raw HTTP error content/messages; transport/unknown failures become source_unavailable. No automatic retry. Server-owned ur1_ command stable per session, UI never chooses actor/command. Lost response → own status; retry uses same command and re-entered exact form. Form/password not persisted.
- UI displays unlinked/awaiting approval/approved-but-onboarding-only/rejected/unavailable explicitly. Existing account path explains canonical login/link not implemented here, no password-reset/link placeholder pretending to work. No learning/HR write button, fake data, sent-Telegram claim or employee grant.
- CSP self assets + official Telegram SDK, no inline script/style; no-store/referrer none/nosniff/permissions policy. frame-ancestors allows Telegram Web origin (native WebView is not an iframe), no unrelated framing. No raw upstream data rendered as HTML.

## Review focus

1. Cross-site bootstrap/launch and duplicate cookies cannot fixate session; no upstream call on guard failure.
2. Upstream may commit then lose response: stable command and explicit status, no fresh mutation key/retry or fabricated success.
3. Closing/restarting worker loses RAM sessions, never durable Moodle account/receipt; reopening is required, no duplicate account path.
4. Worker/edge unavailable or browser offline: form explains unknown outcome, no false successful registration.
5. Unicode/malicious department names/error strings: textContent only, limited safe response, no secret reflection.

### Task1: Safe bridge rejection envelopes

Files: connect-worker/ustar_connect/bridge.py; tests/r16/test_connect_web.py.
Interfaces: BridgeRejected(ValueError) exposes reason/optional whitelisted field; BridgeClient.request otherwise retains response contract.
- [ ] Tests simulate real urllib HTTPError with422 validation/403 expiry/conflict; malformed/oversize/unknown error must remain bridge_unavailable. Observe RED before implementation.
- [ ] Implement bounded safe decoding and no exception chain/body disclosure, no retry.
- [ ] Run targeted unittest, verify existing bridge tests.

### Task2: Browser BFF boundary

Files: new connect-worker/ustar_connect/onboarding.py, web_sessions.py; tests/r16/test_connect_web.py.
Interfaces: OnboardingApp(origin,bot_token,bridge,clock=time.time) is WSGI callable. Bridge dependency consumes fixed operation/payload and returns {ok,result}; test fixture simulates only remote boundary, not Moodle correctness. SessionStore is volatile bounded state and nonblocking locks, not another user DB.
- [ ] Real WSGI requests test HTTPS/Host/Origin/CSRF/cookie/JSON/expiry/limits/rotation, real signed Telegram initData, no verifier bypass or public launch.create, fixed six-field submission and stable command/status/logout.
- [ ] Observe missing app RED; implement minimal guards, then bridge/session orchestration.
- [ ] Verify parallel requests denied when busy, failure sanitization, current-state recheck delegated on every business call, logout removes local session even if revocation upstream unavailable.

### Task3: Mobile UI and evidence

Files: connect-worker/web/{index.html,onboarding.js,onboarding.css}; tests/mobile/connect.cjs; workflow adds browser test after existing pinned Playwright install. README/context entrypoints/state/active/backlog/session/release ledger/index.
- [ ] Browser tests exercise actual assets against synthetic same-origin API: unlinked six-field submission, password cleared, pending and status refresh, active not full login, field validation, offline/no data, expired, no token storage, theme contrast/mobile overflow, unsafe label rendered as text. This is browser UI evidence, not live Telegram/remote transport.
- [ ] Run targeted tests, all r16 (baseline environment gaps reported), node syntax, context/inventory checks. Current environment missing old temporary PHP/venv; restore test-only pinned Python deps, no product install.
- [ ] Self-review only (owner prohibits agents). Publish same PR with expected-head lease; exact final CI receipt in PR body. Preserve worktree/main/production. Activation/edge/token config deferred.

## Remaining product stages (not percent-complete claims)

1. This narrow onboarding form/BFF, then canonical login/recovery/confirmed linking and independent actor step-up.
2. Trusted Telegram receiver/polling/cursors plus canonical outbox leases/delivery/ACK/preferences; one bot, not INF06 token.
3. Capability-filtered employee/team/HRD screens and canonical domain commands, terminal CEO policy only after explicit policy choice.
4. Outbound-only public HTTPS relay and operational/network/security/mobile/restore/load acceptance. Full native LMS/Quiz/SCORM separate origin gate.
5. Bitrix and WhatsApp adapters: actual portal/provider eligibility, webhook/delivery tests. Same domains, no copied users/roles/CRM.
