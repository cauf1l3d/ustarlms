[Owner14:24MSK actual repair PASS](host_apparmor_result_20261013.json):corrected
93e1813e,AppArmor active/success,Docker enforcement/identities/images/restart counts
and selected Snap units preserved. Targeted repair CLOSED;no rerun without a new
error. [Next package/boot/shared recovery planning](host_packages_handoff_20261013.md).
Descriptions below retain source/validation/pre-execution history;server execution
is now accepted in the stated one-profile scope. Full Snap runtime remains unclaimed.

# Pinned AppArmor include repair

Owner [13Oct13:18MSK observation](host_apparmor_observation_20261013.json) confirms
the exact root-owned0644 profile/dev2050/inode3151055/SHAa308e931 and parser failure
at line11:missing `/var/lib/snapd/apparmor/snap-confine`. This is an established
parse error,not proof that the unit has no other failures. Existing core18/LXD/
snapd mounts remain active,an enabled LXD socket listens,daemon inactive and observed
Snap executable count0. Thus the profile is not treated as unused. Actual kernel
`docker-default (enforce)` is observed;SSH socket loaded/active/enabled metadata is
not a reboot/remote-access test. Snap/LXD application acceptance remains unclaimed.

Earlier accepted [production5.1.8 upgrade/repeat](production_patch_upgrade_result_20261013.json),
[scheduled cron/HDD](production_patch_post_switch_status_result_20261013.json),
[owner browser/SCORM](production_patch_browser_result_20261013.json) stay closed.
New source is an independent branch from current main
`c53723c8d669a1013c518f34abf392143d211d9d`,not a stacked PR99 source feature.
These selected receipts are copied byte-for-byte from accepted PR99 evidence;
application/frontend/native source is unchanged. The [earlier OS cached APT plan](host_os_preflight_result_20261013.json)
is a dated proposal,not a package installation result or fresh current catalog.

## Exact behavior

[`host_apparmor_repair.py`](../../scripts/infra/host_apparmor_repair.py) defaults to
PLAN_ONLY. `--repair` requires root and the exact observed source bytes,original
inode/device,root0:0/0644/single-link regular metadata and safe parent directories.
It refuses a now-present include directory,local disabled/complain marker,active
Snap executables,changed production images/confinement or missing Docker enforcement.
It reads only narrow Docker identity/profile/restart-count metadata and selected
Snap unit states;no process arguments/environment or Docker configuration is emitted.

Only the unique literal include directive receives `if exists`;all other profile
bytes stay unchanged. The profile is retained. If the directory is restored in
future,AppArmor still reads it and rejects invalid contents. This is supported
[AppArmor include syntax](https://manpages.debian.org/unstable/apparmor/apparmor.d.5.en.html).
It does not establish full compatibility/security of the retained old Snap/LXD.
No empty directory,profile removal,Snap purge,global AppArmor disable or complain
mode is introduced.

Original bytes are read back from root-only0600 backup in
`/var/lib/ustar-host-apparmor-20261013` (root0700). Receipt records original basic
UID/GID/mode,device/inode and mtime. Candidate is compiled with explicit Q/K,one
job and bounded timeout;candidate profile names must remain in snap-confine scope.
Before live replacement,the source fingerprint,containers,Snap state and kernel
labels are rechecked. A hidden same-directory file is fsynced and atomically
replaces the live file,retaining0:0/0644. The new inode/mtime are intentional;
full ACL/xattr equivalence is not claimed.

Only AppArmor is started when failed/inactive or reloaded when already active;
there is no stop/restart command. Loader invocation is bounded120s. PASS requires
AppArmor active/success,the corrected file readback,all previous kernel labels
remaining,new snap-confine profiles in enforce mode,and unchanged production
IDs/images/running/profile/restart counts and selected Snap unit metadata. Existing
LXD socket/mounts are kept. Successful repeat performs reads/compilation without
rewriting the live file or loading AppArmor again.

Private receipt saves phase/failure code/return code. Compiler/control stdout and
stderr remain in root-only files;exception text/commands/private policy do not go
to public output. Before commit,compile/refusal leaves the live original untouched.
After commit,failed loading/check leaves the corrected file plus exact original
and ERROR phase. Automatically restoring a known broken include or unloading
other kernel profiles would not be a healthy rollback,so neither occurs. If a
systemctl client timeout occurs,the systemd job may still finish;the receipt cannot
claim PASS until a later guarded verification succeeds. No package install/reboot,
Moodle/PHP bootstrap/SQL,backup engine,cron change,image pull,Docker/Snap service
operation or LXD client connection is performed.

## Operator contract

Download the helper from its full reviewed commit,verify its SHA256 and install
root-owned0600 at `/usr/local/sbin/USTAR_HOST_APPARMOR_REPAIR_20261013.py`.
After separate `sudo -v`,a root shell rechecks the installed SHA256 before invoking
`python3 -I -B -u ... --repair`. Do not bypass a refusal or substitute an unpinned
file. This task's reviewed helper SHA256 is
`570986e4cd2199945b705eb280cb183339d9d6ba71d15ad788b09aca5579b225`.
Reviewed implementation commit `3a3ef0f3c5df8355822af76feb6fa62b2553fdfc`;server execution
of this repair passed in the owner result above. Existing host maintenance authorization covers
the targeted reversible fix;no repeat generic permission request is needed.

## Validation and next work

Local transaction tests use actual temporary files/atomic replacements,root
ownership/modes and private backup/receipt checks;Docker/systemctl are simulated.
Compiler failure,source race,changed image/enforcement,active Snap,symlink/hardlink,
load failure and post-load drift refuse PASS;repeat does not reload. Local13 helper
tests:12 pass,real parser test skipped because parser is absent here. Full local
Python suite177 tests passes with11 skips on this main-based checkout.
Dedicated Ubuntu24.04 CI requires real parser and root transaction fixtures;
temporary policies use Q/K so the compiler test loads no kernel policy and writes
no AppArmor cache. It checks missing include fails,conditional absent include
compiles,and a restored existing include is read rather than ignored. [Exact source validation](host_apparmor_validation_20261013.json):Ubuntu fixture
[38386147380](https://github.com/cauf1l3d/ustarlms/actions/runs/38386147380) PASS,
all13 tests/no skips including real parser and root files;context38386146947 PASS.
Review38386147199 source PHP8.2/8.3 PASS;unchanged main frontend npm audit failed
on Next/sharp,so full PR success is not claimed. DB/rollback/full gate skipped.
Frontend build/HTTP smoke skipped after its audit;this helper changes no frontend. This does not
exercise the production loader or full Snap/LXD runtime;those are separately scoped.

After targeted AppArmor acceptance,continue the shared-host update/recovery/window
plan from the actual APT candidates;refresh/simulate before installing. Keep required
mail/DNS/Apache/MariaDB/ISPConfig,exact Docker volumes and one cron. Reuse confirmed
console access;an USTAR archive is not full shared-host recovery. Owner confirmed
latest stable Moodle5.3 LTS after OS:PG17+ migration,all addons/native theme and
isolated coherent recovery rehearsal before production. No intermediate5.2 is
required;accepted5.1.8 checks are not repeated. HTTPS remains selected;broader
unrelated hardening and Snap/LXD replacement/removal are not inferred from this fix.
