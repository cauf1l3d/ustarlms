<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_ustar\briefing\{commands, input_error, meetings};

require_login();
require_capability('local/ustar:use', context_system::instance());
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
try {
    require_sesskey();
    \core\session\manager::write_close();
    $result = meetings::availability((int)$USER->id, optional_param('meetingid', 0, PARAM_INT),
        required_param('roomid', PARAM_INT), commands::local_time(required_param('starttime', PARAM_RAW_TRIMMED)),
        commands::local_time(required_param('endtime', PARAM_RAW_TRIMMED)), optional_param_array('participants', [], PARAM_INT));
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (input_error $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->user_message()], JSON_UNESCAPED_UNICODE);
} catch (required_capability_exception $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Проверка занятости недоступна для вашей роли.'], JSON_UNESCAPED_UNICODE);
} catch (moodle_exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Проверьте дату, время и состав встречи.'], JSON_UNESCAPED_UNICODE);
}
