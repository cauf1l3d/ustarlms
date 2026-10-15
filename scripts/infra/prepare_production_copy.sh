#!/bin/bash
# Prepare a private transfer kit from the last completed HDD backup.
# This script never requests a new backup, maintenance or a source stop.
set -euo pipefail
umask 077
test "$(id -u)" = 0
test "$#" = 0
for ustar_unit in ustar-backup-recovery-20261015.service \
                  ustar-restore-test-20261014.service \
                  ustar-production-import-20261015.service; do
    test "$(systemctl show "$ustar_unit" -p MainPID --value)" = 0
done
ustar_source=https://raw.githubusercontent.com/cauf1l3d/ustarlms
ustar_source=$ustar_source/a1a0c62bfcbb04df96d8797537599db11e692f4f/scripts/infra
ustar_tools=$(mktemp -d /root/ustar-copy-tools.XXXXXXXX)
cd "$ustar_tools"
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/backup_restore_test.py" -o controller.py
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/backup_transfer.py" -o transfer.py
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/production_restore.py" -o production.py
cat <<'SHA' | sha256sum --check --strict -
4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022  controller.py
369628cc4d24e4d44ffa56373cece25b38f69b30783f43770374d3bf77d4610f  transfer.py
b59e1e0e974138af6a9d5e3acca5c856d03ac4719c2c5a336985bb239c5c66eb  production.py
SHA
for ustar_path in /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py \
                  /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py \
                  /usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py; do
    test ! -L "$ustar_path"
done
install -o root -g root -m 600 controller.py \
    /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py
install -o root -g root -m 600 transfer.py \
    /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py
install -o root -g root -m 600 production.py \
    /usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py
ustar_export_unit=ustar-copy-export-$(date -u +%Y%m%dT%H%M%SZ)
systemd-run --unit="$ustar_export_unit" --service-type=exec \
    --property=RemainAfterExit=yes --property=UMask=0077 \
    --property=Nice=10 --property=IOSchedulingClass=best-effort \
    --property=IOSchedulingPriority=7 --property=RuntimeMaxSec=2h \
    /usr/bin/python3 -I -B -u \
    /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py --pack
printf 'EXPORT_UNIT=%s.service\n' "$ustar_export_unit"
printf 'Export uses an existing backup. Wait for TRANSFER_PACK=PASS in its journal.\n'
