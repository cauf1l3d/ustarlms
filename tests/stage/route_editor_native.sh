#!/usr/bin/env bash
# Fast isolated controller feedback before the broader upgrade/rollback matrix completes.
set -Eeuo pipefail
test "$PWD" = /stage
test ! -e /stage/moodle
mkdir -p /artifacts /stage/data/stage_
cp -a /opt/moodle /stage/moodle
cp /source/tests/stage/config.php /stage/moodle/config.php
cp -a /source/moodle/local/ustar /stage/moodle/public/local/ustar
cp -a /source/moodle/theme/ustar /stage/moodle/public/theme/ustar
cd /stage/moodle
php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
    --adminemail=stage@example.invalid --fullname='Route editor synthetic stage' --shortname=route-editor \
    > /artifacts/route-editor-install.log 2>&1
USTAR_STAGE_PREFIX=stage_ bash /source/tests/stage/authenticated_shell_smoke.sh route-editor \
    2>&1 | tee /artifacts/route-editor-native.log
