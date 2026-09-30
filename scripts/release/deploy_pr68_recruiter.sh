#!/usr/bin/env bash
# PR68 only. Exact tested source + explicit recruiter role, never UX work in progress.
set -Eeuo pipefail
umask 077
BASE=0b369f626a0bdfc03446a305ba4ce902444f4ef1
RC=e7f894ef408e2ee0119477fbc648285e422797af
PROD=/opt/ustar/data/moodle/public/public
MOODLE=ustar_moodle
POSTGRES=ustar_postgres
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
REPORT=$(mktemp -d "$HOME/ustar-pr68-report-$STAMP.XXXXXXXX")
BACKUP=$(mktemp -d "$HOME/ustar-pr68-backup-$STAMP.XXXXXXXX")
SOURCE=$(mktemp -d "$HOME/ustar-pr68-source-$STAMP.XXXXXXXX")
MAINTENANCE=0
STOPPED=0
exec > >(tee "$REPORT/deploy.log") 2>&1
fail() {
  local code=$?
  trap - ERR
  if [ "$STOPPED" = 1 ]; then sudo docker start "$MOODLE" >/dev/null || true; fi
  if [ "$MAINTENANCE" = 1 ]; then
    sudo docker exec "$MOODLE" php /var/www/html/admin/cli/maintenance.php --enable || true
    echo "DEPLOY_FAILED: maintenance retained. Backup: $BACKUP; report: $REPORT"
  else
    echo "PREFLIGHT_FAILED: production not changed. Report: $REPORT"
  fi
  exit "$code"
}
trap fail ERR
sudo -v
for cmd in git python3 tar; do command -v "$cmd" >/dev/null; done
test -d "$PROD/local/ustar"
test -d "$PROD/theme/ustar"
git -C "$SOURCE" init -q
git -C "$SOURCE" remote add origin https://github.com/cauf1l3d/ustarlms.git
GIT_TERMINAL_PROMPT=0 git -C "$SOURCE" fetch --no-tags origin "$BASE" "$RC"
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$RC"
git -C "$SOURCE" checkout --detach -q "$RC"
test -z "$(git -C "$SOURCE" diff --name-only --diff-filter=D "$BASE" "$RC" -- moodle/local/ustar moodle/theme/ustar)"
echo "USTAR PR68: $BASE -> $RC"

# Validate DB versions and both immutable identity fields BEFORE maintenance.
cat > "$REPORT/preflight.php" <<'PHP'
<?php
define('CLI_SCRIPT', true);
require '/var/www/html/public/config.php';
if ((int)get_config('local_ustar','version') !== 2026093002
        || (int)get_config('theme_ustar','version') !== 2026092703) {
    throw new RuntimeException('Wrong production versions; expected PR67 RC2.');
}
$u = $DB->get_record('user', ['id'=>75,'username'=>'murtazalieva.p','deleted'=>0,'suspended'=>0], '*', MUST_EXIST);
if (!\local_ustar\employment::is_active((int)$u->id)) {
    throw new RuntimeException('Recruiter employment is not active.');
}
$ctx = context_system::instance();
if (is_siteadmin($u->id) || has_capability('local/ustar:admin',$ctx,$u->id)
        || $DB->record_exists_sql("SELECT 1 FROM {role_assignments} a JOIN {role} r ON r.id=a.roleid
            WHERE a.userid=:u AND a.contextid=:c AND r.shortname=:r",
            ['u'=>$u->id,'c'=>$ctx->id,'r'=>'ustar_hrd'])) {
    throw new RuntimeException('Recruiter already has elevated authority; cannot promise reduced HR shell.');
}
$DB->get_record('role',['shortname'=>'ustar_hr'],'id',MUST_EXIST);
echo 'RECRUITER_IDENTITY_OK='. $u->id .':'. $u->username .PHP_EOL;
echo 'PRODUCTION_VERSIONS_OK'.PHP_EOL;
PHP
sudo docker exec -i "$MOODLE" php /dev/stdin < "$REPORT/preflight.php"
DATA_ROOT=$(sudo docker exec "$MOODLE" php -r 'define("CLI_SCRIPT",true);require "/var/www/html/public/config.php";echo $CFG->dataroot;')
sudo docker inspect "$MOODLE" --format '{{json .Mounts}}' > "$REPORT/mounts.json"
DATA_HOST=$(python3 - "$REPORT/mounts.json" "$DATA_ROOT" <<'PY'
import json, os, sys
target=os.path.normpath(sys.argv[2])
matches=[m for m in json.load(open(sys.argv[1])) if target==m['Destination'] or target.startswith(m['Destination'].rstrip('/')+'/')]
assert matches, 'moodledata must be backed by a host mount'
mount=max(matches,key=lambda m:len(m['Destination']))
assert mount['Type'] in ('bind','volume'), 'unsupported Moodle data mount'
root=os.path.normpath(mount['Source'])
path=os.path.normpath(root+'/'+os.path.relpath(target,mount['Destination']))
assert os.path.commonpath([root,path])==root
print(path)
PY
)
sudo test -d "$DATA_HOST"
sudo env GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$SOURCE" python3 "$SOURCE/scripts/production_manifest.py" --repo "$SOURCE" --moodle-root "$PROD" --commit "$BASE" --require-match --output-dir "$REPORT/baseline"

# Maintenance, stop web/cron, consistent application backup; DB remains running.
sudo docker exec "$MOODLE" php /var/www/html/admin/cli/maintenance.php --enable
MAINTENANCE=1
sudo docker stop "$MOODLE" >/dev/null
STOPPED=1
sudo docker exec "$POSTGRES" sh -c 'exec pg_dump -Fc --no-owner --no-privileges -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "$BACKUP/database.dump"
sudo tar -C "$PROD" -czf - local/ustar theme/ustar > "$BACKUP/ustar-code.tar.gz"
sudo tar -C "$DATA_HOST" -czf - . > "$BACKUP/moodledata.tar.gz"
test -s "$BACKUP/database.dump"
tar -tzf "$BACKUP/ustar-code.tar.gz" >/dev/null
tar -tzf "$BACKUP/moodledata.tar.gz" >/dev/null
sudo docker exec -i "$POSTGRES" pg_restore --list < "$BACKUP/database.dump" > "$REPORT/database-archive-list.txt"
cp "$REPORT/mounts.json" "$BACKUP/mounts.json"
printf '%s\n' "$BASE" > "$BACKUP/source-sha.txt"
printf '%s\n' "$DATA_HOST" > "$BACKUP/moodledata-host.txt"
sha256sum "$BACKUP/database.dump" "$BACKUP/ustar-code.tar.gz" "$BACKUP/moodledata.tar.gz" > "$BACKUP/SHA256SUMS"
sudo env GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$SOURCE" python3 "$SOURCE/scripts/production_manifest.py" --repo "$SOURCE" --moodle-root "$PROD" --commit "$BASE" --require-match --output-dir "$REPORT/baseline-final"

# Install only the two tested components.
sudo cp -a "$SOURCE/moodle/local/ustar/." "$PROD/local/ustar/"
sudo cp -a "$SOURCE/moodle/theme/ustar/." "$PROD/theme/ustar/"
sudo chown -R 33:33 "$PROD/local/ustar" "$PROD/theme/ustar"
sudo env GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$SOURCE" python3 "$SOURCE/scripts/production_manifest.py" --repo "$SOURCE" --moodle-root "$PROD" --commit "$RC" --require-match --output-dir "$REPORT/installed"
sudo docker start "$MOODLE" >/dev/null
STOPPED=0
git -C "$SOURCE" diff --name-only "$BASE" "$RC" -- moodle/local/ustar moodle/theme/ustar > "$REPORT/changed.txt"
while IFS= read -r path; do
  if [[ "$path" = *.php ]]; then
    sudo docker exec "$MOODLE" php -l "/var/www/html/public/${path#moodle/}"
  fi
done < "$REPORT/changed.txt"
sudo docker exec "$MOODLE" php /var/www/html/admin/cli/upgrade.php --non-interactive
sudo docker exec "$MOODLE" php /var/www/html/admin/cli/purge_caches.php

# This is an explicit system-context role assignment to ONE exact account.
cat > "$REPORT/assign-recruiter.php" <<'PHP'
<?php
define('CLI_SCRIPT', true);
require '/var/www/html/public/config.php';
if ((int)get_config('local_ustar','version')!==2026093003
        || (int)get_config('theme_ustar','version')!==2026093001) {
    throw new RuntimeException('Target component versions not installed.');
}
$u=$DB->get_record('user',['id'=>75,'username'=>'murtazalieva.p','deleted'=>0,'suspended'=>0],'*',MUST_EXIST);
if (!\local_ustar\employment::is_active($u->id)) throw new RuntimeException('Recruiter is not active.');
$context=context_system::instance();
$role=$DB->get_record('role',['shortname'=>'ustar_hr'],'*',MUST_EXIST);
$tx=$DB->start_delegated_transaction();
role_assign($role->id,$u->id,$context->id);
accesslib_clear_all_caches(true);
if (!\local_ustar\hr_access::is_recruiter($u->id)
        || !\local_ustar\hr_access::can_grade($u->id)
        || !has_capability('local/ustar:hrmanage',$context,$u->id)
        || \local_ustar\hr_access::can_manage_structure($u->id)
        || \local_ustar\hr_access::can_view_hrd_escalations($u->id)) {
    $tx->rollback(new RuntimeException('Recruiter role boundaries failed; assignment rolled back.'));
}
$tx->allow_commit();
echo 'EXPLICIT_ROLE_OK=ustar_hr;userid=75;username=murtazalieva.p;context=system'.PHP_EOL;
echo 'LOCAL_USTAR_VERSION=2026093003'.PHP_EOL.'THEME_USTAR_VERSION=2026093001'.PHP_EOL;
PHP
sudo docker exec -i "$MOODLE" php /dev/stdin < "$REPORT/assign-recruiter.php"
sudo docker exec "$MOODLE" php /var/www/html/admin/cli/purge_caches.php
sudo docker exec "$MOODLE" php /var/www/html/admin/cli/maintenance.php --disable
MAINTENANCE=0
trap - ERR
echo "DEPLOY_SUCCESS=YES"
echo "DEPLOYED_SHA=$RC"
echo "BACKUP=$BACKUP"
echo "REPORT=$REPORT"
echo "Recruiter must log out and sign in again to refresh navigation."
