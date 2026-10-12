# Separate production5.1.8 candidate; activation is a later contract

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
known final setup line. No offline lab credentials/policy are copied into production.

Before completion,candidate and preserved originals read back;live code/config reread,
DB versions/tasks and container identities compared. Package mutation,source drift,
collisions,unexpected live entries or checksum failures refuse;partial candidate
retained,no existing source/copy deleted. No PHP/Moodle bootstrap,Composer,pulls,
container/service starts/stops,cron edits,data/DB writes,backup,activation or upgrade.

Copy-only storage budget reserves4GiB/inodes and overhead;deployment/PG growth/paired
backup budget is not accepted yet. Nightly archive hash is not decryption/restore proof.
Full RC a42672e retained for unchanged native application/frontend;real candidate
server preparation and fresh paired deployment/rollback contract remain pending.
Local9 guard tests/full284 with11 skips passed before final loader guard;final dedicated
suite10 PASS includes loader refusal. Exact new source CI pending. No full-addon PASS.
