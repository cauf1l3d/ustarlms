#!/usr/bin/env bash
# Deploy native leaderboard avatars and fitted original login with supplied backdrop over PR73.
# Usage: bash deploy_avatars_login.sh FULL_REVIEWED_COMMIT_SHA
set -Eeuo pipefail
umask 022
BASE=fb4036f53928749f42cbaffbfc2f925ada8a7551
RC=${1:?Pass the full reviewed candidate SHA}
[[ $RC =~ ^[0-9a-f]{40}$ ]] || { echo 'Expected a full commit SHA' >&2; exit 2; }
BRANCH=codex/ustar-avatars-login-20261002
ROOT=/opt/ustar/data/moodle/public/public
CLI=/var/www/html/admin/cli
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DIR="$HOME/ustar-avatars-login-$STAMP"
REPORT="$HOME/ustar-avatars-login-report-$STAMP"
BACKUP="$HOME/ustar-avatars-login-backup-$STAMP"
MAINT=0 PAUSED=0 COPIED=0
cleanup() {
    result=$?
    trap - EXIT
    if (( PAUSED )); then sudo docker unpause ustar_moodle || true; fi
    if (( result != 0 )); then
        if (( MAINT && !COPIED )); then
            sudo docker exec -u www-data ustar_moodle php "$CLI/maintenance.php" --disable || true
        fi
        echo "STOP=$result REPORT=$REPORT BACKUP=$BACKUP" >&2
        if (( COPIED && !MAINT )); then
            sudo docker exec -u www-data ustar_moodle php "$CLI/maintenance.php" --enable || true
        fi
        if (( COPIED )); then
            echo 'Maintenance оставлен включённым. После upgrade не откатывайте только файлы: нужен согласованный backup БД, кода и moodledata.' >&2
        fi
    fi
    exit "$result"
}
trap cleanup EXIT
for tool in git python3 rsync tar curl sha256sum; do command -v "$tool" >/dev/null; done
sudo -v
mkdir -m 700 "$REPORT" "$BACKUP"
exec > >(tee "$REPORT/deploy.log") 2>&1
git clone --single-branch --branch "$BRANCH" https://github.com/cauf1l3d/ustarlms.git "$DIR"
git -C "$DIR" checkout --detach "$RC"
test "$(git -C "$DIR" rev-parse HEAD)" = "$RC"
git -C "$DIR" merge-base --is-ancestor "$BASE" "$RC"
# Refuse a modified download or use with a commit that does not contain this installer.
cmp -- "${BASH_SOURCE[0]}" "$DIR/scripts/release/deploy_avatars_login.sh"
# This installer is intentionally limited to this reviewed version pair.
python3 - "$DIR" <<'PY'
from pathlib import Path
import re,sys
root=Path(sys.argv[1])
for component,version in [('local/ustar',2026100202),('theme/ustar',2026100201)]:
    source=(root/'moodle'/component/'version.php').read_text()
    assert int(re.search(r'\$plugin->version\s*=\s*(\d+)',source)[1]) == version, component
PY
sudo docker exec -i -u www-data ustar_moodle php -d opcache.enable_cli=0 <<'PHP' | tee "$REPORT/preflight.txt"
<?php
define('CLI_SCRIPT',true); require '/var/www/html/config.php';
if (!function_exists('imagecreatefrompng') || !function_exists('imagepng') || !class_exists('finfo')) {
    throw new RuntimeException('GD_PNG_AND_FILEINFO_REQUIRED_FOR_CHAT_MEDIA_AND_APP_ICONS');
}
if ($CFG->dirroot!=='/var/www/html/public' || !empty($CFG->maintenance_enabled)) {
    throw new RuntimeException('UNEXPECTED_DIRROOT_OR_EXISTING_MAINTENANCE');
}
$pair=[(int)get_config('local_ustar','version'),(int)get_config('theme_ustar','version')];
$baselines=['2026100201:2026100102'=>'fb4036f53928749f42cbaffbfc2f925ada8a7551'];
$key=implode(':',$pair);
if (!isset($baselines[$key])) { throw new RuntimeException('BASELINE_VERSION_MISMATCH: '.$key); }
echo 'BASELINE_SHA=',$baselines[$key],PHP_EOL;
$url=parse_url($CFG->wwwroot);
if (!$url || !in_array($url['scheme']??'', ['http','https'],true) || isset($url['user']) || isset($url['pass'])) {
    throw new RuntimeException('UNEXPECTED_WWWROOT');
}
echo 'WWWROOT=',rtrim($CFG->wwwroot,'/'),PHP_EOL;
echo 'PREFLIGHT=OK',PHP_EOL;
PHP
BASE=$(sed -n 's/^BASELINE_SHA=//p' "$REPORT/preflight.txt")
WEBROOT=$(sed -n 's/^WWWROOT=//p' "$REPORT/preflight.txt")
[[ $BASE =~ ^[0-9a-f]{40}$ ]]
git -C "$DIR" merge-base --is-ancestor "$BASE" "$RC"
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$BASE" --output-dir "$REPORT/baseline" --require-match
test -z "$(git -C "$DIR" diff --name-only --diff-filter=D "$BASE" "$RC" -- moodle/local/ustar moodle/theme/ustar)"
git -C "$DIR" diff --name-only "$BASE" "$RC" -- moodle/local/ustar moodle/theme/ustar \
    | sed 's#^moodle/##' > "$REPORT/changed-files.txt"
test -s "$REPORT/changed-files.txt"
# Parse candidate PHP before maintenance and before replacing a production file.
while IFS= read -r path; do
    if [[ $path == *.php ]]; then
        sudo docker exec -i -u www-data ustar_moodle php -d opcache.enable_cli=0 -l < "$DIR/moodle/$path"
    fi
done < "$REPORT/changed-files.txt"
sudo docker exec -u www-data ustar_moodle php "$CLI/maintenance.php" --enable
MAINT=1
sudo docker pause ustar_moodle
PAUSED=1
sudo docker exec ustar_postgres sh -eu -c \
    'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; exec pg_dump -Fc -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"' \
    > "$BACKUP/postgres.dump"
sudo tar -C /opt/ustar/data/moodle -czf "$BACKUP/moodle-files.tar.gz" public moodledata
sudo docker unpause ustar_moodle
PAUSED=0
test -s "$BACKUP/postgres.dump"
sudo docker exec -i ustar_postgres pg_restore -l < "$BACKUP/postgres.dump" >/dev/null
sudo tar -tzf "$BACKUP/moodle-files.tar.gz" >/dev/null
sudo chmod 600 "$BACKUP/postgres.dump" "$BACKUP/moodle-files.tar.gz"
sudo sha256sum "$BACKUP/postgres.dump" "$BACKUP/moodle-files.tar.gz" > "$BACKUP/SHA256SUMS"
printf 'BASE=%s\nCANDIDATE=%s\n' "$BASE" "$RC" > "$BACKUP/release.txt"
echo "BACKUP_OK=$BACKUP"
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$BASE" --output-dir "$REPORT/recheck" --require-match
COPIED=1
for plugin in local/ustar theme/ustar; do
    owner=$(sudo stat -c '%u:%g' "$ROOT/$plugin/version.php")
    list="$REPORT/${plugin//\//-}.txt"
    sed -n "\#^$plugin/#s#^$plugin/##p" "$REPORT/changed-files.txt" > "$list"
    # Explicit modes: root-owned code must still be readable by www-data.
    sudo rsync -a --checksum --chmod=D755,F644 --chown="$owner" --files-from="$list" \
        "$DIR/moodle/$plugin/" "$ROOT/$plugin/"
done
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$RC" --output-dir "$REPORT/installed" --require-match
# Check every published source file as the actual PHP user, not as container root.
git -C "$DIR" ls-tree -r --name-only "$RC" -- moodle/local/ustar moodle/theme/ustar \
    | sed 's#^moodle/##' > "$REPORT/all-files.txt"
sudo docker exec -i -u www-data ustar_moodle php -d opcache.enable_cli=0 -r '
foreach (file("php://stdin", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $path) {
    if (!is_readable("/var/www/html/public/".$path)) { fwrite(STDERR,"NOT_READABLE=".$path.PHP_EOL);exit(1); }
}
echo "WWW_DATA_READABILITY=OK",PHP_EOL;' < "$REPORT/all-files.txt"
# Drop web OPcache as well as CLI caches; maintenance remains enabled across restart.
sudo docker restart ustar_moodle >/dev/null
sudo docker exec -u www-data ustar_moodle php -d opcache.enable_cli=0 "$CLI/purge_caches.php"
sudo docker exec -u www-data ustar_moodle php -d opcache.enable_cli=0 "$CLI/upgrade.php" --non-interactive
sudo docker exec -i -u www-data ustar_moodle php -d opcache.enable_cli=0 <<'PHP'
<?php
define('CLI_SCRIPT',true); require '/var/www/html/config.php';
foreach (['local_ustar'=>2026100202,'theme_ustar'=>2026100201] as $p=>$v) {
    $info=\core_plugin_manager::instance()->get_plugin_info($p);
    echo $p,'=',get_config($p,'version'),PHP_EOL;
    if (!$info || (int)$info->versiondisk!==$v || (int)$info->versiondb!==$v || (int)get_config($p,'version')!==$v) {
        throw new RuntimeException('INSTALLED_VERSION_MISMATCH: '.$p);
    }
}
if (!(int)get_config('local_ustar','completion_reward_startedat')) { throw new RuntimeException('LEARNING_REWARD_BOUNDARY_MISSING'); }
$u=$DB->get_record('user',['id'=>75,'username'=>'murtazalieva.p','deleted'=>0,'suspended'=>0],'*',MUST_EXIST);
$ctx=context_system::instance();
if (!\local_ustar\hr_access::is_recruiter($u->id)
        || \local_ustar\hr_access::can_manage_structure($u->id)
        || \local_ustar\hr_access::can_view_hrd_escalations($u->id)) { throw new RuntimeException('RECRUITER_BOUNDARY_FAILED'); }
foreach (['use','hr','hrmanage','viewteam','gradeassessments','requeststaff'] as $cap) {
    if (!has_capability('local/ustar:'.$cap,$ctx,$u->id)) { throw new RuntimeException('MISSING_ACCESS:'.$cap); }
}
foreach (['manageadaptation','approveregistration','taskescalation'] as $cap) {
    if (has_capability('local/ustar:'.$cap,$ctx,$u->id)) { throw new RuntimeException('EXCESS_ACCESS:'.$cap); }
}
echo 'RECRUITER_EFFECTIVE_ACCESS=OK',PHP_EOL;
PHP
sudo docker exec -u www-data ustar_moodle php "$CLI/purge_caches.php"
sudo docker exec -u www-data ustar_moodle php "$CLI/build_theme_css.php" --themes=ustar
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$RC" --output-dir "$REPORT/final" --require-match
sudo docker exec -u www-data ustar_moodle php "$CLI/maintenance.php" --disable
MAINT=0
for page in login/index.php local/ustar/home.php local/ustar/notebook.php local/ustar/catalog.php local/ustar/competition_studio.php local/ustar/achievements.php local/ustar/route_team_quiz.php local/ustar/messages.php local/ustar/app_manifest.php local/ustar/app_worker.php; do
    code=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "$WEBROOT/$page")
    echo "HTTP_PUBLIC=$code $page"
    case "$code" in 2??|3??) ;; *)
        sudo docker exec -u www-data ustar_moodle php "$CLI/maintenance.php" --enable
        MAINT=1
        exit 4 ;;
    esac
done
echo "PRODUCTION_SIGNED_IN_ACCEPTANCE=NOT_PERFORMED_BY_INSTALLER"
echo "DEPLOY_SUCCESS=YES DEPLOYED_SHA=$RC"
echo 'LOCAL_USTAR_VERSION=2026100202 THEME_USTAR_VERSION=2026100201'
echo "BACKUP=$BACKUP REPORT=$REPORT"
