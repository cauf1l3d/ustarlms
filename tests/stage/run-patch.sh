#!/usr/bin/env bash
set -euo pipefail
test "$PWD" = /stage
mkdir -p /artifacts
cp /opt/ustar-runtime.json /artifacts/runtime-profile.json
php /source/tests/stage/verify_patch_runtime.php > /artifacts/runtime-preflight.json
test "$(git -C /opt/moodle rev-parse HEAD)" = "$(php -r 'echo json_decode(file_get_contents("/opt/ustar-runtime.json"), true)["moodle_commit"];')"
pg_dump --version > /artifacts/pg-client.txt
grep -q 'PostgreSQL) 16\.' /artifacts/pg-client.txt
exec bash /source/tests/stage/run.sh
