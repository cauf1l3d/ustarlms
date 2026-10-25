# INF-03 code ownership and private legacy archive

Owner authorized implementation25Oct, native author implementation,0subagents.
Owner says legacy handlers/reports are unused but may be needed for USTAR Connect.

Goal: deny UID33 replacement/modification of deployed code; retain inactive extras
privately with exact bytes and reversible metadata. Preserve Moodle data, DB app
role, current config, HTTPS, running worker policies and accepted source2300.

Approach: existing Python stdlib coordinator/locks/backup boundary. No recursive
shell chmod, code rebuild or container recreation. Record exact whole-tree hashes,
metadata and directory identities; root-owned state and archive outside code.
Read-only bind recreation rejected for this slice because it changes container
identities/mount contracts. Deleting inactive extras rejected: owner needs reuse.

Owner inventory: complete, no ACL/hardlinks/pruned dirs; UID33 849dirs/3908files.
Root config0:33/0640; root/parents protected; data write allowed. Inactive extras
are dated lab_patch_candidate.EXTRAS; githash distribution excluded. Include only
present regular allowlisted files; reject unknown members in public/reports.
Do not infer secrets or HTTP accessibility from names. Never publish contents.

- [x] Add offline snapshot/policy/archive transaction with actual filesystem tests.
  Interfaces: snapshot(root), desired(before), Transaction.apply/recover/undo.
  Unknown symlink/mount/hardlink/ACL/content drift fails closed. Files keep execute
  bits; remove group/other write, uid becomes0; retain gid. Root config untouched.
- [x] Persist before images and archive root0700/files0600 before removal; fsync.
  Guard interruption after chown/chmod and each copy/unlink. Undo restores metadata
  and archived bytes only; never business rows or moodledata. Reject new code drift.
- [x] Integrate pinned ttyfixed DB controller; require app_role_active and verified
  current controls. Ordered own/DB/prebackup/cold locks, unchanged HDD backup,
  stop writers, apply, verify app/HTTPS/read-no-write, restore service policies.
- [x] Test native Unix access, archive privacy, failure/recovery checkpoints,
  wrong source/private state drift, future plugin creation inherits root owner.
- [x] Publish source and receipts; operator --check and apply accepted. No new
  full server lab/physical restore; reuse accepted qualification, targeted delta.

Old full restore can reintroduce legacy and writable code: metadata undo must
precede frozen physical restore. New deployments require exact source/profile
qualification; never silently rebaseline changed code. Full INF03 includes key
and dump permissions outside this code slice, and remains open until accepted.


Self-review follow-up before operator delivery: first candidate7ace868b passed
Review40136758903 and Context40136758899, including14/14 root runtime tests.
Final refinement requires backup recovery before PG restart; strict exact after
identity in active/hardened phases; directory and retained code device/inode
identity checks; actual UID33 open/write/create/unlink/archive-read denials;
existing installer activation preserves root ownership for existing/new files.
Three new guard regressions failed on previous code, then passed after correction.
Final local20tests:18PASS/2UID33 mapping skips; final CI pending. No independent
reviewer/subagents; author review only. Operator --check before any production apply.


25Oct final owner apply receipt: code_hardened/archived_files22/app_roleustar_app/
business_rows_restoredfalse after fresh backup and web_verified. Code slice applied;
post-apply browser acceptance pending. Full INF03 includes outside-code secrets,
keys/dumps/backup access and is not closed by this code slice.
