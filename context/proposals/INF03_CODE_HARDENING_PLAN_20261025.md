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
- [ ] Test native Unix access, archive privacy, failure/recovery checkpoints,
  wrong source/private state drift, future plugin creation inherits root owner.
- [ ] Publish source and receipts; operator --check first, then apply. No new
  full server lab/physical restore; reuse accepted qualification, targeted delta.

Old full restore can reintroduce legacy and writable code: metadata undo must
precede frozen physical restore. New deployments require exact source/profile
qualification; never silently rebaseline changed code. Full INF03 includes key
and dump permissions outside this code slice, and remains open until accepted.
