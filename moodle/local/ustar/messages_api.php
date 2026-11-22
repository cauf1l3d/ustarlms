<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_ustar\{chat_history, chat_input_error, communication, task_files};
use local_ustar\task_workspace\discussion;

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/messages_api.php'));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$postlimit = get_real_size(ini_get('post_max_size'));
if ($postlimit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postlimit) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Размер запроса превышает лимит сервера. Отправьте файлы отдельно.']);
    exit;
}
try {
    require_sesskey();
    $userid = (int)$USER->id;
    $action = required_param('action', PARAM_ALPHA);
    // Native messages, read receipts and uploads use DB/locks. They do not save browser session data.
    \core\session\manager::write_close();
    if ($action === 'search') {
        echo json_encode(['ok' => true, 'users' => communication::search_users($userid, required_param('q', PARAM_TEXT))]);
        exit;
    }
    if (!in_array($action, ['send', 'poll'], true)) {
        throw new invalid_parameter_exception('Неизвестное действие.');
    }
    $conversationid = optional_param('conversationid', 0, PARAM_INT);
    $taskid = optional_param('taskid', 0, PARAM_INT);
    if (($taskid > 0) === ($conversationid > 0) || $taskid < 0 || $conversationid < 0) {
        throw new invalid_parameter_exception('Выберите чат.');
    }
    $delta = optional_param('delta', false, PARAM_BOOL);
    $known = $delta ? optional_param_array('known', [], PARAM_INT) : [];
    if (count($known) > chat_history::PAGE_SIZE) {
        throw new invalid_parameter_exception('Некорректная страница чата.');
    }
    if ($action === 'send') {
        communication::send($userid, $conversationid, optional_param('message', '', PARAM_TEXT), task_files::uploaded(),
            required_param('requestid', PARAM_ALPHANUMEXT), $taskid);
    }
    $read = $action === 'send' || optional_param('read', false, PARAM_BOOL);
    if ($taskid) {
        $thread = discussion::thread($userid, $taskid, 0, $delta ? $known : null, $read || !$delta);
    } else {
        $thread = $delta ? communication::update($userid, $conversationid, $known, $read)
            : communication::conversation($userid, $conversationid);
    }
    $html = '';
    if (!$delta || $thread['messages']) {
        $html = $PAGE->get_renderer('local_ustar')->render_from_template('local_ustar/messages_thread',
            $delta ? ['fragment' => true, 'messages' => $thread['messages'], 'hasmessages' => true] : $thread);
    }
    $result = ['ok' => true, 'html' => $html, 'cansend' => $thread['cansend']];
    echo json_encode($result + ($delta ? [
        'delta' => true, 'ids' => $thread['ids'], 'olderurl' => $thread['hasolder'] ? $thread['olderurl'] : '',
    ] : ['signature' => hash('sha256', $html)]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $known = $e instanceof invalid_parameter_exception || $e instanceof required_capability_exception;
    $scanner = $e instanceof \core\antivirus\scanner_exception;
    $session = $e instanceof moodle_exception && $e->errorcode === 'invalidsesskey';
    http_response_code($known || $scanner || $session ? 400 : 500);
    $message = match (true) {
        $e instanceof chat_input_error => $e->notice,
        $scanner => 'Антивирусная проверка не разрешила отправку вложения.',
        $known => $e->getMessage(),
        default => 'Действие не выполнено. Проверьте подключение и доступ к чату.',
    };
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}
