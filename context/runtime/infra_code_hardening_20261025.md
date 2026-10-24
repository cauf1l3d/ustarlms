# INF03 operator —25Oct2026

**25Oct — INF03 code/archive coordinator prepared; production check pending.**
Owner metadata complete: UID33 owns849dirs/3908files, no ACL/hardlinks; config0:33/0640,
parents protected, moodledata writable. Owner explicitly says old handlers/reports unused
but needed for possible USTAR Connect reuse. Preserve private bytes/path/metadata; no
public source upload. scripts/infra/code_hardening.py implements guarded archive+metadata
transaction with DB app role retained, existing backup/locks and metadata-only undo.
14focused tests:13PASS/1local UID33 namespace skip; strict UID33 runtime CI added.
Check only until CI and owner --check accepted. No code permissions changed on server.
Frozen app/DB/physical recovery requires code-hardening undo first; source drift refuses,
not silent rebaseline. Full INF03 keys/dumps scope still open.0subagents/main unmerged.

Operator SHA256: 6c2da4d1766f563070a245a68a4c9685a39524b60b5a36224a4f562061b492b7

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
