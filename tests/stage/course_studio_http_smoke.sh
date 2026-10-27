#!/usr/bin/env bash
# Author session/API acceptance only on the disposable stage runtime.
set -Eeuo pipefail
test "$PWD" = /stage/moodle
test "${USTAR_STAGE_PREFIX:-}" = stage_
BASE=http://127.0.0.1:8087/local/ustar
COOKIE=/artifacts/shell-cookies.txt
code=$(curl -sS --max-time 30 -b "$COOKIE" -o /artifacts/studio.html -w '%{http_code}' "$BASE/course_studio.php")
test "$code" = 200
php -r '$s=file_get_contents("/artifacts/studio.html");foreach(["u-topbar", "u-course-studio-page", "class=\"u-course-studio\"", "id=\"u-main\""] as $part)if(!str_contains($s,$part))throw new RuntimeException("Studio is missing the native shell: ".$part);if(substr_count($s,"<main")!==1)throw new RuntimeException("Nested main landmark");'
php -r '$s=file_get_contents("/artifacts/studio.html");if(!preg_match("/window.COURSE_STUDIO=(.*?);window.RC_HOSTED_SYNC/s",$s,$m)||preg_match("/Debug info:|Stack trace:|ChatGPT/",$s))exit(1);$d=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);file_put_contents("/artifacts/studio-sesskey",$d["sesskey"]);file_put_contents("/artifacts/studio-user",$d["userId"]);'
sesskey=$(cat /artifacts/studio-sesskey)
actor=$(cat /artifacts/studio-user)
trap 'rm -f /artifacts/studio-sesskey /artifacts/studio-user' EXIT
php -r '$p=json_decode("{\"data\":{\"code\":\"HTTP-01\",\"title\":\"HTTP course\",\"version\":\"1.0\",\"pages\":[{\"id\":\"1\",\"title\":\"Start\",\"html\":\"<p>Lesson</p>\"}]},\"assets\":{},\"fonts\":[],\"theme\":{\"font\":\"Arial\",\"headingFont\":\"Arial\",\"fontSize\":17,\"headingSize\":34,\"lineHeight\":1.65,\"ink\":\"#2b2b2b\",\"accent\":\"#ebc500\",\"paper\":\"#ffffff\",\"background\":\"#ededed\"},\"catalogue\":{},\"revision\":0}");echo json_encode(["course"=>$p,"baseRevision"=>0,"contentmodified"=>0,"mutationId"=>"http-create-1"]);' > /artifacts/studio-input.json
code=$(curl -sS --max-time 30 -b "$COOKIE" -H 'Content-Type: application/json' --data-binary @/artifacts/studio-input.json -o /artifacts/studio-no-csrf.json -w '%{http_code}' "$BASE/course_studio_api.php?route=courses%2FHTTP-01")
test "$code" = 403
for repeat in 1 2; do
    code=$(curl -sS --max-time 60 -b "$COOKIE" -H 'Content-Type: application/json' -H "X-Moodle-Sesskey: $sesskey" -H "X-RC-Owner: $actor" --data-binary @/artifacts/studio-input.json -o "/artifacts/studio-save-$repeat.json" -w '%{http_code}' "$BASE/course_studio_api.php?route=courses%2FHTTP-01")
    test "$code" = 200 || { cat "/artifacts/studio-save-$repeat.json"; exit 1; }
done
php -r '$a=json_decode(file_get_contents("/artifacts/studio-save-1.json"),true,512,JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents("/artifacts/studio-save-2.json"),true,512,JSON_THROW_ON_ERROR);if($a["contentid"]!==$b["contentid"]||$a["revision"]!==$b["revision"])exit(1);file_put_contents("/artifacts/studio-prepare.json",json_encode(["code"=>"HTTP-01","revision"=>$a["revision"],"contentmodified"=>$a["contentmodified"]]));'
for repeat in 1 2; do
    code=$(curl -sS --max-time 90 -b "$COOKIE" -H 'Content-Type: application/json' -H "X-Moodle-Sesskey: $sesskey" -H "X-RC-Owner: $actor" --data-binary @/artifacts/studio-prepare.json -o "/artifacts/studio-ready-$repeat.json" -w '%{http_code}' "$BASE/course_studio_api.php?route=prepare")
    test "$code" = 200 || { cat "/artifacts/studio-ready-$repeat.json"; exit 1; }
done
php -r '$a=json_decode(file_get_contents("/artifacts/studio-ready-1.json"),true,512,JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents("/artifacts/studio-ready-2.json"),true,512,JSON_THROW_ON_ERROR);if($a["cmid"]<=0||$a["cmid"]!==$b["cmid"]||$a["status"]!=="draft")exit(1);'
code=$(curl -sS --max-time 30 -b "$COOKIE" -H 'X-RC-Owner: other-user' -o /artifacts/studio-wrong-user.json -w '%{http_code}' "$BASE/course_studio_api.php?route=courses")
test "$code" = 409
if grep -E 'USTAR_STUDIO_FAILURE|course_studio_api.php.*mutated the session' /artifacts/shell-http-server.log; then exit 1; fi
contentid=$(php -r '$d=json_decode(file_get_contents("/artifacts/studio-save-1.json"),true);echo (int)$d["contentid"];')
for repeat in 1 2; do
    code=$(curl -sS --max-time 30 -b "$COOKIE" -H "X-Moodle-Sesskey: $sesskey" -H "X-RC-Owner: $actor" -X POST -o "/artifacts/studio-material-open-$repeat.json" -w '%{http_code}' "$BASE/course_studio_api.php?route=materials%2F$contentid%2Fopen")
    test "$code" = 200
 done
php -r '$a=json_decode(file_get_contents("/artifacts/studio-material-open-1.json"),true,512,JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents("/artifacts/studio-material-open-2.json"),true,512,JSON_THROW_ON_ERROR);$s=json_decode(file_get_contents("/artifacts/studio-save-1.json"),true);if($a["kind"]!=="project"||$a["code"]!=="HTTP-01"||$a["contentid"]!==$s["contentid"]||$b!==$a)exit(1);'
code=$(curl -sS --max-time 30 -b "$COOKIE" -o /artifacts/studio-material-detail.html -w '%{http_code}' "$BASE/material.php?id=$contentid")
test "$code" = 200
code=$(curl -sS --max-time 30 -b "$COOKIE" -o /artifacts/studio-material-catalog.html -w '%{http_code}' "$BASE/materials.php?page=9999")
test "$code" = 200
php -r '$s=file_get_contents("/artifacts/studio-material-detail.html");if(!str_contains($s,"Открыть в студии")||!str_contains($s,"course_studio.php?materialid="))exit(1);$s.=file_get_contents("/artifacts/studio-material-catalog.html");foreach(["data-materials-view", "data-u-material-view=\"list\"", "data-u-material-view=\"blocks\"", "data-u-material-view=\"cards\""] as $part)if(!str_contains($s,$part))exit(1);if(preg_match("/Debug info:|Stack trace:|Warning:/",$s))exit(1);'
echo 'COURSE_STUDIO_MATERIAL_HTTP=PASS open exact-draft retry actor session'
echo 'COURSE_STUDIO_AUTHENTICATED_HTTP=PASS csrf shared-create retry prepare actor'
