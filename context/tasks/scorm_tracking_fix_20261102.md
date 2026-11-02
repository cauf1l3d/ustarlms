# SCORM tracking —02.11.2026

Owner CM104/course29/SCORM20/user132: savetrack=false, completion=false, tracks=[].
Helper active/HTTP200/ready=false. This supersedes the timestamp hypothesis for this case.

- [x] Reproduce enrolment without a learner role (PHP RED).
- [x] Route-authorised player supplies scoped standard Student enrolment (PHP GREEN).
- [x] Preserve explicit negative capabilities, dates, disabled enrolment and idempotence.
- [x] Implement a bounded two-file614/104 overlay from appb9f1100 to app2150affe.
- [x] Preserve frozen operators/archives, xattrs, worker/egress/config/cron; source rollback only.
- [x] Verify real Moodle native permissions and completion; qualify immutable tool source.
- [x] Build archive, compare exact CI bytes/CRC and downloaded saved copy.
- [ ] Owner installation and fresh SCORM completion/next-point acceptance.

No synthetic completion or manual credit. The earlier unsaved package state is not a
server-side result. After repair, reopen through the route so Moodle can save the attempt.
Current facts/limits: [receipt](../runtime/scorm_tracking_20261102.json).

The companion38fa5e37 checks the fixed original f879 installation. If committed but not
finished, it completes only that phase through loopback8082 before acquiring overlay locks.
An active root-private receipt is accepted as historical; cron backoff and worker states
are not replayed. Current source/control integrity is verified by the overlay. The overlay
changes only the helper and its existing native test, preserving1100/2700 and all104 tables.
No SSH, provider sends, subagents or merge.
