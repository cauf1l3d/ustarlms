# Docker runtime successor — owner delivery draft

Status: **NOT QUALIFIED / DO NOT APPLY**. Exact artifact digest and native CI receipt
must be added before delivery. This document is not authorization to run a source
checkout against production.

## Scope

The accepted Moodle writable layer is 85,491,712 bytes. The transition snapshots
both stopped containers into local images, preserving current installed files;
it does not rebuild from the old Dockerfile or pull an upstream image.

The new containers retain application bind mounts, environment, network addresses,
ports and process configuration. Logging becomes json-file with max-size20m,
max-file3 and compression. Readiness checks cover PostgreSQL availability and the
local Moodle web listener; an unhealthy status alone does not restart a container.
The existing ordinary app role and application versions remain unchanged.

The operation needs downtime. It pauses named USTAR workers and schedules, makes a
fresh backup through the existing HDD producer, and creates an authenticated
encrypted archive of runtime images and private controls on the HDD. Original
containers, images, logs and source inode remain retained. No archive pruning,
Docker log truncation, database restore or business-file restore occurs.

The source Compose becomes a private canonical snapshot-based configuration. Old
build instructions are not used. Recurring backup producer/scheduler and cron
bindings advance together. Existing ordinary certificate renewal remains its
owner. The original frozen update/code/DB controllers retain their old identities;
after this handoff their manual runtime checks/apply/undo are historical. Use the
new operator for current check/status/recover, not a blind old-controller rerun.

## Required delivery evidence

- Final source root/UID/crypto and actual Docker native workflow passes.
- Exact ordinary Python bytes match the tested source build.
- ZIP CRC, all member digests, extraction, Python compile and offline default pass.
- Saved-download bytes and archive checks match the delivered package.

Only after these checks will the owner command block be added with the real digest.
A check runs before apply; no new owner origin/profile/classification probe is needed.

## Operation and recovery semantics

`--check` verifies the fixed server1 profile before the first transition. On an
active generation it checks the new runtime. `--status` reports journal state only.
`--apply --acknowledge-outage` performs the transition; repeated active apply checks
that generation and requests the control companion backup. Do not wrap the apply
in a short timeout that can interrupt backup or recovery.

An interrupted non-active generation requires `--recover --acknowledge-outage`.
Recovery restores predecessor identities and source/control files but preserves
current database rows and moodledata, including writes after release. Unknown
runtime/control edits cause refusal, not automatic adoption. The same exact
installed operator is required because its SHA is bound in the journal.

A successful runtime may have `control_companion_complete: false` if the independent
companion is busy. That does not mean runtime rollback or lost data. The already
installed companion can be retried separately after the busy operation finishes.

Whole-host disaster recovery is not established by these local archives. External
restore stays unknown per owner; copies handed over and UPS presence remain
accepted. SSH/firewall work remains deferred to external-access preparation.
