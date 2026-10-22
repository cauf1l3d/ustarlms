# CON-02A.2 — Telegram personal acknowledgement, 22 October 2026

A new personal notification can carry «Принял» above its fixed Academy button.
The same receiver processes the callback using an ACK-only key. Academy records
the first acknowledgement timestamp; repeated clicks and native/read races retain
it. This does not complete a task, mark provider delivery successful, or create a
second polling consumer. A.2 remains explicitly disabled until a coordinated release.

## Implementation and boundaries

Candidate local_ustar2026102200/theme2026100202,557 tracked addon files/100tables.
One new table holds domain-separated action hashes, expiry, current recipient/link
revision and delivery attempt. Native own ACK still uses POST/sesskey/current
account/capability. Telegram checks private human origin, matching bot/message,
current link/attempt/account/capability and configured recipients. Known provider
message references must match. Opt-out after delivery is distinct from revoke.

Permit v1 remains exact; sender v2 is an explicit profile and separate scoped key.
Malformed v2 never falls back to v1 or resends. Runtime keeps schema1 journal, cursor,
identity rate limits and existing consumer lock. Raw actions/query bodies are not
persisted in journal or privacy export. Uncertain bridge/UI responses do not reset
canonical ACK or authorize an automatic replay. Telegram text excludes task content.

## Continuation and review

Shared chat6ada0312 was read through the last agent messages. It stopped while
waiting for final CI. PR f8c12767 had154native tests/796assertions with one failing
capability-revocation assertion; source8.2/source8.3/runtime/context succeeded.
Pinned Moodle accesslib explicitly leaves USER intact when clearing private caches.
The cache-only correction exposed a second premise failure: assign_capability
defaults overwrite=false, leaving this fixture role's prior CAP_ALLOW unchanged.
The test now explicitly overwrites it, asserts the stored CAP_PROHIBIT and current
has_capability=false in a fresh cookieless worker before checking ACK denial.
Production access checks were unchanged.

Current continuation focused suite:93 tests PASS, no skips. Historical local
974/35environment skips belong to the prior agent environment, not re-executed here.
Whole final CI/native/privacy/lifecycle/wire/race evidence is in the adjacent JSON
receipt once completed. Source references and synthetic-vs-native boundaries stay
explicit. Author-only review covered token/recipient fences, first-time writer,
exact packet fields, credential split, journal uncertainty, privacy cleanup, v1
handback and default-off profiles. No independent reviewer or subagents used.

## Deployment boundary

Server1 remains application2000 with the accepted policy overlay and worker/tool
a86. The one-user Tasks pilot is already accepted; UID130 was last reported revoked.
Do not repeat that pilot, reset journal, widen recipients or request live group tests
now. They were deferred by the owner until full launch, not passed.

No guarded2000+overlay to2100 to2200 installer is supplied by this source slice.
Next deployment work must prepare an exact installer and fresh DB/source/config/data
pair, preserve cron target and HTTPS ownership/renewal, then coordinate receiver/
sender/ACK credentials. No standalone source copy, version downgrade or unpaired
business DB restore is an activation procedure. Product B routing/preferences and
C Bitrix follow separately; actual Bitrix portal/API details are still unknown.

## Required next deployment handoff

The installed a86 controller owns policy/include/release hashes; changing them
requires an explicit ownership handoff, not bypassing its guards. New source2200
also needs a coordinated cron version contract. Fresh DB/source/config/moodledata
pair must be captured while the existing deployment/cron/backup/HTTPS/worker locks
serialize all writers. Receiver and sender stop/start order must preserve cursor
and in-flight uncertainty; root-only scoped credentials/profile cutoffs are written
on the host. The updater must verify the existing2000+accepted-overlay baseline,
execute supported savepoints, verify100-table candidate and canonical HTTPS, and
only then release public work. A post-release DB downgrade is not an automatic
rollback. This is the next unimplemented deployment slice, not a new pilot test.
