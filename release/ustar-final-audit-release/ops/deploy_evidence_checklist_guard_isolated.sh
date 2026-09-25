#!/usr/bin/env bash
set -Eeuo pipefail

TEST_ROOT="/opt/ustar/test-env/ustar-final-audit-release"
PLUGIN_ROOT="${TEST_ROOT}/moodle/public/local/ustar"
STAGING_ROOT="/tmp/ustar_evidence_checklist_guard_candidate"
STAMP="$(date -u +%Y-%m-%d_%H-%M-%S)"
BACKUP_ROOT="${TEST_ROOT}/release-backups/evidence-checklist-gate-before-${STAMP}"

test "$(realpath -- "${TEST_ROOT}")" = "${TEST_ROOT}"
sudo grep -Fq "http://127.0.0.1:18080" "${TEST_ROOT}/moodle/config.php"
test -f "${STAGING_ROOT}/classes/evidence.php"
test -f "${STAGING_ROOT}/classes/position_model.php"
test -f "${STAGING_ROOT}/classes/external/hr_save_checklists.php"
test -f "${STAGING_ROOT}/positions.php"

sudo install -d -o root -g root -m 0750 "${BACKUP_ROOT}/classes/external"
sudo install -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/evidence.php" "${BACKUP_ROOT}/classes/evidence.php"
sudo install -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/position_model.php" "${BACKUP_ROOT}/classes/position_model.php"
sudo install -o root -g root -m 0640 "${PLUGIN_ROOT}/classes/external/hr_save_checklists.php" "${BACKUP_ROOT}/classes/external/hr_save_checklists.php"
sudo install -o root -g root -m 0640 "${PLUGIN_ROOT}/positions.php" "${BACKUP_ROOT}/positions.php"
sudo sha256sum \
  "${BACKUP_ROOT}/classes/evidence.php" \
  "${BACKUP_ROOT}/classes/position_model.php" \
  "${BACKUP_ROOT}/classes/external/hr_save_checklists.php" \
  "${BACKUP_ROOT}/positions.php" | sudo tee "${BACKUP_ROOT}/SHA256SUMS" >/dev/null
sudo chmod 0640 "${BACKUP_ROOT}/SHA256SUMS"

install_candidate() {
  sudo install -o root -g www-data -m 0640 "${STAGING_ROOT}/classes/evidence.php" "${PLUGIN_ROOT}/classes/evidence.php"
  sudo install -o root -g www-data -m 0640 "${STAGING_ROOT}/classes/position_model.php" "${PLUGIN_ROOT}/classes/position_model.php"
  sudo install -o root -g www-data -m 0640 "${STAGING_ROOT}/classes/external/hr_save_checklists.php" "${PLUGIN_ROOT}/classes/external/hr_save_checklists.php"
  sudo install -o root -g www-data -m 0640 "${STAGING_ROOT}/positions.php" "${PLUGIN_ROOT}/positions.php"
}

restore_old() {
  sudo install -o root -g www-data -m 0640 "${BACKUP_ROOT}/classes/evidence.php" "${PLUGIN_ROOT}/classes/evidence.php"
  sudo install -o root -g www-data -m 0640 "${BACKUP_ROOT}/classes/position_model.php" "${PLUGIN_ROOT}/classes/position_model.php"
  sudo install -o root -g www-data -m 0640 "${BACKUP_ROOT}/classes/external/hr_save_checklists.php" "${PLUGIN_ROOT}/classes/external/hr_save_checklists.php"
  sudo install -o root -g www-data -m 0640 "${BACKUP_ROOT}/positions.php" "${PLUGIN_ROOT}/positions.php"
}

lint_files() {
  docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/evidence.php
  docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/position_model.php
  docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/classes/external/hr_save_checklists.php
  docker exec ustar_audit_moodle php -l /var/www/html/public/local/ustar/positions.php
}

install_candidate
lint_files
docker exec ustar_audit_moodle php /var/www/html/public/local/ustar/cli/evidence_checklist_gate_runtime_probe.php guarded

restore_old
lint_files
docker exec ustar_audit_moodle php /var/www/html/public/local/ustar/cli/evidence_checklist_gate_runtime_probe.php unsafe

install_candidate
lint_files
docker exec ustar_audit_moodle php /var/www/html/public/local/ustar/cli/evidence_checklist_gate_runtime_probe.php guarded

test "$(sha256sum "${STAGING_ROOT}/classes/evidence.php" | awk '{print $1}')" = "$(sudo sha256sum "${PLUGIN_ROOT}/classes/evidence.php" | awk '{print $1}')"
test "$(sha256sum "${STAGING_ROOT}/classes/position_model.php" | awk '{print $1}')" = "$(sudo sha256sum "${PLUGIN_ROOT}/classes/position_model.php" | awk '{print $1}')"
test "$(sha256sum "${STAGING_ROOT}/classes/external/hr_save_checklists.php" | awk '{print $1}')" = "$(sudo sha256sum "${PLUGIN_ROOT}/classes/external/hr_save_checklists.php" | awk '{print $1}')"
test "$(sha256sum "${STAGING_ROOT}/positions.php" | awk '{print $1}')" = "$(sudo sha256sum "${PLUGIN_ROOT}/positions.php" | awk '{print $1}')"

docker exec ustar_audit_moodle php /var/www/html/admin/cli/purge_caches.php
curl -fsS -o /dev/null http://127.0.0.1:18080/login/index.php

echo "backup_root=${BACKUP_ROOT}"
echo "roundtrip=PASS"
echo "final_candidate=INSTALLED_ISOLATED_ONLY"
