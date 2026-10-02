#!/usr/bin/env bash
# Uses the disposable server and admin session owned by authenticated_shell_smoke.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
WEBROOT=http://127.0.0.1:8087
COOKIE=/artifacts/shell-cookies.txt
id=$(php /source/tests/stage/adaptation_reset_fixture.php http)
[[ $id =~ ^[0-9]+$ ]]
url="$WEBROOT/local/ustar/adaptation_control.php?adaptationid=$id"
curl -fsS -b "$COOKIE" "$url" -o /artifacts/adaptation-reset-before.html
key=$(php -r '$s=file_get_contents("/artifacts/adaptation-reset-before.html");if(!preg_match("/name=\"sesskey\" value=\"([^\"]+)\"/",$s,$m)||strpos($s,"Сбросить назначение")===false||preg_match("/Debug info:|Stack trace:|codingerror/",$s)){exit(1);}echo html_entity_decode($m[1]);')
# GET and invalid CSRF cannot cancel a cycle.
curl -sS -b "$COOKIE" "$url&action=resetassignment&confirmreset=1&reason=GET" -o /artifacts/adaptation-reset-get.html
test "$(php /source/tests/stage/adaptation_reset_fixture.php status)" = 'active|0'
curl -sS -b "$COOKIE" --data "action=resetassignment&adaptationid=$id&confirmreset=1&sesskey=invalid&reason=CSRF" "$url" -o /artifacts/adaptation-reset-csrf.html
test "$(php /source/tests/stage/adaptation_reset_fixture.php status)" = 'active|0'
# The server checks confirmation too, independently of HTML required validation.
curl -sS -b "$COOKIE" --data "action=resetassignment&adaptationid=$id&sesskey=$key&reason=NoConfirmation" "$url" -o /artifacts/adaptation-reset-unconfirmed.html
test "$(php /source/tests/stage/adaptation_reset_fixture.php status)" = 'active|0'
for attempt in 1 2; do
    code=$(curl -sS -b "$COOKIE" --data "action=resetassignment&adaptationid=$id&confirmreset=1&sesskey=$key" \
        --data-urlencode 'reason=HTTP reset fixture' "$url" -o /artifacts/adaptation-reset-post.html -w '%{http_code}')
    test "$code" = 303
    test "$(php /source/tests/stage/adaptation_reset_fixture.php status)" = 'cancelled|1'
done
curl -fsS -b "$COOKIE" "$url" -o /artifacts/adaptation-reset-after.html
php -r '$s=file_get_contents("/artifacts/adaptation-reset-after.html");if(strpos($s,"HTTP reset fixture")===false||strpos($s,"Назначение отменено")===false||preg_match("/Debug info:|Stack trace:|codingerror/",$s)){exit(1);}echo "ADAPTATION_RESET_AUTHENTICATED_HTTP_CSRF_CONFIRMATION_AND_RETRY=PASS\n";'
