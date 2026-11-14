#!/usr/bin/env bash
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
export USTAR_STAGE_OPERATOR_HTTP=1
umask 077
work=$(mktemp -d /stage/operator-http.XXXXXXXX)
backend=
proxy=
cleanup() {
    if [[ -n $proxy ]]; then kill "$proxy" 2>/dev/null || true; wait "$proxy" 2>/dev/null || true; fi
    if [[ -n $backend ]]; then kill "$backend" 2>/dev/null || true; wait "$backend" 2>/dev/null || true; fi
    rm -rf -- "$work"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' \
    -addext 'subjectAltName=IP:127.0.0.1' -keyout "$work/tls.key" -out "$work/tls.crt" >/dev/null 2>&1
fixture=/source/tests/stage/connect_operator_http_fixture.php
action=$(php "$fixture" seed)
[[ $action =~ ^cb4_[A-Za-z0-9_-]{43}$ ]]
php -S 127.0.0.1:18089 -t /stage/moodle/public >"$work/php.log" 2>&1 &
backend=$!
python3 /source/tests/stage/connect_operator_tls.py "$work/tls.crt" "$work/tls.key" >"$work/tls.log" 2>&1 &
proxy=$!
web=https://127.0.0.1:18088
get=(curl --silent --show-error --fail-with-body --max-time 30 --cacert "$work/tls.crt")
for attempt in $(seq 1 25); do
    if "${get[@]}" -c "$work/cookie" "$web/login/index.php" -o "$work/login.html" 2>/dev/null; then break; fi
    sleep 1
done
token=$(php -r '$p=file_get_contents($argv[1]);if(!preg_match("/name="logintoken"[^>]*value="([^"]+)"/",$p,$m))exit(1);echo html_entity_decode($m[1]);' "$work/login.html")
"${get[@]}" -b "$work/cookie" -c "$work/cookie" --data 'username=operatorhttp' \
    --data-urlencode 'password=Fixture-Only-Password1!' --data-urlencode "logintoken=$token" \
    "$web/login/index.php" -o "$work/login-result.html"
url="$web/local/ustar/connect_bot_confirm.php?action=$action"
"${get[@]}" -b "$work/cookie" "$url" -o "$work/preview.html"
grep -Fq 'Приглашение для synthetic operatorinvitee' "$work/preview.html"
key=$(php -r '$p=file_get_contents($argv[1]);if(!preg_match("/name="sesskey" value="([^"]+)"/",$p,$m))exit(1);echo html_entity_decode($m[1]);' "$work/preview.html")
test "$(php "$fixture" status)" = 0
if grep -Eq 'ui1_[A-Za-z0-9_-]{43}' "$work/preview.html"; then exit 1; fi
"${get[@]}" -b "$work/cookie" --data-urlencode "action=$action" --data 'sesskey=invalid' \
    "$url" -o "$work/csrf.html"
test "$(php "$fixture" status)" = 0
for repeat in 1 2; do
    "${get[@]}" -b "$work/cookie" --data-urlencode "action=$action" --data-urlencode "sesskey=$key" \
        "$url" -o "$work/issued.html"
    test "$(php "$fixture" status)" = 1
    if [[ $repeat == 1 ]]; then
        grep -Eq 'https://t.me/ustarcorp_bot\?start=ui1_[A-Za-z0-9_-]{43}' "$work/issued.html"
    elif grep -Eq 'ui1_[A-Za-z0-9_-]{43}' "$work/issued.html"; then
        exit 1
    fi
done
echo 'CONNECT_OPERATOR_INVITATION_TLS_CSRF_REPLAY=PASS'
