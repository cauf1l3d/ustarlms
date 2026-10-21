# Telegram egress interruption — 2026-10-21

Owner activation27117c8c reached receiver_switch, then probe returned
transport_unavailable. Installer reported recovery_state=inactive; old receiver
active/running. Earlier logs already had9transport failures before activation.
Do not repeat parent preparation/application/HTTPS upgrade or change bridge guards.

Service ustar-telegram-egress.service (name without the space), pinned Xray26.3.27,
loopback18081, credential /etc/ustar-console-egress/xray.json. Restart did not help.
Observed outbound TCP handshake succeeded but no data ACK,1884bytes queued and
retransmissions. Failing hop/filter/remote outage not distinguished.
No secrets or subscription URL obtained. Owner uses NOVAVPS subscription.

Canonical console branch codex/server-console-20261015 at
de4544d97f4f77036cfbb358ab79273361e6a6e5 contains tools/telegram_egress.py and
VPN_TELEGRAM.md: URL fetched once, not saved; one working node pinned; no automatic
subscription refresh/failover. Browser console availability is not egress proof.
Checking example.com through this Telegram-only proxy was invalid and was corrected.

Prepared scripts/infra/connect_egress_refresh.py reuses exact hash-pinned installed
importer, binary and managed unit/config shape. Hidden URL fetch; candidate-specific
transient DynamicUser services on separate loopback ports; actual verified Telegram
TLS before atomic replacement. Existing production proxy stays active during search.
Only egress service restarted after selection; immutable private before-image kept.
Handled failure/SIGINT/SIGTERM restores config and restarts prior service; SIGKILL or
host loss needs manual restore from the printed BACKUP path. No durable boot recovery
or automatic runtime failover claimed. No token access, polling, messages, Academy
changes, host routing/DNS/firewall changes or new binary download in normal installed
state. Existing provider format restrictions preserved; unsupported formats refused.

Tests-first module absent, then7local tests PASS: fixed TLS destination/status, exact
config restore, drift refusal, successful replacement/private backup, interrupt and
failed service recovery. Real subscription/systemd refresh not executed by agent.
Source syntax compilation PASS. Owner refresh and then existing bot probe pending.
After telegram_verified, resume frozen27117c8c --activate, then same-session link/unlink.
Notifications remain off; main unmerged;0subagents.
