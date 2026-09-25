#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
DB_CONTAINER="${USTAR_TEST_DB_CONTAINER:-ustar_audit_postgres}"
TARGET_ROOT="/var/www/html/public/local/ustar"
BACKUP_ROOT="/tmp/ustar_target_core_before"
CANDIDATE_ROOT="/tmp/ustar_target_core_candidate"
OLD_DB="/tmp/ustar_target_core_before.dump"
CANDIDATE_DB="/tmp/ustar_target_core_candidate.dump"
PROBE="/tmp/target_core_runtime_probe.php"

case "${CONTAINER}:${DB_CONTAINER}" in
  ustar_audit_moodle:ustar_audit_postgres|ustar_dr_moodle:ustar_dr_postgres|ustar_rc_moodle:ustar_rc_postgres) ;;
  *) echo "Refusing non-isolated container pair" >&2; exit 2 ;;
esac

restore_db() {
  local dump="$1"
  docker exec "${DB_CONTAINER}" dropdb --if-exists --force -U moodle moodle
  docker exec "${DB_CONTAINER}" createdb -U moodle -O moodle moodle
  docker exec "${DB_CONTAINER}" pg_restore -U moodle -d moodle "${dump}"
}
install_old_files() {
  while IFS=$'\t' read -r rel existed; do
    if [ "${existed}" = 1 ]; then docker exec "${CONTAINER}" cp "${BACKUP_ROOT}/${rel}" "${TARGET_ROOT}/${rel}";
    else docker exec "${CONTAINER}" rm -f "${TARGET_ROOT}/${rel}"; fi
  done < <(docker exec "${CONTAINER}" cat "${BACKUP_ROOT}/EXISTED.tsv")
}
install_candidate_files() {
  while IFS=$'\t' read -r rel existed; do
    docker exec "${CONTAINER}" cp "${CANDIDATE_ROOT}/${rel}" "${TARGET_ROOT}/${rel}"
    docker exec "${CONTAINER}" chown 1000:1000 "${TARGET_ROOT}/${rel}"
    docker exec "${CONTAINER}" chmod 0644 "${TARGET_ROOT}/${rel}"
  done < <(docker exec "${CONTAINER}" cat "${BACKUP_ROOT}/EXISTED.tsv")
}
restore_candidate() { install_candidate_files >/dev/null 2>&1 || true; restore_db "${CANDIDATE_DB}" >/dev/null 2>&1 || true; }
trap restore_candidate EXIT

install_old_files
restore_db "${OLD_DB}"
oldversion="$(docker exec "${DB_CONTAINER}" psql -U moodle -d moodle -Atc "SELECT value FROM mdl_config_plugins WHERE plugin='local_ustar' AND name='version'")"
oldtables="$(docker exec "${DB_CONTAINER}" psql -U moodle -d moodle -Atc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'mdl_local_ustar_%' AND table_name IN ('mdl_local_ustar_evidence_rec','mdl_local_ustar_notifications','mdl_local_ustar_official_tasks')")"
test "${oldversion}" = "2026082301"
test "${oldtables}" = "0"
echo "ROLLBACK_TO_PRE_CORE=PASS"

install_candidate_files
restore_db "${CANDIDATE_DB}"
for rel in "version.php" "db/upgrade.php" "classes/target_schema.php" "classes/target_core.php" "classes/communication.php" "classes/external/get_team.php"; do
  docker exec "${CONTAINER}" php -l "${TARGET_ROOT}/${rel}"
done
docker exec "${CONTAINER}" php "${PROBE}"
echo "REAPPLY_TARGET_CORE=PASS"

trap - EXIT
echo "TARGET_CORE_ROLLBACK_ROUNDTRIP=PASS"
