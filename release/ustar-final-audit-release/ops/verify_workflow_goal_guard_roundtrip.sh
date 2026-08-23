#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
TARGET="/var/www/html/public/local/ustar/classes/external/save_goal.php"
BACKUP="/tmp/ustar_save_goal_before_guard.php"
CANDIDATE="/tmp/ustar_save_goal_candidate_guard.php"
PROBE="/tmp/ustar_workflow_communication_runtime_probe.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing non-isolated Moodle container: ${CONTAINER}" >&2; exit 2 ;;
esac

docker exec "${CONTAINER}" test -f "${BACKUP}"
docker exec "${CONTAINER}" test -f "${CANDIDATE}"
docker exec "${CONTAINER}" test -f "${PROBE}"

install_class() {
  local source="$1"
  docker exec "${CONTAINER}" cp "${source}" "${TARGET}"
  docker exec "${CONTAINER}" chown 1000:1000 "${TARGET}"
  docker exec "${CONTAINER}" chmod 0644 "${TARGET}"
  docker exec "${CONTAINER}" php -l "${TARGET}"
}

restore_candidate() {
  install_class "${CANDIDATE}" >/dev/null 2>&1 || true
}
trap restore_candidate EXIT

install_class "${BACKUP}"
docker exec -e USTAR_EXPECT_UNKNOWN_GOAL_ACTION=accepted "${CONTAINER}" php "${PROBE}"
echo "rollback_sha=$(docker exec "${CONTAINER}" sha256sum "${TARGET}" | cut -d' ' -f1)"

install_class "${CANDIDATE}"
docker exec -e USTAR_EXPECT_UNKNOWN_GOAL_ACTION=rejected "${CONTAINER}" php "${PROBE}"
echo "reapply_sha=$(docker exec "${CONTAINER}" sha256sum "${TARGET}" | cut -d' ' -f1)"

trap - EXIT
echo "WORKFLOW_GOAL_GUARD_ROLLBACK_ROUNDTRIP=PASS"
