#!/usr/bin/env bash
# Sourced only by the disposable Connect DB fixture after its original rollback.
test "$PWD" = /stage/moodle
test -d /source/.stage-input/connect-current/moodle/local/ustar
install_sources /source/.stage-input/connect-current/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/chat-link-current.log 2>&1
psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
  -c "UPDATE stage_task_scheduled SET disabled=1,customised=1 WHERE classname='\local_ustar\task\prune_connect_identity';"
snapshot_sql() {
  psql -X -q -A -t -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
    -f /source/.stage-input/connect-installer.sql > "/artifacts/chat-link-$1.json"
}
snapshot_sql baseline
CHAT_SNAP=/stage/chat-link-pair
(
  umask 077
  mkdir "$CHAT_SNAP"
  pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$CHAT_SNAP/database.dump"
  tar -C /stage/moodle/public -czf "$CHAT_SNAP/source.tar.gz" local/ustar theme/ustar
  tar -C /stage/data -czf "$CHAT_SNAP/moodledata.tar.gz" stage_
  cp /stage/moodle/config.php "$CHAT_SNAP/config.php"
  sha256sum "$CHAT_SNAP/"* > "$CHAT_SNAP/SHA256SUMS"
)
install_sources /source/.stage-input/connect-2000/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/chat-link-upgrade.log 2>&1
snapshot_sql candidate
php admin/cli/upgrade.php --non-interactive > /artifacts/chat-link-repeat.log 2>&1
snapshot_sql repeat
printf 'candidate-only\n' > /stage/data/stage_/chat-link-candidate-only.marker
sha256sum --check --strict "$CHAT_SNAP/SHA256SUMS" > /artifacts/chat-link-pair-checksums.txt
rm -rf /stage/moodle/public/local/ustar /stage/moodle/public/theme/ustar /stage/data/stage_
tar -C /stage/moodle/public -xzf "$CHAT_SNAP/source.tar.gz"
tar -C /stage/data -xzf "$CHAT_SNAP/moodledata.tar.gz"
cp "$CHAT_SNAP/config.php" /stage/moodle/config.php
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > /artifacts/chat-link-drop.log 2>&1
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > /artifacts/chat-link-create.log 2>&1
pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$CHAT_SNAP/database.dump" > /artifacts/chat-link-restore.log 2>&1
snapshot_sql restored
diff -qr /stage/moodle/public/local/ustar /source/.stage-input/connect-current/moodle/local/ustar > /artifacts/chat-link-source-diff.txt
diff -qr /stage/moodle/public/theme/ustar /source/.stage-input/connect-current/moodle/theme/ustar > /artifacts/chat-link-theme-diff.txt
cmp "$CHAT_SNAP/config.php" /stage/moodle/config.php
test ! -e /stage/data/stage_/chat-link-candidate-only.marker
echo 'CHAT_LINK_1905_TO_2000_UPGRADE_REPEAT_PAIRED_RESTORE=PASS' | tee -a /artifacts/connect-result.txt
