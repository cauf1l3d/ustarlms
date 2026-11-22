<?php
require_once(__DIR__ . '/../../config.php');

use local_ustar\{chat_groups, chat_history, chat_input_error, chat_view, communication, task_files};
use local_ustar\task_workspace\discussion;

require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/messages.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Сообщения | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->js(new moodle_url('/local/ustar/messages.js', ['v' => communication::ASSET_VERSION]));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/workchat.css', ['v' => communication::ASSET_VERSION]));
$userid = (int)$USER->id;
$taskid = max(0, optional_param('taskid', 0, PARAM_INT));
$taskmode = $taskid || optional_param('taskchats', false, PARAM_BOOL);
$conversationid = $taskmode ? 0 : max(0, optional_param('conversationid', 0, PARAM_INT));
$offset = max(0, optional_param('offset', 0, PARAM_INT));
$before = max(0, optional_param('before', 0, PARAM_INT));
$listoffset = min(100000, max(0, optional_param('listoffset', 0, PARAM_INT)));
$q = core_text::substr(trim(optional_param('q', '', PARAM_TEXT)), 0, 120);
$find = core_text::substr(trim(optional_param('find', '', PARAM_TEXT)), 0, 120);
$notice = '';
$draft = '';
$requestid = '';
$available = !empty($CFG->messaging) && !\local_ustar\view_as::active() && \local_ustar\employment::is_active($userid);
$baseurl = new moodle_url('/local/ustar/messages.php');
$listurl = $taskmode ? discussion::url() : $baseurl;
if ($available && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    try {
        if ($action === 'send' && ($taskid || $conversationid)) {
            $draft = optional_param('message', '', PARAM_TEXT);
            $requestid = required_param('requestid', PARAM_ALPHANUMEXT);
            communication::send($userid, $conversationid, $draft, task_files::uploaded(), $requestid, $taskid);
        } else if (!$taskmode && $action === 'start') {
            $conversationid = communication::start($userid, required_param('userid', PARAM_INT));
        } else if (!$taskmode && $action === 'create') {
            $conversationid = chat_groups::create($userid, required_param('name', PARAM_TEXT),
                optional_param_array('members', [], PARAM_INT));
        } else if (!$taskmode && in_array($action, ['rename', 'add', 'remove'], true)) {
            chat_groups::change($userid, $conversationid, $action, optional_param('name', '', PARAM_TEXT),
                optional_param_array('members', [], PARAM_INT));
        } else {
            throw new invalid_parameter_exception('Неизвестное действие.');
        }
        redirect($taskid ? discussion::url($taskid) : communication::conversation_url($conversationid));
    } catch (invalid_parameter_exception | required_capability_exception $e) {
        $notice = $e instanceof chat_input_error ? $e->notice : $e->getMessage();
    }
}

$conversations = [];
if ($available) {
    $conversations = $taskmode ? discussion::inbox($userid, $listoffset, $q)
        : ($q !== '' ? communication::groups_by_title($userid, $q, $listoffset)
            : communication::conversations($userid, chat_history::PAGE_SIZE + 1, $listoffset));
}
$hasmorechats = count($conversations) > chat_history::PAGE_SIZE;
$conversations = array_slice($conversations, 0, chat_history::PAGE_SIZE);
$current = null;
$posturl = $taskid ? discussion::url($taskid) : communication::conversation_url($conversationid);
if ($available && ($taskid || $conversationid)) {
    try {
        $current = $taskid ? discussion::thread($userid, $taskid, $before, null, !$before && $find === '', $find)
            : communication::conversation($userid, $conversationid, $offset, $before, $find);
        $current += ['find' => $find, 'searchurl' => $baseurl->out(false), 'resetfindurl' => $posturl->out(false)];
    } catch (invalid_parameter_exception $e) {
        $notice = 'Чат или страница истории недоступны.';
        $taskid = $conversationid = 0;
    }
}
$searchresults = [];
$searcherror = '';
try {
    if ($available && !$taskmode && $q !== '') {
        $searchresults = communication::search_users($userid, $q);
    }
} catch (Throwable $e) {
    $searcherror = 'Поиск сейчас недоступен. Повторите попытку.';
}
$pageurl = static function (int $offset) use ($listurl, $q): string {
    $url = clone $listurl;
    $url->param('listoffset', max(0, $offset));
    if ($q !== '') {
        $url->param('q', $q);
    }
    return $url->out(false);
};
$data = chat_view::composer($posturl, $draft, $requestid) + [
    'available' => $available, 'notice' => $notice, 'taskmode' => $taskmode, 'taskid' => $taskid,
    'conversations' => $conversations, 'hasconversations' => (bool)$conversations, 'hasmorechats' => $hasmorechats,
    'haspreviouschats' => $listoffset > 0, 'morechatsurl' => $pageurl($listoffset + chat_history::PAGE_SIZE),
    'previouschatsurl' => $pageurl($listoffset - chat_history::PAGE_SIZE),
    'current' => $current, 'hascurrent' => $current !== null, 'conversationid' => $conversationid,
    'searchq' => $q, 'searcherror' => $searcherror, 'searchresults' => $searchresults, 'filtered' => $q !== '',
    'hassearchresults' => (bool)$searchresults, 'searched' => !$taskmode && $q !== '',
    'cancreate' => $available && !$taskmode && chat_groups::can_create($userid),
    'listurl' => $listurl->out(false), 'tasksurl' => discussion::url()->out(false),
    'messagesurl' => $baseurl->out(false), 'nativeurl' => (new moodle_url('/message/index.php'))->out(false),
    'messageicon' => \local_ustar\ui::icon('message', 'u-feature-icon'),
    'searchicon' => \local_ustar\ui::icon('search', 'u-feature-icon'),
];
$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/messages', $data);
echo $output->footer();
