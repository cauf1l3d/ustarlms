#!/usr/bin/env bash
set -Eeuo pipefail

CODE_ROOT="/opt/ustar/apps/moodle/moodle"
CONFIG="${CODE_ROOT}/config.php"
PLUGIN="${CODE_ROOT}/public/local/ustar"
THEME="${CODE_ROOT}/public/theme/ustar"
BACKUP_ROOT="/var/backups/ustar/p0-permission-manifests"
STAMP="$(date -u +%Y-%m-%d_%H-%M-%S)"
SNAPSHOT="${BACKUP_ROOT}/${STAMP}"
MANIFEST="${SNAPSHOT}/permissions.before"

for target in "${CODE_ROOT}" "${PLUGIN}" "${THEME}"; do
  resolved="$(realpath -- "${target}")"
  if [[ "${resolved}" != "${target}" ]]; then
    echo "Refusing unexpected target path: ${resolved}" >&2
    exit 1
  fi
done
test -f "${CONFIG}"
test -d "${PLUGIN}"
test -d "${THEME}"

install -d -o root -g root -m 0700 "${BACKUP_ROOT}"
install -d -o root -g root -m 0700 "${SNAPSHOT}"
stat -c '%a|%u|%g|%n' "${CONFIG}" > "${MANIFEST}"
find "${PLUGIN}" "${THEME}" -xdev -printf '%m|%U|%G|%p\n' >> "${MANIFEST}"
chown root:root "${MANIFEST}"
chmod 0600 "${MANIFEST}"

chown root:www-data "${CONFIG}"
chmod 0640 "${CONFIG}"

for target in "${PLUGIN}" "${THEME}"; do
  chown -R adu:adu "${target}"
  find "${target}" -xdev -type d -exec chmod 0755 {} +
  find "${target}" -xdev -type f -exec chmod 0644 {} +
done

test -z "$(find "${PLUGIN}" "${THEME}" -xdev -perm /022 -print -quit)"
test "$(stat -c '%U:%G|%a' "${CONFIG}")" = "root:www-data|640"

docker exec moodle_web php /var/www/html/admin/cli/purge_caches.php
docker exec moodle_web php /var/www/html/admin/cli/check_database_schema.php
curl -kfsS -o /dev/null https://158-160-29-94.nip.io/login/index.php

echo "production_code_hardening=PASS"
echo "rollback_manifest=${MANIFEST}"
