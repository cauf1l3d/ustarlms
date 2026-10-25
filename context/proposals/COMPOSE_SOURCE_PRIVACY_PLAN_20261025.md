# Compose source privacy: targeted metadata step

**Qualified source dbee1d31/adapter20d282e9:** Review40217322352/Context40217322350 SUCCESS;
15native/no skips/actual UID boundary PASS; saved download SHA/CRC/compile/default
verified. [Owner runbook](../runtime/compose_source_privacy_20261025.md). Owner applied0600/UID1000/hash unchanged; companion refused managed-operation
contention, so retry only existing backup. [Owner receipt](../runtime/compose_source_privacy_owner_20261025.json).
Actual Docker rotation remains open.

Owner actual profile: /opt/ustar/apps/stack/docker-compose.yml,904B/SHA41de2ed318ffb7697d92c575d1fc35ab3e6b1d16900e725aca843642d1cb7a09,
UID/GID1000:1000,0644. No .env; postgres has3 environment entries (values not read
or published). Both services lack source logging/healthcheck; Moodle has build.
Current model/live equivalence is unverified. Prior source inventory was a
different fixed path. SSH hardening remains explicitly deferred by owner.

Within the already authorized file/config hardening, restrict this single file
0644→0600, retaining UID/GID, inode, bytes, mtime and parent directories. This
closes other-user read access to this inode; it does not make UID1000/group1000
deployment directories root-owned or establish complete shared-host trust policy.
No app/DB/provider/container/config-content mutation or source logging changes.

Reuse the exact installed/pinned infra_one_pass5067163f Exports/Store/opened/atomic
primitives. A separate child Store under /var/lib/ustar-code-hardening/compose-source
keeps the original single-row before metadata, private source bytes and exact
adapter source, all root0700/0600. Existing code-hardening state/controllers and
one-pass active state are unchanged. Code-hardening lock serializes payload capture.
Current encrypted companion automatically includes the child controls: call only
its --backup-host-state after success, never repeat one-pass --apply.

Guard exact source path/hash/size/owner, single link/no xattrs, expected initial0644,
and saved inode/mtime/bytes/owner plus source hash thereafter. Check is read-only;
apply idempotent; interrupted known chmod recoverable; undo changes mode only and
refuses content/identity/archive drift. Unknown old journals are not adopted.
Default entry is PLAN_ONLY. Native UID1000 remains able to read; UID33 loses read;
private archive remains inaccessible. Targeted integration and native CI qualify
these boundaries; all previous owner/native stands count and are not repeated.

Deliver one small integrity-checked script/ZIP after qualification. Owner one block
check→apply→existing encrypted companion readback, no outage. Actual Docker log
rotation remains open: source has build and recreation needs coordinated frozen
ID/image/mount/recovery/cron/HTTPS lifecycle. This step does not claim active rotation.
