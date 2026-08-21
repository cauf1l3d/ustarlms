<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/ustar:use', context_system::instance());
header('Content-Type: application/json; charset=utf-8');
$q=required_param('q',PARAM_TEXT);
try {
    echo json_encode(['ok'=>true,'groups'=>\local_ustar\global_search::run((int)$USER->id,$q)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Поиск временно недоступен'],JSON_UNESCAPED_UNICODE);
}
