<?php
// Native HTTP request through actual Moodle 5.1.8 bootstrap, fixture only.
if (__DIR__ !== '/source/tests/stage' || !is_dir('/stage/moodle')) {
    http_response_code(500);
    exit('isolated_fixture_required');
}
require('/stage/moodle/config.php');
header('Content-Type: application/json');
echo json_encode([
    'url' => $CFG->wwwroot,
    'sslproxy' => !empty($CFG->sslproxy),
    'cookiesecure' => !empty($CFG->cookiesecure),
    'server_https' => $_SERVER['HTTPS'] ?? null,
]);
