# PR109 over accepted Studio2801

Goal: verify the existing bounded RSS/operator-name optimizations on the accepted Studio source.
Architecture: merge branch histories without rewriting; keep Studio/runtime receipts and frozen operators.
Spec: context/proposals/PERF_QUERY_BATCHING_PLAN_20261027.md
Constraints: no main merge, SSH, subagents, provider sends or server rollout; no SLO speed claim.

- [x] Record owner acceptance of81118e39 and move active task to PERF-01.
- [x] Review PR109, reproduce7RED on accepted Studio, port and observe9GREEN.
- [x] Resolve metadata against79ce47b9; preserve the changed-application negative guard.
- [ ] Qualify the integrated590-file source with source/context/infra and native/full RC.
- [ ] Update PR109 with exact source, evidence and remaining release boundary.
