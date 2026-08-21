<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'all') {
        \local_ustar\communication::mark_all_notifications((int)$USER->id);
    } else if ($action === 'read') {
        $id = required_param('id', PARAM_INT);
        \local_ustar\communication::mark_notification((int)$USER->id, $id);
    }
    redirect(new moodle_url('/local/ustar/notifications.php'));
}

$rows = \local_ustar\communication::notifications((int)$USER->id);
$unread = count(array_filter($rows, static fn(array $row): bool => !empty($row['unread'])));

$data = [
    'notifications' => $rows,
    'hasnotifications' => !empty($rows),
    'unread' => $unread,
    'hasunread' => $unread > 0,
    'sesskey' => sesskey(),
    'bellicon' => \local_ustar\ui::icon('bell', 'u-feature-icon'),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/notifications.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Уведомления | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/notifications', $data);
echo $output->footer();
