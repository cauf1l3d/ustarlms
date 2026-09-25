# Rollback boundary

- Route edits use immutable/versioned point history and reject stale writes.
- Existing isolated database/code rollback drills remain documented in the prior reports and scripts under `ops/`.
- This checkpoint introduced no production migration and therefore requires no production rollback execution.
- Before production, take a fresh snapshot, record candidate hashes, rehearse restore on an isolated copy, then deploy only after the owner gate.

