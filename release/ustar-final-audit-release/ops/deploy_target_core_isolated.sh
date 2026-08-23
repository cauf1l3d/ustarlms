#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
DB_CONTAINER="${USTAR_TEST_DB_CONTAINER:-ustar_audit_postgres}"
SOURCE_ROOT="${USTAR_TARGET_CORE_SOURCE_ROOT:-${SCRIPT_DIR}/../local_ustar}"
PROBE_SOURCE="${USTAR_TARGET_CORE_PROBE_SOURCE:-${SCRIPT_DIR}/target_core_runtime_probe.php}"
TARGET_ROOT="/var/www/html/public/local/ustar"
BACKUP_ROOT="/tmp/ustar_target_core_before"
CANDIDATE_ROOT="/tmp/ustar_target_core_candidate"
DB_BACKUP="/tmp/ustar_target_core_before.dump"
PROBE="/tmp/target_core_runtime_probe.php"

case "${CONTAINER}:${DB_CONTAINER}" in
  ustar_audit_moodle:ustar_audit_postgres|ustar_dr_moodle:ustar_dr_postgres|ustar_rc_moodle:ustar_rc_postgres) ;;
  *) echo "Refusing non-isolated container pair" >&2; exit 2 ;;
esac

files=(
  "version.php" "db/install.xml" "db/upgrade.php" "classes/target_schema.php" "classes/target_core.php"
  "classes/communication.php" "classes/external/get_team.php"
)

test -f "${PROBE_SOURCE}"
docker exec "${CONTAINER}" rm -rf "${BACKUP_ROOT}" "${CANDIDATE_ROOT}"
docker exec "${CONTAINER}" mkdir -p "${BACKUP_ROOT}" "${CANDIDATE_ROOT}"
docker exec "${DB_CONTAINER}" pg_dump -U moodle -d moodle -Fc -f "${DB_BACKUP}"
docker exec "${DB_CONTAINER}" pg_restore -l "${DB_BACKUP}" >/dev/null

for rel in "${files[@]}"; do
  test -f "${SOURCE_ROOT}/${rel}"
  docker exec "${CONTAINER}" mkdir -p "${BACKUP_ROOT}/$(dirname "${rel}")" "${CANDIDATE_ROOT}/$(dirname "${rel}")"
  if docker exec "${CONTAINER}" test -f "${TARGET_ROOT}/${rel}"; then
    docker exec "${CONTAINER}" cp "${TARGET_ROOT}/${rel}" "${BACKUP_ROOT}/${rel}"
    printf '%s\t1\n' "${rel}" | docker exec -i "${CONTAINER}" tee -a "${BACKUP_ROOT}/EXISTED.tsv" >/dev/null
  else
    printf '%s\t0\n' "${rel}" | docker exec -i "${CONTAINER}" tee -a "${BACKUP_ROOT}/EXISTED.tsv" >/dev/null
  fi
  docker cp "${SOURCE_ROOT}/${rel}" "${CONTAINER}:${CANDIDATE_ROOT}/${rel}"
done
docker cp "${PROBE_SOURCE}" "${CONTAINER}:${PROBE}"

restore_files() {
  while IFS=$'\t' read -r rel existed; do
    if [ "${existed}" = 1 ]; then docker exec "${CONTAINER}" cp "${BACKUP_ROOT}/${rel}" "${TARGET_ROOT}/${rel}";
    else docker exec "${CONTAINER}" rm -f "${TARGET_ROOT}/${rel}"; fi
  done < <(docker exec "${CONTAINER}" cat "${BACKUP_ROOT}/EXISTED.tsv")
}
restore_db() {
  docker exec "${DB_CONTAINER}" dropdb --if-exists --force -U moodle moodle
  docker exec "${DB_CONTAINER}" createdb -U moodle -O moodle moodle
  docker exec "${DB_CONTAINER}" pg_restore -U moodle -d moodle "${DB_BACKUP}"
}
committed=0
restore_on_exit() {
  if [ "${committed}" = 0 ]; then restore_files || true; restore_db || true; fi
}
trap restore_on_exit EXIT

for rel in "${files[@]}"; do
  docker exec "${CONTAINER}" cp "${CANDIDATE_ROOT}/${rel}" "${TARGET_ROOT}/${rel}"
  docker exec "${CONTAINER}" chown 1000:1000 "${TARGET_ROOT}/${rel}"
  docker exec "${CONTAINER}" chmod 0644 "${TARGET_ROOT}/${rel}"
done
for rel in "version.php" "db/upgrade.php" "classes/target_schema.php" "classes/target_core.php" "classes/communication.php" "classes/external/get_team.php"; do
  docker exec "${CONTAINER}" php -l "${TARGET_ROOT}/${rel}"
done
docker exec "${CONTAINER}" php -l "${PROBE}"
docker exec "${CONTAINER}" php /var/www/html/admin/cli/upgrade.php --non-interactive
docker exec "${CONTAINER}" php "${PROBE}"

docker exec "${DB_CONTAINER}" pg_dump -U moodle -d moodle -Fc -f /tmp/ustar_target_core_candidate.dump
docker exec "${DB_CONTAINER}" pg_restore -l /tmp/ustar_target_core_candidate.dump >/dev/null
committed=1
trap - EXIT
echo "TARGET_CORE_ISOLATED_DEPLOY=PASS"
