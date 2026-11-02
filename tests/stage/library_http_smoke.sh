#!/usr/bin/env bash
# Called while the existing synthetic admin HTTP session/server are live.
set -Eeuo pipefail
library_check_id=$(php /source/tests/stage/library_http_fixture.php)
for library_target in library.php library.php?tab=catalog library.php?tab=shop library.php?tab=checks library.php?tab=route library_manage.php "library_check.php?id=$library_check_id"; do
    library_page=${library_target//[^a-zA-Z0-9]/_}
    library_status=$(curl -sS --max-time 60 -b /artifacts/shell-cookies.txt -o "/artifacts/library-http-$library_page.html" -w '%{http_code}' "http://127.0.0.1:8087/local/ustar/$library_target")
    test "$library_status" = 200 || { echo "LIBRARY_HTTP_FAILED=$library_target:$library_status";exit 1; }
    php -r '$s=file_get_contents($argv[1]);if (strpos($s,"u-library")===false || preg_match("/codingerror|Debug info:|Stack trace:|Fatal error:/i",$s)) {fwrite(STDERR,"LIBRARY_RENDER_FAILED\n");exit(1);}' "/artifacts/library-http-$library_page.html"
done
php /source/tests/stage/library_http_fixture.php verify
for library_old in knowledge.php catalog.php; do
    library_redirect=$(curl -sS --max-time 15 -b /artifacts/shell-cookies.txt -o /dev/null -w '%{redirect_url}' "http://127.0.0.1:8087/local/ustar/$library_old")
    [[ $library_redirect == *'/local/ustar/library.php'* ]] || { echo "LIBRARY_REDIRECT_FAILED=$library_old";exit 1; }
done
echo 'LIBRARY_HTTP=PASS authenticated tabs/manage/check;no GET attempt writes;legacy redirects'
