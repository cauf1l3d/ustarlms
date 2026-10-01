#!/usr/bin/env bash
# Called while authenticated_shell_smoke owns the isolated HTTP server.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
WEBROOT=http://127.0.0.1:8087
COOKIE=/artifacts/shell-cookies.txt
curl -fsS -b "$COOKIE" "$WEBROOT/local/ustar/messages.php" -o /artifacts/chat-start.html
sesskey=$(php -r '$s=file_get_contents("/artifacts/chat-start.html"); if(!preg_match("/data-sesskey=\"([^\"]+)\"/",$s,$m)){exit(1);}echo html_entity_decode($m[1]);')
read -r one two < <(php -r 'define("CLI_SCRIPT",true);require "/stage/moodle/config.php";echo $DB->get_field("user","id",["username"=>"chatone"])," ",$DB->get_field("user","id",["username"=>"chattwo"]),PHP_EOL;')
code=$(curl -sS -b "$COOKIE" -D /artifacts/chat-create-headers.txt -o /artifacts/chat-create.html -w '%{http_code}' \
    --data-urlencode "sesskey=$sesskey" --data 'action=create' --data-urlencode 'name=HTTP fixture group' \
    --data-urlencode "members[]=$one" --data-urlencode "members[]=$two" "$WEBROOT/local/ustar/messages.php")
test "$code" = 303
conversation=$(php -r '$s=file_get_contents("/artifacts/chat-create-headers.txt");if(!preg_match("/conversationid=([0-9]+)/",$s,$m)){exit(1);}echo $m[1];')
# An actual multipart upload is required: PHP must see is_uploaded_file() true.
cp /source/moodle/theme/ustar/pix/brand/ustar-app-icon.png /artifacts/chat-upload.png
printf 'Synthetic document\n' > /artifacts/chat-upload.txt
for attempt in 1 2; do
    curl -fsS -b "$COOKIE" -F "sesskey=$sesskey" -F action=send -F "conversationid=$conversation" \
        -F requestid=http_fixture_send_00000000000001 -F 'message=HTTP_MEDIA_FIXTURE' \
        -F 'attachments[]=@/artifacts/chat-upload.png;type=image/png' \
        -F 'attachments[]=@/artifacts/chat-upload.txt;type=text/plain' \
        "$WEBROOT/local/ustar/messages_api.php" -o /artifacts/chat-send.json
    php -r '$r=json_decode(file_get_contents("/artifacts/chat-send.json"),true); if(empty($r["ok"])||strpos($r["html"],"<img ")===false||strpos($r["html"],"Скачать")===false){echo json_encode($r);exit(1);} if(substr_count($r["html"],"HTTP_MEDIA_FIXTURE")!==1){exit(1);}echo "CHAT_MULTIPART_AND_RETRY=OK\n";'
done
code=$(curl -sS -b "$COOKIE" "$WEBROOT/local/ustar/messages.php?conversationid=$conversation" -o /artifacts/chat-thread.html -w '%{http_code}')
test "$code" = 200 || { echo "CHAT_THREAD_HTTP_FAILED=$code"; exit 1; }
php -r '$s=file_get_contents("/artifacts/chat-thread.html");if(strpos($s,"HTTP_MEDIA_FIXTURE")===false||preg_match("/Debug info:|Stack trace:|codingerror/i",$s)){exit(1);}echo "CHAT_AUTHENTICATED_RENDER=OK\n";'
fileurl=$(php -r '$r=json_decode(file_get_contents("/artifacts/chat-send.json"),true);if(!preg_match("/<img src=\"([^\"]+)\"/",$r["html"],$m)){exit(1);}echo html_entity_decode($m[1]);')
curl -fsS -b "$COOKIE" "$fileurl" -o /artifacts/chat-download.png
cmp /artifacts/chat-upload.png /artifacts/chat-download.png
# User not in the group must be denied both the API and direct file URL.
OUTSIDER=/artifacts/chat-outsider-cookie.txt
curl -fsS -c "$OUTSIDER" "$WEBROOT/login/index.php" -o /artifacts/chat-outsider-login.html
token=$(php -r '$s=file_get_contents("/artifacts/chat-outsider-login.html");if(!preg_match("/name=\"logintoken\"[^>]*value=\"([^\"]+)\"/",$s,$m)){exit(1);}echo html_entity_decode($m[1]);')
curl -fsS -b "$OUTSIDER" -c "$OUTSIDER" --data-urlencode username=chatoutsider \
    --data-urlencode 'password=Fixture-Only-Password1!' --data-urlencode "logintoken=$token" \
    "$WEBROOT/login/index.php" -o /artifacts/chat-outsider-result.html
curl -fsS -b "$OUTSIDER" "$WEBROOT/local/ustar/messages.php" -o /artifacts/chat-outsider-home.html
outsesskey=$(php -r '$s=file_get_contents("/artifacts/chat-outsider-home.html");if(!preg_match("/data-sesskey=\"([^\"]+)\"/",$s,$m)){exit(1);}echo html_entity_decode($m[1]);')
code=$(curl -sS -b "$OUTSIDER" --data-urlencode "sesskey=$outsesskey" --data "action=poll&conversationid=$conversation" \
    "$WEBROOT/local/ustar/messages_api.php" -o /artifacts/chat-outsider-poll.json -w '%{http_code}')
test "$code" = 400
code=$(curl -sS -b "$OUTSIDER" "$fileurl" -o /artifacts/chat-outsider-file.html -w '%{http_code}')
test "$code" = 404
# Worker cache assets are public; authenticated product data is not a cached asset.
curl -fsS "$WEBROOT/local/ustar/app_manifest.php" -o /artifacts/app-manifest.json
php -r '$r=json_decode(file_get_contents("/artifacts/app-manifest.json"),true);if($r["display"]!=="standalone"||count($r["icons"])!==2){exit(1);}echo "APP_MANIFEST=OK\n";'
curl -fsS -D /artifacts/app-worker-headers.txt "$WEBROOT/local/ustar/app_worker.php" -o /artifacts/app-worker.js
grep -q '^Service-Worker-Allowed: /' /artifacts/app-worker-headers.txt
curl -fsS "$WEBROOT/local/ustar/app_icon.php?size=192" -o /artifacts/app-icon.png
php -r '$s=getimagesize("/artifacts/app-icon.png");if($s[0]!==192||$s[1]!==192){exit(1);}echo "APP_ICON=OK\n";'
rm -f "$OUTSIDER"
echo 'WORKCHAT_AUTHENTICATED_HTTP=PASS'
