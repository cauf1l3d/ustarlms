<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$iselevated = is_siteadmin() || has_capability('local/ustar:hrmanage', $context) || has_capability('local/ustar:admin', $context);
$requestedposition = optional_param('position', '', PARAM_ALPHANUMEXT);

$resolved = \local_ustar\structure::resolve_user((int)$USER->id);
$positionid = (string)($resolved['position']['id'] ?? '');

if ($iselevated && $requestedposition !== '') {
    $positionid = $requestedposition;
}

$previewing = ($iselevated && $requestedposition !== '') || \local_ustar\view_as::active();
$route = null;
if ($positionid !== '') {
    try {
        $route = $previewing
            ? \local_ustar\route_model::read_only_snapshot($positionid, (int)$USER->id)
            : \local_ustar\route_model::for_user($positionid, (int)$USER->id);
    } catch (\Throwable $e) {
        $route = ['ok' => false, 'reason' => 'runtime_error'];
    }
}

$positions = [];
if ($iselevated) {
    $structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
    foreach ($structure['positions'] ?? [] as $position) {
        $positions[] = [
            'id' => (string)$position['id'],
            'name' => (string)$position['name'],
            'selected' => (string)$position['id'] === $positionid,
        ];
    }
    usort($positions, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
}

$adaptation = null;

if (!$previewing) {
    try { $adaptation = \local_ustar\adaptation_service::route_card((int)$USER->id); } catch (\Throwable $e) { $adaptation = null; }
}
if ($adaptation && !empty($adaptation['blocked']) && !empty($route['ok'])) {
    if (!empty($route['currentpoint'])) { $route['currentpoint']['canlaunch'] = false; }
    if (!empty($route['points'])) {
        foreach ($route['points'] as &$routepoint) { $routepoint['canlaunch'] = false; }
        unset($routepoint);
    }
}

$rewards = \local_ustar\route_rewards::summary((int)$USER->id);
$data = [
    'previewing' => $previewing,
    'continueerror' => optional_param('continueerror', 0, PARAM_BOOL),
    'routerewards' => $rewards,
    'rewardsenabled' => !$previewing && \local_ustar\route_rewards::enabled(),
    'route' => !empty($route['ok']) ? $route : null,
    'hasroute' => !empty($route['ok']),
    'noroute' => empty($route['ok']),
    'hasposition' => $positionid !== '',
    'positionid' => $positionid,
    'iselevated' => $iselevated,
    'positions' => $positions,
    'hasadaptation' => !empty($adaptation),
    'adaptation' => $adaptation,
    'studio' => (new moodle_url('/local/ustar/route_studio.php', ['position' => $positionid]))->out(false),
    'coursesurl' => (new moodle_url('/local/ustar/home.php', ['view' => 'learning']))->out(false),
    'homeurl' => (new moodle_url('/local/ustar/home.php'))->out(false),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/route.php', $requestedposition !== '' ? ['position' => $requestedposition] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Мой учебный маршрут | USTAR');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/route_v2.css'));

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/route', $data);
echo $output->footer();

