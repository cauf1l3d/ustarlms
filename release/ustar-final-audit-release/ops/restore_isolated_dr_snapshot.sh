#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 /opt/ustar/test-env/ustar-final-audit-release/dr-snapshots/<stamp>" >&2
  exit 2
fi

SNAPSHOT_ROOT="$(realpath -- "$1")"
SNAPSHOT_PARENT="/opt/ustar/test-env/ustar-final-audit-release/dr-snapshots"
RESTORE_ROOT="/opt/ustar/test-env/ustar-final-dr-restore-20260823"
NETWORK="ustar-final-dr-net"
POSTGRES_CONTAINER="ustar_dr_postgres"
REDIS_CONTAINER="ustar_dr_redis"
MOODLE_CONTAINER="ustar_dr_moodle"

if [[ "${SNAPSHOT_ROOT}" != "${SNAPSHOT_PARENT}/"* ]]; then
  echo "Refusing snapshot outside ${SNAPSHOT_PARENT}" >&2
  exit 1
fi
for file in moodle.sql.gz moodle-code.tgz moodledata.tgz SHA256SUMS; do
  test -f "${SNAPSHOT_ROOT}/${file}"
done
test ! -e "${RESTORE_ROOT}"
for container in "${POSTGRES_CONTAINER}" "${REDIS_CONTAINER}" "${MOODLE_CONTAINER}"; do
  if docker container inspect "${container}" >/dev/null 2>&1; then
    echo "Refusing existing container: ${container}" >&2
    exit 1
  fi
done
if docker network inspect "${NETWORK}" >/dev/null 2>&1; then
  echo "Refusing existing network: ${NETWORK}" >&2
  exit 1
fi

(cd "${SNAPSHOT_ROOT}" && sha256sum -c SHA256SUMS)
install -d -m 0750 "${RESTORE_ROOT}" "${RESTORE_ROOT}/postgres"
tar -xzf "${SNAPSHOT_ROOT}/moodle-code.tgz" -C "${RESTORE_ROOT}"
tar -xzf "${SNAPSHOT_ROOT}/moodledata.tgz" -C "${RESTORE_ROOT}"

sed -i 's#http://127.0.0.1:18080#http://127.0.0.1:18081#g' "${RESTORE_ROOT}/moodle/config.php"
chown root:33 "${RESTORE_ROOT}/moodle/config.php"
chmod 0640 "${RESTORE_ROOT}/moodle/config.php"

docker network create "${NETWORK}" >/dev/null
docker run -d \
  --name "${POSTGRES_CONTAINER}" \
  --network "${NETWORK}" \
  --network-alias db \
  -e POSTGRES_DB=moodle \
  -e POSTGRES_USER=moodle \
  -e POSTGRES_HOST_AUTH_METHOD=trust \
  -v "${RESTORE_ROOT}/postgres:/var/lib/postgresql/data" \
  --health-cmd='pg_isready -U moodle -d moodle' \
  --health-interval=3s \
  --health-timeout=3s \
  --health-retries=20 \
  postgres:16 >/dev/null

for _ in {1..30}; do
  if [[ "$(docker inspect -f '{{.State.Health.Status}}' "${POSTGRES_CONTAINER}")" == "healthy" ]]; then
    break
  fi
  sleep 2
done
test "$(docker inspect -f '{{.State.Health.Status}}' "${POSTGRES_CONTAINER}")" = "healthy"
gzip -dc "${SNAPSHOT_ROOT}/moodle.sql.gz" \
  | docker exec -i "${POSTGRES_CONTAINER}" psql -v ON_ERROR_STOP=1 -U moodle -d moodle >/dev/null

docker run -d \
  --name "${REDIS_CONTAINER}" \
  --network "${NETWORK}" \
  --network-alias redis \
  redis:7-alpine >/dev/null
docker run -d \
  --name "${MOODLE_CONTAINER}" \
  --network "${NETWORK}" \
  -p 127.0.0.1:18081:80 \
  -v "${RESTORE_ROOT}/moodle:/var/www/html" \
  -v "${RESTORE_ROOT}/moodledata:/var/www/moodledata" \
  moodlehq/moodle-php-apache:8.2 >/dev/null

for _ in {1..40}; do
  if docker exec "${MOODLE_CONTAINER}" php /var/www/html/admin/cli/maintenance.php --disable >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
docker exec "${MOODLE_CONTAINER}" php /var/www/html/admin/cli/maintenance.php --disable
docker exec "${MOODLE_CONTAINER}" php /var/www/html/admin/cli/check_database_schema.php

http_code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18081/login/index.php)"
test "${http_code}" = "200"
csv_code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18081/local/ustar/data/staff_position_map_2026-08-13.csv)"
json_code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18081/local/ustar/data/structure_staffmap_2026-08-13.json)"
test "${csv_code}" = "404"
test "${json_code}" = "404"

docker exec "${POSTGRES_CONTAINER}" psql -U moodle -d moodle -Atc \
  "SELECT 'users=' || count(*) FROM mdl_user UNION ALL SELECT 'courses=' || count(*) FROM mdl_course;"
echo "isolated_dr_restore=PASS"
echo "restore_root=${RESTORE_ROOT}"
echo "restore_url=http://127.0.0.1:18081"
