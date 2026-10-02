#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
PLUGIN_ROOT="${TEST_ROOT}/moodle/public/local/ustar"
THEME_ROOT="${TEST_ROOT}/moodle/public/theme/ustar"
BACKUP_ROOT="${TEST_ROOT}/release-backups/academy-icons-before"

SRC_UI="${BUNDLE_ROOT}/local_ustar/classes/ui.php"
SRC_PIX="${BUNDLE_ROOT}/local_ustar/pix/academy"
SRC_LIB="${BUNDLE_ROOT}/theme_ustar/lib.php"
SRC_SCSS="${BUNDLE_ROOT}/theme_ustar/scss/_academy_icons.scss"

BASE_UI="d9ba190d75cdeb8c057717959f7a43b67e354369f3cf175d6cab70ee6a1fb011"
BASE_LIB="f63d581b9daad302d8b66183d35851d266d6ac1140b56d69332f4d1f115ed2c0"
FINAL_UI="45dfad397feb907ae4fa87ddd72497fdad26be436a0111d66140b8b1a2ae2f84"
FINAL_LIB="4b4d5ffc7ebbae9a26175c9c64136739c48c2498d7d522e8f9508a45c3b2d5fb"
FINAL_SCSS="a56fe5dd9baf9538dd1c5e4548e98f467cf3560501b8d98a1f0584c1fd52e202"

test -f "${SRC_UI}"
test -f "${SRC_LIB}"
test -f "${SRC_SCSS}"
test "$(find "${SRC_PIX}" -maxdepth 1 -type f -name '*.png' | wc -l)" = "12"
test "$(sha256sum "${SRC_UI}" | cut -d' ' -f1)" = "${FINAL_UI}"
test "$(sha256sum "${SRC_LIB}" | cut -d' ' -f1)" = "${FINAL_LIB}"
test "$(sha256sum "${SRC_SCSS}" | cut -d' ' -f1)" = "${FINAL_SCSS}"

CURRENT_UI="$(sha256sum "${PLUGIN_ROOT}/classes/ui.php" | cut -d' ' -f1)"
CURRENT_LIB="$(sha256sum "${THEME_ROOT}/lib.php" | cut -d' ' -f1)"
CURRENT_SCSS="absent"
if [[ -f "${THEME_ROOT}/scss/_academy_icons.scss" ]]; then
  CURRENT_SCSS="$(sha256sum "${THEME_ROOT}/scss/_academy_icons.scss" | cut -d' ' -f1)"
fi

if [[ "${CURRENT_UI}" = "${FINAL_UI}" && "${CURRENT_LIB}" = "${FINAL_LIB}" && "${CURRENT_SCSS}" = "${FINAL_SCSS}" ]]; then
  echo "academy_icon_deploy=PASS already_current=true"
elif [[ "${CURRENT_UI}" = "${BASE_UI}" && "${CURRENT_LIB}" = "${BASE_LIB}" && "${CURRENT_SCSS}" = "absent" ]]; then
  test ! -e "${BACKUP_ROOT}"
  install -d -o root -g root -m 0750 "${BACKUP_ROOT}"
  install -D -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/ui.php" "${BACKUP_ROOT}/local_ustar/classes/ui.php"
  install -D -o root -g root -m 0640 "${THEME_ROOT}/lib.php" "${BACKUP_ROOT}/theme_ustar/lib.php"

  install -D -o adu -g adu -m 0644 "${SRC_UI}" "${PLUGIN_ROOT}/classes/ui.php"
  install -D -o adu -g adu -m 0644 "${SRC_LIB}" "${THEME_ROOT}/lib.php"
  install -D -o adu -g adu -m 0644 "${SRC_SCSS}" "${THEME_ROOT}/scss/_academy_icons.scss"
  install -d -o adu -g adu -m 0755 "${PLUGIN_ROOT}/pix/academy"
  for asset in "${SRC_PIX}"/*.png; do
    install -o adu -g adu -m 0644 "${asset}" "${PLUGIN_ROOT}/pix/academy/$(basename -- "${asset}")"
  done
  echo "academy_icon_deploy=PASS already_current=false"
else
  echo "Refusing unexpected isolated baseline" >&2
  echo "ui=${CURRENT_UI}" >&2
  echo "lib=${CURRENT_LIB}" >&2
  echo "scss=${CURRENT_SCSS}" >&2
  exit 1
fi

test "$(sha256sum "${PLUGIN_ROOT}/classes/ui.php" | cut -d' ' -f1)" = "${FINAL_UI}"
test "$(sha256sum "${THEME_ROOT}/lib.php" | cut -d' ' -f1)" = "${FINAL_LIB}"
test "$(sha256sum "${THEME_ROOT}/scss/_academy_icons.scss" | cut -d' ' -f1)" = "${FINAL_SCSS}"
test "$(find "${PLUGIN_ROOT}/pix/academy" -maxdepth 1 -type f -name '*.png' | wc -l)" = "12"

docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/ui.php
docker exec ustar_audit_moodle php -l /var/www/html/public/theme/ustar/lib.php
docker exec ustar_audit_moodle php /var/www/html/admin/cli/purge_caches.php
curl -fsS -o /dev/null http://127.0.0.1:18080/login/index.php
echo "academy_icon_validation=PASS"
