#!/usr/bin/env bash
set -euo pipefail
test "$PWD" = /stage
test ! -e /stage/moodle
test -d /source/.stage-input/connect-baseline/moodle/local/ustar
trap 'code=$?; if [ "$code" -ne 0 ]; then tail -n 70 /artifacts/connect-*.log 2>/dev/null || true; fi; exit "$code"' EXIT
mkdir -p /artifacts /stage/data/stage_ /stage/data/fresh_ /stage/phpunitdata
cp -a /opt/moodle /stage/moodle
cp /source/tests/stage/config.php /stage/moodle/config.php
install_sources() {
    # Newly created isolated tmpfs only; no host/runtime mounts are modified.
    rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar
    cp -a "$1/local/ustar" /stage/moodle/public/local/ustar
    cp -a "$1/theme/ustar" /stage/moodle/public/theme/ustar
}
install_site() {
    php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
        --adminemail=stage@example.invalid --fullname='USTAR Connect isolated fixture' --shortname=ustar-connect
}
cd /stage/moodle
install_site > /artifacts/connect-install-core.log 2>&1
install_sources /source/.stage-input/connect-baseline/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-baseline.log 2>&1
install_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-upgrade.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-upgraded.json
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-repeat.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-repeat.json
cmp /artifacts/connect-upgraded.json /artifacts/connect-repeat.json
USTAR_STAGE_PREFIX=fresh_ install_site > /artifacts/connect-fresh.log 2>&1
USTAR_STAGE_PREFIX=fresh_ php /source/tests/stage/schema_snapshot.php > /artifacts/connect-fresh.json
cmp /artifacts/connect-upgraded.json /artifacts/connect-fresh.json
php public/admin/tool/phpunit/cli/init.php --disable-composer > /artifacts/connect-phpunit-init.log 2>&1
php vendor/bin/phpunit --testsuite local_ustar_testsuite --filter 'connect_(identity|entry|bridge|signup|link_management|notification)_test|organization_identity_test' \
    --fail-on-notice --display-notices --log-junit /artifacts/connect-phpunit.xml \
    2>&1 | tee /artifacts/connect-phpunit.log
php /source/tests/stage/connect_concurrency.php 2>&1 | tee /artifacts/connect-concurrency.log
echo 'CONNECT_SCHEMA_UPGRADE_REPEAT_FRESH_AND_DB=PASS' | tee /artifacts/connect-result.txt
