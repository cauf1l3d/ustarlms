<?php
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: ' . \local_ustar\mobile_app::base_path());
$assets = [
    (new moodle_url('/local/ustar/app_offline.php'))->out(false),
    (new moodle_url('/local/ustar/app_icon.php', ['size' => 192, 'v' => \local_ustar\mobile_app::VERSION]))->out(false),
];
echo 'const USTAR_APP_CACHE = ' . json_encode('ustar-app-' . \local_ustar\mobile_app::VERSION) . ';' . "\n";
echo 'const USTAR_PUBLIC_ASSETS = ' . json_encode($assets, JSON_UNESCAPED_SLASHES) . ';' . "\n";
readfile(__DIR__ . '/app_worker.js');
