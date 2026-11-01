#!/usr/bin/env bash
# Genuine authenticated native HTTP confirms; cookie-free bridge never substitutes for it.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
WEBROOT=http://127.0.0.1:8087
SHELL_COOKIE=/artifacts/shell-cookies.txt
COOKIE=/stage/connect-bot-cookies.txt
SERVER_PID=
cleanup() {
    rm -f "$COOKIE" /stage/connect-role-http.json /stage/connect-bot-csrf /stage/connect-bot-login.html /stage/connect-bot-anonymous.html /stage/connect-bot-wrong-actor.html /stage/connect-bot-before.html /stage/connect-bot-csrf.html /stage/connect-bot-after.html
    if [[ -n $SERVER_PID ]]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
        rm -f "$SHELL_COOKIE"
    fi
    php -n -r 'foreach(["/artifacts/connect-bot-http-server.log","/artifacts/shell-http-server.log"] as $p){if(is_file($p))file_put_contents($p,preg_replace("/(?:cb4|bf4)_[A-Za-z0-9_-]{43}/","[opaque-redacted]",file_get_contents($p)));}'
}
trap cleanup EXIT
if [[ ${1:-} = --standalone ]]; then
    php /source/tests/stage/authenticated_shell_fixture.php
    USTAR_STAGE_HTTP_ROOT="$WEBROOT" php -S 127.0.0.1:8087 -t /stage/moodle/public > /artifacts/connect-bot-http-server.log 2>&1 &
    SERVER_PID=$!
    for attempt in $(seq 1 30); do
        if curl -sS --max-time 2 -c "$SHELL_COOKIE" "$WEBROOT/login/index.php" -o /stage/connect-bot-login.html; then break; fi
        sleep 1
    done
    token=$(php -r '$s=file_get_contents("/stage/connect-bot-login.html");if(!preg_match("/name=\"logintoken\"[^>]*value=\"([^\"]+)\"/",$s,$m))exit(1);echo html_entity_decode($m[1]);')
    curl -fsS --max-time 30 -b "$SHELL_COOKIE" -c "$SHELL_COOKIE" --data-urlencode 'username=admin' \
        --data-urlencode 'password=Fixture-Only-Password1!' --data-urlencode "logintoken=$token" "$WEBROOT/login/index.php" -o /stage/connect-bot-login.html
fi
action=$(php /source/tests/stage/connect_bot_http_fixture.php seed)
[[ $action =~ ^cb4_[A-Za-z0-9_-]{43}$ ]]
url="$WEBROOT/local/ustar/connect_bot_confirm.php?action=$action"
curl -sS --max-time 30 "$url" -o /stage/connect-bot-anonymous.html
test "$(php /source/tests/stage/connect_bot_http_fixture.php status)" = pending
curl -fsS --max-time 30 -b "$SHELL_COOKIE" "$url" -o /stage/connect-bot-wrong-actor.html
php -r '$s=file_get_contents("/stage/connect-bot-wrong-actor.html");if(str_contains($s,"HTTP pending")||str_contains($s,"Подтверждаю указанное действие"))exit(1);'
test "$(php /source/tests/stage/connect_bot_http_fixture.php status)" = pending
curl -fsS --max-time 30 -c "$COOKIE" "$WEBROOT/login/index.php" -o /stage/connect-bot-login.html
token=$(php -r '$s=file_get_contents("/stage/connect-bot-login.html");if(!preg_match("/name=\"logintoken\"[^>]*value=\"([^\"]+)\"/",$s,$m))exit(1);echo html_entity_decode($m[1]);')
curl -fsS --max-time 30 -b "$COOKIE" -c "$COOKIE" --data-urlencode 'username=connect_http_hrd' \
    --data-urlencode 'password=Fixture-Only-Password1!' --data-urlencode "logintoken=$token" "$WEBROOT/login/index.php" -o /stage/connect-bot-login.html
curl -fsS --max-time 30 -b "$COOKIE" "$url" -o /stage/connect-bot-before.html
key=$(php -r '$s=file_get_contents("/stage/connect-bot-before.html");if(!preg_match("/name=\"sesskey\" value=\"([^\"]+)\"/",$s,$m)||!str_contains($s,"HTTP pending")||preg_match("/Debug info:|Stack trace:|codingerror/",$s))exit(1);echo html_entity_decode($m[1]);')
test "$(php /source/tests/stage/connect_bot_http_fixture.php status)" = pending
curl -fsS --max-time 30 -b "$COOKIE" --data-urlencode "action=$action" --data 'sesskey=invalid' "$url" -o /stage/connect-bot-csrf.html
test "$(php /source/tests/stage/connect_bot_http_fixture.php status)" = pending
for repeat in 1 2; do
    curl -fsS --max-time 30 -b "$COOKIE" --data-urlencode "action=$action" --data-urlencode "sesskey=$key" "$url" -o /stage/connect-bot-after.html
    test "$(php /source/tests/stage/connect_bot_http_fixture.php status)" = rejected
    php -r '$s=file_get_contents("/stage/connect-bot-after.html");if(!str_contains($s,"Действие сохранено")||preg_match("/Debug info:|Stack trace:|codingerror/",$s))exit(1);'
done
printf '%s\n' 'CONNECT_ROLE_BOT_NATIVE_COOKIE_POST_CSRF_REPLAY_AND_CANONICAL_ACTOR=PASS' > /artifacts/connect-bot-http-result.txt
cat /artifacts/connect-bot-http-result.txt
