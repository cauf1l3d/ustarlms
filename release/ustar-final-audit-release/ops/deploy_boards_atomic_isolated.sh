#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
CLASS_SOURCE="${USTAR_BOARD_CLASS_SOURCE:-${SCRIPT_DIR}/../local_ustar/classes/boards.php}"
TEST_SCRIPT="${USTAR_BOARD_TEST_SCRIPT:-${SCRIPT_DIR}/test_boards_atomic_save.sh}"
TARGET="/var/www/html/public/local/ustar/classes/boards.php"
BACKUP="/tmp/ustar_boards_before_atomic.php"
CANDIDATE="/tmp/ustar_boards_candidate_atomic.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing non-isolated Moodle container: ${CONTAINER}" >&2; exit 2 ;;
esac

test -f "${CLASS_SOURCE}"
test -f "${TEST_SCRIPT}"

docker exec "${CONTAINER}" cp "${TARGET}" "${BACKUP}"
before_sha="$(docker exec "${CONTAINER}" sha256sum "${BACKUP}" | cut -d' ' -f1)"
docker cp "${CLASS_SOURCE}" "${CONTAINER}:${CANDIDATE}"

committed=0
restore_on_exit() {
  if [ "${committed}" = 0 ]; then
    docker exec "${CONTAINER}" cp "${BACKUP}" "${TARGET}" || true
    docker exec "${CONTAINER}" chown 1000:1000 "${TARGET}" || true
    docker exec "${CONTAINER}" chmod 0644 "${TARGET}" || true
  fi
}
trap restore_on_exit EXIT

docker exec "${CONTAINER}" cp "${CANDIDATE}" "${TARGET}"
docker exec "${CONTAINER}" chown 1000:1000 "${TARGET}"
docker exec "${CONTAINER}" chmod 0644 "${TARGET}"
docker exec "${CONTAINER}" php -l "${TARGET}"
bash "${TEST_SCRIPT}"

after_sha="$(docker exec "${CONTAINER}" sha256sum "${TARGET}" | cut -d' ' -f1)"
committed=1
trap - EXIT
echo "before_sha=${before_sha}"
echo "after_sha=${after_sha}"
echo "BOARDS_ATOMIC_ISOLATED_DEPLOY=PASS"
