#!/usr/bin/env bash
# Actual2200→2300/repeat/paired restore2200, without activating the new sender profile.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test -d /source/.stage-input/con02-update-target/moodle/local/ustar
test ! -e /stage/telegram-presentation-state.json
tg_sources() {
  test "$PWD" = /stage/moodle
  rm -rf public/local/ustar public/theme/ustar
  cp -a "$1/local/ustar" public/local/ustar
  cp -a "$1/theme/ustar" public/theme/ustar
}
tg_sources /source/.stage-input/con02-update-target/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/telegram-presentation-baseline.log 2>&1
php /source/tests/stage/telegram_presentation_probe.php seed
php /source/tests/stage/schema_snapshot.php > /artifacts/telegram-presentation-before-schema.json
TG_PAIR=/stage/telegram-presentation-pair
(
  umask 077
  mkdir "$TG_PAIR"
  pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$TG_PAIR/database.dump"
  tar -C /stage/moodle/public -czf "$TG_PAIR/source.tar.gz" local/ustar theme/ustar
  tar -C /stage/data -czf "$TG_PAIR/moodledata.tar.gz" stage_
  cp config.php "$TG_PAIR/config.php"
  sha256sum "$TG_PAIR/"* > "$TG_PAIR/SHA256SUMS"
)
tg_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/telegram-presentation-upgrade.log 2>&1
php /source/tests/stage/telegram_presentation_probe.php target
php /source/tests/stage/schema_snapshot.php > /artifacts/telegram-presentation-after-schema.json
cmp /artifacts/telegram-presentation-before-schema.json /artifacts/telegram-presentation-after-schema.json
php admin/cli/upgrade.php --non-interactive > /artifacts/telegram-presentation-repeat.log 2>&1
php /source/tests/stage/telegram_presentation_probe.php target
php /source/tests/stage/schema_snapshot.php > /artifacts/telegram-presentation-repeat-schema.json
cmp /artifacts/telegram-presentation-after-schema.json /artifacts/telegram-presentation-repeat-schema.json
sha256sum --check --strict "$TG_PAIR/SHA256SUMS" > /artifacts/telegram-presentation-pair-checksums.txt
rm -rf public/local/ustar public/theme/ustar /stage/data/stage_
tar -C /stage/moodle/public -xzf "$TG_PAIR/source.tar.gz"
tar -C /stage/data -xzf "$TG_PAIR/moodledata.tar.gz"
cp "$TG_PAIR/config.php" config.php
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > /artifacts/telegram-presentation-drop.log 2>&1
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > /artifacts/telegram-presentation-create.log 2>&1
pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$TG_PAIR/database.dump" > /artifacts/telegram-presentation-restore.log 2>&1
php /source/tests/stage/telegram_presentation_probe.php restored
cmp config.php "$TG_PAIR/config.php"
diff -qr public/local/ustar /source/.stage-input/con02-update-target/moodle/local/ustar > /artifacts/telegram-presentation-source-restore.txt
diff -qr public/theme/ustar /source/.stage-input/con02-update-target/moodle/theme/ustar > /artifacts/telegram-presentation-theme-restore.txt
php /source/tests/stage/schema_snapshot.php > /artifacts/telegram-presentation-restored-schema.json
cmp /artifacts/telegram-presentation-before-schema.json /artifacts/telegram-presentation-restored-schema.json
echo 'TELEGRAM_PRESENTATION_UPGRADE_RECOVERY=PASS' | tee /artifacts/telegram-presentation-result.txt
