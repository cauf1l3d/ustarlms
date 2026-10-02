#!/usr/bin/env bash
# Real HTTP login and product rendering on the disposable Moodle instance.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
WEBROOT=http://127.0.0.1:8087
MODE=${1:-candidate}
[[ $MODE = candidate || $MODE = broken-baseline ]]
COOKIE=/artifacts/shell-cookies.txt
SERVER_PID=
cleanup() { if [[ -n $SERVER_PID ]]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi; rm -f "$COOKIE"; }
trap cleanup EXIT
php /source/tests/stage/authenticated_shell_fixture.php
USTAR_STAGE_HTTP_ROOT="$WEBROOT" php -d display_errors=1 -S 127.0.0.1:8087 -t /stage/moodle/public > /artifacts/shell-http-server.log 2>&1 &
SERVER_PID=$!
for attempt in $(seq 1 30); do
    if curl -sS --max-time 2 -c "$COOKIE" "$WEBROOT/login/index.php" -o /artifacts/shell-login.html; then break; fi
    sleep 1
done
token=$(php -r '$s=file_get_contents("/artifacts/shell-login.html"); if (!preg_match("/name=\"logintoken\"[^>]*value=\"([^\"]+)\"/",$s,$m)) {fwrite(STDERR,"LOGIN_TOKEN_MISSING\n");exit(1);}echo html_entity_decode($m[1]);')
curl -fsS --max-time 30 -b "$COOKIE" -c "$COOKIE" \
    --data-urlencode 'username=admin' --data-urlencode 'password=Fixture-Only-Password1!' \
    --data-urlencode "logintoken=$token" "$WEBROOT/login/index.php" -o /artifacts/shell-login-result.html
if [[ $MODE = broken-baseline ]]; then
    curl -sS --max-time 60 -b "$COOKIE" "$WEBROOT/local/ustar/home.php" -o /artifacts/shell-broken-baseline.html
    php -r '$s=file_get_contents("/artifacts/shell-broken-baseline.html"); if (strpos($s,"Cannot call moodle_page::add_body_class after output has been started")===false) {fwrite(STDERR,"BASELINE_DEFECT_NOT_REPRODUCED\n");exit(1);}echo "PR71_BODY_CLASS_DEFECT=REPRODUCED\n";'
    exit 0
fi
# No -L: a login redirect cannot masquerade as a successful product page.
for page in home notebook catalog messages; do
    code=$(curl -sS --max-time 60 -b "$COOKIE" -o "/artifacts/shell-$page.html" -w '%{http_code}' "$WEBROOT/local/ustar/$page.php")
    test "$code" = 200 || { echo "SIGNED_IN_HTTP_FAILED=$page:$code"; exit 1; }
    php -r '
        $s=file_get_contents($argv[1]);
        $expected=$argv[2]==="home"?"u-standard-width":"u-personal-wide";
        if (!preg_match("/<body\\b[^>]*class=\"[^\"]*\\b".$expected."\\b[^\"]*\"/",$s)
                || strpos($s,"data-ustar-preset=")===false
                || preg_match("/Cannot call moodle_page|codingerror|Debug info:|Stack trace:/i",$s)) {
            fwrite(STDERR,"AUTHENTICATED_SHELL_RENDER_FAILED=".$argv[2].PHP_EOL); exit(1);
        }
        echo "AUTHENTICATED_SHELL_RENDER=OK:".$argv[2].PHP_EOL;
    ' "/artifacts/shell-$page.html" "$page"
done
bash /source/tests/stage/chat_http_smoke.sh
bash /source/tests/stage/adaptation_reset_http_smoke.sh
echo 'AUTHENTICATED_SHELL_HTTP=PASS'
