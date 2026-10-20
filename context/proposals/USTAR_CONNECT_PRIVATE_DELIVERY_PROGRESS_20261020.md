# Continuation after accepted cron repair: private chat-link delivery

Owner20Oct20:38MSK accepted two real scheduled successes and requested continuation.
No subagents. Existing RFC/chat-link plan remains the business design: private
Telegram claim, link-only credential, second same Academy session confirmation,
current account/scope checks and revoke. Mini App remains deferred; notify gets a
separate opt-in acceptance. No new authentication model or console account reuse.

## Dependency review and decision

The next delivery consumes installed payload830/local1905/552files/99tables,
canonical HTTPSd1e61a3b and repaired cron v3/c5eabdce. It produces chat-link2000
and later private bridge activation. Two existing interfaces need coordination:

- `academy_https.Manager.guards` owns exact config/http/tls/health hashes.
  `renew` regenerates TLS from the original private HTTP before-image. Blindly
  adding bridge configuration/public path denial breaks renewal or loses denial
  on the next rotation. A subsequent delivery needs an explicit ownership handoff,
  preserving the private original snapshots and rollback ordering.
- The cron producer accepts only1905. An addon-only2000 update would repeat the
  version outage. The release transaction must advance the exact wrapper/state
  contract under the existing cron/backup locks and recover the matching pair.
  Old immutable installer02 and old helper manifests must remain untouched.

Decision: first extend the existing exact-Git read-only preflight with a distinct
`chat-link` profile. Its baseline is the accepted830, never the dated526-file
manifest. Read only current addon hashes/SQL metadata and root-managed HTTPS/
cron contracts, unit states, loopback backend mapping and candidate Docker
bridge gateways/free18443. No PHP bootstrap, Telegram calls, key/token reads,
listener creation or config/lifecycle changes. Gateway metadata is a candidate,
not proof of the effective HTTP peer. Activation still needs a native signed
round trip, exact peer and denial through public Academy80/443.

This bounded extension does not introduce a new rollout architecture or claim a
finished deployment. A production receipt is necessary to choose the exact
private transport and build the matching guarded update, without re-collecting
old whole-host facts. Existing HTTPS and cron acceptance are closed, not redone.

## Work ledger

- Cron owner receipt recorded:17:36:03Z/17:37:23Z,exit0,no new failures/USTAR errors,
  task log4767930→4768022→4768051,adhoc0,overdue18,2 historical failed.
- Existing preflight builder: additive `--profile chat-link`; default initial
  profile/frozen source RC semantics preserved. Runtime plan remains no-host.
-11 new tests observed RED with missing delivery module, then GREEN. Added
  deadline propagation regression; targeted readiness37tests PASS.
- Real temporary files and Git zipapp exercise drift, private permissions,
  symlinks, duplicate JSON, fresh scheduled receipts, redaction and deterministic
  exact-Git packaging. Docker/SQL/systemctl are modeled in these tests; they do
  not prove server1 acceptance or a live private bridge.
- Full local r16:833tests/31environment skips PASS (task venv with harness requirements).
  Source structural checks PASS;PHP not run locally. Context/index checks PASS.
  Exact-source CI verification pending publication.
- Final review: self-review; owner forbids subagents, independent review not claimed.

## Remaining production gates

1. Owner runs the one exact read-only delivery preflight; keep private files private.
2. Guarded1905→2000 payload/recovery with fresh paired backup, coordinated cron
   target and native scheduled acceptance; HTTPS ownership/renewal preserved.
3. Private loopback TLS bridge with public denial and separate link-only key;
   single-consumer receiver update preserving bot token/journal/cursor.
4. Native browser→private Telegram→same browser confirm, replay/stale/group denial,
   revoke and real denial after unlink. Then separate opt-in notification lease/
   delivery acceptance with fresh cutoff; no historical queue replay.
5. External HTTPS/Mini App and native handoff/MFA/role actions remain later gates.
