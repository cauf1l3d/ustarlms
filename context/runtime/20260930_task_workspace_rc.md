# TASK-CRM-01: isolated release candidate evidence

Measured 2026-09-30 by GitHub Actions. Code PR: https://github.com/cauf1l3d/ustarlms/pull/64.

- Source SHA: `06b0b75496b9e3fe613b1001fa7ae599cf7ced80`.
- Base PR #63 SHA: `9b1810d73951756b5b2d747a3aecaae371298bc4`.
- Plugin `2026093001`, release `1.10.0-rc.1`.
- [Full gate #390](https://github.com/cauf1l3d/ustarlms/actions/runs/36655075277): source, frontend, rollback, prepare-rc, moodle-db, gate all success; no skipped jobs.
- Moodle 5.1.1 / PHP 8.2.30 / PostgreSQL 16.10, synthetic isolated Docker runtime.
- Fresh install, upgrade from immutable historical fixture, repeated upgrade and schema parity passed. No production core upgrade is prescribed.
- PHPUnit: 163 tests, 704 assertions, including 21 task workspace scenario tests. Editor DOM checks passed.
- [Frozen package](https://github.com/cauf1l3d/ustarlms/actions/runs/36655075277/artifacts/11072375294): ZIP SHA256 `82ff5720b0f754c9539ce501b6d5a8f4728573bc9426e538e346093affdaf1ac`. Manifest matches exact source SHA; 542 application/frontend file hashes verified against archive.
- [DB evidence](https://github.com/cauf1l3d/ustarlms/actions/runs/36655075277/artifacts/11072720478) and [rollback drill](https://github.com/cauf1l3d/ustarlms/actions/runs/36655075277/artifacts/11071849313).

Production deployment: **not performed**. The user-provided terminal report shows installed plugin `2026092907`, required adaptation tables, maintenance off and unauthenticated HTTP smoke. It does not establish installed source SHA, core identity, an atomic snapshot or authenticated business acceptance.

Authenticated native-page desktop/mobile acceptance, real organization/cron/delivery behavior, source drift against PR #63 and production-specific restore remain before deployment. The existing general release manifest retains its historical production baseline and must not be treated as a fingerprint of the user's currently upgraded server. Use the exact frozen candidate, not a diff on historical main. Restore DB + moodledata + code/config/image together after migration; plugin downgrade alone is unsupported.

Architecture: [ADR 0009](../decisions/0009-task-workspace.md). Delivery: [Russian guide](../releases/TASK_WORKSPACE_RC_20260930_RU.md).
