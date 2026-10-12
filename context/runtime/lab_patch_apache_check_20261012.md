# Apache/PHP inspection of upgraded stage

Owner HR requested read smoke accepted; relay access ended per owner report.
This helper needs running stage containers, not the public relay. It refuses to
start stopped containers. Pinned upgrade/helper and exact upgraded run/baseline
are required. Creates one nonce protected PHP builtins-only probe, queries via
existing stage network namespace, removes it and verifies candidate bytes again.
No Moodle bootstrap in probe, persistent config edits, relay restart, cron,
Composer, upgrade or production mutations. SQL bounded readonly stage only.

Helper SHA256: `927a733c822be1348b77c80f8b9520bb748a02ba58840e2c549244ccd442b124`.
Run installed root-owned helper with `python3 -I -B -u ... --check`.
PASS/SETTINGS_DIFFER are observations of actual Apache settings; server execution
pending. If stage stopped, inspect first; no repeated upgrade to reopen access.
Local tests do not prove server runtime. Full addons/frontend CI still pending.

Dedicated probe tests:4 PASS. Paired upgrade/recovery tests:23 PASS. Broad r16 attempt had missing mcp dependencies and portable temporary-parent fixture failures; fixture fixed and scoped tests repeated. No broad-suite PASS claimed; exact-head CI pending.
