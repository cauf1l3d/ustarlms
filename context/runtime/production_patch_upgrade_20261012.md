# Production Moodle5.1.8 paired deployment transaction

[Owner switch preflight](production_patch_switch_preflight_result_20261012.json)
20:51:43UTC PASS:required12,706,045,952bytes/292211inodes,available64,707,956,736/
6343936. Code33:33/0755/inode920789,data33:33/0770/inode925371,
physicalPG999:1000/0700/inode917939 on device2050. PG children999:999;
Moodledata includes existing root-owned entries and4702 executable regular files.
Preserve these basic UID/GID/mode/content properties;do not normalize live data.
Both restart policies unless-stopped;other container production-bind overlaps0.
Existing cron/backup/helper pins and shared backup lock accepted. Collector scope
closed;next operation includes immediate source/candidate/capacity checks itself.

production_patch_upgrade.py default plan only. Explicit --upgrade-production
--acknowledge-outage performs one controlled sequence against accepted candidate
518-wnd475x8/manifesteb6d025d/sourcee7318ecc. Pins switch preflightfd65eda0,
candidate builder7b8ee79a/readinessf72031dc/archive primitivesbb71ddd and installed
cold9c81abb6/stage-copy452d8fd6. Uses only tested pure cold copier and three
stage transaction functions:write_json,copy_contents,move_children. No stage
engine/readiness/upgrade/restore/lifecycle is called. No Composer/images/roles,
shared-host services,schedules,retention deletion or candidate/frozen-copy writes.

Sequence:

1. Private existing candidate lock;validate accepted manifests,original and candidate
   full code metadata/hashes,current config,container/mount identity and restore budget.
   Refuse existing maintenance/unfinished transaction before any pause.
2. Exact installed HDD producer --backup --acknowledge-outage,private command log;
   producer owns EX backup/cron lock. Validate a NEW encrypted archive,readback and
   login readiness. Parent/child signals blocked during this bounded capture to
   avoid killing cleanup on terminal interruption. HDD failure cannot proceed to
   PG stop/core activation;its own recovery handles an HDD marker. Archives retained.
3. Acquire same EX backup lock after producer exits:managed cron/backup excluded.
   Recheck source/root drift;durable journal before restart-policy changes.
   Temporarily set both policies no to prevent reboot starting a half-restored pair.
   Stop web,check ZERO other client backends across the PostgreSQL cluster,then
   stop PG cleanly/PID absent. No client killed. Bind-root/ID/image identities kept.
4. Fresh private SSD code/config,Moodledata and physicalPG pair,hash/basic metadata
   readback,complete manifest. After durable pair,retain original code children and
   copy accepted code into SAME live root. Keep33:33/0755/inode,not candidate0:0 root.
5. Own climaintenance.html blocks web before DB/session bootstrap;official setup
   CLI branch allows official CLI. Start PG then web/wait readiness. Official root
   admin/cli/upgrade.php --non-interactive twice as33:33,512M,builtin sendmail/binfalse,
   no prepend/append,CLI opcache disabled. Actual configured SMTP/integrations are
   not claimed isolated. Preserve config URL/credentials/mail/cron;candidate HOME only.
6. Read-only DB target2025100608.00/57 preserved addon versions. Preserve customised
   task flags and existing addon tasks;stock core defaults may evolve. Verify active
   code,root metadata and bounded distinct nonempty file references/SHA1/sizes.
7. Durable public-release boundary BEFORE removing only owned exact maintenance file.
   HTTP login200,restore policies,record technical result. Cron resumes when EX lock
   released. Real login/roles/learning/next cron are post-switch acceptance;no full-addon PASS.

Failure BEFORE public release stops both same containers,verifies immutable fresh
pair BEFORE moving any live tree,retains current code/data/PG,and restores ALL three
before startup. No PHP client remains against restored files. Corrupt pair/root
drift/insufficient restore space or inodes refuse copying;containers remain stopped,
restart no,pair/journal/logs retained. Restore can be retried after interruption.

Marker:/var/lib/ustar-production-patch-20261012/switch-active.json;unique switch-518-*
run holds pair,manifest,private log and retained trees. No config/personnel/DB/runtime
logs published. Basic owner/group/mode/content,not timestamps/extended attributes,
are the copier's recorded scope. New maintenance control is not retention history.

Explicit --restore-baseline --acknowledge-outage validates namespace,known images,
IDs,root identity/modes and prefix. AFTER public release additionally requires
--discard-writes-since-snapshot because rollback discards newer writes. HTTP failure
after release does NOT silently restore old data. Incomplete cold capture with no
live changes resumes only original source. HDD errors stay in producer recovery.
Power loss/SIGKILL not claimed automatically repaired;restart no protects partial
pairs once switching begins and same helper supports explicit recovery.

Local24tests:23 PASS/1 owner fixture skipped because local root namespace maps only
UID0. Portable real file copies/restores,first/repeat CLI failure,partial baseline,
restore retry,tamper/client/duplicate/publish/discard-ack guards,task policy,owned
maintenance,closed SSH stream,EX/SH locks,masked stop API/read-only SQL PASS.
PHP8.3 source CI mandates privileged actual33:33 roots,999:1000 PG root/999:999 child
and mixed data owners/modes on temporary files. Docker/SQL/HDD external calls fake.
Schema migration is unchanged official CLI/package already accepted on stage.
Exact new CI and production execution pending. Relevant addon runtime acceptance
and prior native full RC retained separately;no repeated stage cycle.

Window estimate:prior same-package stage transaction1109s,real paired rollback629s.
Allow20–30minutes normally plus extra if recovery needed;not production timing proof.
Short initial HDD web pause,then longer coordinated web/PG pause. Console phase
markers/private raw log;temporary silent copying is expected.
