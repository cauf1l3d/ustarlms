#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 /var/backups/ustar/p0-permission-manifests/<stamp>/permissions.before" >&2
  exit 2
fi

MANIFEST="$(realpath -- "$1")"
ALLOWED_ROOT="/var/backups/ustar/p0-permission-manifests"
CODE_ROOT="/opt/ustar/apps/moodle/moodle"

if [[ "${MANIFEST}" != "${ALLOWED_ROOT}/"*/permissions.before ]]; then
  echo "Refusing manifest outside ${ALLOWED_ROOT}" >&2
  exit 1
fi
test -f "${MANIFEST}"

while IFS='|' read -r mode owner group path; do
  case "${path}" in
    "${CODE_ROOT}/config.php"|"${CODE_ROOT}/public/local/ustar"|"${CODE_ROOT}/public/local/ustar/"*|"${CODE_ROOT}/public/theme/ustar"|"${CODE_ROOT}/public/theme/ustar/"*)
      ;;
    *)
      echo "Refusing path outside approved scope: ${path}" >&2
      exit 1
      ;;
  esac
  test -e "${path}"
  chown "${owner}:${group}" "${path}"
  chmod "${mode}" "${path}"
done < "${MANIFEST}"

docker exec moodle_web php /var/www/html/admin/cli/purge_caches.php
curl -kfsS -o /dev/null https://158-160-29-94.nip.io/login/index.php
echo "production_permission_rollback=PASS"
