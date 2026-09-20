#!/usr/bin/env bash
set -euo pipefail
test "$PWD" = /stage
test -d /source/.stage-input/baseline/moodle/local/ustar
test ! -e /stage/moodle
mkdir -p /artifacts /stage/data/stage_ /stage/data/fresh_ /stage/phpunitdata
cp -a /opt/moodle /stage/moodle
cp /source/tests/stage/config.php /stage/moodle/config.php
install_sources() {
    local source_path="$1"
    # These paths are inside the newly created disposable /stage tree only.
    rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar
    cp -a "$source_path/local/ustar" /stage/moodle/public/local/ustar
    cp -a "$source_path/theme/ustar" /stage/moodle/public/theme/ustar
}
install_site() {
    php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
        --adminemail=stage@example.invalid --fullname='USTAR synthetic stage' --shortname=ustar-stage
}
install_sources /source/.stage-input/baseline/moodle
cd /stage/moodle
install_site > /artifacts/install-baseline.log 2>&1
install_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/upgrade.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/schema-upgraded.json
php admin/cli/upgrade.php --non-interactive > /artifacts/upgrade-repeat.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/schema-repeat.json
cmp /artifacts/schema-upgraded.json /artifacts/schema-repeat.json
USTAR_STAGE_PREFIX=fresh_ install_site > /artifacts/install-fresh.log 2>&1
USTAR_STAGE_PREFIX=fresh_ php /source/tests/stage/schema_snapshot.php > /artifacts/schema-fresh.json
cmp /artifacts/schema-upgraded.json /artifacts/schema-fresh.json
php public/admin/tool/phpunit/cli/init.php > /artifacts/phpunit-init.log 2>&1
vendor/bin/phpunit --testsuite local_ustar_testsuite --log-junit /artifacts/phpunit.xml | tee /artifacts/phpunit.log
php -r 'echo "PHP=" . PHP_VERSION . PHP_EOL;' > /artifacts/runtime.txt
git rev-parse HEAD >> /artifacts/runtime.txt
psql -h db -U ustar_fixture -d ustar_stage1 -Atc 'SELECT version();' >> /artifacts/runtime.txt
echo 'INSTALL_UPGRADE_REPEAT_AND_DB_TESTS=PASS' | tee /artifacts/stage-result.txt
