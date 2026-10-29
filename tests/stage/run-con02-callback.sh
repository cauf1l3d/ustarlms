#!/usr/bin/env bash
# Actual2100→2200/repeat/history/paired restore. Private snapshots never become artifacts.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test -d /source/.stage-input/con02-a1/moodle/local/ustar
test ! -e /stage/con02-callback-state.json
cb_sources() {
  test "$PWD" = /stage/moodle
  rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar
  cp -a "$1/local/ustar" /stage/moodle/public/local/ustar
  cp -a "$1/theme/ustar" /stage/moodle/public/theme/ustar
}
cb_sources /source/.stage-input/con02-a1/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-callback-baseline.log 2>&1
php /source/tests/stage/con02_callback_probe.php seed
php /source/tests/stage/schema_snapshot.php > /artifacts/con02-callback-before-schema.json
CB_PAIR=/stage/con02-callback-pair
(
  umask 077
  mkdir "$CB_PAIR"
  pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$CB_PAIR/database.dump"
  tar -C /stage/moodle/public -czf "$CB_PAIR/source.tar.gz" local/ustar theme/ustar
  tar -C /stage/data -czf "$CB_PAIR/moodledata.tar.gz" stage_
  cp config.php "$CB_PAIR/config.php"
  sha256sum "$CB_PAIR/"* > "$CB_PAIR/SHA256SUMS"
)
# Frozen callback/presentation contract; the current103-table app has its own gate.
cb_sources /source/.stage-input/telegram-presentation-target/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-callback-upgrade.log 2>&1
php /source/tests/stage/con02_callback_probe.php verify
php /source/tests/stage/schema_snapshot.php > /artifacts/con02-callback-after-schema.json
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-callback-repeat.log 2>&1
php /source/tests/stage/con02_callback_probe.php verify
php /source/tests/stage/schema_snapshot.php > /artifacts/con02-callback-repeat-schema.json
cmp /artifacts/con02-callback-after-schema.json /artifacts/con02-callback-repeat-schema.json
php /source/tests/stage/con02_callback_probe.php wire
python3 /source/tests/stage/con02_callback_wire.py v1
python3 /source/tests/stage/con02_callback_wire.py v2
php /source/tests/stage/con02_callback_probe.php wire_v3
python3 /source/tests/stage/con02_callback_wire.py v3
rm -f /stage/con02-callback-wire.json
php /source/tests/stage/con02_callback_concurrency.php 2>&1 | tee /artifacts/con02-callback-concurrency.log
sha256sum --check --strict "$CB_PAIR/SHA256SUMS" > /artifacts/con02-callback-pair-checksums.txt
rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar /stage/data/stage_
tar -C /stage/moodle/public -xzf "$CB_PAIR/source.tar.gz"
tar -C /stage/data -xzf "$CB_PAIR/moodledata.tar.gz"
cp "$CB_PAIR/config.php" config.php
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > /artifacts/con02-callback-drop.log 2>&1
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > /artifacts/con02-callback-create.log 2>&1
pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$CB_PAIR/database.dump" > /artifacts/con02-callback-restore.log 2>&1
php /source/tests/stage/con02_callback_probe.php restored
diff -qr /stage/moodle/public/local/ustar /source/.stage-input/con02-a1/moodle/local/ustar > /artifacts/con02-callback-source-restore.txt
diff -qr /stage/moodle/public/theme/ustar /source/.stage-input/con02-a1/moodle/theme/ustar > /artifacts/con02-callback-theme-restore.txt
cmp config.php "$CB_PAIR/config.php"
php /source/tests/stage/schema_snapshot.php > /artifacts/con02-callback-restored-schema.json
cmp /artifacts/con02-callback-before-schema.json /artifacts/con02-callback-restored-schema.json
echo 'CON02_CALLBACK_UPGRADE_RECOVERY=PASS' | tee /artifacts/con02-callback-result.txt
