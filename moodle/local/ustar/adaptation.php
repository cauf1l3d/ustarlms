<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);
\local_ustar\view_as::assert_writable();

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'daily', PARAM_ALPHA);
if (!in_array($mode, ['daily', 'final'], true)) {
    $mode = 'daily';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', $mode === 'final' ? 'submitfinal' : 'submitdaily', PARAM_ALPHANUMEXT);
    try {
        if ($action === 'submitdaily') {
            \local_ustar\adaptation_service::submit_daily($id, (int)$USER->id, [
                'mastered' => required_param('mastered', PARAM_TEXT),
                'succeeded' => required_param('succeeded', PARAM_TEXT),
                'difficult' => required_param('difficult', PARAM_TEXT),
                'help' => required_param('help', PARAM_ALPHANUMEXT),
                'ready' => required_param('ready', PARAM_ALPHANUMEXT),
                'action' => optional_param('nextaction', '', PARAM_TEXT),
                'overduereason' => optional_param('overduereason', '', PARAM_TEXT),
            ]);
            redirect(new moodle_url('/local/ustar/adaptation.php', ['id' => $id, 'saved' => 1]),
                'Адаптационный лист сохранён', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'resolveissue') {
            \local_ustar\adaptation_service::resolve_daily_issue(
                $id,
                (int)$USER->id,
                required_param('submissionid', PARAM_INT),
                required_param('resolution', PARAM_TEXT)
            );
            redirect(new moodle_url('/local/ustar/adaptation.php', ['id' => $id, 'saved' => 1]),
                'Действие закрыто руководителем', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'submitfinal') {
            \local_ustar\adaptation_service::submit_final_report($id, (int)$USER->id, [
                'mastered' => required_param('mastered', PARAM_TEXT),
                'risks' => required_param('risks', PARAM_TEXT),
                'support' => required_param('support', PARAM_TEXT),
                'ready' => required_param('ready', PARAM_ALPHANUMEXT),
            ]);
            redirect(new moodle_url('/local/ustar/adaptation.php', ['id' => $id, 'mode' => 'final', 'saved' => 1]),
                'Итоговый отчёт сохранён', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'finaldecision') {
            \local_ustar\adaptation_service::manager_final_decision(
                $id,
                (int)$USER->id,
                required_param('decision', PARAM_ALPHA),
                optional_param('reason', '', PARAM_TEXT),
                optional_param('extensiondays', 0, PARAM_INT)
            );
            redirect(new moodle_url('/local/ustar/staffing.php'),
                'Итоговое решение по адаптации сохранено', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\Throwable $e) {
        \core\notification::error($e->getMessage());
    }
}

$data = \local_ustar\adaptation_service::page_context($id, (int)$USER->id, $mode);
$data['sesskey'] = sesskey();
$data['saved'] = optional_param('saved', 0, PARAM_BOOL);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/adaptation.php', ['id' => $id, 'mode' => $mode]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(($mode === 'final' ? 'Итог адаптации' : 'Адаптационный лист') . ' | USTAR');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/adaptation.css'));

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/adaptation', $data);
echo $output->footer();
