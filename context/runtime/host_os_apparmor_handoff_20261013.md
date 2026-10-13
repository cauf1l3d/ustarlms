# AppArmor: next bounded observation and host update order

[Owner OS receipt](host_os_preflight_result_20261013.json), received13Oct04:37MSK,
records Ubuntu24.04.4/running kernel6.8.0-139, pending reboot, linux-generic146,
13 unit observations and a cached-index APT simulation:32 upgrades,18 new firmware
packages,0 removals. Docker29.7.2/containerd2.3.4 candidates are29.9.0/2.4.1;
AppArmor, netplan and open-iscsi also change. Index stamp is12Oct10:47:08UTC;
the list must not be treated as a guaranteed future installation plan.
Only AppArmor is failed. Required Apache/Docker/containerd/MariaDB/mail/DNS/cron
units are active;this does not prove their application functions. Postfix active/
exited is its observed state, not a failed unit. SSH is active with disabled
UnitFileState;socket activation has not yet been inspected.

Both production containers remain running with their accepted image IDs and
docker-default profile labels. This output does not independently read kernel
enforcement. The05Oct profile evidence identifies
`/etc/apparmor.d/usr.lib.snapd.snap-confine.real` and its missing include directory
`/var/lib/snapd/apparmor/snap-confine`. Current failed unit metadata alone does
not prove that this remains the exact cause. snapd is still rc:removed package,
configuration retained. Do not infer absence of consumers from rc alone.

## Execute next

Run sudo validation separately and wait for its prompt to finish:

```bash
sudo -v
```

Then paste this one physical command line:

```bash
sudo -n timeout 60s bash -c 'set -u; ustar_profile=/etc/apparmor.d/usr.lib.snapd.snap-confine.real; if [ -f "$ustar_profile" ] && [ ! -L "$ustar_profile" ]; then stat -c "SNAP_PROFILE=%n UID=%u GID=%g MODE=%a DEV=%d INODE=%i" "$ustar_profile"; sha256sum "$ustar_profile"; ustar_rc=0; timeout 20s apparmor_parser -Q -K -j 1 "$ustar_profile" >/dev/null || ustar_rc=$?; printf "PROFILE_COMPILE_EXIT=%s\n" "$ustar_rc"; else printf "SNAP_PROFILE=absent_or_not_regular\n"; fi; if [ -d /var/lib/snapd/apparmor/snap-confine ]; then printf "SNAP_INCLUDE_DIRECTORY=present\n"; else printf "SNAP_INCLUDE_DIRECTORY=absent\n"; fi; for ustar_binary in /usr/bin/snap /usr/lib/snapd/snap-confine; do if [ -e "$ustar_binary" ] || [ -L "$ustar_binary" ]; then printf "SNAP_BINARY_PRESENT=%s\n" "$ustar_binary"; fi; done; printf "SNAP_UNITS\n"; systemctl list-units --all --no-pager --plain --no-legend "snap*"; printf "SNAP_UNIT_FILES\n"; systemctl list-unit-files --no-pager --no-legend "snap*"; printf "SQUASHFS_MOUNTS\n"; findmnt -rn -t squashfs -o TARGET || true; ustar_snap_processes=0; for ustar_exe in /proc/[0-9]*/exe; do ustar_target=$(readlink "$ustar_exe" 2>/dev/null || true); case "$ustar_target" in /snap/*|/var/lib/snapd/snap/*|/usr/lib/snapd/*|/usr/bin/snap|/usr/bin/snapctl) ustar_snap_processes=$((ustar_snap_processes + 1));; esac; done; printf "SNAP_EXECUTABLE_PROCESSES=%s\n" "$ustar_snap_processes"; printf "SELECTED_KERNEL_PROFILES\n"; if [ -r /sys/kernel/security/apparmor/profiles ]; then sed -n "/^docker-default /p; /snap-confine/p; /^snap[.]/p" /sys/kernel/security/apparmor/profiles; else printf "KERNEL_PROFILES=unavailable\n"; fi; systemctl show ssh.socket -p LoadState -p ActiveState -p SubState -p UnitFileState --no-pager'
```

The parser only compiles this one profile. Explicit `-Q` skips kernel loading;
`-K` disables cache reads and writes;one parser job and20s timeout bound the work.
No profile text, process arguments/environment or journal dump is emitted. Parser
diagnostics may name the failed policy/include;read-only metadata names only the
selected Snap/Docker profiles, Snap units/mounts and SSH socket state. /proc exe
targets are inspected privately and only the count of Snap paths is printed.
The observation is live/non-atomic;it neither proves an atomic absence of all
possible consumers nor authorizes automatic profile removal from this sample.
It performs no Moodle/PHP bootstrap, SQL, backup, package operation, profile
mutation, kernel policy load/unload, service restart or host reboot.

Local verification:exact command bash syntax checked;parser Q/K/j semantics
checked against [Ubuntu Noble manual](https://manpages.ubuntu.com/manpages/noble/man8/apparmor_parser.8.html).
Server execution and current Snap/profile facts remain pending. Local syntax
validation is not a server parser or AppArmor acceptance result.

## Concrete maintenance sequence

1. Confirm the profile hash/type/owner, current compile error and Snap consumers.
   If it is the unused residual profile, preserve its exact bytes and metadata
   outside the profile directory, then quarantine only that file. Keep
   docker-default and all required host policies. Verify AppArmor load and live
   confinement after the targeted repair. No global disable, empty include,
   Snap purge or speculative profile rewrite.
2. Before installation, record the currently installed kernel/boot fallback,
   affected unit startup contracts and package versions. Reuse confirmed sysadmin
   console access. Prepare scoped recovery for shared service configuration and
   actual MariaDB/mail/DNS/ISPConfig data paths as well as USTAR. Existing USTAR
   encrypted archives and cold pairs are not whole-host recovery proof.
3. Refresh the APT catalog and inspect one new dependency simulation, then fix
   the exact package set/version plan. Removals, held/broken dependencies or
   changed restart/boot effects require resolving that concrete difference before
   installation. No Ubuntu release upgrade, image pulls or PostgreSQL major
   migration in this host patch transaction. An apt dist-upgrade simulation
   against Noble repositories is not do-release-upgrade.
4. Apply the reviewed packages and pending reboot in one concrete shared-service
   window. Preserve container images/mounts/restart policies and the single cron
   schedule. Account for Docker/containerd, netplan/open-iscsi, package maintainer
   scripts and needrestart;list-only needrestart cannot guarantee zero restarts.
   Kernel146 is observed through package/reboot metadata;the actual next boot
   selection has not been read and is not promised.
5. After boot, compare required units, running kernel, mounts, exact container
   images/profiles and scheduled cron/HDD state;perform the short actual shared
   service and USTAR checks relevant to this host restart. This is reboot
   acceptance, not a repeated Moodle patch upgrade or full browser matrix.

No install/reboot command is issued by this handoff. The exact profile observation
is the next operator step;installation depends on its result and the concrete
package/recovery/restart plan,not on a new generic permission cycle.

## Next Moodle target

Owner13Oct04:49MSK accepted the explanation and order:complete current OS work,
then prepare the latest stable Moodle5.3 LTS with a compatible PostgreSQL major
(minimum17;production16.15). PHP8.3.33 meets the PHP version minimum. Upgrade can
start from Moodle4.4 or newer;an intermediate5.2 installation is not required.
The completed5.1.8 deployment is an accepted checkpoint,not a prerequisite claimed
by upstream. Official release/requirements verified13Oct:
[latest stable](https://download.moodle.org/releases/latest/),
[5.3 requirements](https://moodledev.io/general/releases/5.3).
PLAT-01 preparation will review all19 addon roots/57 component versions and native
USTAR/theme compatibility,then rehearse DB/core upgrade,repeat and coherent recovery
on an isolated copy before the reviewed production window. No major migration,
new image/profile or DB downgrade has been executed. HTTPS remains in the selected
work;broader unrelated hardening remains deferred. A later major-version rehearsal
does not reopen the accepted5.1.8 patch checks.
