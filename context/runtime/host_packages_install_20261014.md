# Approved cached host package installation

**Current14Oct04:18MSK:** Owner exact d000e800/c753cf2e installation PASS:01:08:25..01:12:55UTC,63 versions verified,APT exit0,147 boot files verified,services resumed,container identities/policies and critical config bytes preserved. Unit success/exit0/PID0,active/exited is RemainAfterExit. Installation acceptance CLOSED;do not rerun installer/cold capture/resume. Next native reboot behind maintenance flock and selected kernel/service/HDD checks;actual reboot/boot acceptance pending. Existing console and139/146 fallback files retained;full host boot restore/external recovery unverified. Latest Moodle major follows host acceptance.

[Owner installation receipt](host_packages_install_result_20261014.json);[native reboot/post-boot handoff](host_reboot_20261014.md). The installation launch commands below are historical and must not be repeated for this accepted transaction.

## Verified immutable source and operator handoff

Implementation `d000e80092da653cc369c2a6d37677cfcd56b1c3`,helper SHA256 `c753cf2e2b4ebdd0c7ff690614f6f2ec14435d035fdea6051cfe45d70e5947d1`.
Published source bytes and tree match local source314688ee6576e460e7dd6fe43c356f99f091bae3.
Exact Ubuntu24.04 run38493438210/job115623715462 SUCCESS:33 installer tests,
48 accepted-controller file/control cases and2 actual isolated sleep-only systemd
cases,no skips;all three log summaries inspected. Context38493438435 SUCCESS.
Broader review38493438226 remains a separate gate;whole PR green is not claimed. Source33 guards/model tests do not establish actual
APT maintainer-script/Docker/shared service install or full boot restoration.

Authenticate separately and wait for the prompt to return:

```bash
sudo -v
```

Then paste the full block. Any download/hash/install error aborts before launch.
This authorizes the already agreed shared service pause;the helper resumes original
runtime afterward and issues no reboot. Do not rerun an installation unit after a
package_install_started receipt without inspecting the actual failure.

```bash
bash <<'BASH'
set -euo pipefail
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/d000e80092da653cc369c2a6d37677cfcd56b1c3/scripts/infra/host_package_install.py -o /home/aduk/USTAR_HOST_PACKAGE_INSTALL_20261014.py
printf '%s  %s\n' c753cf2e2b4ebdd0c7ff690614f6f2ec14435d035fdea6051cfe45d70e5947d1 /home/aduk/USTAR_HOST_PACKAGE_INSTALL_20261014.py | sha256sum --check --strict -
sudo -n install -m 600 -o root -g root /home/aduk/USTAR_HOST_PACKAGE_INSTALL_20261014.py /usr/local/sbin/USTAR_HOST_PACKAGE_INSTALL_20261014.py
sudo -n systemd-run --unit=ustar-host-packages-20261014 --service-type=exec --property=RemainAfterExit=yes --property=KillMode=mixed --property=RuntimeMaxSec=2h --property=TimeoutStopSec=30min --property=UMask=0077 /bin/bash -c 'set -euo pipefail; printf "%s  %s\n" c753cf2e2b4ebdd0c7ff690614f6f2ec14435d035fdea6051cfe45d70e5947d1 /usr/local/sbin/USTAR_HOST_PACKAGE_INSTALL_20261014.py | sha256sum --check --strict -; exec /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_HOST_PACKAGE_INSTALL_20261014.py --install --acknowledge-shared-outage'
BASH
```

After3–5 minutes read status and native unit result:

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_HOST_PACKAGE_INSTALL_20261014.py --status
sudo -n systemctl show ustar-host-packages-20261014.service -p ActiveState -p SubState -p Result -p ExecMainStatus -p MainPID --no-pager
```

Success:complete_reboot_pending,installed_versions_verified=63,services_resumed=true,
new_kernel_boot_files_verified=true and native unit success/exit0. Only then prepare
the separate authorized reboot and boot/shared-service/HDD/cron acceptance. If a
failure occurs,return the filtered status and unit result;private package logs/config
contents must not be pasted publicly. Journal phase/error lines can be read with:

```bash
sudo -n journalctl -u ustar-host-packages-20261014.service -n 80 --no-pager -o cat | awk '/^HOST_PACKAGES_PHASE=|^HOST_PACKAGES_ERROR=|^HOST_PACKAGES_INSTALL=|^HOST_PACKAGES_RESUME=/{print}'
```

Actual operator installation/reboot acceptance remains pending.


Owner03:32MSK [download receipt](host_packages_download_result_20261014.json):
mirror replaced with HTTPS archive.ubuntu.com;original source retained at
`/root/ustar-apt-mirror-20261014.i9mk34/ubuntu.sources`. Signed catalog refresh
completed;two existing DEP11 appstream warnings did not block it. Download finished:
658MB in86s,953MB total archive set,297MB additional installed space.
38 upgrades+25 new,no removals/held upgrades;63 names and43 displayed retry versions
match the [reviewed plan](host_packages_result_20261013.json). Six Docker versions
matched in the first attempt;14 cached versions were not printed. Do not infer
all63 installed versions from these download logs. Installation/reboot pending.

## Installer contract

[`host_package_install.py`](../../scripts/infra/host_package_install.py) embeds the
exact63 approved package/version pairs. It imports only the installed600/root
cold helper after full SHA256/parent/type/owner/link checks:
`3f8f0c0eae0d9702216d276231f2c7151fc40635b962843a0e9fe85efae6f0c4`,
immutable22d8a4e172208d029b0524c3f38e37403fa438b9. That helper's service-control
path passed owner r6 stop/resume;no new cold capture or module source change.

Systemd holds installation across SSH loss. Shared backup flock is acquired
before preflight/service changes,with bounded20-minute wait for existing work.
It is held through installation,resume and checks. APT retains its own native
POSIX locks;the supervisor probes and releases them before APT rather than
holding incompatible parent locks while launching APT. Timers are stopped as
part of the accepted service-control path. Concurrent external APT operators
must not start a separate transaction during this maintenance operation.

Before any stop:require accepted r6 cold-marker/readback result,HDD UUID/ext4,
private archive size/device and unchanged full package TSV baseline;no repeat
43.7GB hash read. Require dpkg audit clear,kernel139 running,fallback139/146 boot
files present. Native simulation must equal63 exact versions,no removals. Native
download-only/no-download validates cached availability. Hash selected netplan,
SSH,fstab,Docker configuration and accepted AppArmor profile without publishing
contents. Snapshot current exact6 container IDs/images/mounts/policies and active
selected shared services/sockets/all timers. Missing/drift/cache failures stop
before an outage. Only preflight logs/private marker files are written then.

Stop/resume calls reuse pinned cold helper:shared web/mail/DNS/DB/cron services
pause,container policies become no,web stops before PostgreSQL,then Docker/socket/
containerd stop. Native APT may start affected services through maintainer scripts;
disabled container policies prevent automatic container starts during that work.
Run native apt-get dist-upgrade --no-download --no-remove with --force-confold,
noninteractive debconf and NEEDRESTART_SUSPEND=1 (plus list-only mode). Locally
modified dpkg conffiles are retained. This does not promise all package scripts
are forbidden to write configuration or start a service. Critical config bytes
are compared afterward;unexpected changes block PASS and a reboot handoff.

APT plan is checked again after stop. Native APT has no Python timeout that kills
dpkg. Supervisor SIGTERM/SIGINT records cancellation and waits for native APT
completion;KillMode=mixed sends the first stop signal to the supervisor. The unit
2-hour runtime cap/30-minute final grace is a safety bound,not a normal duration
or absolute recovery guarantee. Forced power loss/SIGKILL may leave resume_required;
root-private saved marker supports resume only after native package locks are free.

After native APT exit0:verify all63 installed versions and ii status,dpkg audit,
APT dependency check,nonempty147+139+146 boot file pairs,147 modules present in
lsinitramfs output and GRUB syntax/147 entry. This is file verification,not boot
acceptance. Finally resume original units/containers/policies in DB-before-web
order and login200. Require Docker29.9.0,containerd2.4.1,AppArmor active/success,
docker-default enforcement and critical config hashes unchanged. No Docker images,
container definitions,application code/data or database schema is upgraded here.

## Acceptance and recovery

Own marker `/var/lib/ustar-host-packages-20261014/active.json`;own private logs.
Accepted cold marker/archive stays intact. Status emits selected fields only,
no config text/inspection/mount bodies/private package log output.
Successful phase is complete_reboot_pending,services_resumed=true,
installed_versions_verified=63,new_kernel_boot_files_verified=true.
Host reboot acceptance remains PENDING;there is no reboot command in this helper.

Preflight failure makes no service change. Any later failure attempts original
runtime resume after native package locks are free. failed_resumed is not install
success;resume_required means original runtime is not yet verified. After any
package_install_started marker,automatic install repeat is refused pending
inspection,even if APT exit was nonzero. --resume-services restores only runtime/
policies and never retries APT or restores files/DB. Do not stop a live APT/dpkg
transaction or remove its locks. Inspect the phase/error and private log after a
failure;recovery source must match the issued immutable helper SHA.

Retain139/146 boot choices and accepted private HDD root-file archive. Previously
confirmed console access remains available for boot selection/recovery;do not
ask again. Universal apt downgrade and blind live-root tar extraction are not
rollback methods. Full host boot restoration and independent external recovery
remain unverified. Do not overwrite live DB/mail data from the old archive while
writers run. No archive deletion,reboot,release upgrade,Moodle upgrade or PG17
migration is included. Latest Moodle major follows host reboot acceptance.

## Historical validation at source preparation

33 local root tests PASS/no skips:exact plan drift/removal/missing/duplicate gates,
cache/cold receipt/package baseline refusal,APT/stop/boot/config/resume failures,
cancellation waits before resume,native POSIX lock contention and temporary boot
file fixtures. Cleanup receipt I/O cannot block the service resume attempt or claim PASS. Actual APT/maintainer scripts/Docker/shared service installation
are modeled;Ubuntu exact-source CI and operator install remain pending.
Application/source schema map remains526 files/93 tables/32 tasks.

Primary semantics checked14Oct:Ubuntu
[APT](https://manpages.ubuntu.com/manpages/noble/man8/apt-get.8.html),
[dpkg confold](https://manpages.ubuntu.com/manpages/noble/man1/dpkg.1.html),
[needrestart suspend](https://manpages.ubuntu.com/manpages/noble/man1/needrestart.1.html),
[systemd process control](https://www.freedesktop.org/software/systemd/man/latest/systemd.kill.html).
Implementation SHA,CI evidence and exact operator commands will be pinned after
publication;do not treat a draft source as server acceptance.


[Exact source validation receipt](host_packages_install_validation_20261014.json). Operator command pins tested d000e800/c753cf2e;later documentation commits preserve these bytes.
