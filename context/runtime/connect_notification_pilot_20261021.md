# One-employee notification pilot — owner runbook

Frozen code source: `a86e208029b6104150e85d0e90384f84da214c22`.
File: `ustar-notify-pilot-a86e2080.pyz`, 77422 bytes.
SHA256: `d06b88dc081028ce860774fbbe290dadb1c7a55bef82bd1ae135de8a5bd1ad33`.
CI artifact12333470816 from Review39573641220; independently rebuilt exact Git bytes match.
CI acceptance is recorded separately in `connect_notification_pilot_ci_20261021.json`.
Server1 activation and actual Telegram delivery remain owner acceptance, not a CI claim.

## Scope

Preserves the accepted link bridge27117, current bot token, receiver SQLite cursor,
HTTPS origin/renewal, plugin version2000 and DB schema. Replaces one exactly pinned
PHP policy file, retaining Unix owners/mode/xattrs; adds an independent scoped
notification key and one-employee recipient fence. Applies a new cutoff; no old
queue backfill. Notifier never polls getUpdates. Employee preferences/current
employment/link revision/rights/quiet hours/cooldown are checked canonically.

The operator chooses the **numeric Moodle user ID** of the already linked ordinary
employee. This is neither Telegram ID nor login. Open that employee's native user
profile and read `id=N` in `/user/profile.php?id=N`. The tool verifies current
employee status and existing private link using a read-only DB connection; it does
not create users, links, grants or opt-in preferences. Siteadmin/service/test-type
accounts are refused; use the ordinary employee previously linked by the owner.

## Activation

Copy the PYZ supplied in chat to `/home/aduk/` using WinSCP. In SSH run these as
individual commands. Installation does not repeat the Academy upgrade, cold backup,
parent ownership preparation, HTTPS cutover or NOVAVPS subscription import.

```bash
sudo -v
sudo install -o root -g root -m 600 -- \
  /home/aduk/ustar-notify-pilot-a86e2080.pyz \
  /root/ustar-notify-pilot-a86e2080.pyz &&
printf '%s  %s\n' \
  d06b88dc081028ce860774fbbe290dadb1c7a55bef82bd1ae135de8a5bd1ad33 \
  /root/ustar-notify-pilot-a86e2080.pyz | \
  sudo sha256sum --check --strict - &&
sudo /usr/bin/python3 -I -B \
  /root/ustar-notify-pilot-a86e2080.pyz --activate
```

When asked `Числовой ID тестового сотрудника в Академии:`, enter the number N from
the profile URL. No bot token or subscription URL is requested. ID entry occurs
before shared locks. Service stop/probe/start may take time; retain the session.
Expected final JSON: `phase=notification_pilot_active`, `pilot_userids=[N]`,
`sender_active=true`, `historical_replay=false`, `linking_preserved=true`.
Send the final JSON to the agent. It contains no provider subject or credentials.

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-notify-pilot-a86e2080.pyz --status
```

`/start` now gives neutral guidance; it does not query or revoke the current link.
Native Academy Messenger status is the source of truth.

## Native delivery acceptance

1. As that employee, open **Личный кабинет → Мессенджеры** in Academy. Confirm
   `Связь подтверждена`. Under **Уведомления в Telegram**, personally enable
   **Получать уведомления в Telegram** and category **Задачи**. For the test choose
   all severities, interval1 minute and a current time outside quiet hours (equal
   quiet start/end disables quiet hours). Save. No administrator preference write.
2. Through an existing authorized Academy account assign a **new** task to this
   employee, after activation and opt-in. Do not reuse an old event or impersonate
   the employee. Wait within the configured interval. No cron reset is needed.
3. In Telegram verify the new task notification and **Открыть в Академии** button.
   It opens the fixed native tasks workspace, not a task-specific URL. Use the
   employee's own Academy session; current task visibility/rights must still apply.
   Native hostname/CA/VPN requirements are the same as the already accepted Academy.
4. In Messenger page check **Доставка моих уведомлений**. Provider accepted means
   API acceptance, not human read/ACK or task completion. An uncertain result is
   not automatically sent again. Do not recreate the same event to force retry.
5. Revoke the employee's Telegram link in Academy, then create a different new task
   through the authorized UI. Confirm the task remains native and no future Telegram
   delivery is created/sent. A message already begun before revoke may arrive; use
   an event created after revoke. Report delivery status and test times, no secrets.
6. If future pilot use is desired, repeat the normal two-sided link flow personally.
   The pilot remains restricted to the original Academy ID and Telegram subject;
   linking another Telegram account requires explicit pilot deactivation/reconfigure.

Other employees remain excluded even if they opt in. Broad rollout, MiniApp,
provider login and business actions in Telegram are separate stages.

## Failure and deactivation

Normal activation errors trigger guarded before-image recovery. An interrupted
process leaves a private durable journal; retain it. Report final JSON rather than
removing locks/state or repeating old installers. Current status may report
`notification_pilot_recovery_required`; use this same frozen tool:

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-notify-pilot-a86e2080.pyz --deactivate
```

Expected `notification_pilot_inactive`: notifier stopped/disabled, prior link
receiver/source/config restored, original token and latest cursor preserved. It
never restores business DB, employee preferences or journal content. Unknown admin
file drift refuses overwrite and needs targeted review. During an active pilot,
use this tool's status/deactivate; frozen link-only activation27117 intentionally
rejects the additional notifier configuration.
