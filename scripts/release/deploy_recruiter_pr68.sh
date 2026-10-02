#!/usr/bin/env bash
# Installs the frozen PR68 candidate, then explicitly assigns the reduced HR role.
set -Eeuo pipefail
BASE=0b369f626a0bdfc03446a305ba4ce902444f4ef1
RC=e7f894ef408e2ee0119477fbc648285e422797af
BRANCH=codex/ustar-recruiter-access-hotfix-20260930
ROOT=/opt/ustar/data/moodle/public/public
CLI=/var/www/html/admin/cli
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DIR="$HOME/ustar-pr68-$STAMP"
REPORT="$HOME/ustar-pr68-report-$STAMP"
BACKUP="$HOME/ustar-pr68-backup-$STAMP"
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
        if (( COPIED && MAINT )); then
            echo 'Maintenance оставлен включённым. Требуется разбор ошибки; после upgrade не откатывайте только файлы.' >&2
        fi
    fi
    exit "$result"
}
trap cleanup EXIT
sudo -v
mkdir -m 700 "$REPORT" "$BACKUP"
git clone --single-branch --branch "$BRANCH" https://github.com/cauf1l3d/ustarlms.git "$DIR"
git -C "$DIR" checkout --detach "$RC"
test "$(git -C "$DIR" rev-parse HEAD)" = "$RC"
git -C "$DIR" merge-base --is-ancestor "$BASE" "$RC"
versions=$(sudo docker exec -u www-data ustar_moodle php -r '
define("CLI_SCRIPT",true); require "/var/www/html/config.php";
foreach (["local_ustar","theme_ustar"] as $p) { echo $p,"=",get_config($p,"version"),PHP_EOL; }')
echo "$versions"
test "$versions" = $'local_ustar=2026093002\ntheme_ustar=2026092703'
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$BASE" --output-dir "$REPORT/baseline" --require-match
# Pin the person by BOTH identifiers and check eligibility BEFORE copying files.
sudo docker exec -i -u www-data ustar_moodle php <<'PHP'
<?php
define('CLI_SCRIPT',true); require '/var/www/html/config.php';
$u=$DB->get_record('user',['id'=>75,'username'=>'murtazalieva.p','deleted'=>0,'suspended'=>0],'*',MUST_EXIST);
$ctx=context_system::instance();
if (!\local_ustar\employment::is_active($u->id) || is_siteadmin($u->id)
        || has_capability('local/ustar:admin',$ctx,$u->id)
        || $DB->record_exists_sql("SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id=ra.roleid
            WHERE ra.userid=:u AND ra.contextid=:c AND r.shortname=:r",['u'=>$u->id,'c'=>$ctx->id,'r'=>'ustar_hrd'])) {
    throw new RuntimeException('RECRUITER_IDENTITY_OR_AUTHORITY_CONFLICT');
}
foreach (['manageadaptation','approveregistration','taskescalation'] as $cap) {
    if (has_capability('local/ustar:'.$cap,$ctx,$u->id)) { throw new RuntimeException('EXCESS_AUTHORITY:'.$cap); }
}
$DB->get_record('role',['shortname'=>'ustar_hr'],'id',MUST_EXIST);
echo 'RECRUITER_PREFLIGHT=OK',PHP_EOL;
PHP
# Apply only the exact plugin + theme delta. Refuse deletions in this package.
test -z "$(git -C "$DIR" diff --name-only --diff-filter=D "$BASE" "$RC" -- moodle/local/ustar moodle/theme/ustar)"
git -C "$DIR" diff --name-only "$BASE" "$RC" -- moodle/local/ustar moodle/theme/ustar \
    | sed 's#^moodle/##' > "$REPORT/changed-files.txt"
test -s "$REPORT/changed-files.txt"
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
echo "BACKUP_OK=$BACKUP"
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$BASE" --output-dir "$REPORT/recheck" --require-match
# Preserve the installed owner independently for each plugin.
COPIED=1
for plugin in local/ustar theme/ustar; do
    owner=$(sudo stat -c '%u:%g' "$ROOT/$plugin/version.php")
    sed -n "\#^$plugin/#s#^$plugin/##p" "$REPORT/changed-files.txt" > "$REPORT/$(echo "$plugin" | tr / -).txt"
    sudo rsync -a --chown="$owner" --files-from="$REPORT/$(echo "$plugin" | tr / -).txt" \
        "$DIR/moodle/$plugin/" "$ROOT/$plugin/"
done
sudo python3 "$DIR/scripts/production_manifest.py" --repo "$DIR" --moodle-root "$ROOT" \
    --commit "$RC" --output-dir "$REPORT/installed" --require-match
while IFS= read -r path; do
    if [[ $path == *.php ]]; then sudo docker exec ustar_moodle php -l "/var/www/html/public/$path"; fi
done < "$REPORT/changed-files.txt"
sudo docker exec -u www-data ustar_moodle php "$CLI/upgrade.php" --non-interactive
versions=$(sudo docker exec -u www-data ustar_moodle php -r '
define("CLI_SCRIPT",true); require "/var/www/html/config.php";
foreach (["local_ustar","theme_ustar"] as $p) { echo $p,"=",get_config($p,"version"),PHP_EOL; }')
echo "$versions"
test "$versions" = $'local_ustar=2026093003\ntheme_ustar=2026093001'
# Explicit Moodle role assignment; do not derive access from a position title.
sudo docker exec -i -u www-data ustar_moodle php <<'PHP'
<?php
define('CLI_SCRIPT',true); require '/var/www/html/config.php';
$u=$DB->get_record('user',['id'=>75,'username'=>'murtazalieva.p','deleted'=>0,'suspended'=>0],'*',MUST_EXIST);
$ctx=context_system::instance();
if (!\local_ustar\employment::is_active($u->id) || is_siteadmin($u->id)
        || has_capability('local/ustar:admin',$ctx,$u->id)
        || \local_ustar\hr_access::can_view_hrd_escalations($u->id)) {
    throw new RuntimeException('RECRUITER_AUTHORITY_CONFLICT');
}
$roleid=(int)$DB->get_field('role','id',['shortname'=>'ustar_hr'],MUST_EXIST);
role_assign($roleid,$u->id,$ctx->id);
echo 'EXPLICIT_ROLE_ASSIGN=ustar_hr:75:murtazalieva.p',PHP_EOL;
PHP
# Fresh PHP process recomputes effective permissions after role_assign.
sudo docker exec -i -u www-data ustar_moodle php <<'PHP'
<?php
define('CLI_SCRIPT',true); require '/var/www/html/config.php';
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
sudo docker exec -u www-data ustar_moodle php "$CLI/maintenance.php" --disable
MAINT=0
for page in login/index.php local/ustar/hr.php local/ustar/team.php local/ustar/hr_quiz_grading.php; do
    code=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://ustar.local/$page")
    echo "HTTP=$code $page"
    case "$code" in 2??|3??) ;; *) exit 4 ;; esac
done
echo "DEPLOY_SUCCESS=YES DEPLOYED_SHA=$RC"
echo "LOCAL_USTAR_VERSION=2026093003 THEME_USTAR_VERSION=2026093001"
echo "BACKUP=$BACKUP REPORT=$REPORT"
