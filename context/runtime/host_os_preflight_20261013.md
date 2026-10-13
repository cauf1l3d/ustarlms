# Ubuntu host maintenance: initial read-only handoff

Moodle patch scope CLOSED by [production upgrade](production_patch_upgrade_result_20261013.json),
[cron/HDD](production_patch_post_switch_status_result_20261013.json) and
[owner browser acceptance](production_patch_browser_result_20261013.json).
SCORM opens and different-account flows work. No repeat stage/upgrade/status/QA
cycle without a new error. Full addon/write coverage and production restoration
are separate,not inferred from that brief statement.

Next authorized INF-10-OS step reads host release/kernel,reboot flag,selected package
versions/status,APT update stamp,shared-service states,failed unit names and exact
production image/AppArmor labels. It then SIMULATES a dependency-aware package
upgrade against existing indexes. Missing optional packages/services are metadata,
not a reason to install them. Current indexes can be stale;this is an initial plan,
not authority to apply it. Private service configuration,APT source lists,journals and process environment
are excluded from this output;os-release is system-identification metadata.
No catalog refresh,downloads,installation/removal,profile changes or restart occurs.
APT binary cache output paths are disabled;simulation performs no dpkg operations.
This live observation is not an atomic package/service snapshot.

Execute each single physical command line separately after sudo prompt returns:

```bash
sudo -v
```

```bash
sudo -n bash -c 'cat /etc/os-release; uname -r; if [ -f /run/reboot-required ]; then printf "REBOOT_REQUIRED=yes\n"; cat /run/reboot-required.pkgs 2>/dev/null; else printf "REBOOT_REQUIRED=no\n"; fi; dpkg-query -W -f="\${binary:Package}\t\${db:Status-Abbrev}\t\${Version}\n" apparmor apparmor-utils snapd needrestart linux-generic docker-ce docker.io containerd.io containerd; if [ -f /var/lib/apt/periodic/update-success-stamp ]; then stat -c "APT_LAST_UPDATE=%y" /var/lib/apt/periodic/update-success-stamp; else printf "APT_LAST_UPDATE_STAMP=absent\n"; fi; systemctl show apache2 docker containerd mariadb postfix dovecot named amavis apparmor cron ssh apt-daily.timer apt-daily-upgrade.timer -p Id -p LoadState -p ActiveState -p SubState -p UnitFileState -p Result --no-pager; systemctl --failed --no-pager --plain; docker inspect --format "NAME={{.Name}} RUNNING={{.State.Running}} IMAGE={{.Image}} AA={{.AppArmorProfile}}" ustar_moodle ustar_postgres'
```

```bash
sudo -n timeout 60s env LC_ALL=C apt-get --simulate -o Debug::NoLocking=1 -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache= dist-upgrade
```

Only shell syntax and dpkg placeholder quoting were checked locally;the source and
application trees remain unchanged. Host-specific package/service output pending.
Do not run apt upgrade/dist-upgrade without --simulate or invoke release upgrade,
AppArmor reload/disable,snap purge or reboot from this initial handoff.

Review upcoming Inst/Remv/Conf lines,held/dependency failures,kernel,AppArmor/Snap
consumers,Docker/containerd and required Apache/mail/DNS/ISPConfig/MariaDB services.
Next produce a targeted update/recovery/restart plan from actual observations.
Existing sysadmin console access was confirmed previously;no repeat question now.
USTAR encrypted data recovery alone is not full shared-host recovery evidence.
Any service/reboot window is addressed with the concrete reviewed plan,not guessed.

Primary references checked13Oct:
- [Ubuntu APT simulation manual](https://manpages.ubuntu.com/manpages/noble/man8/apt-get.8.html)
  (English index and Jammy manual cover --simulate;some direct fetches timed out).
- [Ubuntu service restarts](https://ubuntu.com/server/docs/how-to/software/automatic-updates/):
  Ubuntu24.04 needrestart can restart affected services during updates.
- [Ubuntu Foundations explanation](https://discourse.ubuntu.com/t/needrestart-changes-in-ubuntu-24-04-service-restarts/44671):
  list-only needrestart is not a blanket suppression of package maintainer scripts.

No current package candidate,OS patch or reboot result is claimed before operator
output. Broad SSH/MFA/firewall redesign remains deferred per owner priority.
