# Execution ledger — plan: USTAR_CONNECT_CON01B3_PLAN_20261019.md

19Oct2026, owner05:30MSK; executor лично,0subagents. Base81a1be76 verified in GitHub PR106,22native tests/87assertions SUCCESS. Existing dedicated non-main checkout reused; no new worktree or dependency installation. Baseline r16 repeated before edits.

Pre-flight interfaces:
- Task1 canonical completion produces userid/requestid inside owned transaction; Task2 consumes these refs for metadata before commit. Ruling: do not wrap register in outer transaction — email lock would release before outer commit, allowing LAN duplicate-email race. Keep lock-only Connect wrapper and canonical owned transaction. Cost if wrong: account/notification partial commit; covered by rollback/race tests.
- Task2 entry snapshot consumed by Task3 retry/concurrency: explicit signup may bind only its own entry, other pre-existing unlinked entries still restart_required. Ruling: no general unlinked session upgrade; no new employee scope. Cost if wrong: stale browser identity promotion; test required.
- Canonical notify_reviewers already writes target_core outbox; Task2 reuses it, Task3 validates single notice/approval. Ruling: no new Telegram-specific outbox/approval permissions in this slice. Cost if wrong: no actual Telegram delivery until consumer slice, explicitly not claimed.
- User prohibits agents and repeated permission loops in already-authorized development. Inline self-review replaces delegated review; shared draft PR106 continues, no merge/deploy. Cloud skill scripts/references not executable via available provider; durable plan/ledger maintained here with project tooling.

Pending: Tasks1–3. Native DB unavailable locally; only GitHub real Moodle/PostgreSQL gate may close native checks.
