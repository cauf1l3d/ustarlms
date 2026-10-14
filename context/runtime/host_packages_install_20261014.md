# Approved cached host package installation

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

## Validation at source preparation

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
