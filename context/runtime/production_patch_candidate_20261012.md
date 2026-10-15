# Separate production5.1.8 candidate; activation is a later contract

**Current owner checkpoint12Oct23:31MSK:**
[candidate518-wnd475x8](production_patch_candidate_result_20261012.json) PASS:
44174 entries,19 roots/57 versions/526 USTAR,manifesteb6d025d/sourcee7318ecc,
four originals retained,source/lifecycle unchanged. Creation is closed;do not repeat
preparation or accepted stage operations without drift. Fixed sourcef1b68694 context
38303047195/review38303047204 SUCCESS(source8.2/8.3/frontend);Python298/11skips,
synthetic PHP config and real candidate-comment-tail tests PASS in source8.3 logs.
Ordinary DB/rollback/gate skipped;unchanged native application prior full RC retained.
Next [switch/paired restore metadata and budget](production_patch_switch_preflight_20261012.md).
Activation,fresh coordinated recovery and relevant addon runtime remain pending.

**Current owner checkpoint12Oct23:06MSK:**
[diagnostic receipt](production_patch_config_diagnostic_result_20261012.json)
c092d3b6/SHA6ea279b4 COMPLETE: one exact setup, no homepage token, only old
final-nonwhitespace guard failed; raw suffix comments_and_whitespace. No config text,
PHP/SQL or candidate/lifecycle changes. Previous refusal is a builder limitation,
not evidence of production config damage. Passed readiness/stage/full RC retained.

Builder now permits only ASCII whitespace and complete line/block/hash comments
after exact setup;preserves every original byte and inserts HOME only in candidate.
Rejects PHP tags, attributes, executable suffix, unfinished comments and non-ASCII
whitespace. Conservative prefix scanner verifies setup is on its own line in PHP,
outside strings/comments/nested delimiters, following a completed statement.
Unsupported mixed tags/heredoc/complex interpolation/attributes refuse;no general
PHP parse or execution. Raw lookalikes/controlled if/loop setup cannot be insertion sites.
Local23 tests:22 PASS/1 PHP-dependent skip in Python-only image. Added real temporary
candidate test with trailing comments/private original readback and source unchanged.
PHP source CI runs a synthetic local setup to compare credentials hash/prefix and
homepage-before-setup values;this does not invoke Moodle or production config.
At that checkpoint fixed source CI/server preparation were pending;later acceptance
is recorded above. No production activation/upgrade.

## Historical refusal and diagnostic contract

[Owner first production candidate attempt](production_patch_candidate_refusal_20261012.json)
on source57583559/SHA7ef2216b verified but refused at configuration before SQL,
live inventory,namespace creation or copying. At that checkpoint the exact failed
condition was unknown: the old guard required one exact setup spelling, no literal homepage
token anywhere, and setup as final non-whitespace text. Official stock config-dist
itself has comments after setup; this demonstrates a possible guard limitation,
not the observed production config shape. Do not publish config lines or credentials.

Mutually exclusive --diagnose-config loads only pinned readiness primitives
and reads at most128KiB of the existing config with no-link/identity/drift guards.
Outputs fixed condition labels, counts and suffix categories; no config text or
values. This is raw-text observation, not PHP semantics or insertion approval.
No stock archive/profile scan, Docker/SQL, candidate namespace/lock, lifecycle or
config writes. In the c092d3b6 diagnostic delivery insertion/refusal rules remained
unchanged until actual shape was known. Local dedicated16 tests PASS, including private-value
sentinels, misleading raw lookalikes, malformed UTF8, comments/closing tag/code,
and diagnostic CLI isolation. Later actual server execution is recorded above.

[Owner production preflight](production_patch_readiness_result_20261012.json)
12Oct14:43:42UTC PASS:526 USTAR,57 addon disk/DB versions,old core metadata/index,
latest1,019,254,423byte HDD archive4e18019e readback,129 enabled tasks unchanged.
Root65,243,254,784bytes free;logical code381,791,142,data629,285,550,PG242,196,330.
Active non-stock module instances:none in aggregate list;block ai_chat1/rbreport10
exist. Counts are usage indicators,not functional acceptance. External AI/service
availability in offline stage remains distinct from patch/data regressions.

production_patch_candidate.py prepares only a new private directory under
/var/lib/ustar-production-patch-20261012. It imports exact installed readiness
f72031dc and stock builderbb71ddd;no lab precheck/bootstrap/lifecycle invoked.
Default plan only;--check reads;--prepare-candidate creates its own namespace marker,
coordination lock and unique copy. Existing namespace marker mismatch refuses.

Official archive stock/core hashes retained;19 live addon roots and23 reviewed extras
copied with independent inodes,content hashes and original overlay metadata. All
core/addon code bytes and inventory must match accepted original frozen snapshot,
except live master config and reviewed extras. Lab-specific public config was rewritten
in the frozen snapshot:production loader separately allowlisted by exact SHA of
original upstream1cd1781,official5.1.8,or reviewed minimal loader. Unknown loaders refuse.
Official new public config/index remain stock. Originals of config,public config/index,
and legacy githash preserved in a private companion tree. Production master config
credentials/URL/mail/cron untouched;only candidate gets relative USTAR homepage before
known standalone setup line;following comments retained. No offline lab credentials/policy are copied into production.

Before completion,candidate and preserved originals read back;live code/config reread,
DB versions/tasks and container identities compared. Package mutation,source drift,
collisions,unexpected live entries or checksum failures refuse;partial candidate
retained,no existing source/copy deleted. No PHP/Moodle bootstrap,Composer,pulls,
container/service starts/stops,cron edits,data/DB writes,backup,activation or upgrade.

Copy-only storage budget reserves4GiB/inodes and overhead;deployment/PG growth/paired
backup budget is not accepted yet. Nightly archive hash is not decryption/restore proof.
Full RC a42672e retained for unchanged native application/frontend;real candidate
server preparation now accepted above,fresh paired deployment/rollback contract pending.
Local9 guard tests/full284 with11 skips passed before final loader guard;final dedicated
suite10 PASS includes loader refusal. Prior source57583559 context38268525410 and
review38268525497 SUCCESS(source8.2/8.3/frontend); ordinary DB/rollback/gate skipped.
Diagnostic c092d3b6 context38301407662/review38301407648 SUCCESS. No full-addon PASS.
