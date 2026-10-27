# Prod truth and audit reconciliation implementation plan

> Execute inline. Owner authorizes push/merges; zero subagents remains in force.

Goal: bring accepted production source and verified reviews into a maintainable main.
Spec: owner request26Oct and supplied reviews in context/reviews/20261026/submitted.
Architecture: preserve PR105/103/106 ancestry and dated evidence; one compact current
entrypoint, explicit source/deployment separation, independent infrastructure CI.

Constraints: no server mutations, notifications or Docker rollout; logging/recreate
belongs to the next planned Moodle/PHP platform window. PR107 stays independent.

- [x] Preserve PR106 at infra/docker-successor-frozen; export and verify local Git bundle.
- [x] Integrate PR105/103/106 on release/prod-truth-20261026; retain original entrypoints.
- [x] Verify supplied claims against current source and receipts; record per-direction verdicts.
- [x] Remove unfinished Docker successor from active tree/gates; preserve frozen branch/history.
- [x] Add ADR0015–18, compact entrypoints, current source/evidence and accepted risks.
- [x] Combine context validators/generators; enforce entrypoint byte limits and risk schema.
- [x] Move privileged infrastructure job to path-filtered ustar-infra workflow; retain app gates.
- [x] Run source/context/local tests and full RC on the exact integration commit.
- [x] Merge qualified integration to main, verify resulting tree/status and reconcile original PRs.

Review focus: do not regress PR105 provenance guards; do not call2300 a fresh whole-host
manifest; no test bypass to hide Docker failures; accepted risks require owner/date/
trigger/evidence and do not prove controls implemented; no unrelated Studio inclusion.

Ruling: Docker engine work frozen by explicit owner scope, not repaired/requalified.
Ruling: original PRs integrated through a merge ancestry to avoid publishing stale
intermediate main contexts; critical conflicts resolved and regenerated before merge.
Ruling: security/UX suggestions are verification inputs, not authority for broad UI or
business refactoring. False findings and unsafe example patches will not be applied.

Local verification: context/source-tree equality, secret guard and35tests pass/1Go environment skip. PHP CLI unavailable locally; source/full RC delegated to actual CI. No owner server action.

Integration PR108 owns exact-head CI and merge receipt. Publication status resolves from that PR; plan preparation is not a claim of server deployment.

Completion: PR108 merged; context/runtime/repository_reconciliation_20261026.json contains exact checks. Author self-review under owner0agents constraint. No server deployment.
