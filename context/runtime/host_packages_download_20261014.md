# Native APT download after accepted host cold recovery

## Current14Oct03:21MSK: central HTTPS reachable; guarded mirror retry

Owner curl -4 probe returned UBUNTU_CENTRAL_HTTPS=OK. Matching source entry is
/etc/apt/sources.list.d/ubuntu.sources line2. Connectivity is observed,not APT
signature verification. Following block accepts regular non-symlink source,
requires line2 be URIs,backs up the original via cp-a into unique root-private
mktemp directory,changes only the exact regional URL on line2,and verifies the
replacement. It preserves other fields/lines and Docker source. If this address
is already set,it proceeds without overwriting the original backup. Unexpected
line contents refuse before edits. Backup path is printed,not config content.
Signed catalog refresh is bounded180s/error-on-any;success is required before
native download-only/no-remove. ForceIPv4 is command-scoped and follows successful
IPv4 HTTPS probe,not a standalone fix for failed regional IPv4. Candidate drift
from refreshed indexes is reviewed before separate installation;no installation
or reboot is in this block. Old metadata/package versions remain provenance.

Authenticate separately with sudo-v,then paste the full block:

```bash
sudo -n bash <<'BASH'
set -euo pipefail
ustar_sources=/etc/apt/sources.list.d/ubuntu.sources
test -f "$ustar_sources"
test ! -L "$ustar_sources"
ustar_line=$(sed -n '2p' "$ustar_sources")
printf '%s\n' "$ustar_line" | grep -qE '^[[:space:]]*URIs:'
if printf '%s\n' "$ustar_line" | grep -qF 'http://ru.archive.ubuntu.com/ubuntu'; then
    ustar_backup=$(mktemp -d /root/ustar-apt-mirror-20261014.XXXXXX)
    cp -a -- "$ustar_sources" "$ustar_backup/ubuntu.sources"
    sed -i '2s|http://ru\.archive\.ubuntu\.com/ubuntu|https://archive.ubuntu.com/ubuntu|' "$ustar_sources"
    printf 'APT_MIRROR_CHANGED; BACKUP=%s\n' "$ustar_backup/ubuntu.sources"
elif printf '%s\n' "$ustar_line" | grep -qF 'https://archive.ubuntu.com/ubuntu'; then
    printf 'APT_MIRROR_ALREADY_SET\n'
else
    printf 'APT_SOURCE_LINE_UNEXPECTED\n' >&2
    exit 1
fi
sed -n '2p' "$ustar_sources" | grep -qF 'https://archive.ubuntu.com/ubuntu'
timeout 180s env LC_ALL=C apt-get --error-on=any -o Acquire::ForceIPv4=true -o Acquire::Retries=1 -o Acquire::http::Timeout=20 -o Acquire::https::Timeout=20 update
env LC_ALL=C apt-get --download-only --no-remove -y -o Acquire::ForceIPv4=true -o Acquire::Retries=1 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 dist-upgrade
BASH
```

Operator should return the source-backup line,catalog result,and download summary/
final error lines. Actual mirror edit/catalog retry/download pending. Shell syntax
passed;no server reachability/source-write/package acceptance is inferred locally.


**Owner14Oct03:14MSK:** [Download receipt](host_packages_download_result_20261014.json) failed at ru.archive.ubuntu.com:IPv6 unreachable and IPv4 timeout.63 package names and49 displayed versions match approved plan;14 cached versions not emitted.6 Docker packages downloaded,102MB fetched;no installation/reboot. Cold archive remains accepted. Next bounded HTTPS probe of central archive plus exact source-entry location;do not rerun unchanged download or use ForceIPv4 alone as a claimed fix. Mirror change/retry remains pending.

Owner status received14Oct03:06MSK confirms [r6 complete](host_cold_recovery_result_20261013.json):
43,704,842,240 bytes,archive SHA256
`8de2742f3ffa303b8f4df3e53fef77fb9047768fdc50eebe696b61be96477730`,
readback verified,223777 protected members,services resumed,package baseline unchanged.
Started23:42:10UTC;services-running readback phase23:54:39UTC;complete00:04:07UTC.
Root-private plaintext file archive remains at
`/srv/ustar-storage/host-recovery-20261013T234210Z-874c1983/root.tar`.
Do not repeat capture/resume or delete the recovery copy. Full host boot restore
and independent external recovery remain unverified;this acceptance is scoped
to the selected-services cold file copy and its readback.

## Next operator command: download only

The [approved catalog/plan](host_packages_result_20261013.json) is38 upgrades+25 new,
zero removals/held upgrades. Native APT download prepares the package cache while
services run. It uses existing validated catalog data and normal APT authentication;
no insecure flags,catalog refresh,dpkg installation,service restart or reboot is
included. `--no-remove` refuses a removal plan. This download command does not pin
the63 versions:review its displayed package plan against the saved exact version
set before installation. A different download plan does not authorize a different
installation plan. No new helper or repeated host scan is needed for this step.

Authenticate separately and wait for the prompt to return:

```bash
sudo -v
```

Then paste only this command:

```bash
sudo -n env LC_ALL=C apt-get --download-only --no-remove -y -o Acquire::Retries=1 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 dist-upgrade
```

Return its package summary and final download/error lines. Expected summary is
`38 upgraded, 25 newly installed, 0 to remove and 0 not upgraded`;the exact saved
versions also require comparison,not merely the counts. A network/cache failure
leaves package installation pending;do not remove locks,clean the cache or use
`--fix-missing` to bypass it. Partial downloads are normal cache state,not backup
corruption. Download writes APT cache and uses native APT locks;it is not a
filesystem-read-only operation and does not establish package/service compatibility.

## Installation and reboot still ahead

After download receipt,prepare a separate exact-version installation command
held by systemd across SSH loss,with normal package authentication,no removals,
existing local conffiles retained and explicit shared-service handling. Review
maintainer-script restarts,needrestart and Docker/containerd behavior:live restore
was false. Preserve working kernel139 and installed146;147 is the agreed candidate.
Check dpkg/APT completion,Docker identities and startup,shared web/mail/DNS/SSH,
147 initramfs/GRUB,then perform the authorized reboot and acceptance. Coordinate
the03:30MSK HDD timer through the existing maintenance lock without breaking its
schedule. A package installation failure requires inspection before rerunning;
APT downgrade is not a universal rollback. Console access was already confirmed.

The cold file archive and retained kernel give recovery options;no unconditional
live-root tar extraction or full boot restoration is prepared or claimed here.
Application/Moodle5.1.8/AppArmor acceptance remains closed. Latest Moodle major
with PostgreSQL17+ and addon rehearsal follows host acceptance.

Primary native command reference checked14Oct:
[Ubuntu apt-get](https://manpages.ubuntu.com/manpages/noble/man8/apt-get.8.html)
(`--download-only`,normal authentication,`--no-remove`). Local shell syntax passed;
actual server download/installation/reboot is pending.

## Bounded mirror follow-up after the03:14MSK failure

HTTPS probe checks server reachability,not APT archive authentication. No source
is modified by these commands. Exact active source-entry locations are emitted
without full configuration lines or unrelated repository credentials. Prepare
backup and guarded URI-only replacement after these observed results;preserve
suites/components/signing keys/Docker sources. Refresh signed APT catalog after
any source change and review candidate/version drift before package installation.

```bash
curl -4 -fsSL --connect-timeout 10 --max-time 25 https://archive.ubuntu.com/ubuntu/dists/noble-updates/InRelease -o /dev/null && printf 'UBUNTU_CENTRAL_HTTPS=OK\n'
```

```bash
sudo -n python3 -I -B - <<'PY'
from pathlib import Path
paths = [Path('/etc/apt/sources.list')]
paths += sorted(Path('/etc/apt/sources.list.d').glob('*.list'))
paths += sorted(Path('/etc/apt/sources.list.d').glob('*.sources'))
for path in paths:
    if not path.is_file():
        continue
    for number, line in enumerate(path.read_text().splitlines(), 1):
        if line.lstrip().startswith(('deb ', 'deb-src ', 'URIs:')) and 'ru.archive.ubuntu.com' in line:
            print(f'SOURCE_FILE={path} LINE={number}')
PY
```
