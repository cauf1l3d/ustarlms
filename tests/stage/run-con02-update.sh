#!/usr/bin/env bash
# Actual direct2000+overlay→2200/repeat, logical DB+source/config/data paired recovery.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test -d /source/.stage-input/con02-update-baseline/moodle/local/ustar
test -d /source/.stage-input/con02-update-target/moodle/local/ustar
test ! -e /stage/con02-update-state.json
test -f /stage/con02-ack-pair/database.dump
cu_sources() {
  test "$PWD" = /stage/moodle
  rm -rf public/local/ustar public/theme/ustar
  cp -a "$1/local/ustar" public/local/ustar
  cp -a "$1/theme/ustar" public/theme/ustar
}
cu_restore_db() {
  psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > /stage/con02-update-db-drop.log 2>&1
  psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > /stage/con02-update-db-create.log 2>&1
  pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$1" > /stage/con02-update-db-restore.log 2>&1
}
# Reset the preceding disposable callback fixture to its original2000 pair.
cu_restore_db /stage/con02-ack-pair/database.dump
rm -rf /stage/data/stage_
tar -C /stage/data -xzf /stage/con02-ack-pair/moodledata.tar.gz
cp /stage/con02-ack-pair/config.php config.php
cu_sources /source/.stage-input/con02-update-baseline/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-update-baseline-upgrade.log 2>&1
php /source/tests/stage/con02_update_probe.php seed
php /source/tests/stage/con02_update_probe.php baseline
printf 'preserved-private-data\n' > /stage/data/stage_/con02-update-business.marker
cu_sql() {
  psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 -f "$1" > "$2"
}
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-baseline.json
CU_PAIR=/stage/con02-update-pair
(
  umask 077
  mkdir "$CU_PAIR"
  pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$CU_PAIR/database.dump"
  tar -C /stage/moodle/public -czf "$CU_PAIR/source.tar.gz" local/ustar theme/ustar
  tar -C /stage/data -czf "$CU_PAIR/moodledata.tar.gz" stage_
  cp config.php "$CU_PAIR/config.php"
  sha256sum "$CU_PAIR/"* > "$CU_PAIR/SHA256SUMS"
)
cu_sources /source/.stage-input/con02-update-target/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-update-direct-upgrade.log 2>&1
php /source/tests/stage/con02_update_probe.php target
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-target.json
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-update-repeat.log 2>&1
php /source/tests/stage/con02_update_probe.php target
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-repeat.json
cmp /artifacts/con02-update-target.json /artifacts/con02-update-repeat.json
# fresh_ was installed from the same immutable product source by run-connect.
cu_sql /source/.stage-input/con02-update-fresh.sql /artifacts/con02-update-fresh.json
for mutation in column index task extra; do
  cu_sql "/source/.stage-input/con02-update-${mutation}.sql" "/artifacts/con02-update-mutated-${mutation}.json"
done
sha256sum --check --strict "$CU_PAIR/SHA256SUMS" > /artifacts/con02-update-private-pair-verified.txt
rm -rf public/local/ustar public/theme/ustar /stage/data/stage_
tar -C public -xzf "$CU_PAIR/source.tar.gz"
tar -C /stage/data -xzf "$CU_PAIR/moodledata.tar.gz"
cp "$CU_PAIR/config.php" config.php
cu_restore_db "$CU_PAIR/database.dump"
diff -qr public/local/ustar /source/.stage-input/con02-update-baseline/moodle/local/ustar > /artifacts/con02-update-source-restored.txt
diff -qr public/theme/ustar /source/.stage-input/con02-update-baseline/moodle/theme/ustar > /artifacts/con02-update-theme-restored.txt
cmp config.php "$CU_PAIR/config.php"
grep -qx 'preserved-private-data' /stage/data/stage_/con02-update-business.marker
php /source/tests/stage/con02_update_probe.php restored
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-restored.json
cmp /artifacts/con02-update-baseline.json /artifacts/con02-update-restored.json
echo 'CON02_UPDATE_NATIVE_LIFECYCLE=PASS' | tee /artifacts/con02-update-lifecycle.txt
