# Первый запуск business bot без Mini App

Owner19Oct17:44MSK в shared dialogue6ad62f29: Mini App после HTTPS, token перевыпущен, подготовить комплект/службы и запуск бота. Предыдущий агент остановился перед реализацией. Sourcebasefd9eea80/C4 CI accepted. Inline personally; no subagents; authorized continuation/publish, no agent SSH or live bot sends.

Goal: install and validate Telegram receiver on server1 through existing loopback Xray, without Academy migration or public listener. `/start`, `/academy`, `/help` state that Mini App is not connected yet. Notifications unit prepared but disabled until separate Academy bridge activation/links.

Architecture: Python3.12 stdlib zipapps, versioned root-owned `/opt/ustar-connect/releases/<sourceSHA>`, atomic current/previous pointers. Root installer validates entire bounded archive/hash/manifest before mutations, rejects unmanaged paths/units. Separate static service users, systemd LoadCredential, StateDirectory0700, loopback-only IP policy. No dependencies downloaded by installer. Token prompted once through tty, never argv/env/log/archive. Config remains local. start/probe explicit; install does not contact Telegram or start polling. Probe uses getMe/getWebhookInfo only; refuses active webhook and never deletes it. Runtime never logs secrets/provider response text.

Limits: no Mini App/BFF service/public HTTPS until separate approved deployment. No Moodle code/schema/DB/config changes, role grants, existing console credentials, Xray config/network/router changes. Exactly one business consumer. Token renewed already; no token request in chat. Existing source remains candidate552/99; immutable production baseline526/93 retained.

## Task1 — honest disabled response and read-only probe
- [x] Tests first: disabled notice explicitly names unavailable Mini App; probe validates bot identity/webhook without journal/getUpdates/send or bridge. Failure codes sanitized, unknown outcomes unchanged.
- [x] Implement/re-run receiver tests and r16.

## Task2 — package, install and service lifecycle
- [x] Tests first for malicious archive/hash/mode/link/extra path rejection before host writes; configured install/offline restart/rollback state; private token/config/no secret output. Real zipapp validation from built exact source.
- [x] Build deterministic receiver/notifications/admin zipapps and bounded tar from exact Git objects, SOURCE_COMMIT/manifest/file hashes. No third-party deps.
- [x] Root install checks supported Linux/systemd/Python, fixed owned paths/no symlinks, no unmanaged unit overwrite; dedicated users, units, atomic release switch. On failure restore pointer/units and previous active state. Stop/rollback preserve credentials and SQLite cursor, prevent unintended duplicate sends. No auto-start initial install.
- [x] Configure through tty with hidden token; default@ustarcorp_bot + explicit http://127.0.0.1:18081. Offline validation via hardened probe unit without --probe; optional read-only Telegram probe; start receiver only after successful probe. Notify unit disabled and config absent until separate activation.
- [x] Native Ubuntu fixture executes actual hardened unit/user/LoadCredential/SQLite and install/reinstall/update/rollback using synthetic transport only. No live Telegram.

## Task3 — delivery
- [x] Full tests/source/context/diff, personal review (not independent), exact-source CI/real service fixture; inspect artifact/checksum/source contents.
- [x] Update current context and PR106, publish verified archive + standalone installer and exact hash-pinned commands. Development vs operator commands clear. Native server1 receiver ACK still owner acceptance, no claimed installation.

## Итог scoped acceptance

Sourceb01bd942, actual host job118145618545 PASS, Context39277324228 SUCCESS, source8.2/8.3 logs690r16/133skips+60mobile/20onboarding/24link/100actor PASS. Package12197369243 downloaded/CRC/SHA/source verified. Все действия этого плана для receiver-only комплекта выполнены. Общий PR review/native Moodle regression ждёт очереди, не объявлен green; C4 PHP/addon source не изменялся. Отдельная установка на server1 и live bot ACK остаются за владельцем; агент не имеет SSH/token и не отправлял Telegram-сообщения.

## Owner acceptance19Oct18:59МСК

Владелец подтвердил работающий receiver. Install b01, private configure helperde2e482, live telegram_verified и owner-start ACK различены в context/runtime/connect_receiver_server1_20261019.json. Два найденных CLI-дефекта (tty r+ и collected oneshot status) исправлены в source; новый runtime package не выдан как установленный. Boot acceptance не заявляется. Текущий следующий план — USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md.
