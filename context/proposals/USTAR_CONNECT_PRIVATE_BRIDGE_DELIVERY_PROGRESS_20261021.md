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
Targeted GREEN locally:20 HTTPS tests,5 runtime tests,10 bridge tests with4 PHP skips;
8 new private-delivery configuration/readiness/package tests including immutable Git,
deterministic ZIP, plan-only mode, corruption/source/gateway/permission refusals.
Initial default-runtime run:877tests/34skips,3MCP-import errors. Recheck in a separate
venv with repository test dependencies:877tests/34skips PASS,exact source93122b2.
Source5cb0d7e0: Context39512853054 SUCCESS;both PHP source jobs SUCCESS;
actual systemd scoped credentials/ACL/UID and native Apache/OpenSSL/PHP runtime
job118938079352 SUCCESS. Wrong key prevents start;token/SQLite bytes unchanged.
Native profile probes pass after renew/lost ACK;public path variants and private GET denied.
Whole Review39512852940 is NOT green:actual Moodle/PG reached124tests/629assertions
and6concurrency PASS,then new signed HTTP scope check refused. Fixed-response-only
diagnostics are added to the isolated fixture;this gate remains pending.
Apache/systemd are separate fixtures:the Apache Moodle bootstrap and Docker boundary
are modeled;the systemd API opener is synthetic. Combined actual-Moodle/TLS/service
production acceptance is not claimed. Self-review caught missing path capture:Apache
appended the original URI to the fixed destination;native log reproduced doubled path.
Explicit literal capture/backreference fixes it;RequireAll enforces POST+loopback.
The CLI PHP fixture explicitly disables opcode caching to observe config replacements;
production Secure-cookie validation is unchanged.
Self-review only;0subagents, no independent reviewer claim.

## Narrow operator gate now prepared

A separate stdlib-only check ZIP validates eight pinned transport/version files,
current HTTPS ownership/live TLS,legacy receiver config/unit manifest and exact backend
gateway. It checks the new root-managed include parent and refuses PHP-writable or
foreign ancestors/existing private paths. It does not chmod/chown,generate a key,
call Telegram,open the journal,poll updates,query SQL,launch backup/cron or activate.
No whole552-file recollection. --check may create a normal anonymous Moodle session/cache.
Default is PLAN_ONLY. Root-private bundle/hash and existing shared locks are required.
This gate resolves the previously unmeasured parent protection needed for a secret
outside the document root;do not infer it from UID33 ownership of config.php alone.
Package factory source and exact CI/hash still need freezing before owner handoff.

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
