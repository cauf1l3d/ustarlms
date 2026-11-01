#!/usr/bin/env bash
# Exact2803 source + actual legacy index pair ->2900 twice, in a separate disposable prefix.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test -d /source/.stage-input/role-bot-baseline/moodle/local/ustar
mkdir -p /stage/data/botold_
sources() {
    rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar
    cp -a "$1/local/ustar" /stage/moodle/public/local/ustar
    cp -a "$1/theme/ustar" /stage/moodle/public/theme/ustar
}
sources /source/.stage-input/role-bot-baseline/moodle
USTAR_STAGE_PREFIX=botold_ php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
    --adminemail=stage@example.invalid --fullname='USTAR frozen2803' --shortname=ustar-role-baseline > /artifacts/connect-role-bot-install2803.log 2>&1
python3 /source/tests/stage/connect_role_bot_native.py --legacy-baseline
sources /source/.stage-input/role-bot-candidate/moodle
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/connect-role-bot-upgrade2900.log 2>&1
python3 /source/tests/stage/connect_role_bot_native.py --after-upgrade
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/connect-role-bot-repeat2900.log 2>&1
python3 /source/tests/stage/connect_role_bot_native.py --after-repeat
python3 /source/tests/stage/legacy_103_projection.py
# Subsequent tests exercise the actual current candidate, not the historical overlay.
sources /source/moodle

# Exact2900 with the accepted legacy index pair upgrades to the current candidate twice.
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/route-access-upgrade.log 2>&1
USTAR_STAGE_PREFIX=botold_ php /source/tests/stage/schema_snapshot.php > /artifacts/route-access-upgraded.json
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/route-access-repeat.log 2>&1
USTAR_STAGE_PREFIX=botold_ php /source/tests/stage/schema_snapshot.php > /artifacts/route-access-repeat.json
cmp /artifacts/route-access-upgraded.json /artifacts/route-access-repeat.json
python3 /source/tests/stage/route_access_native.py --verify
php admin/cli/purge_caches.php > /artifacts/route-access-purge.log 2>&1
