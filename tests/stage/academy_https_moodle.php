<?php
// Native HTTP request through actual Moodle 5.1.8 bootstrap, fixture only.
if (__DIR__ !== '/source/tests/stage' || !is_dir('/stage/moodle')) {
    http_response_code(500);
    exit('isolated_fixture_required');
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/local/ustar/connect_bridge.php') {
    register_shutdown_function(static function(): void {
        global $CFG;
        $server = array_intersect_key($_SERVER, array_flip(['REMOTE_ADDR', 'HTTPS', 'REQUEST_METHOD',
            'REQUEST_URI', 'QUERY_STRING', 'CONTENT_TYPE', 'CONTENT_LENGTH']));
        error_log('SCOPE_FIXTURE_METADATA=' . json_encode(['server' => $server,
            'enabled' => $CFG->ustar_connect_bridge_enabled ?? false,
            'peers' => $CFG->ustar_connect_bridge_peers ?? [], 'status' => http_response_code()]));
    });
    require('/stage/moodle/public/local/ustar/connect_bridge.php');
    exit;
}
require('/stage/moodle/config.php');
header('Content-Type: application/json');
echo json_encode([
    'url' => $CFG->wwwroot,
    'sslproxy' => !empty($CFG->sslproxy),
    'cookiesecure' => !empty($CFG->cookiesecure),
    'server_https' => $_SERVER['HTTPS'] ?? null,
]);
