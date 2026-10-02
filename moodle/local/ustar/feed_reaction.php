<?php
require_once(__DIR__ . '/../../config.php');

// This endpoint has one response contract. A failed reaction must not turn into
// a successful HTML redirect that the feed mistakes for a failed like.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new invalid_parameter_exception('Недопустимый запрос.');
    }
    require_login();
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if (!in_array($action, ['like', 'unlike'], true)) {
        throw new invalid_parameter_exception('Неизвестная реакция.');
    }
    $postid = required_param('postid', PARAM_INT);
    $userid = (int)$USER->id;
    \local_ustar\feed_service::like($postid, $userid, $action === 'like');
    $conditions = ['postid' => $postid, 'userid' => $userid, 'kind' => 'like'];
    echo json_encode(['ok' => true,
        'liked' => $DB->record_exists('local_ustar_feed_reactions', $conditions),
        'count' => (int)$DB->count_records('local_ustar_feed_reactions',
            ['postid' => $postid, 'kind' => 'like'])]);
} catch (\Throwable $e) {
    http_response_code(400);
    error_log('USTAR feed reaction failed: ' . get_class($e));
    echo json_encode(['ok' => false, 'message' => 'Не удалось обновить реакцию. Повторите попытку.']);
}
