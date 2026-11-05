# SDD ledger — plan: docs/superpowers/plans/2026-11-05-studio-csp-remediation.md
Owner reports CSP/YUI trace;45e NOT installed. Native execution/no subagents/SSH/merge.
Ruling: provide supported YUI global=window before first constructor through request-local additionalhtmlhead; preserve strict CSP, no null global (JSON feature code needs .JSON), preserve custom head.
Pre-flight: real head order verified in pinned Moodle core; standalone Studio browser does not exercise native CSP. Native browser test is first deliverable, watch RED before product edit.

Native browser harness277: PHP login succeeds inside the container, but host Chromium receives ERR_CONNECTION_REFUSED (both runtimes); no product RED claimed. CI-only browser compose overlay permits host loopback publishing; existing internal native test networks remain unchanged. Re-run before product edit.
