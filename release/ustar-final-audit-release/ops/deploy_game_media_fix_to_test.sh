#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
PLUGIN_ROOT="${TEST_ROOT}/moodle/public/local/ustar"
BACKUP_ROOT="${TEST_ROOT}/release-backups/game-media-before"

SRC_MEDIA="${BUNDLE_ROOT}/local_ustar/classes/game_media.php"
SRC_QUESTION="${BUNDLE_ROOT}/local_ustar/classes/external/get_game_question.php"
SRC_ADMIN="${BUNDLE_ROOT}/local_ustar/classes/external/admin_get_games.php"

BASE_QUESTION="cec9ddbd1019197b751f2cb8c52a774c4eb5f1d0f65a86961e6c3be1f96d0dac"
BASE_ADMIN="44a88463758fdd1d748c34a9526b966a4c63c1acd83b2dabf9ca7aae40bdc76d"
FINAL_MEDIA="002a48d63f478c3df868e25d6b5c3bbc2187a7a6d5ab662f3f549c300858e20c"
FINAL_QUESTION="5a79d28553289a80be965e40047ac51c7f434a91fa34f6676ffe0d09bb77a9f1"
FINAL_ADMIN="316d015562ebcb2678c9bc42727124f538258c27ac2b253c53e7380fb6363219"

test -f "${SRC_MEDIA}"
test -f "${SRC_QUESTION}"
test -f "${SRC_ADMIN}"
test "$(sha256sum "${SRC_MEDIA}" | cut -d' ' -f1)" = "${FINAL_MEDIA}"
test "$(sha256sum "${SRC_QUESTION}" | cut -d' ' -f1)" = "${FINAL_QUESTION}"
test "$(sha256sum "${SRC_ADMIN}" | cut -d' ' -f1)" = "${FINAL_ADMIN}"

CURRENT_MEDIA="absent"
if [[ -f "${PLUGIN_ROOT}/classes/game_media.php" ]]; then
  CURRENT_MEDIA="$(sha256sum "${PLUGIN_ROOT}/classes/game_media.php" | cut -d' ' -f1)"
fi
CURRENT_QUESTION="$(sha256sum "${PLUGIN_ROOT}/classes/external/get_game_question.php" | cut -d' ' -f1)"
CURRENT_ADMIN="$(sha256sum "${PLUGIN_ROOT}/classes/external/admin_get_games.php" | cut -d' ' -f1)"

if [[ "${CURRENT_MEDIA}" = "${FINAL_MEDIA}" && "${CURRENT_QUESTION}" = "${FINAL_QUESTION}" && "${CURRENT_ADMIN}" = "${FINAL_ADMIN}" ]]; then
  echo "game_media_deploy=PASS already_current=true"
elif [[ "${CURRENT_MEDIA}" = "absent" && "${CURRENT_QUESTION}" = "${BASE_QUESTION}" && "${CURRENT_ADMIN}" = "${BASE_ADMIN}" ]]; then
  test ! -e "${BACKUP_ROOT}"
  install -d -o root -g root -m 0750 "${BACKUP_ROOT}/classes/external"
  install -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/external/get_game_question.php" "${BACKUP_ROOT}/classes/external/get_game_question.php"
  install -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/external/admin_get_games.php" "${BACKUP_ROOT}/classes/external/admin_get_games.php"

  install -D -o adu -g adu -m 0644 "${SRC_MEDIA}" "${PLUGIN_ROOT}/classes/game_media.php"
  install -D -o adu -g adu -m 0644 "${SRC_QUESTION}" "${PLUGIN_ROOT}/classes/external/get_game_question.php"
  install -D -o adu -g adu -m 0644 "${SRC_ADMIN}" "${PLUGIN_ROOT}/classes/external/admin_get_games.php"
  echo "game_media_deploy=PASS already_current=false"
else
  echo "Refusing unexpected isolated game-media baseline" >&2
  echo "media=${CURRENT_MEDIA}" >&2
  echo "question=${CURRENT_QUESTION}" >&2
  echo "admin=${CURRENT_ADMIN}" >&2
  exit 1
fi

test "$(sha256sum "${PLUGIN_ROOT}/classes/game_media.php" | cut -d' ' -f1)" = "${FINAL_MEDIA}"
test "$(sha256sum "${PLUGIN_ROOT}/classes/external/get_game_question.php" | cut -d' ' -f1)" = "${FINAL_QUESTION}"
test "$(sha256sum "${PLUGIN_ROOT}/classes/external/admin_get_games.php" | cut -d' ' -f1)" = "${FINAL_ADMIN}"

docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/game_media.php
docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/external/get_game_question.php
docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/external/admin_get_games.php
docker exec ustar_audit_moodle php /var/www/html/admin/cli/purge_caches.php
curl -fsS -o /dev/null http://127.0.0.1:18080/login/index.php
echo "game_media_validation=PASS"
