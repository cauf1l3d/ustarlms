<?php
require_once(__DIR__ . '/../../config.php');
require_login();

global $PAGE, $USER;
$context = context_system::instance();
if (!\local_ustar\route_tester::can_use()) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}
if (\core\session\manager::is_loggedinas()) {
    throw new moodle_exception('Route Tester control screen must be opened as the real administrator');
}

$action = optional_param('action', '', PARAM_ALPHA);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'launch') {
        $positionid = required_param('positionid', PARAM_ALPHANUMEXT);
        $fresh = optional_param('fresh', 1, PARAM_BOOL);
        $sandboxid = \local_ustar\route_tester::ensure_sandbox((int)$USER->id);
        if ($fresh) {
            \local_ustar\route_tester::reset_sandbox($sandboxid, (int)$USER->id);
        }
        \local_ustar\route_tester::configure_sandbox($sandboxid, (int)$USER->id, $positionid);
        $token = \local_ustar\route_tester::issue_token((int)$USER->id, $sandboxid, $positionid);
        redirect(\local_ustar\route_tester::launch_url($token));
    }
    if ($action === 'reset') {
        $status = \local_ustar\route_tester::status((int)$USER->id);
        if (!empty($status['exists'])) {
            \local_ustar\route_tester::reset_sandbox((int)$status['userid'], (int)$USER->id);
            \core\notification::success('Sandbox сброшен в ноль.');
        }
        redirect(new moodle_url('/local/ustar/route_tester.php'));
    }
    throw new invalid_parameter_exception('Неизвестное действие Route Tester');
}

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$departments = [];
$departmentmap = [];
foreach ($structure['departments'] ?? [] as $department) {
    $id = (string)($department['id'] ?? '');
    if ($id === '') continue;
    $departmentmap[$id] = count($departments);
    $departments[] = [
        'id' => $id,
        'name' => (string)($department['name'] ?? $id),
        'positions' => [],
        'haspositions' => false,
    ];
}
foreach ($structure['positions'] ?? [] as $position) {
    $departmentid = (string)($position['department'] ?? '');
    if (!isset($departmentmap[$departmentid])) continue;
    $idx = $departmentmap[$departmentid];
    $departments[$idx]['positions'][] = [
        'id' => (string)$position['id'],
        'name' => (string)$position['name'],
    ];
    $departments[$idx]['haspositions'] = true;
}
foreach ($departments as &$department) {
    usort($department['positions'], static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
}
unset($department);
usort($departments, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$status = \local_ustar\route_tester::status((int)$USER->id);
$data = [
    'departments' => $departments,
    'posturl' => (new moodle_url('/local/ustar/route_tester.php'))->out(false),
    'sesskey' => sesskey(),
    'testerurl' => \local_ustar\route_tester::tester_url(),
    'viewasurl' => (new moodle_url('/local/ustar/view_as.php'))->out(false),
    'sandboxexists' => !empty($status['exists']),
    'sandboxuserid' => (int)($status['userid'] ?? 0),
    'sandboxusername' => (string)($status['username'] ?? ''),
    'sandboxpositionid' => (string)($status['positionid'] ?? ''),
    'sandboxlastreset' => !empty($status['lastreset']) ? userdate((int)$status['lastreset'], '%d.%m.%Y %H:%M:%S') : 'ещё не сбрасывался',
    'closed' => optional_param('closed', 0, PARAM_BOOL),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/route_tester.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Тестер маршрутов | USTAR');
$PAGE->set_heading('USTAR Academy');
$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/route_tester', $data);
echo $output->footer();
