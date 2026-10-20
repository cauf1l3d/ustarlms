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
  deadline propagation regression; targeted readiness37tests PASS before metadata review.
- Real temporary files and Git zipapp exercise drift, private permissions,
  symlinks, duplicate JSON, fresh scheduled receipts, redaction and deterministic
  exact-Git packaging. Docker/SQL/systemctl are modeled in these tests; they do
  not prove server1 acceptance or a live private bridge.
- Initial full local r16:833tests/31environment skips PASS (task venv with harness requirements).
  Source structural checks PASS;PHP not run locally. Context/index checks PASS.
  Exact-source CI verification pending publication.
- Final self-review found a compatibility error: new preflight guessed root ownership
  for config.php instead of respecting the accepted HTTPS capture. Metadata-drift
  regression observed RED, fixed to compare exact captured uid/gid/mode and hash.
  Symlinks remain refused;root-private HTTPS state/tool/vhosts remain protected.
  Read-only probe must never ask owner to chown application config/tree.
- Added actual UID33+root-group config/parent fixture,explicitly only on disposable
  privileged CI. Local managed filesystem rejects chown33 (EINVAL),so this one test
  is intentionally skipped locally;not claimed native PASS before CI.
- Post-review full local r16:835tests/32skips PASS;the extra skip is actual UID33
  fixture awaiting privileged CI. Source/context checks PASS.
- Intermediatefda8ff35:Context39469396286 SUCCESS,runtime118789171838 SUCCESS;
  source8.2 success,8.3 failed at existing onboarding browser test line50.
  Trace:statusView exposes form before awaited options fetch;test read select
  immediately after form visibility. Preserved literal-XSS/no-img assertions;
  now wait for the actual option to be attached,with250ms synthetic options delay.
  No product JS change. Local browser could not run:runtime browser binary absent,
  download returned invalid/truncated archive;not a local browser PASS claim.
  Final4d70de5c Context39470413912 SUCCESS;runtime118792670114 SUCCESS.
  Actual UID33 and all14 module tests PASS on disposable Ubuntu host;bundle
  SHA67d3d13a matches local/CI,artifact12285519274. Both source8.2/8.3 jobs
  118792669812/118792670033 SUCCESS:835r16/135skips,381PHP/24JS/57templates/99tables,
  actual Chromium60mobile/20onboarding/28link/100actor PASS. The explicit options
  delay reproducer now passes with a condition wait;product JS/guards unchanged.
  Native Connect DB118794127361 SUCCESS:124tests/629assertions,6concurrency,
  accepted baseline paired rollback,native SQL guards and actual Moodle HTTPS
  bootstrap/Secure-cookie PASS. Context39470413912/Review39470413976 SUCCESS;
  full-RC jobs skipped,full RC not claimed. Exact runbook/hash published;owner check pending.
- Initial manual bundle inventory check incorrectly expected8members;actual exact
  whitelist is9 (7 original+2 profile modules). Corrected inspection passed exact
  members,552/99→552/99,1905→2000 and no-host plan;no product patch for this count.
- Future cron2000 exact source derived privately:SHA
  ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751,
  AST differs from acceptedc5eabdce only in VERSIONS. Not installed/not an update tool;
  the matching lock/state/recovery transaction remains required.
- Final review:self-review;owner forbids subagents,independent review not claimed.

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
