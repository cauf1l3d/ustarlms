#!/usr/bin/env bash
set -Eeuo pipefail
test "$PWD" = /stage
test ! -e /stage/moodle
test -d /source/.stage-input/connect-invitation-baseline/moodle/local/ustar
mkdir -p /artifacts /stage/data/stage_ /stage/data/fresh_ /stage/phpunitdata
trap 'code=$?; if (( code )); then tail -n 40 /artifacts/connect-invitation-*.log 2>/dev/null || true; fi; exit "$code"' EXIT
cp -a /opt/moodle /stage/moodle
cp /source/tests/stage/config.php /stage/moodle/config.php
cd /stage/moodle

install_sources() {
    rm -rf public/local/ustar public/theme/ustar
    cp -a "$1/local/ustar" public/local/ustar
    cp -a "$1/theme/ustar" public/theme/ustar
}
install_site() {
    php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
        --adminemail=stage@example.invalid --fullname='USTAR Connect invitation fixture' --shortname=ustar-invites
}
snapshot() {
    php /source/tests/stage/schema_snapshot.php > "/artifacts/connect-invitation-$1.json"
}

export USTAR_STAGE_PREFIX=stage_
install_sources /source/.stage-input/connect-invitation-baseline/moodle
install_site > /artifacts/connect-invitation-baseline.log 2>&1
snapshot before
install_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-invitation-upgrade.log 2>&1
snapshot after
if [[ ${USTAR_STAGE_OPERATOR_UPDATE:-} == 1 ]]; then
    cmp /artifacts/connect-invitation-before.json /artifacts/connect-invitation-after.json
elif cmp -s /artifacts/connect-invitation-before.json /artifacts/connect-invitation-after.json; then
    echo 'Expected invitation columns to be added'
    exit 1
fi
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-invitation-repeat.log 2>&1
snapshot repeat
cmp /artifacts/connect-invitation-after.json /artifacts/connect-invitation-repeat.json
USTAR_STAGE_PREFIX=fresh_ install_site > /artifacts/connect-invitation-fresh.log 2>&1
USTAR_STAGE_PREFIX=fresh_ snapshot fresh
cmp /artifacts/connect-invitation-after.json /artifacts/connect-invitation-fresh.json
if [[ ${USTAR_STAGE_OPERATOR_UPDATE:-} == 1 ]]; then
    bash /source/tests/stage/authenticated_shell_smoke.sh operator
    bash /source/tests/stage/connect_operator_http.sh 2>&1 | tee /artifacts/connect-invitation-operator-http.log
fi
php public/admin/tool/phpunit/cli/init.php --disable-composer > /artifacts/connect-invitation-phpunit-init.log 2>&1
for suite in connect_invitation connect_bot connect_identity connect_notification; do
    php vendor/bin/phpunit --display-notices --fail-on-notice --fail-on-empty-test-suite \
        --log-junit "/artifacts/connect-invitation-${suite}.xml" "public/local/ustar/tests/${suite}_test.php" \
        2>&1 | tee "/artifacts/connect-invitation-${suite}.log"
done
