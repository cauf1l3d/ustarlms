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
# Earlier paired restores intentionally removed the original fresh_ tables.
# Install a new fresh2200 reference from the currently selected fixed534 source.
rm -rf /stage/data/fresh_
mkdir /stage/data/fresh_
USTAR_STAGE_PREFIX=fresh_ php admin/cli/install_database.php --agree-license --adminpass='Fixture-Only-Password1!' \
  --adminemail=stage@example.invalid --fullname='USTAR Connect fresh2200 fixture' --shortname=ustar-connect \
  > /artifacts/con02-update-fresh-install.log 2>&1
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
echo 'CON02_UPDATE_NATIVE_LIFECYCLE=PASS' | tee /artifacts/con02-update-lifecycle.txt

# Reproduce only the two owner-observed omissions in this isolated DB. The
# strict fresh profile above remains independently exercised and unchanged.
psql -X -q -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 <<'LEGACY_SQL'
BEGIN;
DO $legacy$
DECLARE item record; removed integer := 0;
BEGIN
  FOR item IN SELECT schemaname,indexname FROM pg_catalog.pg_indexes
    WHERE schemaname='public' AND (
      (tablename='stage_local_ustar_coin_accounts' AND indexdef ~ '^CREATE UNIQUE INDEX .* USING btree \(userid\)$') OR
      (tablename='stage_local_ustar_test_results' AND indexdef ~ '^CREATE UNIQUE INDEX .* USING btree \(attemptid\)$'))
  LOOP
    EXECUTE format('DROP INDEX %I.%I', item.schemaname, item.indexname);
    removed := removed + 1;
  END LOOP;
  IF removed <> 2 THEN RAISE EXCEPTION 'unexpected historical fixture index count'; END IF;
END $legacy$;
COMMIT;
LEGACY_SQL
php /source/tests/stage/con02_update_probe.php legacy_seed
php /source/tests/stage/con02_update_probe.php baseline
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-historical-baseline.json
CU_HIST_PAIR=/stage/con02-update-historical-pair
(
  umask 077
  mkdir "$CU_HIST_PAIR"
  pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$CU_HIST_PAIR/database.dump"
  tar -C /stage/moodle/public -czf "$CU_HIST_PAIR/source.tar.gz" local/ustar theme/ustar
  tar -C /stage/data -czf "$CU_HIST_PAIR/moodledata.tar.gz" stage_
  cp config.php "$CU_HIST_PAIR/config.php"
  sha256sum "$CU_HIST_PAIR/"* > "$CU_HIST_PAIR/SHA256SUMS"
)
cu_sources /source/.stage-input/con02-update-target/moodle
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-update-historical-direct-upgrade.log 2>&1
php /source/tests/stage/con02_update_probe.php target
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-historical-target.json
php admin/cli/upgrade.php --non-interactive > /artifacts/con02-update-historical-repeat.log 2>&1
php /source/tests/stage/con02_update_probe.php target
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-historical-repeat.json
for mutation in column index task extra; do
  cu_sql "/source/.stage-input/con02-update-${mutation}.sql" "/artifacts/con02-update-historical-mutated-${mutation}.json"
done
sha256sum --check --strict "$CU_HIST_PAIR/SHA256SUMS" > /artifacts/con02-update-historical-private-pair-verified.txt
rm -rf public/local/ustar public/theme/ustar /stage/data/stage_
tar -C public -xzf "$CU_HIST_PAIR/source.tar.gz"
tar -C /stage/data -xzf "$CU_HIST_PAIR/moodledata.tar.gz"
cp "$CU_HIST_PAIR/config.php" config.php
cu_restore_db "$CU_HIST_PAIR/database.dump"
diff -qr public/local/ustar /source/.stage-input/con02-update-baseline/moodle/local/ustar > /artifacts/con02-update-historical-source-restored.txt
diff -qr public/theme/ustar /source/.stage-input/con02-update-baseline/moodle/theme/ustar > /artifacts/con02-update-historical-theme-restored.txt
cmp config.php "$CU_HIST_PAIR/config.php"
grep -qx 'preserved-private-data' /stage/data/stage_/con02-update-business.marker
php /source/tests/stage/con02_update_probe.php restored
cu_sql /source/.stage-input/con02-update-stage.sql /artifacts/con02-update-historical-restored.json
echo 'CON02_UPDATE_HISTORICAL_NATIVE_LIFECYCLE=PASS' | tee /artifacts/con02-update-historical-lifecycle.txt
