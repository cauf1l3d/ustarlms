#!/usr/bin/env bash
set -euo pipefail
test "$PWD" = /stage
test ! -e /stage/moodle
test -d /source/.stage-input/connect-baseline/moodle/local/ustar
trap 'code=$?; if [ "$code" -ne 0 ]; then tail -n 70 /artifacts/connect-*.log /artifacts/con02-ack-*.log 2>/dev/null || true; fi; exit "$code"' EXIT
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
SNAP=/stage/connect-snapshot
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-baseline-schema.json
php -r 'define("CLI_SCRIPT", true); require "/stage/moodle/config.php"; set_config("connect_release_marker", "baseline", "local_ustar");'
printf 'baseline-state\n' > /stage/data/stage_/connect-baseline.marker
php /source/tests/stage/connect_release_probe.php baseline > /artifacts/connect-pair-before.json
psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
    -f /source/.stage-input/connect-installer.sql > /artifacts/connect-installer-baseline.json
# This is a disposable DB/config/data fixture, NOT a production backup tool.
# Snapshot stays in tmpfs; only sanitized assertions/hashes become artifacts.
(
    # Keep only the snapshot private. Host-runner artifact upload must still
    # read the sanitized reports created outside this subshell.
    umask 077
    mkdir "$SNAP"
    pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$SNAP/database.dump"
    tar -C /stage/moodle/public -czf "$SNAP/source.tar.gz" local/ustar theme/ustar
    tar -C /stage/data -czf "$SNAP/moodledata.tar.gz" stage_
    cp /stage/moodle/config.php "$SNAP/config.php"
    sha256sum "$SNAP/database.dump" "$SNAP/source.tar.gz" "$SNAP/moodledata.tar.gz" "$SNAP/config.php" > "$SNAP/SHA256SUMS"
)
install_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-upgrade.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-upgraded.json
psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
    -f /source/.stage-input/connect-installer.sql > /artifacts/connect-installer-candidate.json
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-repeat.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-repeat.json
psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
    -f /source/.stage-input/connect-installer.sql > /artifacts/connect-installer-repeat.json
cmp /artifacts/connect-upgraded.json /artifacts/connect-repeat.json
USTAR_STAGE_PREFIX=fresh_ install_site > /artifacts/connect-fresh.log 2>&1
USTAR_STAGE_PREFIX=fresh_ php /source/tests/stage/schema_snapshot.php > /artifacts/connect-fresh.json
cmp /artifacts/connect-upgraded.json /artifacts/connect-fresh.json
php public/admin/tool/phpunit/cli/init.php --disable-composer > /artifacts/connect-phpunit-init.log 2>&1
php vendor/bin/phpunit --testsuite local_ustar_testsuite --filter 'connect_notification_ack_test::test_(first_ack_timestamp|mark_all_read)' \
    --fail-on-notice --display-notices > /artifacts/con02-ack-writer-phpunit.log 2>&1
php /source/tests/stage/con02_ack_concurrency.php 2>&1 | tee /artifacts/con02-ack-concurrency.log
php vendor/bin/phpunit --testsuite local_ustar_testsuite --filter 'connect_(identity|entry|bridge|signup|link_management|notification|notification_ack|notification_action|actor)_test|organization_identity_test' \
    --fail-on-notice --display-notices --log-junit /artifacts/connect-phpunit.xml \
    2>&1 | tee /artifacts/connect-phpunit.log
php /source/tests/stage/connect_concurrency.php 2>&1 | tee /artifacts/connect-concurrency.log
bash /source/tests/stage/run-academy-https-moodle.sh
echo 'CONNECT_SCHEMA_UPGRADE_REPEAT_FRESH_AND_DB=PASS' | tee /artifacts/connect-result.txt

# Prove restoration of the accepted baseline, not a files-only downgrade.
php -r 'define("CLI_SCRIPT", true); require "/stage/moodle/config.php"; set_config("connect_release_marker", "candidate", "local_ustar");'
printf 'candidate-only\n' > /stage/data/stage_/connect-candidate-only.marker
printf '\n// Candidate-only disposable fixture marker.\n' >> /stage/moodle/config.php
php /source/tests/stage/connect_release_probe.php candidate > /artifacts/connect-pair-candidate.json
sha256sum --check --strict "$SNAP/SHA256SUMS" > /artifacts/connect-pair-checksums.txt
rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar /stage/data/stage_
tar -C /stage/moodle/public -xzf "$SNAP/source.tar.gz"
tar -C /stage/data -xzf "$SNAP/moodledata.tar.gz"
cp "$SNAP/config.php" /stage/moodle/config.php
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 \
    -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > /artifacts/connect-pair-drop.log 2>&1
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 \
    -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > /artifacts/connect-pair-create.log 2>&1
pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$SNAP/database.dump" \
    > /artifacts/connect-pair-restore.log 2>&1
diff -qr /stage/moodle/public/local/ustar /source/.stage-input/connect-baseline/moodle/local/ustar > /artifacts/connect-pair-plugin-diff.txt
diff -qr /stage/moodle/public/theme/ustar /source/.stage-input/connect-baseline/moodle/theme/ustar > /artifacts/connect-pair-theme-diff.txt
cmp "$SNAP/config.php" /stage/moodle/config.php
grep -qx 'baseline-state' /stage/data/stage_/connect-baseline.marker
test ! -e /stage/data/stage_/connect-candidate-only.marker
php /source/tests/stage/connect_release_probe.php baseline > /artifacts/connect-pair-restored.json
psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
    -f /source/.stage-input/connect-installer.sql > /artifacts/connect-installer-restored.json
cmp /artifacts/connect-pair-before.json /artifacts/connect-pair-restored.json
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-pair-restored-schema.json
cmp /artifacts/connect-baseline-schema.json /artifacts/connect-pair-restored-schema.json
php admin/cli/upgrade.php --non-interactive > /artifacts/connect-pair-repeat.log 2>&1
php /source/tests/stage/schema_snapshot.php > /artifacts/connect-pair-repeat-schema.json
cmp /artifacts/connect-baseline-schema.json /artifacts/connect-pair-repeat-schema.json
echo 'CONNECT_ACCEPTED_BASELINE_PAIRED_ROLLBACK=PASS' | tee -a /artifacts/connect-result.txt
source /source/tests/stage/run-chat-link-update.sh

bash /source/tests/stage/run-con02-ack.sh
