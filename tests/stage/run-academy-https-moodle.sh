#!/usr/bin/env bash
set -euo pipefail
test "$PWD" = /stage/moodle
test -f /source/.stage-input/academy-https-config.php
test -f /source/.stage-input/private-bridge.php
test ! -e /stage/moodle/.ustar-connect
ustar_https_fixture_backup=$(mktemp /stage/academy-config.XXXXXXXX)
cp config.php "$ustar_https_fixture_backup"
ustar_https_fixture_pid=''
ustar_https_fixture_cleanup() {
    if [ -n "$ustar_https_fixture_pid" ]; then
        kill "$ustar_https_fixture_pid" 2>/dev/null || true
        wait "$ustar_https_fixture_pid" 2>/dev/null || true
    fi
    cp "$ustar_https_fixture_backup" config.php
    rm -f "$ustar_https_fixture_backup"
    rm -f /stage/moodle/.ustar-connect/bridge.php
    rmdir /stage/moodle/.ustar-connect
}
trap ustar_https_fixture_cleanup EXIT
cp /source/.stage-input/academy-https-config.php config.php
mkdir -m 0750 /stage/moodle/.ustar-connect
chown 0:33 /stage/moodle/.ustar-connect
cp /source/.stage-input/private-bridge.php /stage/moodle/.ustar-connect/bridge.php
chown 0:33 /stage/moodle/.ustar-connect/bridge.php
chmod 0640 /stage/moodle/.ustar-connect/bridge.php
php -r 'if(!posix_setgid(33)||!posix_setuid(33)){throw new RuntimeException("fixture_uid_drop_failed");}$CFG=new stdClass();require "/stage/moodle/.ustar-connect/bridge.php";if($CFG->ustar_connect_bridge_enabled!==true||is_writable("/stage/moodle/.ustar-connect/bridge.php")||is_writable("/stage/moodle/.ustar-connect")){throw new RuntimeException("private_include_not_readonly_for_PHP");}echo "PRIVATE_INCLUDE_UID33=PASS; root-owned, group-readable, not writable\\n";'
php -S 127.0.0.1:8087 -t public /source/tests/stage/academy_https_moodle.php > /artifacts/academy-https-moodle-server.log 2>&1 &
ustar_https_fixture_pid=$!
for attempt in $(seq 1 40); do
    if curl --noproxy '*' -fsS -H 'Host: ustar.local' \
        -D /artifacts/academy-https-moodle-headers.txt \
        http://127.0.0.1:8087/ -o /artifacts/academy-https-moodle.json; then break; fi
    sleep 0.2
done
php -r '$v=json_decode(file_get_contents("/artifacts/academy-https-moodle.json"),true,512,JSON_THROW_ON_ERROR);if($v!==["url"=>"https://ustar.local","sslproxy"=>true,"cookiesecure"=>true,"server_https"=>"on"]){throw new RuntimeException("Moodle HTTPS fixture mismatch");} $h=file_get_contents("/artifacts/academy-https-moodle-headers.txt");if(!preg_match("/Set-Cookie: MoodleSession[^\\r\\n]*;\\s*secure(?:;|\\r?$)/im",$h)){throw new RuntimeException("Secure Moodle session cookie missing");}echo "ACADEMY_MOODLE_HTTPS_BOOTSTRAP=PASS; real Moodle sslproxy and secure session\\n";'
php /source/tests/stage/connect_scope_probe.php
