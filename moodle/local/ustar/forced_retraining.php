<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/ustar:use', context_system::instance());
\local_ustar\view_as::assert_writable();

global $USER;

$context = context_system::instance();
if (!\local_ustar\forced_retraining::can_manage((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', '');
}

$userid = optional_param('userid', 0, PARAM_INT);
$assigned = optional_param('assigned', 0, PARAM_INT);
$targets = \local_ustar\forced_retraining::targets((int)$USER->id);
$targetids = array_map(static fn(array $row): int => (int)$row['id'], $targets);
if ($userid <= 0 || !in_array($userid, $targetids, true)) {
    $userid = $targetids ? (int)$targetids[0] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'assign') {
        $targetuserid = required_param('targetuserid', PARAM_INT);
        $policyids = optional_param_array('policyids', [], PARAM_INT);
        $reason = required_param('reason', PARAM_TEXT);
        $ids = \local_ustar\forced_retraining::assign(
            (int)$USER->id,
            $targetuserid,
            $policyids,
            $reason
        );
        redirect(new moodle_url('/local/ustar/forced_retraining.php', [
            'userid' => $targetuserid,
            'assigned' => count($ids),
        ]));
    }
}

$selected = null;
foreach ($targets as &$target) {
    $target['selected'] = (int)$target['id'] === $userid;
    if ($target['selected']) {
        $selected = $target;
    }
}
unset($target);

$topics = $userid > 0
    ? \local_ustar\forced_retraining::topics_for_user((int)$USER->id, $userid)
    : [];
$active = $userid > 0
    ? \local_ustar\forced_retraining::active_for_manager((int)$USER->id, $userid)
    : [];
foreach ($active as &$item) {
    $item['assigneddate'] = userdate((int)$item['assignedat'], get_string('strftimedatetimeshort', 'langconfig'));
}
unset($item);

$data = [
    'teamurl' => (new moodle_url('/local/ustar/team.php'))->out(false),
    'selfurl' => (new moodle_url('/local/ustar/forced_retraining.php'))->out(false),
    'targets' => $targets,
    'hastargets' => !empty($targets),
    'selected' => $selected,
    'hasselected' => !empty($selected),
    'topics' => $topics,
    'hastopics' => !empty($topics),
    'active' => $active,
    'hasactive' => !empty($active),
    'sesskey' => sesskey(),
    'assigned' => $assigned,
    'assignedok' => $assigned > 0,
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/forced_retraining.php', $userid > 0 ? ['userid' => $userid] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Принудительное переобучение | USTAR');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/forced_retraining.css'));

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/forced_retraining', $data);
echo $output->footer();
