# Telegram operator interface implementation plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan inline. Existing owner authorization covers PR103 development and publication; native installation remains owner-run.

**Goal:** Make the existing private bot readable, show collected measurements and useful automatic incidents, and prepare opt-in native opening of the existing console.

**Architecture:** A pure presentation module consumes the existing bounded snapshot and rule state. The existing private-chat allowlist, durable queue, transport and privileged-operation boundary remain in place. Mini App opens the existing login; it grants no Telegram-based authentication.

**Tech Stack:** Python3.12, SQLite outbox, Telegram ReplyKeyboardMarkup/WebAppInfo, existing console wheel.

**Spec:** Owner request18Oct: readable bot, missing measurements, native Telegram console opening, important automatic alerts. Preserve stable host/home networking and duplicate operator commands in chat.

## Global Constraints

- No production Academy, host routes/DNS/firewall, proxy destination or authentication changes.
- Missing/stale measurements remain unknown; optional unconfigured checks must be identified separately from measured faults.
- Private numeric actor AND chat allowlists apply to buttons and commands. No shell, archive or decryption-key delivery.
- Native Mini App is disabled by default; the client must reach and trust the existing HTTPS origin.
- Keep incident persistence, sustained thresholds, bounded maintenance, retries and recovery semantics.

## Review Focus

- Partial or stale per-probe data must not be displayed as a current measurement or recovery.
- Four button taps in one polling batch must be answered; replayed/unauthorized updates must not.
- Existing queued plain text must remain deliverable; no implicit HTML parsing.
- The independent observer zipapp must include the new presentation dependency.
- Mini App activation must preserve token/proxy/IDs and reject a malformed opt-in value.

### Task 1: Cards, keyboard and informative alerts

Files: create `server-console/backend/ustar_console/telegram_views.py` and `server-console/tests/test_telegram_views.py`; modify `alerts.py` and `build-release.sh`.

Interfaces: `command_reply(text,data,origin)` stays string-or-None. Presentation exposes `reply`, `keyboard`, `incident`; `Queue.deliver(...,reply_markup=None)` remains observer-compatible.

- [ ] Write tests for resource/database/backup/lab cards, unknown/stale data, status wording, Russian buttons/command mentions, incident values/actions, burst/replay and immediate first-cycle reply.
- [ ] Run `console-venv/bin/python -m pytest -q server-console/tests/test_telegram_views.py`; expect assertions failing on old output.
- [ ] Implement Russian cards, units/MSK time, bounded plain text, four commands/10s, keyboard and same-cycle delivery. Render existing alert thresholds without changing host behavior.
- [ ] Run the new tests and full `server-console/tests`; expect all PASS. Include the presentation module in observer.pyz.

### Task 2: Opt-in native opening

Files: `alerts.py`, `setup.py`, tests and operator runbook.

Interfaces: credential optional `mini_app: bool`; `ustar-console-setup --telegram-mini-app on|off` changes only that switch and reloads notifier. The keyboard uses `web_app.url=settings.origin` only when opted in.

- [ ] Test opt-in parsing, private allowlist, native keyboard payload, token/proxy/ID preservation and notifier-only reload; run and observe failure.
- [ ] Implement opt-in and normal `/panel` network/certificate guidance; keep existing console login and CSRF.
- [ ] Run full backend suite; expect PASS. Document current LAN/local-CA constraints and separate home-access dependency.

### Task 3: Review and delivery

- [ ] Record owner-reported working bot as a new dated receipt; retain earlier failure receipts.
- [ ] Update canonical context and generated indexes; run context/secret checks and full backend verification.
- [ ] Obtain one fresh whole-change review; address material findings with RED→GREEN tests.
- [ ] Publish source to PR103, verify exact CI/artifact, then publish pinned installation runbook and duplicate every installation command in chat.

Execution ledger: workspace `egress-delivery/telegram-operator-progress.md` outside git. No per-task implementation agents.
