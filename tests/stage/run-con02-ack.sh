#!/usr/bin/env bash
# Disposable actual2000→2100, HTTP contract and paired recovery. No host mounts/credentials.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test -d /source/.stage-input/connect-2000/moodle/local/ustar
test -d /source/.stage-input/con02-old-worker/connect-worker/ustar_connect
test ! -e /stage/con02-ack-state.json
con02_install_sources() {
  test "$PWD" = /stage/moodle
  rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar
  cp -a "$1/local/ustar" /stage/moodle/public/local/ustar
  cp -a "$1/theme/ustar" /stage/moodle/public/theme/ustar
}
con02_install_sources /source/.stage-input/connect-2000/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-ack-baseline-upgrade.log 2>&1
php /source/tests/stage/con02_ack_probe.php seed
php /source/tests/stage/schema_snapshot.php > /artifacts/con02-ack-before-schema.json
CON02_PAIR=/stage/con02-ack-pair
(
  umask 077
  mkdir "$CON02_PAIR"
  pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$CON02_PAIR/database.dump"
  tar -C /stage/moodle/public -czf "$CON02_PAIR/source.tar.gz" local/ustar theme/ustar
  tar -C /stage/data -czf "$CON02_PAIR/moodledata.tar.gz" stage_
  cp /stage/moodle/config.php "$CON02_PAIR/config.php"
  sha256sum "$CON02_PAIR/"* > "$CON02_PAIR/SHA256SUMS"
)
con02_install_sources /source/.stage-input/con02-a1/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-ack-upgrade.log 2>&1
php /source/tests/stage/con02_ack_probe.php verify
php /source/tests/stage/schema_snapshot.php > /artifacts/con02-ack-after-schema.json
cmp /artifacts/con02-ack-before-schema.json /artifacts/con02-ack-after-schema.json
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-ack-repeat.log 2>&1
php /source/tests/stage/con02_ack_probe.php verify
php /source/tests/stage/con02_ack_probe.php wire
python3 /source/tests/stage/con02_ack_wire.py

con02_http_pid=''
con02_cleanup() {
  if [ -n "$con02_http_pid" ]; then
    kill "$con02_http_pid" 2>/dev/null || true
    wait "$con02_http_pid" 2>/dev/null || true
  fi
}
trap con02_cleanup EXIT
USTAR_STAGE_HTTP_ROOT=http://127.0.0.1:8087 php -S 127.0.0.1:8087 -t public > /stage/con02-ack-http.log 2>&1 &
con02_http_pid=$!
for attempt in $(seq 1 50); do
  if curl --noproxy '*' -fsS http://127.0.0.1:8087/login/index.php -o /dev/null; then break; fi
  sleep 0.2
done
python3 /source/tests/stage/con02_ack_http.py
con02_cleanup
con02_http_pid=''
trap - EXIT

sha256sum --check --strict "$CON02_PAIR/SHA256SUMS" > /artifacts/con02-ack-pair-checksums.txt
rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar /stage/data/stage_
tar -C /stage/moodle/public -xzf "$CON02_PAIR/source.tar.gz"
tar -C /stage/data -xzf "$CON02_PAIR/moodledata.tar.gz"
cp "$CON02_PAIR/config.php" /stage/moodle/config.php
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > /artifacts/con02-ack-drop.log 2>&1
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > /artifacts/con02-ack-create.log 2>&1
pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$CON02_PAIR/database.dump" > /artifacts/con02-ack-restore.log 2>&1
php /source/tests/stage/con02_ack_probe.php restored
diff -qr /stage/moodle/public/local/ustar /source/.stage-input/connect-2000/moodle/local/ustar > /artifacts/con02-ack-restored-source.txt
diff -qr /stage/moodle/public/theme/ustar /source/.stage-input/connect-2000/moodle/theme/ustar > /artifacts/con02-ack-restored-theme.txt
cmp "$CON02_PAIR/config.php" /stage/moodle/config.php
echo 'CON02_ACK_COMPAT=PASS' | tee /artifacts/con02-ack-result.txt
