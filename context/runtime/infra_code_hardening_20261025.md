# INF03 operator —25Oct2026

**25Oct — INF03 code hardening applied by owner; keys/dumps slice next.**
Operator SHA6447d5d6: prepared/backup_complete/writers_stopped/archive_ready,
22removed/web_verified -> code_hardened, archived_files22, app_roleustar_app,
business_rows_restored=false. Coordinator verified protected retained tree,
private archive, effective app role/HTTPS and resumed original services/policies.
Owner precheck was44210entries/22archive/4785planned metadata changes; not a new
independent post-apply census. Browser acceptance after hardening still pending.
Review40141745333/Context40141745326 SUCCESS on e573cd8e; both1130/174skips/browser,
20/20root runtime and existing DB fixture121015572073 PASS. Do not repeat apply.
Legacy extras unused per owner, preserved for future USTAR Connect. Before old
app/DB/physical restore undo code hardening first; source drift requires review.
Next INF03 config/keys/dumps/backup access outside code, metadata-only inventory.
New /var/lib journals/archive remain outside standard backup scope: preserve exact
fresh pre-hardening HDD backup. Full INF03/DR still open.0subagents/main unmerged.



**Targeted qualification25Oct:** codee573cd8e, Context40141745326 SUCCESS;
both source jobs121014745523/121014745350 SUCCESS (1130tests/174environment skips
each, browser pass). Runtime121014745480 SUCCESS; all20 targeted tests PASS with
zero skips, including actual UID33 open/write/create/unlink/archive denial and
interrupted chown recovery. Whole Review40141745333 still running inherited
connect-db121015572073 at this documentation checkpoint. Operator --check only
until accepted server receipt; no file hardening applied to production.


**25Oct — INF03 code/archive coordinator prepared; production check pending.**
Owner metadata complete: UID33 owns849dirs/3908files, no ACL/hardlinks; config0:33/0640,
parents protected, moodledata writable. Owner explicitly says old handlers/reports unused
but needed for possible USTAR Connect reuse. Preserve private bytes/path/metadata; no
public source upload. scripts/infra/code_hardening.py implements guarded archive+metadata
transaction with DB app role retained, existing backup/locks and metadata-only undo.
20focused tests:18PASS/2local UID33 namespace skips; strict UID33 runtime CI added.
Check only until CI and owner --check accepted. No code permissions changed on server.
Frozen app/DB/physical recovery requires code-hardening undo first; source drift refuses,
not silent rebaseline. Full INF03 keys/dumps scope still open.0subagents/main unmerged.

Operator SHA256: 6447d5d642c136ef5352690be19076636463a49a91cd0b9acb384440721a30d5

Standalone builder embeds pinned ttyfixed DB coordinator and code coordinator.
Use root:root0600 operator. Default PLAN_ONLY; --check is read-only and avoids PHP
bootstrap/provider calls. --apply/--undo/--recover require --acknowledge-outage.
Own state/archive /var/lib/ustar-code-hardening is root0700; files0600. No contents
from archive or private logs may be pasted in chat/Git. Archive copies verified
before originals removed, checksummed private before images retained. Code uid0,
gid preserved, group/other write removed, execute bits retained; config unchanged.
Code/data/PG mount identities unchanged. Seven known PDFs/legacy extras saved only
when present; unknown reports/symlinks/hardlinks/mounts/xattrs/owner IDs refuse.
Whole-tree drift after deployment requires reviewed refresh; undo is metadata and
legacy-file restoration, never DB/data restore. Code undo precedes DB undo and old
physical/app restore. Default does not overwrite/reuse completed generation.
Checks cover real filesystem transactions; service tests use doubles, not server
acceptance. Native UID33 check runs under sudo in existing runtime CI. No agent SSH.


## Current operator check

Extract ustar-code-hardening-20261025.py from delivered ZIP to /home/aduk/.
Install as root:root0600 in /root and verify the SHA256 above. Run Python3 -I -B
with --check only. Expected ready_for_code_hardening; no password prompt and no
service outage for check. The complete code tree is hashed; allow several minutes.
After an accepted check, --apply --acknowledge-outage performs fresh HDD backup,
stops existing writers and web, archives allowlisted extras, protects metadata,
checks app role/HTTPS and restores original service policies/states.
Success code_hardened. On interruption use same operator --recover
--acknowledge-outage. Explicit rollback --undo --acknowledge-outage. No external
timeout for writes. Never use an old physical/app/DB undo before code undo.

Archive manifest retains original file mtime, mode, UID/GID and SHA256; private
archive copies are root:root0600 under root0700 parents. Original content stays
in private state for metadata-only restoration. No business rows/data restoration.
After a legitimate source deployment the old full-tree state requires review:
new source is not silently accepted by old undo. The real installer-copy fixture
shows root ownership survives both existing-file and newly-created-file activation;
its DB/service boundary remains modeled. Future full-core upgrade/restore still
requires corresponding source/profile qualification.


## Backup coverage boundary

The existing accepted backup covers full code+moodledata and DB but not all host
/etc and /var/lib state. Preserve the exact fresh pre-hardening encrypted HDD
backup/receipt: it contains every legacy byte before removal. The new private
archive and journal under /var/lib/ustar-code-hardening stay on SSD and are not
silently added to or claimed covered by the unchanged scheduled backup helper.
Backup retention/host-state/offsite/clean-host restore remain INF14/OPS02 work.
