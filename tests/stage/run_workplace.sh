#!/usr/bin/env bash
set -Eeuo pipefail
test "$PWD" = /stage
test ! -e /stage/moodle
test -d /source/.stage-input/workplace-baseline/moodle/local/ustar
mkdir -p /artifacts /stage/data/stage_ /stage/data/fresh_ /stage/phpunitdata
cp -a /opt/moodle /stage/moodle
cp /source/tests/stage/config.php /stage/moodle/config.php
cd /stage/moodle

install_site() {
    php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
        --adminemail=stage@example.invalid --fullname='USTAR workplace fixture' --shortname=ustar-workplace
}

export USTAR_STAGE_PREFIX=stage_
export USTAR_DISPOSABLE_FIXTURE=workplace
cp -a /source/.stage-input/workplace-baseline/moodle/local/ustar public/local/
cp -a /source/.stage-input/workplace-baseline/moodle/theme/ustar public/theme/
install_site > /artifacts/workplace-install-baseline.log 2>&1
php /source/tests/stage/workplace_fixture.php seed > /artifacts/workplace-fixture.json
php /source/tests/stage/schema_snapshot.php > /artifacts/workplace-schema-before.json
rm -rf public/local/ustar
cp -a /source/moodle/local/ustar public/local/
php admin/cli/upgrade.php --non-interactive > /artifacts/workplace-upgrade.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/workplace-schema-upgrade.json
cmp /artifacts/workplace-schema-before.json /artifacts/workplace-schema-upgrade.json
php admin/cli/upgrade.php --non-interactive > /artifacts/workplace-repeat.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/workplace-schema-repeat.json
cmp /artifacts/workplace-schema-upgrade.json /artifacts/workplace-schema-repeat.json
USTAR_STAGE_PREFIX=fresh_ install_site > /artifacts/workplace-install-fresh.log 2>&1
USTAR_STAGE_PREFIX=fresh_ php /source/tests/stage/schema_snapshot.php > /artifacts/workplace-schema-fresh.json
cmp /artifacts/workplace-schema-upgrade.json /artifacts/workplace-schema-fresh.json
echo 'WORKPLACE_FRESH_UPGRADE_REPEAT_SCHEMA=OK'

php public/admin/tool/phpunit/cli/init.php --disable-composer > /artifacts/workplace-phpunit-init.log 2>&1
for suite in workplace communication task_workspace connect_bot connect_identity connect_invitation connect_notification; do
    php vendor/bin/phpunit --display-warnings --fail-on-warning --display-notices --fail-on-notice --fail-on-empty-test-suite \
        --log-junit "/artifacts/workplace-$suite.xml" "public/local/ustar/tests/${suite}_test.php" \
        2>&1 | tee "/artifacts/workplace-$suite.log"
done
USTAR_DISPOSABLE_FIXTURE=briefing USTAR_STAGE_PREFIX=fresh_ php /source/tests/stage/briefing_smoke.php /stage/moodle \
    | tee /artifacts/workplace-briefing-smoke.log

USTAR_STAGE_HTTP_ROOT=http://127.0.0.1:8087 php -d upload_max_filesize=25M -d post_max_size=55M -d memory_limit=256M \
    -S 0.0.0.0:8087 -t public > /artifacts/workplace-http.log 2>&1 &
webpid=$!
trap 'kill "$webpid" 2>/dev/null || true; wait "$webpid" 2>/dev/null || true' EXIT
for attempt in $(seq 1 30); do
    if curl --fail --silent --max-time 2 http://127.0.0.1:8087/login/index.php >/dev/null; then
        touch /artifacts/workplace-browser-ready
        wait "$webpid"
        exit
    fi
    sleep 1
done
exit 1
