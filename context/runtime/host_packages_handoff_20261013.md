**14Oct03:06MSK:** [Cold archive r6 accepted](host_cold_recovery_result_20261013.json). Prior metadata pass is complete;[native download-only next](host_packages_download_20261014.md). Package installation/reboot pending.

[Owner15:04MSK results](host_packages_result_20261013.json) received;the planning commands below completed. [Next cold recovery capture](host_cold_recovery_20261013.md). Do not repeat this metadata pass by default.

# Host package/boot/shared recovery planning after AppArmor PASS

[Owner14:24MSK AppArmor result](host_apparmor_result_20261013.json):exact installed
570986e4 source pinned to3a3ef0f3,complete/PASS,corrected profile93e1813e,
original a308e931 retained with metadata,AppArmor start/active/success,prior kernel
labels and Docker enforcement/container identities/images/restart counts/selected
Snap units preserved. Snap-confine parent and namespace helper loaded. Targeted
repair CLOSED;do not rerun it or the accepted Moodle patch without a new error.
Full Snap/LXD runtime and compiled rule-byte equivalence remain separate scopes.
No host package installation or reboot has happened.

The prior APT simulation used12Oct10:47UTC indexes:32 upgrades/18 new firmware/
no removals. It is not a current executable update plan. Next authorized step
refreshes the catalog and computes a fresh dependency simulation,then reads boot
and selected shared data-path metadata. No new executable helper is installed.
Source/profile/Moodle/DB business data and service configuration are unchanged by
the provided planning commands. Catalog refresh writes APT metadata and executes
existing configured APT update hooks;there is no blanket claim that it is a
filesystem-read-only operation. It runs no dpkg install/remove or reboot command.

## Operator commands

Execute each physical command separately;wait for sudo prompt to return first.

```bash
sudo -v
```

The catalog operation must succeed before simulation. Error-on-any and set-e prevent
a failed source update from being mistaken for a fully refreshed plan. Preserve
repository validation;do not add insecure flags,release upgrades or -y installation.

```bash
sudo -n bash -c 'set -e; timeout 180s env LC_ALL=C apt-get --error-on=any -o Acquire::Retries=1 -o Acquire::http::Timeout=20 -o Acquire::https::Timeout=20 update; timeout 60s env LC_ALL=C apt-get --simulate -o Debug::NoLocking=1 -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache= dist-upgrade'
```

Read installed kernel states,current running kernel,root/boot/HDD free bytes,DPKG
audit,actual boot symlinks,GRUB selection keys/menu IDs and only kernel image paths.
Kernel arguments,GRUB passwords and full generated config are excluded. No kernel removal,GRUB
write or initramfs regeneration. Selected logical directory sizes are live
estimates,capped45s and restricted to each filesystem;missing/link/incomplete
paths are reported instead of guessed. These conventional candidate directories
are not proof of all configured service data roots. Per-file path/error text is
suppressed so mailbox filenames/personnel do not enter the report. No file content
or full GRUB command line/environment is emitted.

```bash
sudo -n timeout 90s bash -c 'set -u; printf "MEASURED_AT_UTC="; date -u +%Y-%m-%dT%H:%M:%SZ; printf "RUNNING_KERNEL="; uname -r; df -B1 / /boot /srv/ustar-storage; printf "DPKG_AUDIT\n"; dpkg --audit; dpkg-query -W -f="\${binary:Package}\t\${db:Status-Abbrev}\t\${Version}\n" "linux-image-*-generic" linux-generic; printf "BOOT_LINKS\n"; for ustar_link in /vmlinuz /vmlinuz.old /initrd.img /initrd.img.old /boot/vmlinuz /boot/vmlinuz.old /boot/initrd.img /boot/initrd.img.old; do if [ -L "$ustar_link" ]; then printf "%s -> " "$ustar_link"; readlink -e "$ustar_link" || true; fi; done; printf "GRUB_SELECTION\n"; if [ -r /etc/default/grub ]; then sed -n "/^GRUB_DEFAULT=/p; /^GRUB_SAVEDEFAULT=/p" /etc/default/grub; fi; if [ -r /boot/grub/grub.cfg ]; then sed -n "/^[[:space:]]*menuentry /p; /^[[:space:]]*submenu /p; s/^[[:space:]]*linux[[:space:]]\+\([^[:space:]]\+\).*/KERNEL_IMAGE=\1/p" /boot/grub/grub.cfg; fi; if command -v grub-editenv >/dev/null 2>&1; then grub-editenv /boot/grub/grubenv list 2>/dev/null | sed -n "/^saved_entry=/p; /^next_entry=/p; /^recordfail=/p"; fi; printf "RECOVERY_PATH_LOGICAL_BYTES\n"; ustar_paths=(); for ustar_path in /etc /usr/local/ispconfig /var/lib/mysql /var/vmail /var/spool/postfix /var/lib/bind /var/www /var/snap/lxd/common/lxd; do if [ -d "$ustar_path" ] && [ ! -L "$ustar_path" ]; then ustar_paths+=("$ustar_path"); else printf "PATH_MISSING_OR_LINK=%s\n" "$ustar_path"; fi; done; if [ "${#ustar_paths[@]}" -gt 0 ]; then timeout 45s du -sx --apparent-size -B1 -- "${ustar_paths[@]}" 2>/dev/null || printf "RECOVERY_SIZE_SCAN_INCOMPLETE\n"; fi'
```

MariaDB uses local socket/no defaults,no password flag and batch mode. It sets
only session timeouts (5s),enters READ ONLY,and selects version/datadir/socket plus
aggregate information_schema engine/table counts/estimated bytes. No named tables,
account/employee records,user list,FLUSH/LOCK/DDL,dump,repair or Moodle bootstrap.
Outer15s bounds connect/query work. Client absence/auth failure is metadata failure,
not a reason to install packages or disclose credentials. Dovecot reads only two
mail storage settings;there is no account/userdb lookup or full config dump.
Global templates can be empty/overridden;they do not prove all user mail locations.
Docker reads only server version/live-restore and container names/statuses for
the daemon/reboot effects,not full inspect/config. Retained LXD data root is a
selected size candidate,not an activated LXD client. Only selected storage-setting
values are emitted,so this is not a claim of zero
configuration access. Any raw credential/personnel text unexpectedly present in
owner output must stay private;publish only reviewed/sanitized receipt fields.

```bash
sudo -n timeout 50s bash -c 'printf "MARIADB_METADATA_ONLY\n"; timeout 15s mariadb --no-defaults --protocol=socket --connect-timeout=5 --batch --skip-column-names -e "SET SESSION max_statement_time=5; SET SESSION lock_wait_timeout=5; START TRANSACTION READ ONLY; SELECT @@version, @@datadir, @@socket; SELECT ENGINE, COUNT(*), COALESCE(SUM(COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0)),0) FROM information_schema.TABLES WHERE ENGINE IS NOT NULL GROUP BY ENGINE; COMMIT;" || printf "MARIADB_METADATA_READ_FAILED\n"; printf "DOVECOT_MAIL_LOCATION\n"; timeout 5s doveconf -h mail_location || printf "MAIL_LOCATION_READ_FAILED\n"; printf "DOVECOT_MAIL_HOME\n"; timeout 5s doveconf -h mail_home || printf "MAIL_HOME_READ_FAILED\n"; printf "DOCKER_DAEMON_REBOOT_METADATA\n"; timeout 10s docker info --format "VERSION={{.ServerVersion}} LIVE_RESTORE={{.LiveRestoreEnabled}}" || printf "DOCKER_INFO_READ_FAILED\n"; timeout 10s docker ps -a --format "NAME={{.Names}} STATUS={{.Status}}" || printf "DOCKER_INVENTORY_READ_FAILED\n"'
```

All command lines passed bash-n locally. Actual host catalog/boot/path/SQL result
is pending;local syntax is not server APT/SQL/boot acceptance. No reversible low-
impact command-mirroring tests or full Moodle/CI/browser cycle is added.

## Concrete result to prepare from output

Fix the exact package/version set and retained kernel fallback from current output.
No dist-upgrade removal/new service activation is accepted merely because an old
simulation had zero removals. Prepare verified private recovery for affected
package/service configuration,actual MariaDB data/engine needs,mail/spool,DNS/
ISPConfig and USTAR images/code/config/data. USTAR archive/cold PG pair is not a
whole shared-host recovery proof. Plan a brief coherent capture for nontransactional
or mutable data;do not pass a live raw MariaDB-directory copy as a cold baseline.
Account for Docker/containerd,netplan/open-iscsi,maintainer scripts/needrestart and
full-host reboot. Reuse previously confirmed console access and retain current
working kernel;do not ask again for the initial console/maintenance authorization.
The exact service window and restore steps are made concrete before the disruptive
package/reboot operation. Subsequent post-change checks are reboot acceptance,not
repeating already accepted patch/stage/role tests. Latest Moodle5.3/PG17+/addon
major rehearsal follows OS as confirmed by owner;HTTPS remains selected.

Primary sources checked13Oct:
- [Ubuntu apt-get](https://manpages.ubuntu.com/manpages/noble/man8/apt-get.8.html):
  update,simulation and error-on-any semantics.
- [MariaDB read-only transaction](https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction),
  [statement limits](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/query-limits-and-timeouts),
  [information_schema tables](https://mariadb.com/docs/server/reference/system-tables/information-schema/information-schema-tables/information-schema-tables-table):
  aggregate storage estimates are not exact backup sizes.
- [Ubuntu Noble doveconf](https://manpages.ubuntu.com/manpages/noble/man1/doveconf.1.html):
  selected setting output;full daemon config is not needed here.
