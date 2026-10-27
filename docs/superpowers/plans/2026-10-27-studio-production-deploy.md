# Studio production deployment package

Owner27Oct explicitly chooses acceptance on production and requests archive/commands.
Execution stays with owner; no agent SSH. No additional approval loop.

Goal: a fixed2300→2600 application/cron package using the existing paired transaction,
without worker/config/key/HTTPS/Compose changes or container recreation.

Sources: accepted app/worker6c56a5b6; target2d95137a (same582application files as7eb1a3f6),
full RC40434525376/context40434525438/infra40434525375 PASS. Both schemas100tables equal.

- [x] Write RED tests for fixed release/schema/delta/cron contract and offline CLI.
- [x] `course_studio_update.py`: adapter over connect_con02_application.Application,
  strict accepted Telegram2300 predecessor verification, immutable worker/runtime,
  new private journal, target2600 cron only. Reuse physical pair/metadata/lock/recovery.
- [x] `build_course_studio_update.py`: exact-Git standalone Python and tar.gz archive;
  declared sources/assets/bytes/hash validation before imports; default offline PLAN_ONLY.
- [x] Test actual pair copies/public boundary/crash/restore using existing fixtures,
  preserve root/GID33 code hardening and all Connect controls. Historical index
  alternate is the exact accepted two omissions only; no repairs/adoption.
- [ ] Verify archive CRC/content/SHA and packaged plan; run appropriate source/context/
  native CI. Publish tool source without changing the qualified application.
- [ ] Deliver concrete upload/check, systemd upgrade/status and recover commands;
  explicit destructive restore flag after public writes. Persist owner-ready archive
  and commands through the repository/delivery channel, no secrets in the package.

Limits: modeled Docker/SQL unit tests are not a production drill. Existing native
Moodle CI qualifies the same app files; deployment status remains pending until owner
returns terminal/visual evidence. No worker rollout, replay or recipient expansion.
