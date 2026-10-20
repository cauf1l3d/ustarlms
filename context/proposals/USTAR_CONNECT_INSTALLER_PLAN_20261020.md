# Connect Academy operator installer

Continuation of the owner-approved source RC and request to prepare installation.
Scope: install the already verified830d3703 addon payload on the accepted e1d57f5b
baseline. Receiver, Connect activation and external HTTPS remain separate.
Execute inline; no subagents or production access.

## Procedure

Read-only default plan and `--check`; explicit `--upgrade --acknowledge-outage`
inside a detached systemd job. Fixed production roots/images, Python3.12,
root-owned package, exact source/DB versions and table set; refuse drift.
Reuse existing pure cold copy/hash/fsync/container primitives, not the old
core-upgrade entry point. Check managed cron/backup helpers, locks, mounts,
overlapping containers, restart policies and conservative restore capacity.

Create a fresh encrypted HDD backup using the pinned installed producer.
Then hold its existing exclusive lock, prevent automatic container restarts,
stop web cleanly, reject other database clients and stop PostgreSQL cleanly.
Copy and read back full code/config, moodledata and physical PostgreSQL together.
Only then replace the contents of the two USTAR addons; preserve core/config,
other plugins and root ownership. Run native CLI upgrade twice behind CLI
maintenance; verify source hashes, versions, all existing plugin/task flags,
expected new cleanup task and schema repeat stability. Check referenced files.

Before removing maintenance, durably mark the public-write boundary. Restore
original restart policies and check login. Failures before that boundary restore
all three trees together; recovery failures leave containers stopped with restart
disabled. Interruptions/power loss retain a private journal and pair; explicit
`--restore` resumes recovery. After public release require
`--discard-writes-since-snapshot`; never silently discard newer user writes.

## Implementation and evidence ledger

1. `tests/r16/test_connect_academy_installer.py`: RED missing module; real temporary
   trees and metadata, modeled Docker/SQL. Cover success, upgrade/repeat failure,
   invalid candidate/baseline, stale helpers, insufficient capacity, contaminated
   pair, interruption, late rollback consent and no recovery writes after failed stop.
2. `scripts/infra/connect_academy_installer.py`: transaction controller and narrow
   adapter. `build_connect_academy_installer.py`: deterministic exact-Git package,
   embedding fixed public RC830 payload and tested primitives. Default plan has
   no host access; validate all embedded hashes before writing.
3. Run relevant and full r16, context/source checks, root ownership checks in CI;
   build package from published exact commit, record SHA and operator runbook.
   Existing native RC830 migration/recovery evidence remains valid for unchanged
   Moodle sources; host adapter tests do not claim production restore acceptance.

Review focus: failed clean shutdown, process loss between journal and write,
fresh backup producer failure, untrusted journal/pair paths, partial-policy update,
insufficient restore capacity and release-boundary errors. No secrets in stdout
or uploaded artifacts. Recovery pair and command log remain root-private on host.
