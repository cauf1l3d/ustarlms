#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
TEST_SCRIPT="${USTAR_BOARD_TEST_SCRIPT:-${SCRIPT_DIR}/test_boards_atomic_save.sh}"
TARGET="/var/www/html/public/local/ustar/classes/boards.php"
BACKUP="/tmp/ustar_boards_before_atomic.php"
CANDIDATE="/tmp/ustar_boards_candidate_atomic.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing non-isolated Moodle container: ${CONTAINER}" >&2; exit 2 ;;
esac

docker exec "${CONTAINER}" test -f "${BACKUP}"
docker exec "${CONTAINER}" test -f "${CANDIDATE}"
test -f "${TEST_SCRIPT}"

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
USTAR_EXPECT_POSTED=24 USTAR_EXPECT_CONFLICTS=0 bash "${TEST_SCRIPT}"
echo "rollback_sha=$(docker exec "${CONTAINER}" sha256sum "${TARGET}" | cut -d' ' -f1)"

install_class "${CANDIDATE}"
bash "${TEST_SCRIPT}"
echo "reapply_sha=$(docker exec "${CONTAINER}" sha256sum "${TARGET}" | cut -d' ' -f1)"

trap - EXIT
echo "BOARDS_ATOMIC_ROLLBACK_ROUNDTRIP=PASS"
