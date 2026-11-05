# SDD ledger — plan: docs/superpowers/plans/2026-11-05-studio-csp-remediation.md
Owner reports CSP/YUI trace;45e NOT installed. Native execution/no subagents/SSH/merge.
Ruling: provide supported YUI global=window before first constructor through request-local additionalhtmlhead; preserve strict CSP, no null global (JSON feature code needs .JSON), preserve custom head.
Pre-flight: real head order verified in pinned Moodle core; standalone Studio browser does not exercise native CSP. Native browser test is first deliverable, watch RED before product edit.

Native browser harness277: PHP login succeeds inside the container, but host Chromium receives ERR_CONNECTION_REFUSED (both runtimes); no product RED claimed. CI-only browser compose overlay permits host loopback publishing; existing internal native test networks remain unchanged. Re-run before product edit.

Valid native RED: eb00cd12 /42041487667 jobs127293512865 and127293513317 reproduce both exact owner exceptions on5.1.8/5.1.1. Minimal course_studio.php head append added after this evidence; awaiting same-browser GREEN. Task2 Delivery missing-operation RED observed; new bounded operator local14 tests13pass/1UID33skip with actual PHP. Forced local UID33 fixture cannot chown33 on this filesystem (EINVAL); mandatory dedicated CI remains authoritative.

First GREEN attempt f4d81940 /42042561288: both native runtimes passed absence of original errors, Y.one and explicit window/global and custom-head assertions; negative eval probe returned allowed because synchronous DOM-script insertion inherits CDP Runtime's default allowUnsafeEvalBlockedByCSP. Probe corrected to a real browser click after evaluation returns, preserving EvalError assertion. Product/header/operator bytes unchanged. CDP primary contract: https://chromedevtools.github.io/devtools-protocol/tot/Runtime/#method-evaluate. Fresh full qualification required.

Both native browser jobs a28 PASS (actual YUI/API/save/fresh390reload/parent+preview CSP). Source8.3 twice hit15min deadline during Ubuntu font dependency download, after all1633 Python tests passed; actual font mirror download progress confirms tooling budget, not an application failure (jobs127302809679 and127311818773). Increase only source CI timeout to25min; preserve assertions/dependencies/runtime pins. New exact qualification required; app901/toolf4d/archive33e0 unchanged.
