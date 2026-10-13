# Production Moodle5.1.8 paired deployment transaction

[Post-switch cron/HDD](production_patch_post_switch_status_result_20261013.json)
owner13Oct04:08MSK PASS:scheduled cron04:07MSK exit0/no new or USTAR errors,
HDD03:30MSK complete/success/exit0/no recovery marker,next14Oct03:30MSK. Existing
binding/schedule match;residual2 failed/17 overdue counts unchanged. Technical
upgrade/repeat/cron/backup observations closed;only brief browser confirmation
pending. No repeated server command,installer,upgrade or stage cycle required.


[Owner production execution](production_patch_upgrade_result_20261013.json) received
13Oct03:47MSK:installed a8caec55/helper6f87a780 PASS,finished00:27:29UTC/03:27MSK.
Fresh HDD archive c857f79b/1,049,765,934bytes and verified cold pair5822b456 captured;
accepted candidate activated;official CLI first/repeat PASS,core2025100608.00,
57 addons/5151 references/HTTP200,preserved root/ID/image metadata,restart policies
and customised/addon task flags.132 tasks enabled;existing default task flags did
not change(the field counts changed OLD entries,not total added task definitions).
Production restoration not executed. Core upgrade/repeat scope complete;
[next post-switch cron,HDD and authenticated QA](production_patch_post_switch_20261013.md).
Prior failed attempt remains historical;no repeating upgrade/diagnostic/stage work.


[Current diagnostic](production_patch_transaction_diagnostic_result_20261013.json)
owner13Oct02:50MSK COMPLETE at12Oct23:49:48UTC on installed a8caec55/helper6f87a780:
current old core/57 addons/all task flags match,other PG clients0. Standard web
SIGWINCH/PG SIGINT. Ten ID-only receipts are consistent with failure after web stop
before normal PG stop;this is control-flow inference,not direct exit/SQL evidence.
Past guard remains UNRECORDED. Collector complete;next use the same tested installed
upgrade-production transaction,with fresh recovery and exact guard recording.
No further collector,download,install or stage operation is justified by this result.

[Owner execution and diagnostic](production_patch_stop_result_20261013.json),
received13Oct01:48MSK:source d3e1bfed/helper24a51add ran12Oct21:52:04..21:53:56UTC.
Fresh HDD capture PASS,then stopped before creation of any cold-pair directory.
Original source resumed:core2025100601.03/login200/5151 file references;
live_trees_modified=false/paired_trees_restored=false/public_released=false.
No production upgrade or actual paired production restoration accepted.
Old helper stored only exception type and overwrote the phase during recovery;
Docker event query returned no historical exits. Specific failing guard UNKNOWN.
Do not infer web/PG exit codes,other-client counts or task drift from this receipt.

New helper retains fixed failure_phase/failure_code and bounded safe scalar details
before recovery,then prints them even when baseline_restored becomes the final phase.
Distinguishes web/PG unclean stop,unconfirmed stop,remaining PG PID,version/task drift,
other-client count,command return/timeout and OS errno;no exception/argv/stderr text.
Strict clean-stop/zero-client/version/task checks unchanged pending actual cause.

--diagnose-transaction reads only a restored unreleased baseline. Existing SH lock,
validated private marker/run/IDs/root metadata,container inspect and bounded read-only
DB comparisons;no PHP/Moodle bootstrap,stop,start,backup,activation or journal write.
Reads bounded private log but returns ONLY exact known full container-ID lines as
fixed container labels,never raw text. Unknown stop signals become OTHER_SIGNAL.
Current core/addon/task comparisons and other-client count are CURRENT observations,
not proof of the old shutdown condition;old absent failure fields stay UNRECORDED.
Refuses unfinished/released transactions instead of pretending they need a normal
diagnostic. Next operator command is this read-only mode,not another upgrade attempt.

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

Local31tests:30 PASS/1 owner fixture skipped because local root namespace maps only
UID0. Portable real file copies/restores,first/repeat CLI failure,partial baseline,
restore retry,tamper/client/duplicate/publish/discard-ack guards,task policy,owned
maintenance,closed SSH stream,EX/SH locks,masked stop API/read-only SQL PASS.
New stop-evidence/privacy/old-receipt diagnostic tests preserve source and control
file signatures;no mutation lock/host action in diagnostic. Real stop guard still
rejects nonzero/OOM and records fixed evidence. PHP8.3 source CI mandates privileged
actual33:33 roots,999:1000 PG root/999:999 child
and mixed data owners/modes on temporary files. Docker/SQL/HDD external calls fake.
Schema migration is unchanged official CLI/package already accepted on stage.
Previous exact d3e1bfed context38312720879/review38312720878 SUCCESS;privileged owner
fixture1/OK(no skip). New diagnostic exact a8caec55 [CI receipt](production_patch_stop_ci_20261013.json):
context38319610463/review38319610453 SUCCESS,source PHP8.2/8.3/frontend SUCCESS;
Python344/12skips and privileged owner fixture1/OK(no skip). Server diagnostic pending. Production upgrade
stopped safely as above. Relevant addon runtime acceptance
and prior native full RC retained separately;no repeated stage cycle.

Window estimate:prior same-package stage transaction1109s,real paired rollback629s.
Allow20–30minutes normally plus extra if recovery needed;not production timing proof.
Short initial HDD web pause,then longer coordinated web/PG pause. Console phase
markers/private raw log;temporary silent copying is expected.
