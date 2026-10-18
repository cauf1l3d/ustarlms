# Telegram graphics and measured console fields

> **For agentic workers:** Continue the approved existing-console change with TDD and one final fresh-context review. This resumes the user's 18 October 12:40 MSK request in the shared conversation, rather than reopening bot installation or remote access design.

**Goal:** Readable Telegram image cards from real measurements, plus HDD figures, live rules and durable incident history in the installed console.

**Architecture:** Extend the existing private allowlisted bot and safe JSON API. Generate bounded PNGs locally; preserve the full text reply and durable retries. Reuse the collector's history and HDD measurements. Persist alert transitions in the notifier's private SQLite database, exporting only fixed structured fields for authenticated readers.

**Tech stack:** Existing Python3.12/FastAPI/SQLite/React, Pillow13.0.0, a bundled licensed Cyrillic font subset.

**Spec:** The approved request in the shared conversation and the contracts below; existing `server-console/DESIGN.md`, `OBSERVABILITY.md` and `TELEGRAM_LATENCY_20261018.md` remain applicable.

## Global constraints

- Real measured values only; stale/no_data remains unknown. Missing history is a gap, never a zero or an interpolated bridge.
- Existing Telegram user/chat equality, private-chat allowlists, offset transaction, burst limits and fixed verified HTTPS/loopback CONNECT route remain.
- Full text is still delivered. Photo rejection or unavailable rendering falls back to text; ambiguous transport failures retry without silently losing the requested image.
- No Academy, routing, DNS, firewall, CA, password, TOTP or bot token reset. No new public port or Mini App requirement.
- Independent external HTTP/observer and restore/RTO need their own evidence; do not infer them from local telemetry.
- PNG <=262144 bytes; total queued PNG bytes <=16MiB; completed PNG blobs are released. One logical command keeps one durable outbox row.
- Preserve existing state tables. New columns/tables are additive; code rollback remains possible.

## Interfaces and tasks

### Task 1: Readable PNG cards

Files: `telegram_cards.py`, `fonts/`, `test_telegram_cards.py`.

- Consumes: canonical command, sanitized snapshot, optional map of `cpu`/`ram`/`http` fixed `1h` native history responses.
- Produces: `render_card(command: str, data: dict, series: dict|None=None) -> bytes|None`.
- [x] Write and observe failing tests for PNG output, stale/missing/invalid values, bounded histories and gap rendering.
- [x] Implement measured cards with large Cyrillic text, units, timestamps, status words and color; bundle licensed font and include wheel package data.
- [x] Run renderer tests and visually inspect synthetic, explicitly labeled QA fixtures at phone-scale readability.

### Task 2: Durable image delivery and incident export

Files: `alerts.py`, `telegram_transport.py`, `notification_state.py`, `server.py`, `build-release.sh`, dependency locks, updater and focused tests.

- Consumes: Task1 renderer, fixed native history JSON and existing SQLite rule evaluation.
- Produces: bounded multipart `sendPhoto` over the unchanged verified transport; outbox `photo`/`photo_sent` migration; last100 structured incidents exported by `Queue.status()`; fresh-only overview notification state and explicitly historical events API.
- Events contract: `/api/events` adds `incidents`, `incidents_status` (`ok`/`stale`/`no_data`), `incidents_observed_at`; each event has `id`, `at`, `check_id`, `status`, `reason_code`. Existing security events remain.
- Rules contract: notifications rules add numeric `since`, `last`, `sent` to `key`, `state`, `emitted`. Existing queued rows remain valid.
- [x] RED: test authorized commands, image+text delivery/retry/restart/fallback and image limits; multipart bytes, rate limits, endpoint and TLS; event persistence/recovery and authenticated/freshness-safe API.
- [x] GREEN: implement additive migrations and fixed structured exports without raw text/log/config disclosure.
- [x] Add hash-locked Pillow dependency; updater installs locked dependencies inside the backed-up venv, then validates wheel and pip state. Native fixture exercises absent Pillow and rollback.
- [ ] Run backend suite, actual transport fixtures and native CI installation tests.

### Task 3: Actual console fields

Files: UI main/style, browser tests, prototype fixture and generated prototype.

- Consumes: existing `hdd` capacity/inode fields and Task2 event/rules contracts.
- Produces: current HDD figures in server/backups, rule candidates/confirmed states/times, incident/recovery history, actual protected HTTPS download guidance replacing unconfigured SFTP text.
- [x] RED: browser checks for HDD, event history, rule freshness, real missing external evidence, phone layout.
- [x] GREEN: display sources and ages; hide current figures when unavailable or stale, keep historical incidents labeled.
- [ ] Run UI build/Chromium suite, regenerate and check offline prototype.

### Task 4: Review and release

- [x] Review the complete diff in a fresh context; fix material findings with reproducing tests.
- [ ] Run full applicable checks, regenerate canonical context/index and publish to the already authorized draft PR103 with a head lease.
- [ ] Verify all GitHub checks and inspect the downloadable artifact's hash/source/wheel/font/observer.
- [ ] Deliver exact owner update commands and list native checks still requiring server/browser/Telegram access.

## Review focus

1. Outbox created before update or process interruption between photo and text: migration and retry must retain the logical reply.
2. Fresh host snapshot with stale notification/history source: current rule/graph state must not be promoted to healthy.
3. Missing fonts/Pillow or rejected photo: full text reply and native keyboard must remain usable.
4. Unknown caller/chat/forwarded message and arbitrary PNG/path/remote response: no unauthorized rendering, file reads or endpoint widening.
5. Existing installation without the new dependency: updater and rollback must handle the whole venv without changing credentials or units.

## Execution evidence

Baseline230 backend PASS. Renderer54PASS with actual PNG visual QA. Delivery/history tests observed RED before implementation; failed-evaluation rule health and transient Telegram5xx/408/ambiguous JSON found and corrected RED→GREEN. Fresh independent review: Critical0/Important1 fixed/Minor0. Final pre-publication backend312PASS; UI/wheel build and prototype check PASS. Local Chromium binary is corrupt and CDN returned HTML, so no local browser PASS is claimed;72 cases and native dependency/rollback fixtures are CI gates.
