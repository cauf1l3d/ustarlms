<?php
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new invalid_parameter_exception('Недопустимый запрос.');
    }
    require_login();

    $postid = required_param('postid', PARAM_INT);
    $payload = \local_ustar\feed_external_content::payload($postid, (int)$USER->id);

    echo json_encode(
        ['ok' => true] + $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (\Throwable $e) {
    http_response_code(400);
    error_log('USTAR external feed content failed: ' . get_class($e));
    echo json_encode([
        'ok' => false,
        'message' => 'Не удалось открыть полный материал внутри Ленты.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
