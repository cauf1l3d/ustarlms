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
sources /source/.stage-input/library-baseline/moodle

# Exact2900 with the accepted legacy index pair upgrades to the current candidate twice.
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/route-access-upgrade.log 2>&1
USTAR_STAGE_PREFIX=botold_ php /source/tests/stage/schema_snapshot.php > /artifacts/route-access-upgraded.json
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/route-access-repeat.log 2>&1
USTAR_STAGE_PREFIX=botold_ php /source/tests/stage/schema_snapshot.php > /artifacts/route-access-repeat.json
cmp /artifacts/route-access-upgraded.json /artifacts/route-access-repeat.json
python3 /source/tests/stage/route_access_native.py --verify
# The delivered successor starts from this exact accepted app1100 baseline.
python3 -c 'import sys,json;sys.path[:0]=["/source/tests/stage","/source/scripts/infra"];from connect_role_bot_native import query;from connect_academy_installer import db_query;from pathlib import Path;Path("/artifacts/library-baseline-native.json").write_text(json.dumps(query(db_query("botold_"))))'
sources /source/moodle
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/library-upgrade.log 2>&1
USTAR_STAGE_PREFIX=botold_ php /source/tests/stage/schema_snapshot.php > /artifacts/library-upgraded.json
USTAR_STAGE_PREFIX=botold_ php admin/cli/upgrade.php --non-interactive > /artifacts/library-repeat.log 2>&1
USTAR_STAGE_PREFIX=botold_ php /source/tests/stage/schema_snapshot.php > /artifacts/library-repeat.json
cmp /artifacts/library-upgraded.json /artifacts/library-repeat.json
python3 /source/tests/stage/library_native.py --verify
php admin/cli/purge_caches.php > /artifacts/library-purge.log 2>&1
