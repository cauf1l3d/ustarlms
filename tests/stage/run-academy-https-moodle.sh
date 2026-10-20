#!/usr/bin/env bash
set -euo pipefail
test "$PWD" = /stage/moodle
test -f /source/.stage-input/academy-https-config.php
ustar_https_fixture_backup=$(mktemp /stage/academy-config.XXXXXXXX)
cp config.php "$ustar_https_fixture_backup"
ustar_https_fixture_pid=''
ustar_https_fixture_cleanup() {
    cp "$ustar_https_fixture_backup" config.php
    rm -f "$ustar_https_fixture_backup"
    if [ -n "$ustar_https_fixture_pid" ]; then kill "$ustar_https_fixture_pid" 2>/dev/null || true; fi
}
trap ustar_https_fixture_cleanup EXIT
cp /source/.stage-input/academy-https-config.php config.php
php -S 127.0.0.1:8087 -t public /source/tests/stage/academy_https_moodle.php > /artifacts/academy-https-moodle-server.log 2>&1 &
ustar_https_fixture_pid=$!
for attempt in $(seq 1 40); do
    if curl --noproxy '*' -fsS -H 'Host: ustar.local' \
        -D /artifacts/academy-https-moodle-headers.txt \
        http://127.0.0.1:8087/ -o /artifacts/academy-https-moodle.json; then break; fi
    sleep 0.2
done
php -r '$v=json_decode(file_get_contents("/artifacts/academy-https-moodle.json"),true,512,JSON_THROW_ON_ERROR);if($v!==["url"=>"https://ustar.local","sslproxy"=>true,"cookiesecure"=>true,"server_https"=>"on"]){throw new RuntimeException("Moodle HTTPS fixture mismatch");} $h=file_get_contents("/artifacts/academy-https-moodle-headers.txt");if(!preg_match("/Set-Cookie: MoodleSession[^\\r\\n]*;\\s*secure(?:;|\\r?$)/im",$h)){throw new RuntimeException("Secure Moodle session cookie missing");}echo "ACADEMY_MOODLE_HTTPS_BOOTSTRAP=PASS; real Moodle sslproxy and secure session\\n";'
