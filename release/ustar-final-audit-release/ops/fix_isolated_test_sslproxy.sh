#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG="/opt/ustar/test-env/ustar-final-audit-release/moodle/config.php"
test -f "${CONFIG}"

if grep -Fq '$CFG->sslproxy = false;' "${CONFIG}" && grep -Fq '$CFG->reverseproxy = false;' "${CONFIG}"; then
  echo "isolated proxy overrides already present"
  exit 0
fi

awk '{ if ($0 ~ /require_once/) { if (!ssl) print "$CFG->sslproxy = false;"; if (!reverse) print "$CFG->reverseproxy = false;"; } if ($0 == "$CFG->sslproxy = false;") ssl=1; if ($0 == "$CFG->reverseproxy = false;") reverse=1; print }' \
  "${CONFIG}" > "${CONFIG}.audit"
mv "${CONFIG}.audit" "${CONFIG}"
chown root:33 "${CONFIG}"
chmod 0640 "${CONFIG}"

grep -Fq '$CFG->sslproxy = false;' "${CONFIG}"
grep -Fq '$CFG->reverseproxy = false;' "${CONFIG}"
echo "isolated test proxy overrides added"
