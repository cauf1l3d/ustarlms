<?php
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode(\local_ustar\mobile_app::manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
