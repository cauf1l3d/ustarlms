#!/usr/bin/env bash
set -Eeuo pipefail

TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
BACKUP_ARCHIVE="/var/backups/ustar/2026-08-22_22-17-34.tar.gz"
PROD_CODE="/opt/ustar/apps/moodle/moodle"
PROD_DATA="/var/lib/docker/volumes/moodle-project_moodledata/_data"
NETWORK="ustar-final-audit-net"
POSTGRES_CONTAINER="ustar_audit_postgres"
REDIS_CONTAINER="ustar_audit_redis"
MOODLE_CONTAINER="ustar_audit_moodle"

if [[ -e "${TEST_ROOT}" ]]; then
  echo "Refusing existing test root: ${TEST_ROOT}" >&2
  exit 1
fi
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
test -f "${BACKUP_ARCHIVE}"
test -d "${PROD_CODE}"
test -d "${PROD_DATA}"

install -d -m 0750 "${TEST_ROOT}"
install -d -m 0750 "${TEST_ROOT}/restore" "${TEST_ROOT}/postgres"

cp -a --reflink=auto "${PROD_CODE}" "${TEST_ROOT}/moodle"
cp -a --reflink=auto "${PROD_DATA}" "${TEST_ROOT}/moodledata"
tar -xzf "${BACKUP_ARCHIVE}" -C "${TEST_ROOT}/restore" --strip-components=1

sed -i 's#https://158-160-29-94.nip.io#http://127.0.0.1:18080#g' "${TEST_ROOT}/moodle/config.php"
awk '{ if ($0 ~ /require_once/) { print "$CFG->noemailever = true;"; print "$CFG->sslproxy = false;"; print "$CFG->reverseproxy = false;"; } print }' \
  "${TEST_ROOT}/moodle/config.php" > "${TEST_ROOT}/moodle/config.php.audit"
mv "${TEST_ROOT}/moodle/config.php.audit" "${TEST_ROOT}/moodle/config.php"
chown root:33 "${TEST_ROOT}/moodle/config.php"
chmod 0640 "${TEST_ROOT}/moodle/config.php"

docker network create "${NETWORK}" >/dev/null

docker run -d \
  --name "${POSTGRES_CONTAINER}" \
  --network "${NETWORK}" \
  --network-alias db \
  -e POSTGRES_DB=moodle \
  -e POSTGRES_USER=moodle \
  -e POSTGRES_HOST_AUTH_METHOD=trust \
  -v "${TEST_ROOT}/postgres:/var/lib/postgresql/data" \
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

gzip -dc "${TEST_ROOT}/restore/moodle.sql.gz" | \
  docker exec -i "${POSTGRES_CONTAINER}" psql -v ON_ERROR_STOP=1 -U moodle -d moodle >/dev/null

docker run -d \
  --name "${REDIS_CONTAINER}" \
  --network "${NETWORK}" \
  --network-alias redis \
  redis:7-alpine >/dev/null

docker run -d \
  --name "${MOODLE_CONTAINER}" \
  --network "${NETWORK}" \
  -p 127.0.0.1:18080:80 \
  -v "${TEST_ROOT}/moodle:/var/www/html" \
  -v "${TEST_ROOT}/moodledata:/var/www/moodledata" \
  moodlehq/moodle-php-apache:8.2 >/dev/null

http_code="000"
for _ in {1..40}; do
  http_code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/ || true)"
  if [[ "${http_code}" == "200" || "${http_code}" == "303" ]]; then
    break
  fi
  sleep 2
done
if [[ "${http_code}" != "200" && "${http_code}" != "303" ]]; then
  echo "Test Moodle did not become ready; HTTP ${http_code}" >&2
  docker logs --tail 100 "${MOODLE_CONTAINER}" >&2
  exit 1
fi

docker exec "${POSTGRES_CONTAINER}" psql -U moodle -d moodle -Atc \
  "SELECT 'users=' || count(*) FROM mdl_user UNION ALL SELECT 'courses=' || count(*) FROM mdl_course UNION ALL SELECT 'ustar_tables=' || count(*) FROM pg_tables WHERE tablename LIKE 'mdl_local_ustar_%';"
echo "isolated_test_url=http://127.0.0.1:18080"
echo "isolated_test_root=${TEST_ROOT}"
