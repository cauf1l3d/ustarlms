# Read-only production preflight before Moodle5.1.8 preparation

[Owner scheduler status](hdd_backup_schedule_status_20261012.json) confirms
12Oct03:30..03:31:39MSK scheduled success,no recovery error/marker,next13Oct03:30MSK.
Correction:the scheduler --status output contains no archive name/hash. Do not ask
to repeat it for those fields. The new production_patch_readiness.py reads the
saved export/success/sidecar and rehashes the actual latest HDD archive.

Default plan only,--check validates pinned profile7725410,526 USTAR file hashes,
original core metadata/index,57 preserved addon disk/DB versions,official package,
existing production image/bind identities and static config prefix/homepage policy.
SQL uses psql -X in BEGIN READ ONLY with15s statement/3s lock limits;only versions,
enabled task count and aggregate activity/block usage. Production tasks untouched.
No PHP or Moodle bootstrap,no config/password output,no code/data/DB mutations,
container/service starts/stops,pulls,backup/upgrade or filesystem output files.
Existing shared backup lock protects archive reads;no lock creation. A busy lock
or drift/refusal stops the collector before proposing activation.

Logical code/Moodledata/PG sizes and filesystem free/inode values are live estimates,
not an atomic snapshot or final upgrade budget. Archive hash is not decryption/restore
proof. This neither activates a candidate nor verifies every third-party function.
Homepage config missing the USTAR relative URL is reported for a reviewed insertion;
configuration is never changed by this collector. Missing/dynamic prefix or homepage,
changed baseline,archive corruption,symlinks and unresolved recovery marker refuse.

Local portable guard tests11 PASS;prior broad r16 run274tests/11skipped PASS before
one final positive fixture addition;that additional test passed in the dedicated run.
Docker/SQL mocked locally,real server collection and exact-head source CI pending.
Full RC a42672e/run38257457655 remains accepted for unchanged Moodle/frontend code.
Next operator --check report;then prepare a separate production candidate and a
fresh paired backup/rollback/deployment contract using actual sizes and addon usage.
