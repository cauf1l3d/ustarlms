# Coordinated activation implementation — 21 October 2026

Plan: USTAR_CONNECT_PRIVATE_BRIDGE_DELIVERY_PLAN_20261021.md.
Owner accepted full parent preparation; no more parent diagnostics.
Implementation continues inline, no subagents. Separate existing feature checkout.

Ruling: keep the existing HTTPS manager, root include and receiver installer file
primitives. Deployment owns its durable private transaction and all existing locks;
HTTPS profile operations reuse the already-held HTTPS lock in the CLI instance.
This avoids lock self-deadlock and leaves standalone renewal serialization unchanged.

Ruling: stage the immutable receiver release with identical unit bytes; switch its
current pointer only while stopped. Never restore or copy its live SQLite journal.
The activation utility owns deactivate/recovery; old receiver admin intentionally
refuses credential drop-ins rather than bypassing the bridge transaction.

Ruling: no fresh application backup/cold copy or Moodle upgrade: the only application
file change is the captured configuration include, with metadata preserved. Private
backups/desired bytes are under /var/lib/ustar-connect-bridge, mode0700/0600.
Key is generated on the host, exactly link.claim/telegram/current bot tenant.
Canonical HTTPS remains enabled during rollback; profile renewal stays supported.

Tests-first:8new tests failed because Deployment/CLI missing. Implemented activation,
probe-before-start, cancellation recovery, certificate renewal followed by deactivate,
metadata drift refusal and no cursor restoration. Added true lost-process recovery
and distinct old/new immutable receiver; active-pointer drift test failed before fix.
Apache administrator-edit test failed before fix; now all HTTPS owned inputs are
validated before any recovery file mutation. Bundle test failed before builder existed.
12targeted local tests pending final run; actualUID33/CI proof pending. No live
activation or Telegram delivery claimed. Package must be frozen after relevant CI.

Remaining owner acceptance: activate, own Academy→Telegram→same-session confirmation,
then unlink. Notifications require separate delivery activation; Mini App/external
HTTPS/native handoff/role actions are later scope. Accepted application2000 remains.
