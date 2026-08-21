<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$conversationid = optional_param('conversationid', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'send') {
        $conversationid = required_param('conversationid', PARAM_INT);
        $message = required_param('message', PARAM_TEXT);
        \local_ustar\communication::send((int)$USER->id, $conversationid, $message);
        redirect(new moodle_url('/local/ustar/messages.php', ['conversationid' => $conversationid]));
    }

    if ($action === 'start') {
        $otheruserid = required_param('userid', PARAM_INT);
        $conversationid = \local_ustar\communication::start((int)$USER->id, $otheruserid);
        redirect(new moodle_url('/local/ustar/messages.php', ['conversationid' => $conversationid]));
    }
}

$conversations = \local_ustar\communication::conversations((int)$USER->id);
if ($conversationid <= 0 && $conversations) {
    $conversationid = (int)$conversations[0]['id'];
}

$current = null;
if ($conversationid > 0) {
    $current = \local_ustar\communication::conversation((int)$USER->id, $conversationid);
}

$searchresults = $q !== '' ? \local_ustar\communication::search_users((int)$USER->id, $q) : [];
foreach ($searchresults as &$result) {
    $result['sesskey'] = sesskey();
}
unset($result);

$data = [
    'conversations' => $conversations,
    'hasconversations' => !empty($conversations),
    'current' => $current,
    'hascurrent' => $current !== null,
    'searchq' => s($q),
    'searchresults' => $searchresults,
    'hassearchresults' => !empty($searchresults),
    'searched' => $q !== '',
    'sesskey' => sesskey(),
    'messageicon' => \local_ustar\ui::icon('message', 'u-feature-icon'),
    'searchicon' => \local_ustar\ui::icon('search', 'u-feature-icon'),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/messages.php', $conversationid ? ['conversationid' => $conversationid] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Сообщения | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/messages', $data);
echo $output->footer();
