# Independent full-addon Moodle 5.3 rehearsal preparation

Owner14Oct17:14MSK [asset and cron acceptance](moodle53_assets_result_20261014.json)
closes formal5.3 archive download/SHA256,PG17.11/linux-amd64 pull/digest and the
post-boot scheduled cron observation:exit0,new failures none,USTAR errors0.
Two existing scheduled failure flags and18 overdue tasks remain unchanged
within that observation;no flags/history are reset. Host packages and native
kernel147/Docker29.9.0 boot acceptance remain closed. Route refresh diagnosis
is explicitly deferred. Actual full-addon5.3 compatibility remains unverified.

## Current action

Prepare new private copies from the accepted5.1.8 patch lab that already exited
cleanly after host reboot with restart=no. The source is the retained12Oct
snapshot,not today's production data. The helper does not restart the source
lab or any production container. It performs Docker inspect only:no SQL,PHP,
downloads,network/container/service operations,upgrade or deletion.

The root-private run under`/var/lib/ustar-moodle53-20261014/stage-*` contains:

| Path | Purpose |
|---|---|
| baseline/code,moodledata,postgres16 | Frozen paired5.1.8 recovery inputs |
| working/code,moodledata,postgres16 | Independent mutable copy for the rehearsal |
| baseline/controls,working/controls | Private original state/PG environment/Apache/PHP controls |
| candidate53 | SHA-checked stock5.3 plus all19 addon roots/57 components |
| manifest.json | Private code/data/PG hashes,metadata and container provenance |

All526 USTAR files,source5.1.8 version,PG_VERSION16/absence of postmaster.pid,
owned stopped container IDs/images/binds and exact PG17 digest/platform/version
metadata are checked. Both copies preserve bytes,UID/GID/mode,mtime and xattrs,
have independent file inodes and are read back. Stock53 files cannot be
overwritten by addons;legacy extra files and the existing config are retained
in the paired copies. Candidate53 has no config.php and cannot be activated by
this helper. Source/production runtime/config/trees must remain unchanged.
Legitimate production healthcheck log timestamps are excluded from identity
comparison. Partial outputs remain private;successful runs are not overwritten.

Budget includes two paired copies,stock53/addons,controls,2GiB future PG17
growth allowance and4GiB free reserve. This is a preparation allowance,not a
production upgrade capacity guarantee. Only the known private application
trees are scanned;proc/host-root traversals from the old host archive task are
not involved.

## Operator handoff

Authenticate sudo separately and wait for the prompt. The helper/profile source
commit is3255900554c18190a31e63edfac61ba3377e85b2,the all-green selected CI
commit. Original helper sourcea675303c has identical source/profile blobs. Helper SHA256:
3d5057573456f413645b974f3524c73bb3707a63de93e6b767ee8f20c88f061c.
Profile SHA256:40ad9cdd7235807db3b3514e335683c14b5cb6fb7433d568b0e9cee071bb6fb1.

```bash
bash <<'BASH'
set -euo pipefail
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/3255900554c18190a31e63edfac61ba3377e85b2/scripts/infra/moodle53_stage_prepare.py -o /home/aduk/USTAR_MOODLE53_STAGE_PREPARE_20261014.py
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/3255900554c18190a31e63edfac61ba3377e85b2/scripts/infra/moodle53_profile_20261014.json -o /home/aduk/USTAR_MOODLE53_PROFILE_20261014.json
printf '%s  %s\n' 3d5057573456f413645b974f3524c73bb3707a63de93e6b767ee8f20c88f061c /home/aduk/USTAR_MOODLE53_STAGE_PREPARE_20261014.py 40ad9cdd7235807db3b3514e335683c14b5cb6fb7433d568b0e9cee071bb6fb1 /home/aduk/USTAR_MOODLE53_PROFILE_20261014.json | sha256sum --check --strict -
sudo -n install -m 600 -o root -g root /home/aduk/USTAR_MOODLE53_STAGE_PREPARE_20261014.py /usr/local/sbin/USTAR_MOODLE53_STAGE_PREPARE_20261014.py
sudo -n install -m 600 -o root -g root /home/aduk/USTAR_MOODLE53_PROFILE_20261014.json /usr/local/sbin/USTAR_MOODLE53_PROFILE_20261014.json
sudo -n systemd-run --unit=ustar-moodle53-prepare-20261014 --service-type=exec --property=RemainAfterExit=yes --property=RuntimeMaxSec=90min --property=TimeoutStopSec=2min --property=Nice=10 --property=UMask=0077 /bin/bash -c 'set -euo pipefail; printf "%s  %s\n" 3d5057573456f413645b974f3524c73bb3707a63de93e6b767ee8f20c88f061c /usr/local/sbin/USTAR_MOODLE53_STAGE_PREPARE_20261014.py 40ad9cdd7235807db3b3514e335683c14b5cb6fb7433d568b0e9cee071bb6fb1 /usr/local/sbin/USTAR_MOODLE53_PROFILE_20261014.json | sha256sum --check --strict -; exec /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_MOODLE53_STAGE_PREPARE_20261014.py --prepare'
BASH
```

The service survives SSH disconnect. It never stops another service,so there is
no resume-services mode. Status/progress:

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_MOODLE53_STAGE_PREPARE_20261014.py --status
sudo -n systemctl show ustar-moodle53-prepare-20261014.service -p ActiveState -p SubState -p Result -p ExecMainStatus -p MainPID --no-pager
sudo -n journalctl -u ustar-moodle53-prepare-20261014.service -n 30 --no-pager -o cat | awk '/^MOODLE53_STAGE_PHASE=|^MOODLE53_COPY_BYTES=|^MOODLE53_STAGE_PREPARE=|^MOODLE53_STAGE_PREPARE_ERROR=/{print}'
```

Return the final status JSON,unit result and phase markers. Expected result:
phase=prepared/exit0/MOODLE53_STAGE_PREPARE=PASS;NO_ACTIVATION;NO_UPGRADE.
Do not run the old5.1.8 production-upgrade helper or start all retained labs.
The following controller will configure only the new mutable namespace,start
its PG16 copy,export a logical dump,restore into a new PG17 directory,compare
data/sequences/roles/extensions and5.1.8 behavior,then run official5.3 CLI
upgrade/repeat and full-addon/browser/paired-recovery acceptance. Those actual
Moodle/data/role checks and production activation remain pending.

## Validation scope

[Validation receipt](moodle53_stage_validation_20261014.json):25 local real-file/archive
cases PASS with fixture-only UID mapping;Ubuntu24.04 root25 cases PASS without
UID mapping/skips. Actual formal archive parser PASS:78,513 entries/463,270,880
expanded bytes. No root/public vendor directory is in that distribution;actual
Moodle bootstrap/dependency compatibility is a later isolated-stage check.
Pinned-image synthetic PG16→17 rows/sequence/ACL/extension,independent redo and
restore into16 PASS. Exact selected CI32559005/run38584832688 and context38584832741
PASS;both selected job logs and public/synthetic artifact uploads inspected.
The first root0600 public-metadata artifact upload failure was fixed only in CI;
operator helper/profile bytes did not change. Remote blobs equal locally checked
ones and outer/inner Bash syntax PASS. Full PR release gate/full-addon53 runtime,
server preparation,production activation and full host restore are not claimed.
