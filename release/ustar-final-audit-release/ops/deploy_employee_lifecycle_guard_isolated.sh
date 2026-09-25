#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
SOURCE_ROOT="${USTAR_LIFECYCLE_SOURCE_ROOT:-${SCRIPT_DIR}/../local_ustar}"
PROBE_SOURCE="${USTAR_LIFECYCLE_PROBE_SOURCE:-${SCRIPT_DIR}/employee_lifecycle_runtime_probe.php}"
TARGET_ROOT="/var/www/html/public/local/ustar"
BACKUP_ROOT="/tmp/ustar_employee_lifecycle_before_guard"
CANDIDATE_ROOT="/tmp/ustar_employee_lifecycle_candidate_guard"
PROBE="/tmp/employee_lifecycle_runtime_probe.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing non-isolated Moodle container: ${CONTAINER}" >&2; exit 2 ;;
esac

files=(
  "classes/external/hr_import_people.php"
  "classes/external/hr_bulk_assign_positions.php"
)

test -f "${PROBE_SOURCE}"
docker exec "${CONTAINER}" rm -rf "${BACKUP_ROOT}" "${CANDIDATE_ROOT}"
for rel in "${files[@]}"; do
  test -f "${SOURCE_ROOT}/${rel}"
  docker exec "${CONTAINER}" mkdir -p "${BACKUP_ROOT}/$(dirname "${rel}")" "${CANDIDATE_ROOT}/$(dirname "${rel}")"
  docker exec "${CONTAINER}" cp "${TARGET_ROOT}/${rel}" "${BACKUP_ROOT}/${rel}"
  docker cp "${SOURCE_ROOT}/${rel}" "${CONTAINER}:${CANDIDATE_ROOT}/${rel}"
done
docker cp "${PROBE_SOURCE}" "${CONTAINER}:${PROBE}"

committed=0
restore_on_exit() {
  if [ "${committed}" = 0 ]; then
    for rel in "${files[@]}"; do
      docker exec "${CONTAINER}" cp "${BACKUP_ROOT}/${rel}" "${TARGET_ROOT}/${rel}" || true
      docker exec "${CONTAINER}" chown 1000:1000 "${TARGET_ROOT}/${rel}" || true
      docker exec "${CONTAINER}" chmod 0644 "${TARGET_ROOT}/${rel}" || true
    done
  fi
}
trap restore_on_exit EXIT

for rel in "${files[@]}"; do
  docker exec "${CONTAINER}" cp "${CANDIDATE_ROOT}/${rel}" "${TARGET_ROOT}/${rel}"
  docker exec "${CONTAINER}" chown 1000:1000 "${TARGET_ROOT}/${rel}"
  docker exec "${CONTAINER}" chmod 0644 "${TARGET_ROOT}/${rel}"
  docker exec "${CONTAINER}" php -l "${TARGET_ROOT}/${rel}"
done
docker exec "${CONTAINER}" php -l "${PROBE}"
docker exec "${CONTAINER}" php "${PROBE}" guarded

committed=1
trap - EXIT
echo "EMPLOYEE_LIFECYCLE_GUARD_ISOLATED_DEPLOY=PASS"
