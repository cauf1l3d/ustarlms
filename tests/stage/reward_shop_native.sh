#!/usr/bin/env bash
set -Eeuo pipefail
test "$PWD" = /stage
test ! -e /stage/moodle
test -d /source/.stage-input/reward-shop-baseline/moodle/local/ustar
mkdir -p /artifacts /stage/data/stage_ /stage/data/fresh_ /stage/phpunitdata
trap 'code=$?; if (( code )); then tail -n 45 /artifacts/reward-shop-*.log 2>/dev/null || true; fi; exit "$code"' EXIT
cp -a /opt/moodle /stage/moodle
cp /source/tests/stage/config.php /stage/moodle/config.php
cd /stage/moodle

install_sources() {
    rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar
    cp -a "$1/local/ustar" /stage/moodle/public/local/ustar
    cp -a "$1/theme/ustar" /stage/moodle/public/theme/ustar
}
install_site() {
    php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
        --adminemail=stage@example.invalid --fullname='USTAR reward shop fixture' --shortname=ustar-rewards
}

export USTAR_STAGE_PREFIX=stage_
install_sources /source/.stage-input/reward-shop-baseline/moodle
install_site > /artifacts/reward-shop-baseline.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/reward-shop-before.json
install_sources /source/.stage-input/reward-shop-installed/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/reward-shop-installed.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/reward-shop-installed.json
install_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/reward-shop-successor.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/reward-shop-after.json
cmp /artifacts/reward-shop-installed.json /artifacts/reward-shop-after.json
php admin/cli/upgrade.php --non-interactive > /artifacts/reward-shop-repeat.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/reward-shop-repeat.json
cmp /artifacts/reward-shop-after.json /artifacts/reward-shop-repeat.json
USTAR_STAGE_PREFIX=fresh_ install_site > /artifacts/reward-shop-fresh.log 2>&1
USTAR_STAGE_PREFIX=fresh_ php /source/tests/stage/schema_snapshot.php > /artifacts/reward-shop-fresh.json
cmp /artifacts/reward-shop-after.json /artifacts/reward-shop-fresh.json
php public/admin/tool/phpunit/cli/init.php --disable-composer > /artifacts/reward-shop-phpunit-init.log 2>&1
php vendor/bin/phpunit --display-notices --fail-on-notice --log-junit /artifacts/reward-shop-unit.xml \
    public/local/ustar/tests/reward_shop_test.php 2>&1 | tee /artifacts/reward-shop-unit.log
php /source/tests/stage/reward_shop_fixture.php 2>&1 | tee /artifacts/reward-shop-fixture.log
features=(team_achievements connect_announcements)
if [[ ${USTAR_STAGE_BROADCAST_WIRE:-} = 1 ]]; then
    features+=(connect_notification connect_bot feed_roles)
fi
for feature in "${features[@]}"; do
    php vendor/bin/phpunit --display-notices --fail-on-notice --fail-on-empty-test-suite \
        --log-junit "/artifacts/reward-shop-${feature}.xml" "public/local/ustar/tests/${feature}_test.php" \
        2>&1 | tee "/artifacts/reward-shop-${feature}.log"
done
if [[ ${USTAR_STAGE_BROADCAST_WIRE:-} = 1 ]]; then
    (
        set -Eeuo pipefail
        export USTAR_STAGE_BROADCAST_FIXTURE=1
        php /source/tests/stage/connect_broadcast_http_fixture.php
        php -S 127.0.0.1:18087 /source/tests/stage/connect_broadcast_http_fixture.php \
            > /artifacts/reward-shop-broadcast-http.log 2>&1 &
        bridgepid=$!
        trap 'kill "$bridgepid" 2>/dev/null || true; wait "$bridgepid" 2>/dev/null || true' EXIT
        for attempt in $(seq 1 20); do
            if curl --silent --max-time 2 http://127.0.0.1:18087/ -o /dev/null; then break; fi
            sleep 1
        done
        python3 /source/tests/stage/connect_broadcast_http.py
    ) 2>&1 | tee /artifacts/reward-shop-broadcast-wire.log
fi
echo 'REWARD_SHOP_SCHEMA_AND_DB=PASS'
bash /source/tests/stage/authenticated_shell_smoke.sh studio-browser
