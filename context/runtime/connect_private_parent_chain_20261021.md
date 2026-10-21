# Parent-chain refusal and correction — 21 October 2026

Owner ran frozen e58354d3: first managed_operation_busy_retry_later, then
unsafe_include_parent for /opt/ustar/data/moodle UID33/GID33/0775.
configuration_changed=false, directory_ownership_changed_in_this_run=false.
The busy condition cleared; repeating the old package cannot repair this ancestor.
The prior guard inspected ancestors before mutating the public directory.

Correction extends the existing explicit preparation to the two measured directories:
/opt/ustar/data/moodle 33:33/0775 → 0:33/0755;
/opt/ustar/data/moodle/public 33:33/0755 → 0:33/0755.
No recursion; children/config.php/token/journal are retained; no service restart.
The ancestor group-write bit must also be removed to prevent replacement of the
secret-bearing child. Unknown ancestors are reported together and refused before
mutation. Directory descriptors, inode checks and durable schema2 before-images
support bounded recovery after each ownership/mode mutation. Existing schema1
transactions remain readable. Extended ACL mask changes are projected and checked;
restoration preserves named entries and restores the original ACL mask.

Targeted tests include exact UID33 chain, forward/reverse interrupted mutations,
unknown ancestors, ACL restoration, inode replacement and metadata drift.
Local runtime maps UID0 only; actual UID33 tests require the existing strict CI gate.
No application/cron/HTTPS reinstall. Bridge activation remains the next deployment
transaction; this preparation alone does not enable account linking or notifications.
No subagents; no production changes by agent. Owner execution remains pending.

## Frozen owner handoff

Source87fe3da727650e5ab80db4f611088a34b1fd1c96; artifact12315050039,
run39528533688/job118988969203. Exact16 strict actualUID33 tests PASS/0skips.
Build and plan mode PASS. Whole run cancelled by subsequent docs-only head;
not a whole-CI-success claim. Local readonly/config8tests PASS; full local suite
903tests/50skips had3environment errors from missing mcp dependency.
PYZ SHA25676562298c6e5b8ac17d485a8189dfb376fb32798021da46f9b85cb1551b05428.
Owner command: install root600, verify this SHA, run --prepare-include-parent.
This supersedes the e583 execution instructions; activation remains pending.
