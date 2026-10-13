# Shared-host cold SSD file recovery before package installation

Owner [15:04MSK packet](host_packages_result_20261013.json) completed APT update and
simulation:38 upgrades,25 new packages,no removals/held packages. New kernel147,
Docker29.9.0/containerd2.4.1 and AppArmor/netplan/open-iscsi are in scope. Running
kernel139 and installed146 remain fallback candidates. SSD free62,750,203,904B,
HDD475,042,938,880B. Six USTAR/retained-lab containers run;live restore=false.
MariaDB root/no-password access failed1045. No engine/datadir proof was obtained;
no password change or disclosure is needed for this file-level cold capture.
ISPConfig missing-or-link remains unresolved;absence does not authorize removal.
Prior AppArmor/Moodle518/cron/HDD/browser acceptance stays closed.

## Source and scope

Reviewed helper `scripts/infra/host_cold_recovery.py`,immutable implementation
`2d1b25029ac1949117b205aaa2d52565af0ba387`,SHA256
`bcbbd378eded04b2ff0103aa2cef5d38d54f6cbfef60df67f53067bcb7a6aadd`.
[Validation](host_cold_recovery_validation_20261013.json):17 local root tests PASS;
[exact Ubuntu24.04 root workflow](https://github.com/cauf1l3d/ustarlms/actions/runs/38398856106)
job115300978352,17 tests/no skips PASS,real GNU tar/sparse/hardlink/xattr restoration
and separate-process POSIX lock exercised. Docker/systemctl are models,not a
server rehearsal. Context CI succeeded;source PHP8.2/8.3 succeeded. Unchanged
main frontend audit failed;build/HTTP/DB/rollback/full gate skipped. No full PR green.

Capture archives the SSD root filesystem,including existing OS binaries,package
state,boot files,configuration,USTAR/current code/data/PostgreSQL,all six containers'
in-root bind/volume data and cold Docker/containerd storage. It keeps the exact
original runtime state/policies in private receipts. It excludes virtual filesystems,
HDD itself,logs,temporary directories,lost+found and active swap files;Unix sockets
have no persistent payload and are omitted. This is a file recovery archive with
selected applications stopped,not an atomic image of every running OS process or
a tested bare-metal boot restore. Additional persistent filesystems refuse;foreign
service mount/symlink targets refuse. Existing Snap read-only mounts remain mounted.

Root0700 HDD directory and root0600 unencrypted `root.tar`;all raw configurations,
keys/data/manifests/package inventory/command errors stay private. Do not upload
these payloads or logs. Public output contains phase/byte counts/final checks only.
A matching source/copy SHA is not an external independent backup or boot recovery test.
No archive deletion,package operation,OS release upgrade,reboot,Moodle CLI/PHP
bootstrap,DB password operation or application SQL runs.

Before pause:fixed root/HDD UUID,ext4/rw/distinct-device/private ancestry/space,
exact six-container inventory,no Swarm/live restore,required active services and
canonical in-root service roots. Holds a HDD fd as cwd so lazy unmount cannot write
into SSD fallback;rechecks disk around capture/readback. Acquires existing USTAR
backup/cron flock EX and actual APT/dpkg POSIX record locks before any stop. Busy
locks refuse without stopping services. Marker remains on SSD independently of HDD.

Freezes original active timers and matching sockets,then cron/shared web/mail/DNS/
DB services,web containers before PG,temporarily disables exact restart policies,
requires graceful exit codes/no OOM and dead host service processes,stops Docker
then its socket then containerd. The socket order matters because Docker requires it.
No global AppArmor unload or Snap/LXD activation. Originally inactive services are
not explicitly started. Manifest hashes/metadata for protected roots and symlink
targets are stable before/after copy;tar failures,source changes or budget errors
never pass. GNU sparse/ACL/xattr/numeric-owner support preserves file recovery data.

Finally attempts all original services even after one start failure:MariaDB and
containerd/Docker,PG readiness before web,exact container IDs/images/mounts/policies,
shared services/sockets/timers and login200. SSD marker write failure cannot skip
service recovery. Restores active/unit-file states;no persistent unit enable/mask
change. Services resume before the sequential HDD readback and protected member
hash checks. Package inventory must match pre-capture. A resume error leaves
`resume_required` and bounded code;no automatic file/DB restore. Catch-up of restored
timers follows their existing configuration;no blanket claim of suppressing every
external job. The systemd task survives SSH loss;live failures remain operator evidence.

## Operator handoff

Run separate physical commands,wait for each terminal prompt. The start command
explicitly acknowledges web/mail/DNS/container downtime while SSD files copy.
Total15-30min is an estimate for about50GB,not measured RTO;service pause ends after
copy and before readback. Source hashing/metadata scans may extend that pause.
Installation/reboot follows an accepted capture receipt,not these commands.

```bash
sudo -v
```

```bash
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/2d1b25029ac1949117b205aaa2d52565af0ba387/scripts/infra/host_cold_recovery.py -o /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
printf '%s  %s\n' bcbbd378eded04b2ff0103aa2cef5d38d54f6cbfef60df67f53067bcb7a6aadd /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict - && sudo -n install -m 600 -o root -g root /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
sudo -n systemd-run --unit=ustar-host-recovery-20261013 --service-type=exec --property=RemainAfterExit=yes --property=RuntimeMaxSec=2h --property=TimeoutStopSec=10min --property=UMask=0077 /bin/bash -c 'set -euo pipefail; printf "%s  %s\n" bcbbd378eded04b2ff0103aa2cef5d38d54f6cbfef60df67f53067bcb7a6aadd /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict -; exec /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --capture --acknowledge-shared-outage'
```

Status may be repeated safely;it does not perform capture or restart. Wait for
`phase=complete` and `archive_readback_sha256_verified=true` before installation.

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --status
```

```bash
sudo -n systemctl show ustar-host-recovery-20261013.service -p ActiveState -p SubState -p Result -p ExecMainStatus --no-pager
```

Only if task has stopped and receipt says resume required,run existing helper
`--resume-services` in a verified root shell. It uses the SSD marker even without
HDD access,starts original runtime/policies and checks readiness. It does not
restore archive files,DB,old package binaries or roll back new business writes.
Do not run another capture over an unfinished marker. Private command log and
specific phase remain local;publish only reviewed metadata from status.

## Recovery and next concrete operation

Keep `root.tar`,copy SHA,private original unit/container policy snapshot and source
helper together. Restore an OS file archive only with services offline and console/
rescue access:verify SHA,retain failing/current trees separately,restore to a clean
root with GNU tar numeric ownership/ACL/xattrs/sparse support,correct virtual fs/
swap handling and restore exact original restart policies from receipt. Existing
console access was confirmed06Oct;rescue medium/actual boot restore remain untested.
Do not merge raw MariaDB/PG trees into a running server or claim a file overlay is
a consistent rollback. No automatic whole-root rollback is authorized by this helper.

After capture PASS:retain kernels139/146,install exact reviewed63 package/version
set with no removals and preserved local AppArmor/network configuration,verify
DPKG/kernel147/initramfs/GRUB,and reboot in the owner shared maintenance window.
Accept the resulting kernel,required services,HDD mount,all original container
images/mounts/policies and one cron/HDD schedule. This is post-OS-change acceptance,
not repeating the accepted518/stage/role matrix. Latest stable Moodle53/PG17+/addon
rehearsal follows OS;HTTPS remains selected. Package installation/reboot/server
capture are pending until owner execution evidence is returned.

Primary references:[GNU tar attributes](https://www.gnu.org/software/tar/manual/html_node/Attributes.html),
[one filesystem](https://www.gnu.org/software/tar/manual/html_node/One.html),
[Docker live restore](https://docs.docker.com/engine/daemon/live-restore/),
[MariaDB physical backup scope](https://mariadb.com/docs/server/server-usage/backup-and-restore/backup-and-restore-overview).
