#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
PLUGIN_ROOT="${TEST_ROOT}/moodle/public/local/ustar"
BACKUP_ROOT="${TEST_ROOT}/release-backups/game-catalog-before"

SRC_GAMES="${BUNDLE_ROOT}/local_ustar/classes/external/get_games.php"
SRC_TEMPLATE="${BUNDLE_ROOT}/local_ustar/templates/games.mustache"

BASE_GAMES="5f2d59fbad4acb6dd8832c88ee415a79411a81a7d500703b4f61cc1ce25198b4"
BASE_TEMPLATE="e061b840acdecab40d8841b1a317d362b939ff795e96a0decfbba047de3405ea"
FINAL_GAMES="28ea31e0990b732b6a8603c97df1a3c776f29a8fb8d4dd54c13c6fbd3dc15f4e"
FINAL_TEMPLATE="ce6cafd95d601fe4829c0e31d51cec907c7b413cd2aa423f767f75db6cd46cca"

test "$(sha256sum "${SRC_GAMES}" | cut -d' ' -f1)" = "${FINAL_GAMES}"
test "$(sha256sum "${SRC_TEMPLATE}" | cut -d' ' -f1)" = "${FINAL_TEMPLATE}"

CURRENT_GAMES="$(sha256sum "${PLUGIN_ROOT}/classes/external/get_games.php" | cut -d' ' -f1)"
CURRENT_TEMPLATE="$(sha256sum "${PLUGIN_ROOT}/templates/games.mustache" | cut -d' ' -f1)"

if [[ "${CURRENT_GAMES}" = "${FINAL_GAMES}" && "${CURRENT_TEMPLATE}" = "${FINAL_TEMPLATE}" ]]; then
  echo "game_catalog_deploy=PASS already_current=true"
elif [[ "${CURRENT_GAMES}" = "${BASE_GAMES}" && "${CURRENT_TEMPLATE}" = "${BASE_TEMPLATE}" ]]; then
  test ! -e "${BACKUP_ROOT}"
  install -d -o root -g root -m 0750 "${BACKUP_ROOT}/classes/external" "${BACKUP_ROOT}/templates"
  install -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/external/get_games.php" "${BACKUP_ROOT}/classes/external/get_games.php"
  install -o root -g root -m 0640 "${PLUGIN_ROOT}/templates/games.mustache" "${BACKUP_ROOT}/templates/games.mustache"

  install -D -o adu -g adu -m 0644 "${SRC_GAMES}" "${PLUGIN_ROOT}/classes/external/get_games.php"
  install -D -o adu -g adu -m 0644 "${SRC_TEMPLATE}" "${PLUGIN_ROOT}/templates/games.mustache"
  echo "game_catalog_deploy=PASS already_current=false"
else
  echo "Refusing unexpected isolated game-catalog baseline" >&2
  echo "games=${CURRENT_GAMES}" >&2
  echo "template=${CURRENT_TEMPLATE}" >&2
  exit 1
fi

test "$(sha256sum "${PLUGIN_ROOT}/classes/external/get_games.php" | cut -d' ' -f1)" = "${FINAL_GAMES}"
test "$(sha256sum "${PLUGIN_ROOT}/templates/games.mustache" | cut -d' ' -f1)" = "${FINAL_TEMPLATE}"

docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/external/get_games.php
docker exec ustar_audit_moodle php /var/www/html/admin/cli/purge_caches.php
curl -fsS -o /dev/null http://127.0.0.1:18080/login/index.php
echo "game_catalog_validation=PASS"
