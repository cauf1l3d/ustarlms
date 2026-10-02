<?php
require_once(__DIR__ . '/../../config.php');
require_login();
global $USER;

$context = context_system::instance();
require_capability('local/ustar:managecompetition', $context);
$errors = [];
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    try {
        $action = optional_param('action', '', PARAM_ALPHA);
        $competitionid = optional_param('competitionid', 0, PARAM_INT);
        if ($action === 'create') {
            $start = strtotime(optional_param('startdate', '', PARAM_RAW) . ' 00:00:00');
            $end = strtotime(optional_param('enddate', '', PARAM_RAW) . ' 23:59:59');
            $competitionid = \local_ustar\competition::create_draft(
                optional_param('code', '', PARAM_ALPHANUMEXT),
                optional_param('title', '', PARAM_TEXT),
                optional_param('departmentid', '', PARAM_ALPHANUMEXT),
                (int)$start,
                (int)$end,
                optional_param('pointsperxp', 1, PARAM_INT),
                (int)$USER->id
            );
            $notice = 'Черновик сезона создан. Проверьте даты и аудиторию перед публикацией.';
        } else if ($action === 'publish') {
            \local_ustar\competition::publish($competitionid, (int)$USER->id);
            $notice = 'Сезон опубликован: аудитория и правило v1 зафиксированы.';
        } else if ($action === 'close') {
            \local_ustar\competition::close($competitionid, (int)$USER->id);
            $notice = 'Сезон закрыт: итоговые места сохранены с общей позицией для ничьих.';
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
$departments = \local_ustar\competition::department_options();
foreach ($departments as &$department) {
    $department['selected'] = false;
}
unset($department);
$rows = \local_ustar\competition::operator_rows();
$data = [
    'rows' => $rows,
    'hasrows' => !empty($rows),
    'departments' => $departments,
    'errors' => $errors,
    'haserrors' => !empty($errors),
    'notice' => $notice,
    'hasnotice' => $notice !== '',
    'sesskey' => sesskey(),
];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/competition_studio.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Соревнования | USTAR');
$PAGE->set_heading('Соревнования USTAR');
$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/competition_studio', $data);
echo $output->footer();
