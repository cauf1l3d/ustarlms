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
Frozen readonly package:e4d7a154/14de548410f1cc861e9f9eae8d79018ab4f6dee67c218a4f84d29fdae95075af,
artifact12307432021/run39514608522;downloaded CI bytes match local assembly.
Both source jobs PASS886/136skips;runtime118943644504 PASS;whole review not green.
Owner command delivered,JSON receipt pending. [Runbook](../runtime/connect_private_bridge_check_20261021.md).

Actual e4 Moodle HTTP diagnostic isolated the refusal:HTTPS=on,peer127.0.0.1,
config flag enabled="1" (string),404bridge_disabled. Core normalizes pre-bootstrap
scalar flags. Delivery compiler now appends plugin-only include after setup returns;
core HTTPS/DB settings remain before setup,strict boolean guard/application unchanged.
New native fixture consumes that actual compiler/include and drops a PHP child to
UID/GID33 to prove group read/no write. Sourceec9af9e4 Context39516048080/Review39516048076 SUCCESS;124/629+6concurrency,
UID33 private include,signed scope/wrong key/counts and both paired restores PASS;
frozen readonly e4 package has no activation mode and does not call its compiler.

## Final verified source checkpoint

[Exact receipt](../runtime/connect_private_bridge_ci_20261021.json):ec9af9e4 full source
CI PASS,886/136skips on both PHP versions. Actual Moodle/PG now proves the corrected
post-bootstrap private include,UID/GID33 read/no-write,signed scope denial,wrong key
and unchanged six Connect counts. Native124/629+6concurrency and both paired restore
gates PASS. Apache/systemd separate fixtures PASS. Full RC skipped;no live acceptance.
Readonly e4 packet remains frozen and valid;owner parent/permission result pending.
No full root activation package exists yet. Source components alone do not activate.

## Remaining delivery gates

1. Coordinated root transaction: exact HTTPS tool/state ownership handoff, private PHP
   include outside document root, independently generated256bit link.claim key scoped
   to current Telegram bot, exact backend peer proven by signed request.
2. Managed receiver/probe LoadCredential drop-ins, unchanged kernel loopback policy;
   verified release switch with one consumer stopped, token/journal/cursor untouched.
   Durable recovery must cover partial activation, administrator drift and service ACK.
3. Native acceptance of coordinated managed drop-ins and partial activation recovery
   (the separate systemd scoped-probe fixture is already green), final immutable
   activation package/hash/CI and operator activate/deactivate runbook.
4. Owner same-session Academy→Telegram→confirm, replay/stale/group denial and revoke;
   then separately opt-in notifications with fresh cutoff. MiniApp remains deferred.

No new artifact is authorized as a production activation package by this ledger.
Agent has not accessed server1, generated a live key or called Telegram.

## Owner parent refusal and bounded preparation

Owner21Oct e4/SHA14de5484 check refused unsafe_include_parent,exact outer code directory
UID33/GID33/mode0755. No configuration change. This was the first guard;later transport,
receiver and live HTTPS checks were not reached and must not be claimed accepted.
[Owner receipt](../runtime/connect_private_bridge_parent_owner_20261021.json).

The existing root-managed-include design now gets explicit --prepare-include-parent.
It first checks all other readiness conditions and every ancestor above the exact
reported directory,then changes only its UID33→0 with descriptor-based fchown.
GID33,0755,xattrs and every child retain metadata/content. A root-private durable
inode-bound before-image permits interrupted prepare/restore resume. Unknown inode,
owner,mode or xattr drift refuses. Restore refuses any private include/key/drop-in
or activated HTTPS bridge. Default PLAN_ONLY and --check do not change ownership.
No service restart,container recreation,credential generation or app update occurs.
RED:missing guarded preparation assertions observed. Local namespace cannot map UID33;
a new mandatory sudo CI-host fixture must execute the real ownership and UID33 denial
cases before publishing operator commands. New source CI pending.
