#!/usr/bin/env bash
# Install-only recovery-aware operator workflow. No database restore or deletion.
set -Eeuo pipefail
umask 077
RC=06b0b75496b9e3fe613b1001fa7ae599cf7ced80
BASE=9b1810d73951756b5b2d747a3aecaae371298bc4
MOODLE=ustar_moodle
PG=ustar_postgres
RUN="${USTAR_DEPLOY_WORK_DIR:-$HOME/ustar-task-rc-06b0b75}"
BACKUP=''
PHASE=preflight
for bin in git python3 docker rsync tar gzip flock; do command -v "$bin" >/dev/null; done
sudo -v
mkdir -p "$RUN"
exec 9>"$RUN/deploy.lock"
flock -n 9 || { echo 'Другой запуск уже работает.' >&2; exit 2; }

# Config/inspect output is stored privately; credentials are never printed.
    test "$#" -eq 0
    if [[ ! -d "$RUN/repo/.git" ]]; then
        git clone --no-checkout https://github.com/cauf1l3d/ustarlms.git "$RUN/repo"
    fi
    git -C "$RUN/repo" fetch origin codex/ustar-task-workspace-rc-20260930
    test "$(git -C "$RUN/repo" rev-parse "$RC^{commit}")" = "$RC"
    git -C "$RUN/repo" merge-base --is-ancestor "$BASE" "$RC"
    test -z "$(git -C "$RUN/repo" diff --name-only "$BASE" "$RC" -- moodle/theme)"
    sudo docker exec -i -u www-data "$MOODLE" php >"$RUN/config-read.json" <<'PHP'
<?php
 define('CLI_SCRIPT', true);
 require '/var/www/html/config.php';
 $cli = '';
 foreach (['/var/www/html/admin/cli', $CFG->dirroot . '/admin/cli', dirname($CFG->dirroot) . '/admin/cli'] as $path) {
     if (is_file($path . '/upgrade.php') && is_file($path . '/maintenance.php')) { $cli = $path; break; }
 }
 echo json_encode(['dirroot'=>$CFG->dirroot, 'dataroot'=>$CFG->dataroot, 'cli'=>$cli,
     'dbtype'=>$CFG->dbtype, 'dbhost'=>$CFG->dbhost, 'dbname'=>$CFG->dbname, 'dbuser'=>$CFG->dbuser,
     'version'=>(string)get_config('local_ustar','version'), 'maintenance'=>!empty($CFG->maintenance_enabled),
     'wwwroot'=>$CFG->wwwroot], JSON_THROW_ON_ERROR);
PHP
    mapfile -t RUNNING < <(sudo docker ps -q)
    sudo docker inspect "${RUNNING[@]}" >"$RUN/containers-read.json"
    python3 - "$RUN" "$MOODLE" "$PG" "$RC" "$BASE" <<'PY'
import json,sys,subprocess,hashlib
from pathlib import Path
run=Path(sys.argv[1]); name,pg,rc,base=sys.argv[2:]
cfg=json.loads((run/'config-read.json').read_text()); containers=json.loads((run/'containers-read.json').read_text())
byname={c['Name'].lstrip('/'):c for c in containers}; app=byname[name]; db=byname[pg]
assert cfg['version']=='2026092907', 'EXPECTED_LOCAL_USTAR_2026092907'
assert not cfg['maintenance'], 'MAINTENANCE_ALREADY_ENABLED'
assert cfg['dbtype']=='pgsql' and cfg['cli'], 'UNSUPPORTED_RUNTIME_OR_CLI'
assert cfg['dbname'] not in ('postgres','template0','template1'), 'REFUSING_SYSTEM_DATABASE'
aliases={pg, db['Config'].get('Hostname','')}
for n in db['NetworkSettings']['Networks'].values(): aliases.update(n.get('Aliases') or []); aliases.add(n.get('IPAddress',''))
assert cfg['dbhost'] in aliases, 'DB_HOST_IS_NOT_USTAR_POSTGRES'
def mapping(path):
    matches=[m for m in app['Mounts'] if path==m['Destination'] or path.startswith(m['Destination'].rstrip('/')+'/')]
    assert matches, 'NO_PERSISTENT_MOUNT: '+path
    m=max(matches,key=lambda x:len(x['Destination']))
    assert m['RW'] and m['Type'] in ('bind','volume'), 'MOUNT_NOT_WRITABLE'
    src=Path(m['Source']); assert src.is_absolute() and src.is_dir() and not src.is_symlink(), 'INVALID_MOUNT_SOURCE'
    assert str(src) not in ('/','/opt','/var','/home','/usr','/etc'), 'REFUSING_BROAD_MOUNT'
    actual=src/Path(path).relative_to(m['Destination'])
    assert actual.is_dir() and actual.resolve()==actual, 'SYMLINK_OR_MISSING_RUNTIME_PATH'
    return str(src),str(actual),m['Destination']
codevol,root,codedest=mapping(cfg['dirroot']); datavol,data,datadest=mapping(cfg['dataroot'])
assert codevol!=datavol and not Path(codevol).is_relative_to(datavol) and not Path(datavol).is_relative_to(codevol), 'OVERLAPPING_VOLUMES'
for m in app['Mounts']:
    if m['Destination'].startswith(codedest.rstrip('/')+'/') or m['Destination'].startswith(datadest.rstrip('/')+'/'):
        raise SystemExit('NESTED_MOUNT_REQUIRES_SEPARATE_BACKUP: '+m['Destination'])
plugin=Path(root)/'local/ustar'; assert plugin.is_dir() and plugin.resolve()==plugin, 'INVALID_PLUGIN_PATH'
repo=run/'repo'
def git(*args): return subprocess.check_output(['git','-C',str(repo),*args])
paths=git('ls-tree','-r','--name-only',base,'--','moodle/local/ustar','moodle/theme/ustar').decode().splitlines()
errors=[]
for p in paths:
    target=Path(root)/p.removeprefix('moodle/')
    if not target.is_file() or target.resolve()!=target or hashlib.sha256(target.read_bytes()).digest()!=hashlib.sha256(git('show',base+':'+p)).digest(): errors.append(p)
for p in git('diff','--name-only','--diff-filter=A',base,rc,'--','moodle/local/ustar').decode().splitlines():
    if (Path(root)/p.removeprefix('moodle/')).exists() or (Path(root)/p.removeprefix('moodle/')).is_symlink(): errors.append('NEW_FILE_COLLISION: '+p)
assert not git('diff','--name-only','--diff-filter=D',base,rc,'--','moodle/local/ustar'), 'UNEXPECTED_FILE_REMOVAL'
if errors: raise SystemExit('SOURCE_DRIFT; установка не начата:\n'+'\n'.join(errors[:40]))
# Stop all running containers sharing the code/data source, including normal cron sidecars.
writers=[c['Name'].lstrip('/') for c in containers if c['Name'].lstrip('/')!=pg and any(m['Source'] in (codevol,datavol) for m in c['Mounts'])]
assert name in writers
state={'rc':rc,'base':base,'moodle':name,'pg':pg,'code_volume':codevol,'root':root,'data_volume':datavol,
       'cli':cfg['cli'],'dbtype':cfg['dbtype'],'dbhost':cfg['dbhost'],'dbname':cfg['dbname'],'dbuser':cfg['dbuser'],
       'wwwroot':cfg['wwwroot'],'writers':writers,'app_image':app['Image'],'db_image':db['Image'],'db_mounts':sorted((m['Source'],m['Destination']) for m in db['Mounts'])}
(run/'state.json').write_text(json.dumps(state,indent=2))
print('SOURCE_BASE=OK'); print('CODE_ROOT='+root); print('DATA_ROOT='+data); print('QUIESCE='+','.join(writers))
PY
get() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$RUN/state.json" "$1"; }
MOODLE="$(get moodle)"; PG="$(get pg)"; CLI="$(get cli)"
CODEVOL="$(get code_volume)"; DATAVOL="$(get data_volume)"; ROOT="$(get root)"
DBNAME="$(get dbname)"; DBUSER="$(get dbuser)"
mapfile -t WRITERS < <(python3 -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))["writers"]))' "$RUN/state.json")
fail() {
    status=$?
    trap - ERR
    if [[ "$PHASE" == backup ]]; then
        sudo docker start "$MOODLE" >/dev/null || true
        sudo docker exec -u www-data "$MOODLE" php "$CLI/maintenance.php" --disable >/dev/null || true
        sudo docker start "${WRITERS[@]}" >/dev/null || true
    elif [[ "$PHASE" != preflight ]]; then
        sudo docker start "$MOODLE" >/dev/null || true
        sudo docker exec -u www-data "$MOODLE" php "$CLI/maintenance.php" --enable >/dev/null || true
    fi
    echo "STOPPED: phase=$PHASE; backup=$BACKUP. Автоматического отката БД нет." >&2
    exit "$status"
}
trap fail ERR


BACKUP="$HOME/ustar-backups/tasks-rc-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p -m 700 "$BACKUP"
cp "$RUN/state.json" "$BACKUP/state.json"
# Require room for an uncompressed-size estimate plus a full emergency restore snapshot.
BYTES=$(sudo du -sb "$CODEVOL" "$DATAVOL" | awk '{s+=$1} END {printf "%.0f",s}')
DBBYTES=$(sudo docker exec -u postgres "$PG" sh -eu -c 'psql -XAt -U "${POSTGRES_USER:-postgres}" -d "$1" -c "SELECT pg_database_size(current_database())"' sh "$DBNAME")
FREE=$(df --output=avail -B1 "$BACKUP" | tail -1 | tr -d ' ')
test "$FREE" -gt "$((2 * (BYTES + DBBYTES) + 1073741824))" || { echo 'Недостаточно места для полного снимка и отката.' >&2; exit 20; }
APP_UID=$(sudo docker exec "$MOODLE" id -u www-data)
APP_GID=$(sudo docker exec "$MOODLE" id -g www-data)
PHASE=backup
sudo docker exec -u www-data "$MOODLE" php "$CLI/maintenance.php" --enable
sudo docker stop "${WRITERS[@]}" >/dev/null
sudo docker exec -u postgres "$PG" sh -eu -c 'pg_dump -Fc -U "${POSTGRES_USER:-postgres}" -d "$1"' sh "$DBNAME" >"$BACKUP/database.dump"
sudo docker exec -u postgres "$PG" sh -eu -c 'pg_dumpall --globals-only -U "${POSTGRES_USER:-postgres}"' >"$BACKUP/database-globals.sql"
sudo docker inspect "$MOODLE" "$PG" >"$BACKUP/containers.json"
sudo tar --numeric-owner -czf "$BACKUP/code-volume.tar.gz" -C "$CODEVOL" .
sudo tar --numeric-owner -czf "$BACKUP/moodledata-volume.tar.gz" -C "$DATAVOL" .
gzip -t "$BACKUP/code-volume.tar.gz" "$BACKUP/moodledata-volume.tar.gz"
sudo docker exec -i -u postgres "$PG" pg_restore --file=/dev/null <"$BACKUP/database.dump"
(cd "$BACKUP" && sha256sum state.json database.dump database-globals.sql containers.json code-volume.tar.gz moodledata-volume.tar.gz >SHA256SUMS)
touch "$BACKUP/BACKUP_READY"
printf '%s\n' "$BACKUP" >"$HOME/ustar-task-rc-last-backup"
echo "BACKUP_READY=$BACKUP"

# Apply only exact RC plugin files. No delete and no Moodle core/theme/config replacement.
mkdir -p "$BACKUP/candidate"
git -C "$RUN/repo" archive "$RC" moodle/local/ustar | tar -x -C "$BACKUP/candidate"
PHASE=deploy
sudo rsync -a --chown="$APP_UID:$APP_GID" "$BACKUP/candidate/moodle/local/ustar/" "$ROOT/local/ustar/"
sudo docker start "$MOODLE" >/dev/null
# Register access.php definitions before upgrade.php assigns the new capability.
# This is the same Moodle API used by prior USTAR capability migrations.
sudo docker exec -i -u www-data "$MOODLE" php <<'PHP'
<?php
 define('CLI_SCRIPT', true);
 require '/var/www/html/config.php';
 require_once($CFG->libdir . '/accesslib.php');
 update_capabilities('local_ustar');
 if (!$DB->record_exists('capabilities', ['name' => 'local/ustar:taskescalation'])) {
     throw new RuntimeException('TASK_ESCALATION_CAPABILITY_NOT_REGISTERED');
 }
 accesslib_clear_all_caches(true);
 echo 'TASK_ESCALATION_CAPABILITY=REGISTERED'.PHP_EOL;
PHP
sudo docker exec -u www-data "$MOODLE" php "$CLI/upgrade.php" --non-interactive
sudo docker exec -u www-data "$MOODLE" php "$CLI/purge_caches.php"
sudo docker exec -i -u www-data "$MOODLE" php <<'PHP'
<?php
 define('CLI_SCRIPT', true); require '/var/www/html/config.php';
 $v=(string)get_config('local_ustar','version');
 if ($v!=='2026093001') { throw new RuntimeException('VERSION_MISMATCH='.$v); }
 foreach (['meta','templates','tpl_versions','series','reports','escalations','settings'] as $suffix) {
     $name='local_ustar_task_'.$suffix;
     if (!$DB->get_manager()->table_exists(new xmldb_table($name))) { throw new RuntimeException('MISSING_TABLE='.$name); }
     echo 'TABLE_OK='.$name.PHP_EOL;
 }
 $task=\core\task\manager::get_scheduled_task('\\local_ustar\\task\\process_work_tasks');
 if (!$task || $task->get_disabled()) { throw new RuntimeException('WORK_TASK_CRON_NOT_REGISTERED_OR_DISABLED'); }
 foreach (['ustar_hrd','ustar_superadmin'] as $shortname) {
     $roleid=$DB->get_field('role','id',['shortname'=>$shortname]);
     if ($roleid && !$DB->record_exists('role_capabilities', [
         'roleid'=>$roleid, 'capability'=>'local/ustar:taskescalation',
         'contextid'=>context_system::instance()->id, 'permission'=>CAP_ALLOW,
     ])) { throw new RuntimeException('MISSING_ESCALATION_GRANT='.$shortname); }
 }
 echo 'CORPORATE_ESCALATION_GRANTS=OK'.PHP_EOL;
 echo 'LOCAL_USTAR='.$v.PHP_EOL; echo 'WORK_TASK_CRON=REGISTERED'.PHP_EOL;
PHP
sudo python3 - "$RUN/repo" "$ROOT" "$RC" <<'PY'
import sys,subprocess,hashlib
from pathlib import Path
repo,root,sha=sys.argv[1:]
def git(*args):return subprocess.check_output(['git','-c','safe.directory='+repo,'-C',repo,*args])
for p in git('ls-tree','-r','--name-only',sha,'--','moodle/local/ustar').decode().splitlines():
 actual=Path(root)/p.removeprefix('moodle/'); assert actual.is_file() and not actual.is_symlink()
 assert hashlib.sha256(actual.read_bytes()).digest()==hashlib.sha256(git('show',sha+':'+p)).digest(),p
print('INSTALLED_PLUGIN_HASHES=OK')
PY
sudo docker exec -u www-data "$MOODLE" php "$CLI/maintenance.php" --disable
sudo docker start "${WRITERS[@]}" >/dev/null
sudo docker exec -u www-data "$MOODLE" php "$CLI/purge_caches.php"
PHASE=complete
trap - ERR
printf 'DEPLOY_OK=%s\nBACKUP=%s\nMAINTENANCE=OFF\n' "$RC" "$BACKUP"
