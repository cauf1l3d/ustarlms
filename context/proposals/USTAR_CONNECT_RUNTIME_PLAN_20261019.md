# Первый запуск business bot без Mini App

Owner19Oct17:44MSK в shared dialogue6ad62f29: Mini App после HTTPS, token перевыпущен, подготовить комплект/службы и запуск бота. Предыдущий агент остановился перед реализацией. Sourcebasefd9eea80/C4 CI accepted. Inline personally; no subagents; authorized continuation/publish, no agent SSH or live bot sends.

Goal: install and validate Telegram receiver on server1 through existing loopback Xray, without Academy migration or public listener. `/start`, `/academy`, `/help` state that Mini App is not connected yet. Notifications unit prepared but disabled until separate Academy bridge activation/links.

Architecture: Python3.12 stdlib zipapps, versioned root-owned `/opt/ustar-connect/releases/<sourceSHA>`, atomic current/previous pointers. Root installer validates entire bounded archive/hash/manifest before mutations, rejects unmanaged paths/units. Separate static service users, systemd LoadCredential, StateDirectory0700, loopback-only IP policy. No dependencies downloaded by installer. Token prompted once through tty, never argv/env/log/archive. Config remains local. start/probe explicit; install does not contact Telegram or start polling. Probe uses getMe/getWebhookInfo only; refuses active webhook and never deletes it. Runtime never logs secrets/provider response text.

Limits: no Mini App/BFF service/public HTTPS until separate approved deployment. No Moodle code/schema/DB/config changes, role grants, existing console credentials, Xray config/network/router changes. Exactly one business consumer. Token renewed already; no token request in chat. Existing source remains candidate552/99; immutable production baseline526/93 retained.

## Task1 — honest disabled response and read-only probe
- [ ] Tests first: disabled notice explicitly names unavailable Mini App; probe validates bot identity/webhook without journal/getUpdates/send or bridge. Failure codes sanitized, unknown outcomes unchanged.
- [ ] Implement/re-run receiver tests and r16.

## Task2 — package, install and service lifecycle
- [ ] Tests first for malicious archive/hash/mode/link/extra path rejection before host writes; configured install/offline restart/rollback state; private token/config/no secret output. Real zipapp validation from built exact source.
- [ ] Build deterministic receiver/notifications/admin zipapps and bounded tar from exact Git objects, SOURCE_COMMIT/manifest/file hashes. No third-party deps.
- [ ] Root install checks supported Linux/systemd/Python, fixed owned paths/no symlinks, no unmanaged unit overwrite; dedicated users, units, atomic release switch. On failure restore pointer/units and previous active state. Stop/rollback preserve credentials and SQLite cursor, prevent unintended duplicate sends. No auto-start initial install.
- [ ] Configure through tty with hidden token; default@ustarcorp_bot + explicit http://127.0.0.1:18081. Offline validation via hardened probe unit without --probe; optional read-only Telegram probe; start receiver only after successful probe. Notify unit disabled and config absent until separate activation.
- [ ] Native Ubuntu fixture executes actual hardened unit/user/LoadCredential/SQLite and install/reinstall/update/rollback using synthetic transport only. No live Telegram.

## Task3 — delivery
- [ ] Full tests/source/context/diff, personal review (not independent), exact-source CI/real service fixture; inspect artifact/checksum/source contents.
- [ ] Update current context and PR106, publish verified archive + standalone installer and exact hash-pinned commands. Development vs operator commands clear. Native server1 receiver ACK still owner acceptance, no claimed installation.
