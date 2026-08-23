#!/usr/bin/env bash
set -Eeuo pipefail

TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
CODE_ROOT="${TEST_ROOT}/moodle"
SNAPSHOT_ROOT="${TEST_ROOT}/release-backups/permissions-before-hardening"

grep -Fq "http://127.0.0.1:18080" "${CODE_ROOT}/config.php"
test -d "${CODE_ROOT}/public/local/ustar"
test -d "${CODE_ROOT}/public/theme/ustar"
test ! -e "${SNAPSHOT_ROOT}/manifest.txt"

install -d -o root -g root -m 0750 "${SNAPSHOT_ROOT}"
find "${CODE_ROOT}/public/local/ustar" "${CODE_ROOT}/public/theme/ustar" \
  -xdev -printf '%m|%u:%g|%p\n' > "${SNAPSHOT_ROOT}/manifest.txt"
stat -c '%a|%U:%G|%n' "${CODE_ROOT}/config.php" >> "${SNAPSHOT_ROOT}/manifest.txt"
chown root:root "${SNAPSHOT_ROOT}/manifest.txt"
chmod 0600 "${SNAPSHOT_ROOT}/manifest.txt"

chown root:33 "${CODE_ROOT}/config.php"
chmod 0640 "${CODE_ROOT}/config.php"

for target in "${CODE_ROOT}/public/local/ustar" "${CODE_ROOT}/public/theme/ustar"; do
  chown -R adu:adu "${target}"
  find "${target}" -xdev -type d -exec chmod 0755 {} +
  find "${target}" -xdev -type f -exec chmod 0644 {} +
done

test -z "$(find "${CODE_ROOT}/public/local/ustar" "${CODE_ROOT}/public/theme/ustar" -xdev -perm /022 -print -quit)"
docker exec ustar_audit_moodle php /var/www/html/admin/cli/purge_caches.php
curl -fsS -o /dev/null http://127.0.0.1:18080/login/index.php

echo "test_code_hardening=PASS"
