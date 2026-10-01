<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/messages_api.php'));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    require_sesskey();
    $userid = (int)$USER->id;
    $action = required_param('action', PARAM_ALPHA);
    $conversationid = optional_param('conversationid', 0, PARAM_INT);
    if ($action === 'search') {
        echo json_encode(['ok' => true, 'users' => \local_ustar\communication::search_users(
            $userid, required_param('q', PARAM_TEXT))]);
        exit;
    }
    if ($action === 'send') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new invalid_parameter_exception('POST required'); }
        \local_ustar\communication::send($userid, $conversationid,
            optional_param('message', '', PARAM_TEXT), \local_ustar\task_files::uploaded(),
            required_param('requestid', PARAM_ALPHANUMEXT));
    } else if ($action !== 'poll') {
        throw new invalid_parameter_exception('Неизвестное действие.');
    }
    $thread = \local_ustar\communication::conversation($userid, $conversationid);
    $html = $PAGE->get_renderer('local_ustar')->render_from_template('local_ustar/messages_thread', $thread);
    echo json_encode(['ok' => true, 'html' => $html, 'cansend' => $thread['cansend'],
        'signature' => hash('sha256', $html)]);
} catch (Throwable $e) {
    http_response_code(400);
    $known = $e instanceof invalid_parameter_exception || $e instanceof required_capability_exception;
    echo json_encode(['ok' => false, 'error' => $known ? $e->getMessage()
        : 'Действие не выполнено. Проверьте подключение и доступ к чату.']);
}
