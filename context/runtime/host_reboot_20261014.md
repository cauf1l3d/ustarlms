# Native host reboot after accepted package installation

**Историческая инструкция.** Соответствующий этап принят владельцем13–14Oct; не повторять без новой причины. Текущее состояние — [сверка16Oct](../audits/AUDIT_RECONCILIATION_20261016_RU.md), следующая работа — пульт/Telegram.

**Owner14Oct04:41MSK accepted:** [post-boot receipt](host_reboot_result_20261014.json)
confirms actual kernel6.8.0-147 boot,Docker29.9.0,selected shared services healthy,
zero failed units,AppArmor enforcement,HDD mount/timer/no recovery marker and
Moodle login final200. All six IDs/restart policies match saved installation.
Four labs retain restart=no and clean exit0;do not start them just to make six
running. Native boot acceptance is CLOSED in this observed scope. One post-boot
scheduled cron status remains in the [Moodle53 asset handoff](moodle53_preparation_20261014.md).
Do not repeat installation/cold capture/resume/reboot. Commands below are the
historical handoff already executed. Full host restore and shared mail/DNS
end-to-end checks are not inferred from selected active units.

Owner04:18MSK [installation receipt](host_packages_install_result_20261014.json)
confirms d000e800/c753cf2e complete_reboot_pending,63 installed versions/ii and native
APT/dpkg checks,147 boot files/modules/GRUB checks,services resumed,container
identities/policies and critical config bytes preserved. Native unit success/exit0,
PID0;active/exited is RemainAfterExit,not a running installation. Duration4m29s.
Package installation acceptance CLOSED;do not rerun installer/cold capture/resume.
Docker29.9.0/containerd2.4.1/AppArmor enforcement passed pinned-helper gates;
this is source-guard inference from owner complete receipt,not independent agent access.
Host kernel was139 at preflight;147 file verification is not actual boot acceptance.

## Native reboot handoff

Authorize no new package/service configuration or restart-policy changes. Previously
confirmed console access and139/146 retained boot choices remain. Existing GRUB
DEFAULT0/no saved or next entry was observed13Oct;kernel147 entry and syntax passed
the installer. There is no new GRUB selector write in this handoff.

Authenticate separately and wait for the prompt,then native reboot behind the same
maintenance flock. Bounded10-minute wait prevents crossing an active backup/cron
holder;timeout aborts the reboot request. File lock does not replace native APT/dpkg
locks;the completed installer exited0/PID0 and no package work is launched here.
Use normal PID1 shutdown,without force flags or manual docker stop/policy changes:
manual stop would affect unless-stopped startup. SSH disconnect is expected once
the request is accepted;no guarantee of a fixed boot duration.

```bash
sudo -v
```

```bash
sudo -n flock -w 600 /run/ustar-backup/lock /usr/bin/systemctl reboot
```

Reconnect via the same SSH access when available;authenticate sudo separately:

```bash
sudo -v
```

Then collect only public selected runtime metadata:

```bash
bash <<'BASH'
uname -r
uptime -s
sudo -n docker info --format 'DOCKER={{.ServerVersion}} LIVE_RESTORE={{.LiveRestoreEnabled}}'
sudo -n docker ps -a --format 'NAME={{.Names}} STATE={{.State}} STATUS={{.Status}}'
findmnt --mountpoint /srv/ustar-storage -o TARGET,SOURCE,FSTYPE,UUID,OPTIONS
sudo -n systemctl --failed --no-pager
sudo -n systemctl show apparmor.service docker.service containerd.service apache2.service caddy.service mariadb.service dovecot.service postfix.service postfix@-.service named.service cron.service amavis.service memcached.service postgrey.service spamd.service ssh.socket ustar-hdd-backup.timer ustar-hdd-backup-recover.service -p Id -p ActiveState -p SubState -p Result --no-pager
sudo -n grep -F 'docker-default (enforce)' /sys/kernel/security/apparmor/profiles
curl -fsSL --max-redirs 5 --connect-timeout 5 --max-time 30 -o /dev/null -w 'MOODLE_LOGIN_FINAL_HTTP=%{http_code}\n' http://127.0.0.1:8082/login/index.php
sudo -n python3 -I -B /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py --status
BASH
```

## Required observed outcome

Expected running kernel6.8.0-147-generic,working production web/PG containers and
Docker29.9.0,critical shared web/mail/DNS/SSH/cron active,AppArmor Docker enforcement,
login200,HDD exact ext4 UUID359a2bae-4e79-461a-ab72-1597f605d801 at the exact mountpoint,
backup timer active/enabled and recovery marker absent. One-shot postfix and recovery
units may be active/exited or inactive/dead with success;do not call that a daemon
failure. Current boot failed units require inspection. Retained lab startup depends
on original saved policies;observe their state before claiming all six autostart.
If an expected container/service is absent,restore only the saved same identity and
original policy after inspection;do not create new containers or repeat package install.

No direct agent production connection exists;owner actual reboot/selected acceptance
is recorded above. Package
installer's old reboot_executed=false/status remains the historical installation
receipt,it is not live boot evidence. New kernel/uptime/runtime observation records
boot acceptance separately. If SSH does not return,use already available console
to inspect boot;select retained139 if147 failed rather than repeat forced reboot.
Full host boot restore/external recovery remains unverified;no blind root tar restore.
After actual boot/shared/HDD acceptance,observe scheduled cron health and continue
the agreed latest Moodle major/PG17+ rehearsal;old518/browser acceptance stays closed.

Primary command references:
[systemctl reboot](https://manpages.ubuntu.com/manpages/noble/man1/systemctl.1.html),
[Docker restart policies](https://docs.docker.com/engine/containers/start-containers-automatically/).
All operator shell blocks passed bash-n;syntax is not server boot acceptance.
