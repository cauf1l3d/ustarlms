#!/usr/bin/env bash
set -Eeuo pipefail
git config --global --add safe.directory /source
ART=/artifacts/rollback-drill
SNAP=/stage/snapshot
MOODLE=/stage/moodle
DATA=/stage/data/stage_
BASE=/source/.stage-input/baseline/moodle

on_error() {
    local failure_status="$1" failure_line="$2" failure_command="$3"
    echo "ROLLBACK_RUNNER_FAILED exit=$failure_status line=$failure_line command=$failure_command" >&2
    tail -n 80 "$ART"/[0-9]*.log 2>/dev/null || true
    exit "$failure_status"
}
trap 'on_error "$?" "$LINENO" "$BASH_COMMAND"' ERR

fail() {
    echo "ROLLBACK_DRILL_FAIL: $*" >&2
    exit 1
}

test "$PWD" = /stage || fail "runner must start in /stage"
test -d "$BASE/local/ustar" || fail "immutable baseline input missing"
test -d "$BASE/theme/ustar" || fail "immutable baseline theme missing"
test -d /source/moodle/local/ustar || fail "candidate plugin missing"
test -d /source/moodle/theme/ustar || fail "candidate theme missing"
test ! -e /opt/ustar || fail "production-style /opt/ustar path must not exist in drill runner"

mkdir -p "$ART" "$SNAP" "$DATA"
exec > >(tee "$ART/rollback-runner-inner.log") 2>&1
cp -a /opt/moodle "$MOODLE"
cp /source/tests/stage/config.php "$MOODLE/config.php"

BASELINE_SHA="$(php -r 'echo json_decode(file_get_contents("/source/tests/stage/runtime.json"), true)["upgrade_from_commit"];')"
CANDIDATE_SHA="$(git -C /source rev-parse HEAD)"

test "$BASELINE_SHA" = "378d397152a8c83f8b0d046e2e561ab2732d6b02" || fail "unexpected baseline SHA: $BASELINE_SHA"
test "$CANDIDATE_SHA" != "$BASELINE_SHA" || fail "candidate must differ from baseline"

install_sources() {
    local src="$1"
    rm -rf "$MOODLE/public/local/ustar" "$MOODLE/public/theme/ustar"
    cp -a "$src/local/ustar" "$MOODLE/public/local/ustar"
    cp -a "$src/theme/ustar" "$MOODLE/public/theme/ustar"
}

set_marker() {
    local value="$1"
    php -r 'define("CLI_SCRIPT", true); require "/stage/moodle/config.php"; set_config("rollback_drill_marker", $argv[1], "local_ustar");' "$value"
}

cd "$MOODLE"

# Baseline deployment: core first, then exact production-source baseline.
php admin/cli/install_database.php --agree-license \
    --adminpass='Fixture-Only-Password1!' \
    --adminemail=stage@example.invalid \
    --fullname='USTAR rollback stage' \
    --shortname=ustar-rollback > "$ART/01-install-core.log" 2>&1

# version.php uses Moodle maturity constants: load core before reading metadata.
plugin_version() {
    php -r 'define("CLI_SCRIPT", true); require "/stage/moodle/config.php"; $plugin=new stdClass(); require $argv[1]; echo $plugin->version;' "$1"
}
BASELINE_VERSION="$(plugin_version "$BASE/local/ustar/version.php")"
CANDIDATE_VERSION="$(plugin_version /source/moodle/local/ustar/version.php)"
[[ "$BASELINE_VERSION" =~ ^[0-9]+$ && "$CANDIDATE_VERSION" =~ ^[0-9]+$ ]] || fail "invalid plugin version metadata"
test "$BASELINE_VERSION" != "$CANDIDATE_VERSION" || fail "candidate plugin version must differ from baseline"

install_sources "$BASE"
php /source/tests/stage/register_baseline_capabilities.php > "$ART/02-baseline-preparation.log" 2>&1
php admin/cli/upgrade.php --non-interactive > "$ART/03-baseline-upgrade.log" 2>&1
set_marker baseline
printf 'baseline-state\n' > "$DATA/rollback-baseline.marker"
php /source/tests/stage/rollback_probe.php baseline baseline > "$ART/04-baseline-smoke.json"

# Consistent rollback bundle: DB + moodledata + application source + protected config + runtime refs.
pg_dump -h db -U ustar_fixture -d ustar_stage1 -Fc -f "$SNAP/database.dump"
tar -C /stage/data -czf "$SNAP/moodledata.tar.gz" stage_
tar -C "$MOODLE/public" -czf "$SNAP/source.tar.gz" local/ustar theme/ustar
cp "$MOODLE/config.php" "$SNAP/config.php"
{
    echo "baseline_sha=$BASELINE_SHA"
    echo "candidate_sha=$CANDIDATE_SHA"
    echo "baseline_plugin_version=$BASELINE_VERSION"
    echo "candidate_plugin_version=$CANDIDATE_VERSION"
    php -r 'echo "php=" . PHP_VERSION . PHP_EOL;'
    pg_dump --version
    pg_restore --version
    psql -h db -U ustar_fixture -d ustar_stage1 -Atc 'SELECT version();'
} > "$SNAP/runtime.txt"
sha256sum "$SNAP/database.dump" "$SNAP/moodledata.tar.gz" "$SNAP/source.tar.gz" "$SNAP/config.php" "$SNAP/runtime.txt" \
    > "$ART/05-snapshot-sha256.txt"

# Candidate deployment through the same Moodle upgrade path used for release.
install_sources /source/moodle
php admin/cli/upgrade.php --non-interactive > "$ART/06-candidate-upgrade.log" 2>&1
set_marker candidate
printf 'candidate-only\n' > "$DATA/rollback-candidate-only.marker"
php /source/tests/stage/rollback_probe.php candidate candidate > "$ART/07-candidate-smoke.json"

if diff -qr "$MOODLE/public/local/ustar" "$BASE/local/ustar" >/dev/null 2>&1 \
   && diff -qr "$MOODLE/public/theme/ustar" "$BASE/theme/ustar" >/dev/null 2>&1; then
    fail "candidate source unexpectedly equals baseline"
fi

# Full rollback. Files-only rollback is intentionally not used.
rm -rf "$MOODLE/public/local/ustar" "$MOODLE/public/theme/ustar"
tar -C "$MOODLE/public" -xzf "$SNAP/source.tar.gz"
cp "$SNAP/config.php" "$MOODLE/config.php"

rm -rf "$DATA"
tar -C /stage/data -xzf "$SNAP/moodledata.tar.gz"

psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 \
    -c 'DROP DATABASE IF EXISTS ustar_stage1 WITH (FORCE);' > "$ART/08-db-drop.log" 2>&1
psql -h db -U ustar_fixture -d postgres -v ON_ERROR_STOP=1 \
    -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > "$ART/09-db-create.log" 2>&1
pg_restore -h db -U ustar_fixture -d ustar_stage1 --no-owner --exit-on-error "$SNAP/database.dump" \
    > "$ART/10-db-restore.log" 2>&1

test -f "$DATA/rollback-baseline.marker" || fail "baseline moodledata marker missing after restore"
test ! -e "$DATA/rollback-candidate-only.marker" || fail "candidate-only moodledata survived restore"
grep -qx 'baseline-state' "$DATA/rollback-baseline.marker" || fail "baseline moodledata marker corrupted"

diff -qr "$MOODLE/public/local/ustar" "$BASE/local/ustar" > "$ART/11-plugin-source-diff.txt" \
    || fail "restored plugin source differs from baseline"
diff -qr "$MOODLE/public/theme/ustar" "$BASE/theme/ustar" > "$ART/12-theme-source-diff.txt" \
    || fail "restored theme source differs from baseline"

php /source/tests/stage/rollback_probe.php restored baseline > "$ART/13-restored-smoke.json"
php admin/cli/upgrade.php --non-interactive > "$ART/14-post-restore-upgrade.log" 2>&1

cp "$SNAP/runtime.txt" "$ART/15-runtime.txt"
{
    echo "baseline_sha=$BASELINE_SHA"
    echo "candidate_sha=$CANDIDATE_SHA"
    echo "database_snapshot_sha256=$(sha256sum "$SNAP/database.dump" | awk '{print $1}')"
    echo "moodledata_snapshot_sha256=$(sha256sum "$SNAP/moodledata.tar.gz" | awk '{print $1}')"
    echo "source_snapshot_sha256=$(sha256sum "$SNAP/source.tar.gz" | awk '{print $1}')"
    echo "ROLLBACK_DRILL=PASS"
} | tee "$ART/result.txt"
