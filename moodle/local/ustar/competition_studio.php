<?php
require_once(__DIR__ . '/../../config.php');
require_login();
use local_ustar\competition;
use local_ustar\task_workspace\calendar;
$context = context_system::instance();
require_capability('local/ustar:managecompetition', $context);
$errors = [];
$timezone = core_date::get_user_timezone();
if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) { $timezone = 'Europe/Moscow'; }
$input = ['title' => '', 'departmentid' => '', 'pointsperxp' => 1,
    'startdate' => userdate(time(), '%Y-%m-%d', $timezone),
    'enddate' => userdate(time() + 14 * DAYSECS, '%Y-%m-%d', $timezone)];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    try {
        $action = required_param('action', PARAM_ALPHA);
        $id = optional_param('competitionid', 0, PARAM_INT);
        if ($action === 'create') {
            foreach ($input as $key => $value) {
                $input[$key] = required_param($key, $key === 'pointsperxp' ? PARAM_INT : PARAM_TEXT);
            }
            $start = calendar::timestamp($input['startdate'], '00:00', $timezone);
            $end = calendar::timestamp($input['enddate'], '23:59', $timezone) + 59;
            // The operator works with titles, not internal identifiers.
            $code = 'season_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
            competition::create_draft($code, $input['title'], $input['departmentid'], $start, $end,
                $input['pointsperxp'], (int)$USER->id);
        } else if ($action === 'publish') {
            competition::publish($id, (int)$USER->id);
        } else if ($action === 'close') {
            competition::close($id, (int)$USER->id);
        } else { throw new invalid_parameter_exception('Неизвестное действие соревнования.'); }
        redirect(new moodle_url('/local/ustar/competition_studio.php'),
            ['create' => 'Черновик создан. Проверьте период и подразделение, затем опубликуйте сезон.',
                'publish' => 'Сезон опубликован.', 'close' => 'Итоговые места сохранены.'][$action]);
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}
$departments = competition::department_options();
foreach ($departments as &$department) { $department['selected'] = $department['id'] === $input['departmentid']; }
unset($department);
$rows = competition::operator_rows();
$counts = ['drafts' => 0, 'published' => 0, 'closed' => 0];
foreach ($rows as &$row) {
    $row['statuslabel'] = ['draft' => 'Черновик', 'published' => 'Опубликован', 'closed' => 'Завершён'][$row['status']] ?? $row['status'];
    $row['draft'] = $row['status'] === 'draft';
    $row['closed'] = $row['status'] === 'closed';
    $row['waiting'] = $row['status'] === 'published' && !$row['canclose'];
    $key = $row['draft'] ? 'drafts' : ($row['closed'] ? 'closed' : 'published');
    $counts[$key]++;
}
unset($row);
$data = $input + $counts + ['rows' => $rows, 'hasrows' => !empty($rows), 'departments' => $departments,
    'hasdepartments' => !empty($departments), 'errors' => $errors, 'sesskey' => sesskey(), 'timezone' => $timezone,
    'readonly' => \local_ustar\view_as::active(), 'haserrors' => !empty($errors),
    'gamesurl' => (new moodle_url('/local/ustar/game_studio.php'))->out(false)];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/competition_studio.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Студия соревнований | USTAR');
$PAGE->set_heading('Студия соревнований');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css'));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/competition_studio.css'));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/competition_studio', $data);
echo $OUTPUT->footer();
