#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
SCRIPT_HOST="/tmp/test_page_allow.php"
SCRIPT_CONTAINER="/tmp/test_page_allow.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing unexpected test container: ${CONTAINER}" >&2; exit 1 ;;
esac
test -f "${SCRIPT_HOST}"
docker inspect -f '{{.Name}}' "${CONTAINER}" | grep -Fxq "/${CONTAINER}"
docker cp "${SCRIPT_HOST}" "${CONTAINER}:${SCRIPT_CONTAINER}"
docker exec "${CONTAINER}" php -l "${SCRIPT_CONTAINER}"

pairs=(
  "audit_employee|/local/ustar/home.php"
  "audit_employee|/local/ustar/team.php"
  "audit_retail_head|/local/ustar/team.php"
  "audit_hr|/local/ustar/team.php"
  "audit_hr|/local/ustar/hr.php"
  "audit_hr|/local/ustar/operations.php"
  "audit_hr|/local/ustar/positions.php"
  "audit_hr|/local/ustar/materials.php"
  "audit_hr|/local/ustar/route_studio.php"
  "audit_hr|/local/ustar/checklist_studio.php"
  "audit_hr|/local/ustar/material_bulk.php"
  "audit_ceo|/local/ustar/team.php"
  "audit_ceo|/local/ustar/executive.php"
  "audit_superadmin|/local/ustar/team.php"
  "audit_superadmin|/local/ustar/brand.php"
  "audit_superadmin|/local/ustar/game_studio.php"
  "audit_superadmin|/local/ustar/checklist_studio.php"
  "audit_superadmin|/local/ustar/material_bulk.php"
)

for pair in "${pairs[@]}"; do
  IFS='|' read -r username path <<< "${pair}"
  docker exec "${CONTAINER}" php "${SCRIPT_CONTAINER}" "${username}" "${path}"
done

echo "page_allow_tests=PASS count=${#pairs[@]}"
