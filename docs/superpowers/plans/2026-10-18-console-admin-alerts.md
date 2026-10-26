# Console account and notification implementation plan

> For agentic workers: implementation uses fixed contracts below, TDD, isolated worktree, independent final review. User explicitly requested continuing and finishing existing design; no repeated approval handoff.

Goal: ship browser-managed console users, proven Telegram linking and personal notifications plus honest web log state.
Architecture: existing Auth DB stays web-only; notifier-only account store is accessed through a peer-checked Unix socket; UI uses authenticated fixed API routes.
Spec: server-console/ADMIN_ALERTS_20261018.md

Global constraints: preserve old accounts/bootstrap recipients and CA/token/TOTP; no arbitrary shell/URLs; no native SSH; no network changes.
Review focus: legacy migration, last-owner concurrency, disabled identity Telegram revocation, code replay/forwarding, per-recipient quiet/repeat/recovery semantics.

- [x] Auth users: add enabled migration/list/create/update, last owner transaction, revoke sessions, owner-only routes registered from admin_users.py. First run migration/last owner/role/CSRF tests failing then passing.
- [x] Notifier: implement notifier_control.py private store/RPC/link/preferences, link command and dynamic recipients in alerts.py, maintain legacy behavior when no personal settings. First run ownership/link/replay/preferences/evaluation tests failing then passing.
- [x] UI: add users owner page and my Telegram/notifications page; accessible mobile forms, themes, role denial, no secret echo. First run new browser scenarios failing then passing.
- [x] Integration/observability: register APIs, revoke notifier before disabling local account, independent events sanitize; regression source_empty/events-ok; safe diagnostic tool and no-second-server heartbeat design.
- [ ] Delivery: full suites/typecheck/build, independent review/fix, publish with expected-ref lease to PR103, exact-source CI/archive verification, bounded update commands and truthful pending native checks/context.
