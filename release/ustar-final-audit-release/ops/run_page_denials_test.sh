#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${USTAR_TEST_CONTAINER:-ustar_audit_moodle}"
SCRIPT_HOST="/tmp/test_page_denial.php"
SCRIPT_CONTAINER="/tmp/test_page_denial.php"

case "${CONTAINER}" in
  ustar_audit_moodle|ustar_dr_moodle|ustar_rc_moodle) ;;
  *) echo "Refusing unexpected test container: ${CONTAINER}" >&2; exit 1 ;;
esac
test -f "${SCRIPT_HOST}"
docker inspect -f '{{.Name}}' "${CONTAINER}" | grep -Fxq "/${CONTAINER}"
docker cp "${SCRIPT_HOST}" "${CONTAINER}:${SCRIPT_CONTAINER}"
docker exec "${CONTAINER}" php -l "${SCRIPT_CONTAINER}"

pairs=(
  "audit_employee|/local/ustar/hr.php"
  "audit_employee|/local/ustar/executive.php"
  "audit_employee|/local/ustar/brand.php"
  "audit_employee|/local/ustar/route_studio.php"
  "audit_employee|/local/ustar/game_studio.php"
  "audit_employee|/local/ustar/checklist_studio.php"
  "audit_employee|/local/ustar/material_bulk.php"
  "audit_retail_head|/local/ustar/hr.php"
  "audit_retail_head|/local/ustar/executive.php"
  "audit_retail_head|/local/ustar/brand.php"
  "audit_retail_head|/local/ustar/route_studio.php"
  "audit_retail_head|/local/ustar/game_studio.php"
  "audit_retail_head|/local/ustar/checklist_studio.php"
  "audit_retail_head|/local/ustar/material_bulk.php"
  "audit_hr|/local/ustar/executive.php"
  "audit_hr|/local/ustar/brand.php"
  "audit_hr|/local/ustar/game_studio.php"
  "audit_ceo|/local/ustar/hr.php"
  "audit_ceo|/local/ustar/operations.php"
  "audit_ceo|/local/ustar/positions.php"
  "audit_ceo|/local/ustar/materials.php"
  "audit_ceo|/local/ustar/material_ack_export.php"
  "audit_ceo|/local/ustar/route_studio.php"
  "audit_ceo|/local/ustar/brand.php"
  "audit_ceo|/local/ustar/game_studio.php"
  "audit_ceo|/local/ustar/checklist_studio.php"
  "audit_ceo|/local/ustar/material_bulk.php"
  "audit_superadmin|/local/ustar/hr.php"
  "audit_superadmin|/local/ustar/operations.php"
  "audit_superadmin|/local/ustar/positions.php"
  "audit_superadmin|/local/ustar/materials.php"
  "audit_superadmin|/local/ustar/material_ack_export.php"
  "audit_superadmin|/local/ustar/route_studio.php"
  "audit_superadmin|/local/ustar/executive.php"
)

for pair in "${pairs[@]}"; do
  IFS='|' read -r username path <<< "${pair}"
  docker exec "${CONTAINER}" php "${SCRIPT_CONTAINER}" "${username}" "${path}"
done

echo "page_denial_tests=PASS count=${#pairs[@]}"
