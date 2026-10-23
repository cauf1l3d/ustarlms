# CON-02 deployment implementation progress — 22Oct2026

**23Oct owner continuation:** b971 readonly preflight refused exactly two absent legacy
UNIQUE indexes. Fix4394b994 accepts strict XMLDB or the exact observed pair on frozen2000;
no DB index/data repair. New source Context39947880072/Review39947880106 SUCCESS:
both PHP1048/153skips, strictroot11/11, actual runtime/HTTPS, native154/800 plus strict
AND historical direct2000→2200/repeat/restore, duplicate legacy rows preserved.
[Incident/current handoff](connect_con02_index_compat_20261023.md),
[new CI receipt](connect_con02_index_compat_ci_20261023.json).74focused/1local skip PASS.
Owner recheck next; production2000+overlay/a86 unchanged. Older table/history below
records the first handoff and is superseded by this continuation.

Owner: «ок приступай к реализации план потверждаю». Approved plan:
[9 tasks/45 steps](../proposals/USTAR_CONNECT_CON02_DEPLOYMENT_PLAN_20261022.md).
Self implementation and author review,0subagents. PR106 remains unmerged.
No SSH, production changes or provider messages. Production2000+overlay/a86,
UID130 last revoked; accepted pilot is not repeated.

| Task | Final observed state —23Oct2026 MSK |
|---|---|
| 1 Fixed profile/contracts | Complete; frozen baseline/target/full XMLDB/default/index contracts, native revalidated. |
| 2 Images/locks/journal | Complete; strict actual root/GID33 state11/11, durable images/ACL/xattrs/inodes/locks. |
| 3 App+cron transaction | Complete; direct2000→2200/repeat/paired restore, public-write recovery, cold IDs/custom tasks preserved. |
| 4 v2/ACK profiles | Complete; exact immutable534 worker, old UID/subject/cutoffs/revoke preserved, distinct ACK credential. |
| 5 Runtime transitions | Complete; actual systemd/UID33/private TLS, disable/re-enable/failure fallback, cursor/uncertainty preserved. |
| 6 Plain.py/CLI | Complete; b971 ordinary.py verified against CI/exact Git, default PLAN_ONLY, strict flags/tamper denials. |
| 7 Native direct upgrade/recovery | Complete; native154/800 plus new direct lifecycle, fresh9indexes,4SQLmutations denied. |
| 8 Actual systemd/HTTPS | Complete; both downloaded PASS reports, HTTPS unchanged per operation/authorized renewal/serialized owner recovery. |
| 9 Regression/artifact/handoff | Complete as source/handoff; both PHP1040/153skips, final CI green, exact artifact delivered; owner execution next. |

Final [runbook](connect_con02_deployment_20261023.md) and [receipt](connect_con02_deployment_ci_20261023.json).
Below is dated execution history; earlier pending states are superseded by this table.

TDD: contract pattern initially7 assertion failures for missing interface, then7 PASS.
State pattern initially7 assertion failures for missing interface; now6 PASS/1skip.
Combined `python3 -m unittest discover -s tests/r16 -p 'test_connect_con02_*.py' -v`:
14 tests,1 skip. Local root user namespace cannot map GID33 (`chown` EINVAL);
strict UID33 CI is mandatory and is not claimed passed. Baseline cron10/10 PASS.
Exact a86 missing objects restored from GitHub and verified by Git blob/tree/commit SHA.
No installer artifact or owner server commands are ready yet. Source534 product remains unchanged.

Task3 TDD:8 missing-interface assertion failures,then8 PASS. Added actual xattr recovery test:
old copy lost user.con02 (ENODATA),then9 PASS after a private xattr-aware adapter.
Original primitives/modules remain unchanged. Combined23 tests/1 UID33 environment skip PASS.
Direct/repeat preserves customised task [1,1]; partial cron pair restores; post-public
recovery preserves a new write. Fresh child trace confirms only first4 locks held.

Tasks4/5 TDD: profile interface7 assertion failures including subcases→5 tests PASS;
runtime7 missing-interface assertion failures→7 PASS. Actual fork/exit after
include_handoff resumes the same generation/cutoff/key images. Real SQLite cursor42→84
and uncertainty survive disable/re-enable; failed v2 probe returns saved v1 runtime.
Application/service controls in those tests are modeled; no actual systemd PASS claimed.
Combined35 tests/1 UID33 environment skip PASS. Next plain.py/CLI, then native/actual gates.

Task6 TDD7 missing-interface assertions→7 package tests PASS. Two exact Git builds
produce identical ordinary.py bytes. Every asset/module is checked before embedded
imports; invalid inventory/base64/tamper/dirty builder and combined flags deny.
Default works with python -I -B and with malicious neighboring module files present;
it emits PLAN_ONLY without managed-host/network access. Combined42tests/1skip PASS;
check_context and diff --check PASS. No final deployable tool SHA/hash is handed off yet.

Task7 RED: missing direct-upgrade runner wiring assertion failed;then10 application/wiring
tests PASS. New runner restores the private synthetic2000 pair, installs exact1e8+overlay,
seeds revoke/custom task/history, upgrades directly to fixed534/repeats, compares fresh
and restores2000. Four actual transaction-scoped SQL mutations feed the new validator.
Only sanitized SQL metadata/results become artifacts. Local PHP/Docker are unavailable;
PHP syntax and native lifecycle are CI gates, not claimed passed here.

Task7 actual CI950: source8.2/source8.3/connect-runtime SUCCESS; connect-db FAILED
(job120014562880). Direct2000→2200 and repeat/history/revoke checks passed, then
fresh_ SQL failed: prior paired restores intentionally removed that schema.
Fixed fixture to install a fresh2200 reference anew. Downloaded native ZIP SHA
bf641ed795acb4e178e18d1e74e08103550f18549d5392c32937364185be0064 verified.
Native PG metadata also exposed prefixed pg_indexes.tablename and9 ACK indexes
(including foreign-key indexes, two userid entries). New focused REDs observed;
contract9/9 GREEN after normalization and full XMLDB index export. New validator
passes verified downloaded99→100 snapshots. Complete native rerun still pending.

Task8 RED: missing actual fixtures wiring assertion;then8 runtime/wiring tests PASS.
Added new Runtime on actual systemd/UID33/LoadCredential/private TLS, actual frozen
CLI handoff refusals and separate actual HTTPS owner callback in the existing
Apache fixture. SQL/systemd boundaries are labelled per fixture. No actual PASS claimed yet.

Before Task8 publication: combined47tests/1local UID33 namespace skip PASS;
Python/wrapper/YAML/bash syntax and diff --check PASS. Fresh reinstall/PG index
normalization and whitespace fixes are source-tested; actual required CI rerun next.
No completed actual Task8 marker has been received yet.

Additional native-derived export checks: index field whitespace and XMLDB
foreign-unique semantics observed RED→GREEN; contract11/11 PASS. Fixed product
source remains534; only deployment profile/tool/tests changed. All actual gates
must rerun on the new commit; prior runtime success does not prove these new fixtures.

Task9 crash review RED→GREEN: unchanged images retain inode; status reports an
interrupted run while DB is stopped; unknown cron drift refuses before journal writes;
recorded container identities bind while stopped; interrupted physical code restore
keeps config.php/private include present and resumes the same generation. Combined
53 tests/1 local UID33 namespace skip PASS. ca3 actual root state7/7 and old gates
passed; new systemd fixture failed before transition because the initial v1 wrapper
read a not-yet-created ACK credential. ENOENT is now treated as absent; sanitized
fixture journal collection added. New actual runtime/native rerun required.

Task9 additional RED→GREEN: initial baseline cannot adopt unknown columns/type/nullability/
default/indexes; production journal binds exact control inventory and paths; interrupted
symlink publication resumes only the matching saved target/metadata; foreign collision
is preserved/refused; restore checks remaining one-copy budget instead of the already-used
three-copy upgrade budget. Recovered exceptions now go to private logs. Actual downloaded
PG99→100 snapshots pass the complete fixed XMLDB/default/index profile. Fresh-index parity
now includes prefixed pg_catalog tables instead of comparing two empty index lists. Positive
readonly check preserves all files/inodes and creates no state/locks; actual fork/exit at all
7 runtime durable phases preserves generation/cutoff/keys. Combined61tests/1local skip PASS.
20eb source8.2/8.3 SUCCESS; new runtime fixture failed on missing production_manifest import
path, fixed and lazy Context construction smoke-tested. Context20eb failed only because the
progress index was not regenerated; regenerate before publication. Native20eb in progress.
Full r16 running; syntax and unchanged fixed534 product component trees verified.

Whole local r16:1035 tests,55 environment skips,6 errors limited to three historical
package tests missing local38d Git objects and three harness imports missing MCP dependency.
No product assertion failures; this is not a local full PASS. Required CI with complete Git
and pinned dependencies must provide the full regression result. Context regeneration,
development557files/100tables and check_context PASS; fixed534 component trees unchanged.

20eb native job120032854820 SUCCESS: CON02_UPDATE_NATIVE=PASS. Artifact12453170173
ZIP SHA b890a73f2d468f5eb56a968137cb3af6e625b9e337da042da64fa4de37a937ec verified;
current strengthened full XMLDB/default/index validator accepts actual baseline/target/
repeat/restored snapshots and denies4 native mutations. Fresh parity contains all9
actual ACK indexes. Final source CI rerun remains required, including new systemd/HTTPS.

7c Context39839664092 SUCCESS. Actual runtime reached immutable staging and correctly
refused unsafe_release_parent in the disposable runner. Fixture now records writable
root-owned ancestors, temporarily removes group/other write bits, and restores original
modes in cleanup. Production stage guard remains unchanged. Final actual runtime/native
and both source gates must rerun;7c ordinary file is not released for owner use.

C533 Context39840142807 SUCCESS. Runner confirms /opt root-owned0777; fixture temporarily
protects it and restores original mode. Staging then refused strict worker metadata.
A real default-POSIX-ACL test reproduced replace() retaining an inherited access ACL
absent from the desired image (RED); replace now removes inherited extras before restoring
saved xattrs (GREEN). This preserves exact metadata; production guards are not weakened.
Actual CI attribution remains pending; public release-file metadata diagnostics added.
Added real fork/exit after public commit and successful explicit late restore only with
both outage/discard flags:16 app tests PASS. Combined64tests/1local UID33 skip PASS.
Final CI rerun required; source/worker534 and production2000/a86 unchanged.

B4 actual runtime passes immutable staging, ACK credential/probe and repeated receiver
handoffs after the ACL correction. The next fixture refusal is unsafe_activation_parent
before the intended frozen legacy drift check. The disposable parent normalization now
also covers the synthetic /usr/local fixture/tool ancestors and restores originals.
Author review added recorded-ID binding for completed app rechecks/runtime readiness
and readonly check; a replaced-container RED now refuses before journal/service changes.
Combined65tests/1local UID33 skip PASS. Final required actual/runtime/HTTPS/native/source
checks remain pending; artifact not released.

0821 Context39841829745 and Review39841829756 SUCCESS, both source1039tests/153skips,
actual root state11/11, new runtime/HTTPS markers and native154tests/800assertions PASS.
Native artifact12454232125 SHA35a01df89423fba6ed79afdbec88132298b0bd9e5cd711abacbc6b1dc090bcf1
verified. Final author ACL audit found one further activation case: an existing app file
without an access ACL inherited its directory's default ACL during replacement. Actual
filesystem RED reproduced; adapter now removes extras and verifies the exact saved xattrs
for existing paths.66focused/1local UID33 skip GREEN. This final implementation must rerun
required CI before its ordinary command is handed off. Product534/production2000+a86 unchanged.
