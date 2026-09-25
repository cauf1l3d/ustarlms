#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
POSTGRES_CONTAINER="${USTAR_TEST_POSTGRES_CONTAINER:-ustar_audit_postgres}"
PROBE_SOURCE="${SCRIPT_DIR}/boards_atomic_probe.php"
PROBE_CONTAINER="/tmp/ustar_boards_atomic_probe.php"
OUTDIR="$(mktemp -d)"
EXPECTED_POSTED="${USTAR_EXPECT_POSTED:-1}"
EXPECTED_CONFLICTS="${USTAR_EXPECT_CONFLICTS:-23}"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing non-isolated Moodle container: ${CONTAINER}" >&2; exit 2 ;;
esac

cleanup() {
  docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" cleanup >/dev/null 2>&1 || true
  docker exec "${CONTAINER}" rm -f "${PROBE_CONTAINER}" >/dev/null 2>&1 || true
  rm -rf "${OUTDIR}"
}
trap cleanup EXIT

docker cp "${PROBE_SOURCE}" "${CONTAINER}:${PROBE_CONTAINER}"
docker exec "${CONTAINER}" php -l "${PROBE_CONTAINER}"
docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" validation
docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" acl

setup="$(docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" setup)"
printf '%s\n' "${setup}"
baseline="$(printf '%s\n' "${setup}" | sed -n 's/^baseline_rows=//p')"
test -n "${baseline}"

docker exec "${POSTGRES_CONTAINER}" psql -U moodle -d moodle -v ON_ERROR_STOP=1 -c \
  "BEGIN; SELECT id FROM mdl_local_ustar_boards WHERE ownerid=(SELECT id FROM mdl_user WHERE username='audit_employee') AND title='__audit_board_atomic_race_20260823__' FOR UPDATE; SELECT pg_sleep(5); COMMIT;" \
  >"${OUTDIR}/lock.txt" 2>&1 &
lockpid=$!
sleep 1

pids=()
for worker in $(seq 1 24); do
  docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" race "${worker}" >"${OUTDIR}/${worker}.txt" 2>&1 &
  pids+=("$!")
done

wait "${lockpid}"
for pid in "${pids[@]}"; do
  wait "${pid}"
done

posted="$(awk '/^posted,/{s++} END {print s+0}' "${OUTDIR}"/*.txt)"
conflicts="$(awk '/^conflict,/{s++} END {print s+0}' "${OUTDIR}"/*.txt)"
workerlines="$(awk '/^(posted|conflict),/{s++} END {print s+0}' "${OUTDIR}"/*.txt)"
echo "attempts=24"
echo "posted=${posted}"
echo "conflicts=${conflicts}"
echo "worker_lines=${workerlines}"

result="$(docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" result)"
printf '%s\n' "${result}"
test "${posted}" = "${EXPECTED_POSTED}"
test "${conflicts}" = "${EXPECTED_CONFLICTS}"
test "${workerlines}" = "24"
test "$(printf '%s\n' "${result}" | sed -n 's/^persisted_rows=//p')" = "1"
test "$(printf '%s\n' "${result}" | sed -n 's/^final_version=//p')" = "2"
test "$(printf '%s\n' "${result}" | sed -n 's/^single_document=//p')" = "1"

cleanupresult="$(docker exec "${CONTAINER}" php "${PROBE_CONTAINER}" cleanup)"
printf '%s\n' "${cleanupresult}"
test "$(printf '%s\n' "${cleanupresult}" | sed -n 's/^final_rows=//p')" = "${baseline}"

docker exec "${CONTAINER}" rm -f "${PROBE_CONTAINER}"
trap - EXIT
rm -rf "${OUTDIR}"
echo "BOARDS_ATOMIC_SAVE=PASS expected_posted=${EXPECTED_POSTED} expected_conflicts=${EXPECTED_CONFLICTS}"
