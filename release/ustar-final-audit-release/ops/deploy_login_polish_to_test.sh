#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SOURCE_LIB="${BUNDLE_ROOT}/theme_ustar/lib.php"
SOURCE_LOGIN="${BUNDLE_ROOT}/theme_ustar/scss/_login.scss"
TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
THEME_ROOT="${TEST_ROOT}/moodle/public/theme/ustar"
BACKUP_ROOT="${TEST_ROOT}/release-backups/login-polish-original"

BASE_LIB_HASH="a83eec5af81375f29157169a910eca234f27d56f03c2a42cbe82438841af0476"
TARGET_LIB_HASH="f63d581b9daad302d8b66183d35851d266d6ac1140b56d69332f4d1f115ed2c0"
TARGET_LOGIN_HASH="002336fdf6572675d1486b8c841e727a70b60b3c87fdf381de04cccf48730aa7"

test -f "${SOURCE_LIB}"
test -f "${SOURCE_LOGIN}"
test -f "${THEME_ROOT}/lib.php"
test "$(sha256sum "${SOURCE_LIB}" | awk '{print $1}')" = "${TARGET_LIB_HASH}"
test "$(sha256sum "${SOURCE_LOGIN}" | awk '{print $1}')" = "${TARGET_LOGIN_HASH}"
grep -Fq "http://127.0.0.1:18080" "${TEST_ROOT}/moodle/config.php"

actual_lib_hash="$(sha256sum "${THEME_ROOT}/lib.php" | awk '{print $1}')"
if [[ -f "${THEME_ROOT}/scss/_login.scss" ]]; then
  actual_login_hash="$(sha256sum "${THEME_ROOT}/scss/_login.scss" | awk '{print $1}')"
else
  actual_login_hash="absent"
fi

if [[ "${actual_lib_hash}" == "${TARGET_LIB_HASH}" && "${actual_login_hash}" == "${TARGET_LOGIN_HASH}" ]]; then
  echo "login_polish_test_already_current=true"
elif [[ "${actual_lib_hash}" == "${BASE_LIB_HASH}" && "${actual_login_hash}" == "absent" ]]; then
  install -d -o root -g root -m 0750 "${BACKUP_ROOT}/scss"
  test ! -e "${BACKUP_ROOT}/lib.php"
  cp -a "${THEME_ROOT}/lib.php" "${BACKUP_ROOT}/lib.php"

  install -o root -g 33 -m 0644 "${SOURCE_LIB}" "${THEME_ROOT}/lib.php"
  install -o root -g 33 -m 0644 "${SOURCE_LOGIN}" "${THEME_ROOT}/scss/_login.scss"
  echo "login_polish_test_deployed=true"
else
  echo "Refusing unexpected test theme state: lib=${actual_lib_hash}, login=${actual_login_hash}" >&2
  exit 1
fi

docker exec ustar_audit_moodle php -l /var/www/html/public/theme/ustar/lib.php
docker exec ustar_audit_moodle php /var/www/html/admin/cli/purge_caches.php

test "$(sha256sum "${THEME_ROOT}/lib.php" | awk '{print $1}')" = "${TARGET_LIB_HASH}"
test "$(sha256sum "${THEME_ROOT}/scss/_login.scss" | awk '{print $1}')" = "${TARGET_LOGIN_HASH}"
sha256sum "${THEME_ROOT}/lib.php" "${THEME_ROOT}/scss/_login.scss"
