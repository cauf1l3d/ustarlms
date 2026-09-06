<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER;

$context = context_system::instance();
\local_ustar\adaptation_service::require_hrd_actor((int)$USER->id);
\local_ustar\view_as::assert_writable();

$selectedid = optional_param('adaptationid', 0, PARAM_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHANUMEXT);
    try {
        $adaptationid = required_param('adaptationid', PARAM_INT);
        if ($action === 'takecase') {
            \local_ustar\adaptation_service::take_case($adaptationid, required_param('fingerprint', PARAM_RAW_TRIMMED), (int)$USER->id);
            redirect(new moodle_url('/local/ustar/adaptation_control.php', ['adaptationid' => $adaptationid]), 'Эскалация взята в контроль', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'resolvecase') {
            \local_ustar\adaptation_service::resolve_case($adaptationid, required_param('fingerprint', PARAM_RAW_TRIMMED), (int)$USER->id, required_param('resolution', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/adaptation_control.php', ['adaptationid' => $adaptationid]), 'Эскалация закрыта', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'hrdfinal') {
            \local_ustar\adaptation_service::hrd_final_decision($adaptationid, (int)$USER->id, required_param('decision', PARAM_ALPHA), required_param('reason', PARAM_TEXT), optional_param('extensiondays', 0, PARAM_INT));
            redirect(new moodle_url('/local/ustar/adaptation_control.php', ['adaptationid' => $adaptationid]), 'Решение HRD сохранено', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\Throwable $e) {
        \core\notification::error($e->getMessage());
    }
}

$data = \local_ustar\adaptation_service::hr_control_context((int)$USER->id, $selectedid);
$data['sesskey'] = sesskey();
$data['operationsurl'] = (new moodle_url('/local/ustar/operations.php'))->out(false);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/adaptation_control.php', $selectedid ? ['adaptationid' => $selectedid] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Контроль адаптации | USTAR');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/adaptation_control.css'));

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/adaptation_control', $data);
echo $output->footer();
