# Production switch and paired restore conditions

[Owner candidate](production_patch_candidate_result_20261012.json) received12Oct23:31MSK:
candidate518-wnd475x8 PASS,44174 entries,manifesteb6d025d/sourcee7318ecc,
19 addon roots/57 versions/526 USTAR hashes;four originals preserved privately.
Production readback/identities unchanged. Candidate creation is closed;do not rebuild
or repeat accepted stage upgrade/rollback/read smoke without drift/new failure.
Copy-only budget did not include physical paired recovery or DB growth.

production_patch_switch_preflight.py default is plan only;--check is read-only.
Pins installed candidate builder7b8ee79a/transitive readinessf72031dc and archive
primitivesbb71ddd. Reads exact accepted manifests up to32MiB,checks live/candidate
config identity without emitting values. Does not rehash every candidate code file
yet;that check belongs immediately before activation. No PHP or SQL executes.

Checks only the missing switch contract: root UID/GID/mode/device/inode for code,
Moodledata and physicalPG;metadata-only data/PG allocation/owner/mode aggregates;
PG_VERSION16;shared filesystem and conservative three-pair/two-code-copy budget
with4GiB reserve,2GiB DB growth and128MiB volatility allowance. Live estimates are
explicitly non-atomic;vanished files counted/bounded32,walk100k/180s bounds,links,
nonregular types and nested mounts refuse. No filenames or private payloads emitted.

Inspects Docker mount/restart metadata including stopped containers to exclude
other containers binding production trees. No environment/inspect text emitted.
Exact installed cron66fd51d3,HDD producer75ca974f,backup engine0d31e694 hashed but
not executed. Existing backup resume marker refuses. Reads existing backup lock
and candidate lock without creation;kernel shared backup lock blocks capture while
allowing normal managed cron SH. Future switching needs the same EX backup lock.
Live container identities/start times compared after inspection. No directories,
files,configuration changes,backup,outage,lifecycle,activation or upgrade.

The candidate/code root is deliberately private. Activation must retain the LIVE
bind-root inode/owner/mode,not apply private candidate-root permissions to the live
root. This is an activation requirement,not a candidate creation defect. Restart
policies must be preserved/recovered explicitly in the deployment transaction.
Fresh physical paired baseline and automatic/manual production restore are not yet
implemented/accepted by this collector. Passed stage paired restore is retained in
its scope;the collector does not turn it into a production restore result.

Local15 tests PASS:real metadata/config reads with output/mkdir denied,credential,
personnel and Docker-env sentinels absent,manifest/config/helper tampering rejected,
resume marker/PG major/link/overlap/policy/capacity/lifecycle drift refusals,wide data
modes reported without chmod,real shared/exclusive lock conflict and lock replacement
detection. New source CI/server preflight pending. Full addon functional acceptance
remains distinct. Next use actual metadata to finish the paired deployment/restore
transaction and fresh recovery handoff;no repeated candidate or stage preparations.
