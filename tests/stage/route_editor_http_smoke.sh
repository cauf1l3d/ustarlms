#!/usr/bin/env bash
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
php /source/tests/stage/route_editor_http_fixture.php
python3 /source/tests/stage/route_editor_http.py
