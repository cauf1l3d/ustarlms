#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
TARGET_ROOT="/var/www/html/public/local/ustar"
BACKUP_ROOT="/tmp/ustar_org_reporting_before_guard"
CANDIDATE_ROOT="/tmp/ustar_org_reporting_candidate_guard"
PROBE="/tmp/organization_reporting_runtime_probe.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing non-isolated Moodle container: ${CONTAINER}" >&2; exit 2 ;;
esac

files=(
  "classes/org.php"
  "classes/external/get_team.php"
  "team.php"
  "executive.php"
  "templates/executive.mustache"
)

install_tree() {
  local root="$1"
  for rel in "${files[@]}"; do
    docker exec "${CONTAINER}" test -f "${root}/${rel}"
    docker exec "${CONTAINER}" cp "${root}/${rel}" "${TARGET_ROOT}/${rel}"
    docker exec "${CONTAINER}" chown 1000:1000 "${TARGET_ROOT}/${rel}"
    docker exec "${CONTAINER}" chmod 0644 "${TARGET_ROOT}/${rel}"
  done
}

restore_candidate() {
  install_tree "${CANDIDATE_ROOT}" >/dev/null 2>&1 || true
}
trap restore_candidate EXIT

install_tree "${BACKUP_ROOT}"
docker exec "${CONTAINER}" php "${PROBE}" unsafe
echo "ROLLBACK_TO_CURRENT=PASS"

install_tree "${CANDIDATE_ROOT}"
for rel in "classes/org.php" "classes/external/get_team.php" "team.php" "executive.php"; do
  docker exec "${CONTAINER}" php -l "${TARGET_ROOT}/${rel}"
done
docker exec "${CONTAINER}" php "${PROBE}" guarded
echo "REAPPLY_CANDIDATE=PASS"

trap - EXIT
echo "ORGANIZATION_REPORTING_GUARD_ROLLBACK_ROUNDTRIP=PASS"
