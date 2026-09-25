#!/usr/bin/env bash
set -Eeuo pipefail

TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
SNAPSHOT_PARENT="${TEST_ROOT}/dr-snapshots"
STAMP="$(date -u +%Y-%m-%d_%H-%M-%S)"
SNAPSHOT_ROOT="${SNAPSHOT_PARENT}/${STAMP}"
MOODLE_CONTAINER="ustar_audit_moodle"
POSTGRES_CONTAINER="ustar_audit_postgres"

test -d "${TEST_ROOT}/moodle"
test -d "${TEST_ROOT}/moodledata"
test ! -e "${SNAPSHOT_ROOT}"
docker inspect -f '{{.Name}}' "${MOODLE_CONTAINER}" | grep -Fxq "/${MOODLE_CONTAINER}"
docker inspect -f '{{.Name}}' "${POSTGRES_CONTAINER}" | grep -Fxq "/${POSTGRES_CONTAINER}"

install -d -o root -g root -m 0750 "${SNAPSHOT_PARENT}" "${SNAPSHOT_ROOT}"

recover_source_test() {
  docker start "${MOODLE_CONTAINER}" >/dev/null 2>&1 || true
  docker exec "${MOODLE_CONTAINER}" php /var/www/html/admin/cli/maintenance.php --disable >/dev/null 2>&1 || true
}
trap recover_source_test EXIT

docker exec "${MOODLE_CONTAINER}" php /var/www/html/admin/cli/maintenance.php --enable
docker stop "${MOODLE_CONTAINER}" >/dev/null

docker exec "${POSTGRES_CONTAINER}" pg_dump -U moodle -d moodle --no-owner --no-privileges \
  | gzip -9 > "${SNAPSHOT_ROOT}/moodle.sql.gz"
tar --numeric-owner -czf "${SNAPSHOT_ROOT}/moodle-code.tgz" -C "${TEST_ROOT}" moodle
tar --numeric-owner -czf "${SNAPSHOT_ROOT}/moodledata.tgz" -C "${TEST_ROOT}" moodledata

docker start "${MOODLE_CONTAINER}" >/dev/null
docker exec "${MOODLE_CONTAINER}" php /var/www/html/admin/cli/maintenance.php --disable

sha256sum \
  "${SNAPSHOT_ROOT}/moodle.sql.gz" \
  "${SNAPSHOT_ROOT}/moodle-code.tgz" \
  "${SNAPSHOT_ROOT}/moodledata.tgz" \
  > "${SNAPSHOT_ROOT}/SHA256SUMS"
sha256sum -c "${SNAPSHOT_ROOT}/SHA256SUMS"
chown -R root:root "${SNAPSHOT_ROOT}"
find "${SNAPSHOT_ROOT}" -type d -exec chmod 0750 {} +
find "${SNAPSHOT_ROOT}" -type f -exec chmod 0640 {} +

trap - EXIT
recover_source_test

echo "isolated_dr_snapshot=PASS"
echo "snapshot_root=${SNAPSHOT_ROOT}"
du -sh "${SNAPSHOT_ROOT}"
