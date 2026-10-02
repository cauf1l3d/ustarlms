<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);
// Native messaging calls the renderer even while building its read model.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/messages.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Сообщения | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->js(new moodle_url('/local/ustar/messages.js', ['v' => \local_ustar\mobile_app::VERSION]));
$conversationid = optional_param('conversationid', 0, PARAM_INT);
$offset = max(0, optional_param('offset', 0, PARAM_INT));
$listoffset = max(0, optional_param('listoffset', 0, PARAM_INT));
$q = trim(optional_param('q', '', PARAM_TEXT));
$notice = '';
$draft = '';
$available = !empty($CFG->messaging) && !\local_ustar\view_as::active()
    && \local_ustar\employment::is_active((int)$USER->id);
$requestid = bin2hex(random_bytes(16));
if ($available && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    try {
        if ($action === 'send') {
            $draft = optional_param('message', '', PARAM_TEXT);
            $requestid = required_param('requestid', PARAM_ALPHANUMEXT);
            \local_ustar\communication::send((int)$USER->id, $conversationid, $draft,
                \local_ustar\task_files::uploaded(), $requestid);
        } else if ($action === 'start') {
            $conversationid = \local_ustar\communication::start((int)$USER->id, required_param('userid', PARAM_INT));
        } else if ($action === 'create') {
            $conversationid = \local_ustar\chat_groups::create((int)$USER->id,
                required_param('name', PARAM_TEXT), optional_param_array('members', [], PARAM_INT));
        } else if (in_array($action, ['rename', 'add', 'remove'], true)) {
            \local_ustar\chat_groups::change((int)$USER->id, $conversationid, $action,
                optional_param('name', '', PARAM_TEXT), optional_param_array('members', [], PARAM_INT));
        } else { throw new invalid_parameter_exception('Неизвестное действие.'); }
        redirect(new moodle_url('/local/ustar/messages.php', ['conversationid' => $conversationid]));
    } catch (invalid_parameter_exception | required_capability_exception $e) {
        $notice = $e->getMessage();
    }
}
$conversations = $available ? \local_ustar\communication::conversations((int)$USER->id, 51, $listoffset) : [];
$hasmorechats = count($conversations) > 50;
$conversations = array_slice($conversations, 0, 50);
// Mobile first visit shows the list, rather than opening an arbitrary chat.
$current = null;
if ($available && $conversationid > 0) {
    try { $current = \local_ustar\communication::conversation((int)$USER->id, $conversationid, $offset); }
    catch (invalid_parameter_exception $e) { $notice = $e->getMessage(); $conversationid = 0; }
}
$searchresults = [];
$searcherror = '';
try { if ($available && $q !== '') { $searchresults = \local_ustar\communication::search_users((int)$USER->id, $q); } }
catch (Throwable $e) { $searcherror = 'Поиск сейчас недоступен. Повторите попытку.'; }
$data = [
    'available' => $available, 'notice' => $notice, 'draft' => $draft, 'requestid' => $requestid,
    'conversations' => $conversations, 'hasconversations' => !empty($conversations),
    'hasmorechats' => $hasmorechats, 'haspreviouschats' => $listoffset > 0,
    'morechatsurl' => (new moodle_url('/local/ustar/messages.php', ['listoffset' => $listoffset + 50]))->out(false),
    'previouschatsurl' => (new moodle_url('/local/ustar/messages.php', ['listoffset' => max(0, $listoffset - 50)]))->out(false),
    'current' => $current, 'hascurrent' => $current !== null, 'conversationid' => $conversationid,
    'searchq' => $q, 'searcherror' => $searcherror, 'searchresults' => $searchresults,
    'hassearchresults' => !empty($searchresults), 'searched' => $q !== '', 'sesskey' => sesskey(),
    'cancreate' => $available && \local_ustar\chat_groups::can_create((int)$USER->id),
    'apiurl' => (new moodle_url('/local/ustar/messages_api.php'))->out(false),
    'listurl' => (new moodle_url('/local/ustar/messages.php', ['list' => 1]))->out(false),
    'nativeurl' => (new moodle_url('/message/index.php'))->out(false),
    'maxbytes' => \local_ustar\chat_files::max_bytes(),
    'maxsize' => display_size(\local_ustar\chat_files::max_bytes()),
    'maxpostbytes' => get_real_size(ini_get('post_max_size')),
    'messageicon' => \local_ustar\ui::icon('message', 'u-feature-icon'),
    'searchicon' => \local_ustar\ui::icon('search', 'u-feature-icon'),
];
$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/messages', $data);
echo $output->footer();
