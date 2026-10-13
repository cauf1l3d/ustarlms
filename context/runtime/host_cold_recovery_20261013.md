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

## Current14Oct02:32MSK: r5 proc link failure; services restored

Owner r5 at23:28:39..23:29:13UTC:failed_resumed/services_resumed=true.
Both initial manifest and its one retry failed with FileNotFoundError/errno2,
operation resolve_link,scope /proc,path SHA c7340aa1fdce4e73fb524ece4360d1347f9836a165fecace23cb8c680242ee7d.
No copy/install/reboot occurred. Exact server link text remains private/unread.
Old code follows p.resolve(strict=True) before applying exclusions. A real
/proc/self/fd pipe link reproduces the same failure locally,even though
p.exists() returns true. Excluded proc data should never be traversed.

The new component resolver checks excluded boundaries before lstat/readlink,
preserves raw symlink targets in the manifest/tar,and handles absolute,relative
and indirect aliases. Persistent targets still require the copied filesystem;
root targets refuse,dangling/cyclic links retain link-only behavior. No entry
skipping/file deletion or broader archive exclusion is introduced. Source
comparison and archive readback remain mandatory. Local48 tests PASS,including
actual pipe link/tar/readback/restore and persistent-target guards. Exact Ubuntu
CI/publication and new server execution pending. Previous capture/resume
command sections below are historical and must not be reused as the next step.

## Previous14Oct02:17MSK: r4 resumed; missing path unrecorded

Owner confirmed successful original runtime resume at23:05:54UTC. r4 later
reached selected_services_stopped,then FileNotFoundError before copying;
services_resumed=true at23:08:40UTC. No archive/install/reboot acceptance.
Live metadata-only scan completed without missing-file error;Docker root hit
foreign-filesystem protection. This does not identify the past cold missing
path or establish a completed full scan of Docker state.

Prepared correction: filesystem calls attach operation/path to the original
exception. Status retains failure stage,errno,operation,scope,path SHA and
stack function/line,while the full path stays only in root600 SSD JSON
`/var/lib/ustar-host-recovery-20261013/failure.private.json` (or
`manifest-retry.private.json`). No file content/configuration is emitted.
Diagnostic-write errors never replace the original failure or prevent resume.
Only the initial pre-copy manifest may restart once after a missing child;
every required root must exist,all partial entries are discarded,and all other
errors still refuse. Post-copy source equality/archive verification never
retry or skip entries. This is bounded transient-path handling and improved
evidence,not proof that the unknown owner failure is fixed.

Exact implementation 99b3e0dc40554f5769b50e338e54d748789ebf93,SHA256
962a75d0c1d0f69c92efdb37fa52d8d986e81730cb39984f09d49fedc7189e40.
Local41 root tests PASS;Ubuntu24.04 run38484018659/job115593190536 passes
41 file/control cases +2 actual isolated systemd cases,no skips;both summaries
read. Context38484018640 PASS. Whole PR is not green:frontend audit fails on
unchanged dependencies and broad PHP review is still running at this receipt.
Initial non-root diagnostic-test assumptions were corrected;root safety checks
remain intact. Published helper bytes and source tree match local.
Server execution of this revision and completed archive remain pending.

### Previous capture r5 (executed; failed and resumed)

The owner has already authorized the maintenance/capture;original services
are restored. Use separate commands. This will pause selected services for
copying and resume them before archive readback. Exact failure mechanism on
r4 is not proven;new code records it if it recurs. The one complete pre-copy
rescan does not ignore missing required paths or weaken final integrity checks.

```bash
sudo -v
```

```bash
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/99b3e0dc40554f5769b50e338e54d748789ebf93/scripts/infra/host_cold_recovery.py -o /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
printf '%s  %s\n' 962a75d0c1d0f69c92efdb37fa52d8d986e81730cb39984f09d49fedc7189e40 /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict - && sudo -n install -m 600 -o root -g root /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
sudo -n systemd-run --unit=ustar-host-recovery-20261014-r5 --service-type=exec --property=RemainAfterExit=yes --property=RuntimeMaxSec=2h --property=TimeoutStopSec=10min --property=UMask=0077 /bin/bash -c 'set -euo pipefail; printf "%s  %s\n" 962a75d0c1d0f69c92efdb37fa52d8d986e81730cb39984f09d49fedc7189e40 /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict -; exec /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --capture --acknowledge-shared-outage'
```

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --status
```

```bash
sudo -n journalctl -u ustar-host-recovery-20261014-r5.service -n 50 --no-pager -o cat | awk '/^HOST_RECOVERY_PHASE=|^HOST_RECOVERY_COPY_BYTES=|^HOST_RECOVERY_MANIFEST_RETRY=|^HOST_COLD_RECOVERY_ERROR=|^HOST_COLD_RECOVERY=/{print}'
```

Check status and filtered journal after a minute. Accept only complete plus
services_resumed=true and archive_readback_sha256_verified=true. If the new
unit fails before a new marker,its journal is authoritative;an old
failed_resumed status is not new capture success. Never rerun a failed capture
without reading its receipt. Private diagnostic paths stay on the host.
Previous command sections below are history;do not rerun old capture helpers.

## Previous owner14Oct01:09MSK: r3 resume required; restore runtime first

[Owner r3 receipt](host_cold_recovery_result_20261013.json):verified94488652,
5d69566b installed,r3 invocationd13be599,22:07:20..25UTC,phase resume_required,
failure CONTAINER_IDENTITY_DRIFT,unit failed/exit1/MainPID0. Original runtime
resumption is unverified. Source places this failure in container metadata
comparison before archive copy. Do not run capture or package installation.

Source bug:raw mount array order was treated as identity. Corrected resume helper
`2766a1a9608d58ebf6f5ff28c5f2f06b90dbc389`,SHA256
`4bf199c14fcf69a39a2283ca93d87b304e42b881e22c16ccec1c0d5c710116b2`
compares whole mount records independent of order,retaining duplicates and
source/destination/type/RW plus exact IDs/images/inventory. It does not waive
real differences. Actual r3 changed fields remain unknown until new resume
receipt. Real changes prevent container operations and persist only field names
in `resume_identity_differences`;shared services are still attempted. Recover
uses existing SSD marker and restores only original runtime/policies. No file,
DB,archive restore,copy,package operation,reboot or extra service stop occurs.

34 local root fixtures PASS;exact Ubuntu24.04 run38476723475/job115569100844
passes34 file/model +2 actual systemd tests,no skips;both summaries inspected.
Tests include reordered mounts/full original-ID resume,real mount/ID/image
refusal and persistence of failure diagnostics with untouched data. Docker
recovery is modeled;native systemd tests use isolated sleep-only units. Context
38476723506 and source PHP8.2/8.3 in38476723495 PASS;unchanged frontend audit fails,
DB/rollback/full gate skip. Previous capture commands below are historical,
not the next action. Current urgent action is the guarded resume handoff.

### Urgent original-runtime resume commands

The failed capture unit has MainPID0. Execute separate commands. This recovery
uses the existing SSD marker,shared locks and original identities/policies;
it starts original services/containers and performs no stop,capture or data restore.
Use a new systemd unit so SSH loss does not interrupt recovery.

```bash
sudo -v
```

```bash
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/2766a1a9608d58ebf6f5ff28c5f2f06b90dbc389/scripts/infra/host_cold_recovery.py -o /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
printf '%s  %s\n' 4bf199c14fcf69a39a2283ca93d87b304e42b881e22c16ccec1c0d5c710116b2 /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict - && sudo -n install -m 600 -o root -g root /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
sudo -n systemd-run --unit=ustar-host-recovery-20261014-resume-r3 --service-type=exec --property=RemainAfterExit=yes --property=RuntimeMaxSec=2h --property=TimeoutStopSec=10min --property=UMask=0077 /bin/bash -c 'set -euo pipefail; printf "%s  %s\n" 4bf199c14fcf69a39a2283ca93d87b304e42b881e22c16ccec1c0d5c710116b2 /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict -; exec /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --resume-services'
```

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --status
```

```bash
sudo -n systemctl show ustar-host-recovery-20261014-resume-r3.service -p ActiveState -p SubState -p Result -p ExecMainStatus -p MainPID --no-pager
```

Successful recovery is services_resumed=true and phase=failed_resumed,with
service Result=success/ExecMainStatus0;the failed capture stays a failed capture.
If resume_required persists,read resume_identity_differences and current unit
metadata;do not restart capture or waive real identity changes.

## Previous owner diagnostic17:26MSK and accepted source correction

[Reviewed owner metadata](host_cold_recovery_result_20261013.json) confirms
successful stop jobs and systemd UNIT_SUCCESS for amavis,Apache,caddy and cron.
The source stop order localizes the guard refusal to `cron.service`;its actual
past numeric ExecMainCode/ExecMainStatus were not recorded. The current14 units
are active/Result=success;cron is simple/KillSignal15. Those current properties
are policy/resume evidence,not a measurement of its earlier signal status.

The earlier guard required ExecMainStatus0 regardless of ExecMainCode. Corrected
`stop_service` distinguishes exited code from CLD_KILLED signal. Inactive/dead,
MainPID0 and Result=success remain mandatory. Only zero exit/no main status or
configured SIGTERM on a daemon type pass. SIGKILL,core dump,nonzero exit,timeout,
OOM,remaining main PID or wrong state refuse. Docker/containerd use this same
check after graceful container stop. File/process/mount/archive guards stay.

Each checked stop records only unit/state/result/main-exit metadata. A failure
stores the unit and selected properties in the existing SSD marker and status;
command failures store the unit too. No raw commands,environment or configuration
are exposed. Existing finally recovery persists that receipt and resumes the
original runtime. Model tests exercise failure/resume before archive creation.
Actual Ubuntu systemd tests stop unique sleep-only units:SIGTERM accepted,
SIGKILL refused even with SuccessExitStatus=SIGKILL. Both passed without skips.

Use the updated operator commands below with new r3 unit. Previous read-only
command and r2 result sections are dated history. New r3 execution,accepted
archive,package installation and reboot remain pending. No AppArmor/Moodle
acceptance replay. Primary version-matched sources:
[systemd255 service semantics](https://github.com/systemd/systemd/blob/v255/man/systemd.service.xml),
[UNIT_SUCCESS identifier](https://github.com/systemd/systemd/blob/v255/src/systemd/sd-messages.h).

## Previous owner result17:07MSK: r2 failed, services resumed

[Latest owner receipt](host_cold_recovery_result_20261013.json):verified00b15bc1
helper launched as r2/invocation34da76b91415422b85110a89ee50854f. The marker is
`failed_resumed`,14:05:16.445898..14:05:17.991866UTC,`services_resumed=true`,
`failure_code=SERVICE_UNCLEAN_STOP`,package/reboot=false;systemd exit1/failed/PID0.
The disk guard passed. Exact failure location is the shared-service stop check,
before Docker container stop,source manifest or archive copy;archive path is
planned metadata,not evidence of a created payload. Service recovery already
passed;do not call resume-services or start another capture for this receipt.
Next:read only PID1 journal fields for the stop window and current unit exit
policy metadata. Current properties after restart are not past shutdown proof.
Failed unit name/past Result/ExecMainCode/ExecMainStatus remain unknown;do not
waive the shutdown guard or claim a healthy signal termination without evidence.
The operator capture commands below remain historical handoff,not the next step
until the stop diagnosis is resolved. Source is unchanged;22 fixture passes do
not demonstrate this shared host's service shutdown semantics.

### Completed read-only operator diagnostic

Run sudo authentication separately,then this one metadata command. It reads
only the existing SSD marker,PID1 journal fields in the recorded stop window
and current service exit-policy properties. Raw MESSAGE/config/environment
content is not printed. Current properties are explicitly post-resume.

```bash
sudo -n python3 -I -B - <<'PY'
import json
import subprocess
from datetime import datetime, timedelta, timezone
from pathlib import Path

m = json.loads(Path("/var/lib/ustar-host-recovery-20261013/active.json").read_text())
names = m["services"]
start = datetime.fromisoformat(m["started_at"]) - timedelta(seconds=1)
end = datetime.fromisoformat(m["updated_at"]) + timedelta(seconds=1)
fmt = "%Y-%m-%d %H:%M:%S UTC"
raw = subprocess.check_output(
    ["journalctl", "_PID=1", "--since", start.astimezone(timezone.utc).strftime(fmt),
     "--until", end.astimezone(timezone.utc).strftime(fmt),
     "-n", "500", "--no-pager", "-o", "json"],
    text=True, timeout=15, stderr=subprocess.DEVNULL
)
fields = ("__REALTIME_TIMESTAMP", "UNIT", "OBJECT_SYSTEMD_UNIT",
          "MESSAGE_ID", "EXIT_CODE", "EXIT_STATUS", "RESULT",
          "JOB_TYPE", "JOB_RESULT")
events = []
for line in raw.splitlines():
    e = json.loads(line)
    if e.get("UNIT") in names or e.get("OBJECT_SYSTEMD_UNIT") in names:
        events.append({k: e[k] for k in fields if k in e})

props = ("Id", "ActiveState", "SubState", "Result", "ExecMainCode",
         "ExecMainStatus", "Type", "KillSignal", "SuccessExitStatus")
current = subprocess.check_output(
    ["systemctl", "show", *names, "--no-pager",
     *["--property=" + k for k in props]],
    text=True, timeout=15, stderr=subprocess.DEVNULL
)
units = [dict(line.split("=", 1) for line in block.splitlines() if "=" in line)
         for block in current.strip().split("\n\n") if block.strip()]
print(json.dumps({
    "failure_code": m.get("failure_code"),
    "service_stop_order": names,
    "stop_window_events": events,
    "current_service_metadata_after_resume": units
}, indent=2))
PY
```

## Source and scope

Reviewed helper `scripts/infra/host_cold_recovery.py`,immutable implementation
`5d69566b19a0a85a597eafc79cda24b4851c2800`,SHA256
`944886527998f428a86c835817525cd1da92511348ccf276ebf0933ce1561c54`.
[Validation](host_cold_recovery_validation_20261013.json):29 local root tests PASS;
[exact Ubuntu24.04 root workflow](https://github.com/cauf1l3d/ustarlms/actions/runs/38415153155)
job115357515006,29 model/file tests plus2 real systemd tests/no skips PASS,real GNU tar/sparse/hardlink/xattr restoration
and separate-process POSIX lock exercised. Docker/shared production-service control and mount inventories are modeled;two
additional tests operate on unique sleep-only systemd units in the Ubuntu CI VM.
No server/shared-service rehearsal is claimed. Exact context CI38415153882 and source PHP8.2/8.3 jobs in38415153492 succeeded. Unchanged
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

## Owner preflight failure and narrow correction

[Owner diagnostic16:42MSK](host_cold_recovery_result_20261013.json) identified
only `/sys/kernel/config`,type `configfs`,UUID null. Previous implementation2d1b2502
failed at13:00:01UTC with `ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED` before the
SSD transaction marker,service pause,archive directory or payload. Status stayed
`not_started`,and the first systemd unit is retained as failed;no resume is needed
for this preflight refusal. The correction allows only this type/path pair inside
already-excluded `/sys`,still traverses children and refuses other persistent
filesystems. It performs no mount/unmount or kernel configuration operation.
Primary reference:[Linux configfs](https://docs.kernel.org/filesystems/configfs.html),
an in-memory kernel configuration interface. Five added mount regression tests
cover accepted configfs,wrong location,different persistent type,foreign nested
mount and other external storage. The original17 recovery tests still pass.

## Operator handoff

Both previous failed units remain for diagnosis. Use the new `-r3` unit name below
to avoid colliding with that retained transient service. Run separate physical
commands,wait for each terminal prompt. The start command
explicitly acknowledges web/mail/DNS/container downtime while SSD files copy.
Total15-30min is an estimate for about50GB,not measured RTO;service pause ends after
copy and before readback. Source hashing/metadata scans may extend that pause.
Installation/reboot follows an accepted capture receipt,not these commands.

```bash
sudo -v
```

```bash
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/5d69566b19a0a85a597eafc79cda24b4851c2800/scripts/infra/host_cold_recovery.py -o /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
printf '%s  %s\n' 944886527998f428a86c835817525cd1da92511348ccf276ebf0933ce1561c54 /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict - && sudo -n install -m 600 -o root -g root /home/aduk/USTAR_HOST_COLD_RECOVERY_20261013.py /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py
```

```bash
sudo -n systemd-run --unit=ustar-host-recovery-20261013-r3 --service-type=exec --property=RemainAfterExit=yes --property=RuntimeMaxSec=2h --property=TimeoutStopSec=10min --property=UMask=0077 /bin/bash -c 'set -euo pipefail; printf "%s  %s\n" 944886527998f428a86c835817525cd1da92511348ccf276ebf0933ce1561c54 /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py | sha256sum --check --strict -; exec /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --capture --acknowledge-shared-outage'
```

Status may be repeated safely;it does not perform capture or restart. Wait for
`phase=complete` and `archive_readback_sha256_verified=true` before installation.

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py --status
```

```bash
sudo -n systemctl show ustar-host-recovery-20261013-r3.service -p ActiveState -p SubState -p Result -p ExecMainStatus --no-pager
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
rehearsal follows OS;HTTPS remains selected. First preflight and r2 stop refusal
are recorded;updated r3 execution,completed archive,package installation and
reboot remain pending owner execution evidence.

Primary references:[GNU tar attributes](https://www.gnu.org/software/tar/manual/html_node/Attributes.html),
[one filesystem](https://www.gnu.org/software/tar/manual/html_node/One.html),
[Docker live restore](https://docs.docker.com/engine/daemon/live-restore/),
[MariaDB physical backup scope](https://mariadb.com/docs/server/server-usage/backup-and-restore/backup-and-restore-overview).
