# Execution ledger — plan: USTAR_CONNECT_CON01C1_PLAN_20261019.md

Owner19Oct10:33MSK. Existing clean feature checkout and remote PR106 head26219dac agree; prior B4 Context39214124458/Review39214124509 SUCCESS verified. Development personally,0subagents, no production/main/network changes.

Pre-flight: transport returns typed fixed errors/result; journal consumes only minimal verified update metadata; receiver consumes both and existing launch.create, never caller-selected Moodle actor. Main Mini App direct URL supplies signed start_param; web_app URL query is not equivalent.

Ruling: permit SQLite transport journal per RFC rather than new Moodle business tables — cursor/receipts only; cost: local journal backup and disk policy become runtime acceptance, no business state moves out of Academy.
Ruling: interrupted side effect becomes uncertain, no automatic launch/send retry — avoids duplicate issue/send without provider idempotency; cost: some users must request new /start after failure.
Ruling: reset offset0 after acknowledged empty poll and24h downtime — Telegram IDs can randomize after week; local receipts still dedup; cost: possible replay is handled, no invented contiguous-ID completeness. Gap after24h explicitly possible.

Test-only /tmp/connect-bff-venv remains available; no new product dependencies required. Local PHP/Chromium absence carried forward; final native/browser proof from exact CI.

Task1 transport:25initial tests observed RED missing modules before implementation; narrow TLS client/receiver/journal then GREEN. Transport tests cover fixed endpoints/timeout/filter, loopback proxy+TLS/redirect policy,429/401/409/403, timeout/5xx uncertainty, malformed/duplicate/oversize JSON, no secret error text. Real Bot API not called.

Task2 journal/receiver: actual SQLite FULL transaction rollback test via abort trigger, distinct-process exclusive flock, restart/dedup/uncertain after actual fake send then SystemExit, receipt retention500/rowcap10000/privacy and ownership/ancestor/file modes/hardlinks. Two real guard RED→GREEN: incomplete receipt previously could advance cursor without recovery; unsafe writable ancestor was not rejected. Bounded first-poll cleanup RED→GREEN (double prepare pruned1000, now exactly500). Freshness RED→GREEN (batch timestamp could execute stale command after long poll; now per-action current clock). One test expectation corrected: previous accepted event10 legitimately leaves offset11, not0; no production change to hide it.

Task3 runtime/integration: opt-in --run/default validation/no-state CLI tests, no raw errors, real journal/injected API shutdown test. Initial shutdown fixture used future synthetic timestamp and passed for wrong reason; corrected to current date, observed actual post-SIGTERM send RED, fixed stop checks before polling and between updates. Receiver URL→real Telegram signer/verifier→existing WSGI integration PASS with synthetic Moodle boundary; no claim native/live end-to-end. Additional CA trust permission RED→GREEN.41targeted tests PASS; latest full count/CI at publication.

Ruling: existing launch binds bridge key ID, so use the same private provider key ID for receiver issue and BFF exchange — separate phase keys would break current protocol; cost: one private worker credential spans the explicit6operation allowlist, still no Moodle actor/admin authority. Example renamed accordingly; secret never stored in local journal.

Review personally (no independent reviewer per user prohibition): follow creation/send commit order, failure classification, stale-action clock, offset reset/recovery and persisted cooldown, SDK start_param/key binding, exact route separation and ownership checks. Operational UID/systemd/relay/BotFather/real API ACK remain activation work. No new Moodle schema/version or business writes/deploy. Context/source manifest unchanged542/99; current exact receipt is PR106.

Pre-publication:41receiver tests PASS; full639r16 OK/30environment skips, JS/XMLDB/Mustache source structural and context542files/99tables PASS; whitespace PASS. No local PHP/Chromium execution claimed. Current exact CI still pending publication; previous B4 receipts remain separate. Immutable final source/CI receipt will be PR106, no context-only commit loop. No additional product dependency, schema change or runtime activation.
