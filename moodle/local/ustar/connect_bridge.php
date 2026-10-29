<?php
// Private worker facade. Disabled until explicit trusted TLS/peer/key configuration.
define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    \local_ustar\connect\bridge_http::guard($_SERVER, ($CFG->ustar_connect_bridge_enabled ?? false) === true,
        (array)($CFG->ustar_connect_bridge_peers ?? ['127.0.0.1', '::1']));
    $body = file_get_contents('php://input', false, null, 0, 16385);
    $headers = [];
    foreach (['Key', 'Time', 'Nonce', 'Signature'] as $name) {
        $headers['X-Ustar-' . $name] = (string)($_SERVER['HTTP_X_USTAR_' . strtoupper($name)] ?? '');
    }
    $result = \local_ustar\connect\bridge_service::dispatch((string)$body, $headers,
        (array)($CFG->ustar_connect_bridge_keys ?? []));
    $flags=JSON_THROW_ON_ERROR;
    if(isset($result['title'],$result['buttons'])){$flags|=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;}
    echo json_encode(['ok' => true, 'result' => $result], $flags);
} catch (\local_ustar\registration_validation_exception $error) {
    http_response_code(422);
    echo json_encode(\local_ustar\connect\signup_request::validation_error($error));
} catch (\InvalidArgumentException $error) {
    http_response_code($error->getMessage() === 'bridge_disabled' ? 404 : 403);
    // All implementation errors use fixed reason codes, not supplied payloads.
    echo json_encode(['ok' => false, 'reason' => $error->getMessage()]);
} catch (\Throwable $error) {
    http_response_code(503);
    echo '{"ok":false,"reason":"bridge_unavailable"}';
}
