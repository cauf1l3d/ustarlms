# Private bridge delivery — implementation ledger

Continuation: owner application2000/new scheduled cron/browser-open accepted at c95e59dc.
Shared conversation6ad7edb3 was read directly through its last source-review message.
No application update, source recollection, cron repair or HTTPS cutover is repeated.

## Completed source components

- Worker signed scope probe uses registration.options with deliberately invalid,
  nonempty business fields. Actual PHP authenticates HMAC, then rejects link-only
  scope before durable nonce consumption. A broad key stops at invalid_payload,
  also before nonce consumption. Only exact HTTP403/forbidden_operation succeeds;
  wrong keys, generic denial, redirects, HTML and successful broad responses fail.
- Receiver --probe adds this proof only when linking_enabled=true. Old receiver-only
  configuration stays compatible; no Journal/getUpdates/sendMessage is opened by probe.
- Existing HTTPS manager has an internal loopback bridge transport profile: fixed path,
  POST only, public HTTP/HTTPS denial, shared CA leaf with SANs ustar.local/localhost/
  127.0.0.1. One existing renewal scheduler and original HTTPS rollback images remain.
- Profile changes persist both site before-images and previous state before writes.
  Recovery handles partial writes/lost reload ACK; unknown administrator drift refuses.
  Renewal renders the active profile. HTTP rollback requires bridge deactivation first.
- No bridge activation CLI has been exposed. Profile API is for the forthcoming
  coordinated delivery transaction; a direct call is not an operator procedure.

## Verification

RED observed: two runtime assertions, missing scoped-probe method, four profile tests.
Targeted GREEN locally:19 HTTPS tests,5 runtime tests,10 bridge tests with4 PHP skips.
Local whole suite:877tests/34skips,3errors because MCP is absent in the default runtime;
not claimed green. Recheck with repository test dependencies and exact CI pending.
New native fixtures exercise actual Apache/OpenSSL/PHP facade plus a separate real
Moodle5.1.8/PG16 signed denial and unchanged six Connect tables. Their results are
not claimed before CI. Systemd/Docker boundaries in Apache fixture remain modeled.
Self-review only;0subagents, no independent reviewer claim.

## Remaining delivery gates

1. Coordinated root transaction: exact HTTPS tool/state ownership handoff, private PHP
   include outside document root, independently generated256bit link.claim key scoped
   to current Telegram bot, exact backend peer proven by signed request.
2. Managed receiver/probe LoadCredential drop-ins, unchanged kernel loopback policy;
   verified release switch with one consumer stopped, token/journal/cursor untouched.
   Durable recovery must cover partial activation, administrator drift and service ACK.
3. Real systemd credential/isolation acceptance for the scoped probe, final immutable
   package/hash/CI and operator activation/deactivation runbook.
4. Owner same-session Academy→Telegram→confirm, replay/stale/group denial and revoke;
   then separately opt-in notifications with fresh cutoff. MiniApp remains deferred.

No new artifact is authorized as a production activation package by this ledger.
Agent has not accessed server1, generated a live key or called Telegram.
