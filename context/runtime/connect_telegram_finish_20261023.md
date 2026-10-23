# Telegram: завершение уведомлений и личного подтверждения, 23Oct2026

Status: **owner deployed application2300/ACK/presentation and accepted new Telegram ACK/time and own Academy history**.

## Owner deployment23Oct

[Owner terminal receipt](connect_telegram_finish_owner_20261023.json): exact file SHA OK,
baseline_ready2200, all upgrade phases through app_ready/local2300/theme0202,
then runtime_active with ack_enabled=true and presentation_enabled=true.
App generation94d379904f7f4f7f8795b43e68421d89;
runtime54bb0b7e4ba746928a639279ea729415. Both services active/enabled; database available.
The owner subsequently confirmed a new task notification, persistent «Принято» with
time after clicking, and correct own Academy delivery history. This closes installation
and that visual acceptance. It does not supply a scheduled cron/restore receipt or
a repeated-click timestamp measurement. Do not repeat the accepted pilot, relink the
employee or rerun deployment. The earlier two canonical ACKs remain historical2200 evidence.

This completes the installation of the notification/ACK presentation slice, not the
whole Telegram product. The next functional stage is CON03 Mini App with external
HTTPS, Academy auth and own task/checklist actions, as scoped in the existing RFC.
Group live acceptance/reserve remain deferred. Commands below are retained as the
installation/recovery runbook; the reported2300 installation has already completed.

Context39978015123 and Review39978015139 SUCCESS (one source job retry).

## Границы и результат

Source application/worker `6c56a5b6ab830b97b706a5d7028b49cfae31e7a4`, controller
`3d1938ae0f2997e79ebbd3e4c3c1c6aa9cd7c134`; local_ustar2300/theme0202,
557files/100tables. Tool/application/worker component trees are checked equal by
the builder. Production is owner-reported6c56/local2300 with ACK and presentation active;
the prior534/local2200 deployment is retained in its separate historical receipt.
The accepted one-person fence, current opt-in/preferences, notify_since/ack_since,
link/ACK keys, receiver cursor and uncertain events remain unchanged. No fallback,
new routing store, digest, Bitrix or automatic group expansion.

Telegram headings distinguish all20bounded event types, including assigned/reminder.
After a personal ACK, the original message keeps a fixed Academy link and a visible
`✓ Принято · DD.MM.YYYY HH:MM UTC` row with the canonical first time. Repeated
callbacks do not replace that first time. A failed UI edit never erases a saved ACK.
Native own history shows event, transport outcome and personal ACK/time separately;
preferences retain the existing v1 format. ACK does not complete a task or suppress
future business reminders. Source v1/v2 permits remain compatible; v3 is explicit,
scoped, default-off. No arbitrary task title/body/URL crosses the worker bridge.

## Проверки

Final machine receipt: [connect_telegram_finish_ci_20261023.json](connect_telegram_finish_ci_20261023.json).
Both PHP runners:1077r16/153environment skips; strictroot11/11 zero skips.
Actual Moodle158tests/830assertions, v1/v2/v3 wire and20label parity,3callback/4ACK races.
Actual2200→2300/repeat/paired restore2200: all4schema snapshots identical,100tables,
history/preferences/ACK/provider receipt retained. Source archive557files equals exact Git.
Local successor20focused tests passed;53legacy tests passed with1local GID mapping skip.
Whole local1072 run had55skips and7errors:6known environment errors (missing38d81 Git
objects in cloned fixtures and missing MCP dependency), plus one corrected mismatch
from editing a fixture while that long process held an older definition. Stable CI
is authoritative; no whole local PASS claim. No independent reviewer; owner required
self execution/0subagents. No agent SSH, provider sends or main merge.

Actual UID33/systemd/LoadCredential/privateTLS, safe v2 handback and actual service
exit77 recovery passed. Actual Apache/OpenSSL/PHP owner/renewal/extra AutoReport vhost
passed separately. Native Moodle/PostgreSQL lifecycle is a separate gate; this is
not a combined production drill or full DR. FullRC/rollback jobs skipped, not passed.
A Chromium captureScreenshot protocol failure in PHP8.3 was retried once without
changing code/assertions; retry and final source/native gates passed; details are retained in the receipt.

## Поставка владельцу

One ordinary file: `ustar-telegram-update-3d1938ae.py`,31153214bytes,
SHA256 `6f7729459e1ef25271fd86324e441c823a6d39763c7fec84baf7cf640a41eb31`.
The downloaded CI bytes equal the independent exact-Git build. Seven predecessor worker
files also equal the accepted4394 CI receipt; the new --check verifies their live bytes.
Keep existing4394 and its journal as history. All commands below use the new file.

First copy the downloaded file to `/home/aduk/`, then verify and check:

```bash
sudo -v
sudo -n install -o root -g root -m 600 \
  /home/aduk/ustar-telegram-update-3d1938ae.py \
  /root/ustar-telegram-update-3d1938ae.py &&
printf '%s  %s\n' \
  6f7729459e1ef25271fd86324e441c823a6d39763c7fec84baf7cf640a41eb31 \
  /root/ustar-telegram-update-3d1938ae.py |
sudo -n sha256sum --check --strict - &&
sudo -n /usr/bin/python3 -I -B \
  /root/ustar-telegram-update-3d1938ae.py --check
```

Expected: baseline_ready/local2200/ack_enabled=true/host_changes=false/provider_called=false.
It verifies the exact accepted4394 app/runtime journal, source534, effective cron2200,
fixed worker534 bytes, old100schema/indexes and current HTTPS ownership/topology.
It makes no provider call or configuration change.

After a successful check, in the chosen maintenance window:

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-telegram-update-3d1938ae.py --upgrade --acknowledge-outage &&
sudo /usr/bin/python3 -I -B \
  /root/ustar-telegram-update-3d1938ae.py --enable-presentation &&
sudo /usr/bin/python3 -I -B \
  /root/ustar-telegram-update-3d1938ae.py --status
```

Upgrade: fresh HDD receipt, stopped workers, paired cold copy, source2300, two official
CLI upgrades, matching cron2300 and durable public-write boundary. Wait for app_ready;
a cold copy can take several minutes. Then enable-presentation publishes fixed worker6c56,
adds only a separate notification.lease/begin_v3/finish key and presentation flag,
and restores the original service enabled/active states. ACK-only key/cutoff remains
valid for outstanding buttons. Expected enable output: runtime_active,ack_enabled=true,
presentation_enabled=true. Status: app_ready/runtime_active/local2300 and original services.

## Восстановление

The new private journal is `/var/lib/ustar-connect-telegram-update`; old CON02 journals
are read only. No lock removal or destructive ad-hoc repair is part of the procedure.

`--recover` before the public boundary restores the matched2200 pair; a new upgrade
then requires another explicit --upgrade/outage invocation. After the public boundary,
recovery completes2300 and preserves newer public writes. An interrupted runtime enable
can be resumed with the same --enable-presentation generation/key. --recover for a runtime
handoff returns to the saved v2 profile; no cursor or business DB rollback.

To return to v2 presentation while keeping app2300 and ACK enabled:

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-telegram-update-3d1938ae.py --disable-presentation
```

A late application pair restore additionally requires disabled presentation and BOTH
`--restore --acknowledge-outage --discard-writes-since-snapshot`; it can discard newer
business records and is never an automatic runtime fallback. Keep the saved pair.

## Узкая owner-проверка после установки

Use the already linked ordinary employee/current fence, without relinking or repeating
the accepted pilot. Inspect one new notification: correct assigned/reminder heading,
then click Принял and verify the persistent first timestamp plus the same own-history
ACK in Academy. The business task state remains independent. Existing messages are not
mass-edited. Live group acceptance remains deferred until full launch, not passed.
