# Docker runtime successor25Oct2026

Status: engineering design/preparation under the owner's eight-stage infrastructure request;
not a production apply receipt. Work is inline,0agents/no agent SSH/provider sends/main merge.
Owner metadata is [recorded](../runtime/infra_docker_writable_layer_owner_20261025.json).

## Intent

Enable bounded Docker logging and local readiness while preserving the actual runtime,
current Academy557files/100tables/local2300, ordinary DB role and all existing data.
Moodle's writable layer is85,491,712B; package/system/shared-file prefix changes must
survive. Counts do not identify packages. No more grouping probes are needed.

## Approach and alternatives

Daemon defaults do not rotate existing containers. Rebuilding the old source/image
would not preserve its current writable layer. Engineering choice: snapshot each
stopped current container with Docker's own commit, verify original immutable layer
lineage and process configuration, retain the original images and stopped containers,
and create successors from those exact local snapshots. No registry pull/push/build.
This intentionally produces new image and container IDs; it is a controlled runtime
baseline transition, not an assertion that old pins remain current.

Canonical Compose becomes a private immutable-image model derived from the current
source plus live Env/command/mount/port/network facts. It has a distinct project name
so retained predecessor Compose labels cannot be selected by ordinary current
Compose commands. Original source inode/bytes/owner/mode are parked privately for
mode/content/inode-preserving recovery. Keep deployer UID1000 and0600 on the new file.
No application or DB schema/content migration and no business-row restore occurs.

## Recurring and recovery consumers

The current HDD producer source matches repo a604fbcd; its dependency engine has been
materialized privately and byte-verified0d31e694. Engine preflight/save use actual
inspected image IDs dynamically. Change only the producer's two typed IMAGES literals.
Current cron source must match the accepted app journal after-image; require a literal
two-service IMAGES map, patch only those literals and rebuild its installation binding
using the exact current helper identity function. Do not infer cronv3 from the saved
Libraryv2 sourcefe94ac6c. Archive before/after sources and installation metadata.

Ordinary HTTPS renew verifies files/topology/current runtime dynamically; saved runtime
ID equality is checked only for transactional cutover. Keep its source/state/leaf and
units unchanged, and verify the current HTTPS state before/after the transition.

Preserve the three original pinned manual controllers and their old journals as
predecessor recovery evidence. Their old ID/image binding is not the active generation.
The new lifecycle check/recover is the current runtime entry point; old app/role/code
CLI apply/undo cannot be used blindly on the successor. Existing historical physical
baselines and full-host restore results retain their dated scope. Clean-host restore
is explicitly unknown/deferred, not newly claimed. Full-host cold tools need a later
qualified current-profile handoff; no automatic old whole-host restore is introduced.

## Transition

1. Validate pinned predecessor helpers, active role/code/HTTPS/worker/cron controls,
   source41de2ed3/0600 and exact current containers, current mounts and local-only ports.
2. Preserve private controls/policies and disable only the USTAR cron entry and its
   existing workers/timers while coordinating existing locks. Do not stop host cron.
3. Invoke the existing verified HDD producer for a fresh consistent logical backup.
   No duplicate application upgrade/role/full-restore owner stand or new physical pair:
   this transition changes runtime objects, never bind contents or DB records.
4. Stop the exact old containers, retain restart policies, commit both snapshots and
   verify parent layer prefix, snapshot ownership labels and process configuration.
   Save snapshots plus exact private runtime controls to a readback-verified encrypted
   HDD archive. Key remains separate. No archive/image/container/log pruning.
5. Park/disconnect predecessor containers, install the new private Compose model,
   create with no build/pull, and refuse any unexpected process/HostConfig/bind delta.
6. Start PG then Moodle, verify readiness and unchanged role/source/HTTPS facts; patch
   cron/HDD bindings to the new images/IDs and resume original worker/timer policy.
7. Publish a public receipt of exact generation/IDs/images/archive/config hashes and
   logging options. Capture new private control records with existing companion.

Use json-file max-size20m/max-file3/compress=true. Health checks must be local,
non-business probes (PG accepting state and Moodle listener); Docker health by itself
is not application/SLO acceptance or automatic unhealthy restart policy.

## Recovery and boundaries

Journal intent before each mutation; adopt only objects carrying this generation's
verified source-container/source-image labels. Before completion or explicit recovery,
park the owned successors, restore original Compose inode/helper images/network/name/
restart/worker/timer state and original container IDs. Never restore business rows or
bind files. Keep all generation artifacts for diagnosis. Unknown content/object drift
refuses and retains private state; no force removal, broad JSON substitution or guard
bypass. Retained predecessor objects consume space and are recorded, not forgotten.

Backup archive includes current runtime snapshots and private controls, not a
whole-host image or new offsite transfer. The ordinary HDD producer then saves the
new live snapshots in its future backups, closing the previously uncaptured package
layer. New snapshot pairing/offsite identity remains part of explicit DR uncertainty.

SSH/key/firewall deferred; UPS present; copies handed over/external restore unknown.
Existing accepted SSD/Moodle/OS/reboot/HTTPS/backup/DB/code/Connect stands remain valid.
Native qualification adds only the Docker/control transition delta to existing CI.
