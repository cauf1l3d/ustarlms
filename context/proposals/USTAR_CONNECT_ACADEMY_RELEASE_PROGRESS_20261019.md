# Progress — Connect Academy release19Oct

Owner publication scope21:35MSK: code/tests without the full private server report. Clean branch based directly on remote35721073;private local commits are not ancestors. Public context below describes source/fixture contracts and verification only.

Source implementation: deterministic RC builder and7 real-Git tests;accepted-baseline paired recovery fixture;full-RC candidate packaging step. Targeted RED→GREEN and local715r16 PASS/30explicit environment skips already observed. Full source in the new public checkout is verified again before publication. PHP/Docker native checks rely on CI.

Native full RC and new paired recovery: pending. No production change or deployment acceptance. No new server collection requested. First receiver remains an independently installed component;this package does not reinstall it.

First public source6a147c82/tree0c3fb3fa:Context39304639503 PASS. Review39304639877 passed PHP8.2/8.3/source/browser,receiver host,legacy rollback and package build. Native Connect job118239572848 actually passed121tests/611assertions,six concurrency checks,upgrade/repeat/fresh and CONNECT_ACCEPTED_BASELINE_PAIRED_ROLLBACK. Its artifact upload then failed EACCES on a sanitized report:global umask077 had also made root-created artifacts private from the host runner. Fix scopes umask077 and private directory to the snapshot subshell only;reports retain normal readable modes. Actual failed upload is the regression witness;new native upload/full gate pending. No business source changes or weakened checks.

Source review: author self-review under owner no-subagent rule. No outstanding Critical/Important findings;native verification must finish before release claims. Source-only RC is intentionally not a production installer;maintenance/cron coordination and fresh paired backup belong to the next reviewed operator procedure.
