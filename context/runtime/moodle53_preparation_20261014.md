# Moodle 5.3 LTS: official assets and isolated migration preparation

**Owner14Oct17:14MSK:** the download-only block below completed successfully;do not repeat it.
[Asset/cron receipt](moodle53_assets_result_20261014.json) pins the actual PG17 digest
and closes the post-boot scheduled cron observation. Next:[independent full-addon
stage preparation](moodle53_stage_prepare_20261014.md). Later pending-download
statements below describe the earlier handoff,not the current state.

**Current owner priority14Oct:** route refresh diagnosis is deferred;resume this
Moodle major transition. Step3 preparation is complete in the source/syntax scope:
the next operator action is the download-only block below. Actual server assets,
image digest and compatibility acceptance remain pending. Host installation and
native boot acceptance stay closed;do not repeat that work.

Owner14Oct04:41MSK [native host boot acceptance](https://github.com/cauf1l3d/ustarlms/blob/d1a3b6e1f62aa00a550016fdb55a55a4c47344a3/context/runtime/host_reboot_result_20261014.json)
is PASS:kernel147,Docker29.9.0,selected shared units/AppArmor/HDD healthy,login
final200. All six container IDs/policies match the saved installation;four labs
have restart=no and clean exit0. Retained labs are intentionally not autostarted.
Host package install/reboot/cold capture are closed in this observed scope.
One post-boot scheduled Moodle cron status remains to observe;preparing cached
official assets does not require that unrelated service work be repeated.

## Target verified on 14 October

[Official target metadata](moodle53_target_20261014.json).

| Item | Observed production | Chosen rehearsal target |
|---|---|---|
| Moodle | 5.1.8 / 2025100608.00 | Formal 5.3.0 LTS / 2026100500.00 |
| PHP | 8.3.33 | Existing version meets PHP minimum8.3;extensions/settings checked before stage |
| PostgreSQL | 16.15 | Official 17.11-trixie for linux/amd64,pinned by actual pulled digest |
| Addons | 57 component versions / 19 roots | Full inventory retained and tested |
| USTAR source | 526 plugin/theme files | Existing source;no business/schema patch inferred |

[Moodle5.3 requirements](https://moodledev.io/general/releases/5.3) permit upgrade
from4.4+,require64-bit PHP8.3+,sodium,max_input_vars>=5000 and PostgreSQL17+.
[Latest formal release](https://download.moodle.org/releases/latest/) is5.3;
weekly5.3+ is a separate moving package and not this pinned formal archive.
The official [archive checksum](https://packaging.moodle.org/stable503/moodle-5.3.tgz.sha256)
is7e5edf110555956571f40e42acffde0eb23681ebebd212a2fec0fe7d795dd511.
Upstream tagv5.3.0 public/version.php was read:branch503,2026100500.00,
MATURITY_STABLE. Release notes show5Oct;download page shows3Oct package build;
do not confuse package build with release date.

[PostgreSQL version policy](https://www.postgresql.org/support/versioning/)
lists17.11 as current minor on14Oct. Official image definition lists
17.11-trixie/amd64,source2603e26e245e558218728ee14e0a42dcb020dc7f.
Major versions need logical dump/reload or pg_upgrade;an image-tag replacement
with the16 data directory is not a migration. Choose logical dump/restore into
a distinct new17 cluster for this small database,subject to measured data,
roles/extensions/encoding/collation and restore validation. No pg_upgrade
hardlink mode or shared source data files is part of this preparation.

## First operator step: cache assets,read cron status

Authenticate sudo separately if needed and wait for the shell prompt.
Paste only the block. This uses native utilities;no new root helper or service
supervisor is required. SHA guard reviews the existing cron v3 status helper;
status does not run Moodle PHP/tasks or modify the installed schedule.
A new root-private directory is created under/var/lib;the archive is checked
before partial-name publication. Docker pull adds one official image to cache
and does not start/recreate/change a container. No production pause,APT,
database migration,tar extraction,cron tick or lab startup occurs.

```bash
sudo -n bash <<'BASH'
set -euo pipefail
umask 077
printf '%s  %s\n' 66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852 /usr/local/sbin/USTAR_CRON_SETUP_20261005.py | sha256sum --check --strict -
timeout 60s python3 -I -B -u /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
ustar_assets_dir=$(mktemp -d /var/lib/ustar-moodle53-assets-20261014.XXXXXXXX)
printf 'ASSETS_DIRECTORY=%s\n' "$ustar_assets_dir"
curl -fL --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 600 --progress-bar https://download.moodle.org/download.php/direct/stable503/moodle-5.3.tgz -o "$ustar_assets_dir/moodle-5.3.tgz.partial"
printf '%s  %s\n' 7e5edf110555956571f40e42acffde0eb23681ebebd212a2fec0fe7d795dd511 "$ustar_assets_dir/moodle-5.3.tgz.partial" | sha256sum --check --strict -
mv "$ustar_assets_dir/moodle-5.3.tgz.partial" "$ustar_assets_dir/moodle-5.3.tgz"
docker pull --platform linux/amd64 docker.io/library/postgres:17.11-trixie
test "$(docker image inspect --format '{{.Os}}/{{.Architecture}}' docker.io/library/postgres:17.11-trixie)" = linux/amd64
docker image inspect --format 'PG17_ID={{.Id}} OS={{.Os}} ARCH={{.Architecture}} REPO_DIGESTS={{json .RepoDigests}}' docker.io/library/postgres:17.11-trixie | tee "$ustar_assets_dir/pg17-image.txt"
printf 'MOODLE53_ASSETS=READY; DOWNLOAD_ONLY\n'
BASH
```

Return the cron JSON,ASSETS_DIRECTORY,checksum result andPG17_ID/REPO_DIGESTS
receipt. PG17 tag alone is not immutable;use the returned content digest in
the future profile and same-platform CI. A failed download or hash never
authorizes extraction. Partial files/new private asset directories stay for
inspection;existing stage,archive,code,config,data and images are not deleted.
Bash-n is syntax validation,not registry/network/compatibility acceptance.
Actual asset download,exact archive/image readback and server migration remain
pending until the owner returns their receipts.

## Following rehearsal

1. Pin exact official package/core metadata and PostgreSQL17 image digest;add
   synthetic source/DB fresh-install/5.1.8-upgrade/repeat/paired-restore CI.
2. Use a separate private copy of the already accepted5.1.8 stage and database;
   inspect stopped source identities/binds and disabled tasks before starting
   only those lab resources actually needed. Keep frozen baseline separate.
3. Restore a logical dump into the new17 cluster first and verify schema,
   extensions,roles,table/sequence/data counts and application on5.1.8.
   Then replace only the mutable copy's stock core with5.3 plus all19 addon roots.
4. Run official CLI upgrade/repeat in the isolated copy with outbound/mail/cron
   blocked. Compare57 addon metadata,USTAR data/progress/roles/files/task flags,
   testSCORM/quiz/materials/workspace and theme_ustar/Boost navigation.
5. Restore the whole code/config/moodledata/database pair and repeat migration/
   upgrade to prove recovery. Lab role/browser acceptance is specific to5.3.
   Production window is prepared only from that accepted exact matrix.

5.3 release notes include Boost/navigation/AMD changes;PHP version and matching
plugin metadata alone do not verify theme/addon runtime compatibility. Preserve
existing task history and role assignments. Actual5.3 full-addon integration,
performance/role learning QA,paired recovery and deployment remain unverified.
Production5.1.8/browser acceptance stays closed;HTTPS remains the next agreed
infrastructure goal after the selected platform work.
