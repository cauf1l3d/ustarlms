<?php
require_once(__DIR__ . '/../../config.php');

use local_ustar\connect\announcements;
use local_ustar\feed_service;
use local_ustar\task_files;

require_login();
$actorid = (int)$USER->id;
announcements::require_manager($actorid);
$postid = optional_param('postid', 0, PARAM_INT);
$base = new moodle_url('/local/ustar/connect_announcements.php');
$url = new moodle_url($base, $postid ? ['postid' => $postid] : []);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Рассылки Telegram | USTAR');
$PAGE->set_heading('Рассылки Telegram');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/ux_tools.css'));
header('Cache-Control: no-store, private');

$error = '';
$preview = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_sesskey();
        if (required_param('action', PARAM_ALPHA) === 'create') {
            $postid = feed_service::create($actorid, 'academy', 'academy',
                ['department:' . required_param('department', PARAM_ALPHANUMEXT)], required_param('body', PARAM_TEXT),
                true, 0, task_files::uploaded(), required_param('requestkey', PARAM_ALPHANUMEXT));
            redirect(new moodle_url($base, ['postid' => $postid]), 'Публикация готова. Выберите получателей в Telegram.');
        }
        $sent = announcements::enqueue($actorid, $postid, required_param('fingerprint', PARAM_ALPHANUM),
            optional_param_array('recipients', [], PARAM_INT));
        redirect($url, "Поставлено в очередь: $sent.");
    }
} catch (invalid_parameter_exception $failure) {
    $error = $failure->debuginfo;
}
if ($postid > 0) {
    try {
        $preview = announcements::preview($actorid, $postid);
    } catch (invalid_parameter_exception $failure) {
        $error = $failure->debuginfo;
    }
}
$posts = $DB->get_records_select('local_ustar_feed_posts',
    "status = :published AND publishertype IN ('academy', 'department') AND COALESCE(sourcepostid, 0) = 0",
    ['published' => 'published'], 'publishedat DESC, id DESC', 'id,body,publishedat', 0, 50);
$options = [];
foreach ($posts as $post) {
    $options[] = [
        'id' => $post->id, 'title' => shorten_text($post->body, 80, true),
        'date' => userdate((int)$post->publishedat, '%d.%m.%Y'), 'selected' => (int)$post->id === $postid,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/connect_announcements', [
    'base' => $base->out(false), 'formurl' => $url->out(false), 'options' => $options,
    'departments' => \local_ustar\competition::department_options(),
    'requestkey' => bin2hex(random_bytes(16)),
    'connecturl' => (new moodle_url('/local/ustar/connect.php'))->out(false),
    'enabled' => announcements::enabled(), 'error' => $error, 'preview' => $preview, 'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
