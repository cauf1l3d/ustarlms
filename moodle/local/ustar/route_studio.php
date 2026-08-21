<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:hrmanage', $context);

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$departments = [];
foreach ($structure['departments'] ?? [] as $department) {
    $departments[(string)$department['id']] = (string)$department['name'];
}
$positions = array_values($structure['positions'] ?? []);
usort($positions, static function(array $a, array $b) use ($departments): int {
    $da = $departments[(string)($a['department'] ?? '')] ?? '';
    $db = $departments[(string)($b['department'] ?? '')] ?? '';
    return [$da, (string)$a['name']] <=> [$db, (string)$b['name']];
});

$positionid = optional_param('position', '', PARAM_ALPHANUMEXT);
if ($positionid === '' && $positions) {
    $positionid = (string)$positions[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $positionid = required_param('position', PARAM_ALPHANUMEXT);
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $actorid = (int)$USER->id;

    if ($action === 'ensure') {
        \local_ustar\route_model::seed_from_required_courses($positionid, $actorid);
    } else if ($action === 'reorder') {
        $route = \local_ustar\route_model::ensure_route($positionid, $actorid);
        $pointids = optional_param_array('pointids', [], PARAM_INT);
        \local_ustar\route_model::reorder((int)$route->id, $pointids, $actorid);
    } else if ($action === 'add_point') {
        $route = \local_ustar\route_model::ensure_route($positionid, $actorid);
        $title = required_param('title', PARAM_TEXT);
        $phase = optional_param('phase', \local_ustar\route_model::PHASE_ADAPTATION, PARAM_ALPHANUMEXT);
        $requirementtype = optional_param('requirementtype', '', PARAM_ALPHANUMEXT);
        $source = optional_param('source', '', PARAM_RAW_TRIMMED);
        $status = optional_param('status', \local_ustar\route_model::STATUS_DRAFT, PARAM_ALPHANUMEXT);
        $requirements = [];
        if (in_array($requirementtype, ['course', 'cm'], true) && (int)$source > 0) {
            $requirements[] = ['type' => $requirementtype, 'sourceid' => (int)$source, 'required' => true];
        } else if ($requirementtype === 'skill' && $source !== '') {
            $requirements[] = ['type' => 'skill', 'sourcekey' => clean_param($source, PARAM_ALPHANUMEXT), 'required' => true];
        } else if ($requirementtype === 'previous_adaptation') {
            $requirements[] = ['type' => 'previous_adaptation', 'required' => true];
        }
        $sort = 10;
        foreach (\local_ustar\route_model::points((int)$route->id) as $point) {
            $sort = max($sort, (int)$point->sortorder + 10);
        }
        \local_ustar\route_model::add_point(
            (int)$route->id,
            'point_' . substr(sha1($positionid . ':' . $title . ':' . microtime(true)), 0, 12),
            $phase,
            $sort,
            [
                'title' => $title,
                'summary' => '',
                'requirements' => $requirements,
                'renewalpolicy' => \local_ustar\route_model::RENEW_KEEP,
                'validdays' => 0,
                'status' => $status,
                'effectivedate' => 0,
            ],
            $actorid
        );
    } else if ($action === 'new_version') {
        $route = \local_ustar\route_model::ensure_route($positionid, $actorid);
        $pointid = required_param('pointid', PARAM_INT);
        if (!$DB->record_exists('local_ustar_route_points', ['id' => $pointid, 'routeid' => (int)$route->id, 'active' => 1])) {
            throw new invalid_parameter_exception('Точка не относится к выбранному маршруту');
        }
        $title = required_param('title', PARAM_TEXT);
        $summary = optional_param('summary', '', PARAM_TEXT);
        $policy = optional_param('renewalpolicy', \local_ustar\route_model::RENEW_KEEP, PARAM_ALPHANUMEXT);
        $status = optional_param('status', \local_ustar\route_model::STATUS_DRAFT, PARAM_ALPHANUMEXT);
        $validdays = optional_param('validdays', 0, PARAM_INT);
        \local_ustar\route_model::create_version($pointid, [
            'title' => $title,
            'summary' => $summary,
            'renewalpolicy' => $policy,
            'validdays' => $validdays,
            'status' => $status,
            'effectivedate' => $status === \local_ustar\route_model::STATUS_PUBLISHED ? time() : 0,
        ], $actorid);
    } else if ($action === 'archive_point') {
        $route = \local_ustar\route_model::ensure_route($positionid, $actorid);
        $pointid = required_param('pointid', PARAM_INT);
        \local_ustar\route_model::archive_point((int)$route->id, $pointid, $actorid);
    } else {
        throw new invalid_parameter_exception('Неизвестное действие редактора маршрута');
    }

    redirect(new moodle_url('/local/ustar/route_studio.php', [
        'position' => $positionid,
        'saved' => 1,
    ]));
}

$saved = optional_param('saved', 0, PARAM_BOOL);
$route = $positionid !== '' ? \local_ustar\route_model::admin_view($positionid) : null;
$routeexists = !empty($route['ok']);

if ($routeexists) {
    foreach ($route['points'] as &$point) {
        $latest = \local_ustar\route_model::latest_version((int)$point['id']);
        $point['formtitle'] = $latest ? (string)$latest->title : 'Точка маршрута';
        $point['formsummary'] = $latest ? (string)$latest->summary : '';
        $point['formvaliddays'] = $latest ? (int)$latest->validdays : 0;
        $currentpolicy = $latest ? (string)$latest->renewalpolicy : \local_ustar\route_model::RENEW_KEEP;
        $point['policies'] = [
            ['id' => 'keep', 'name' => 'Сохранить прошлый результат', 'selected' => $currentpolicy === 'keep'],
            ['id' => 'all', 'name' => 'Обязать пройти новую версию', 'selected' => $currentpolicy === 'all'],
            ['id' => 'expiry', 'name' => 'Повтор после истечения срока', 'selected' => $currentpolicy === 'expiry'],
        ];
    }
    unset($point);
}

$options = [];
foreach ($positions as $position) {
    $department = $departments[(string)($position['department'] ?? '')] ?? '';
    $options[] = [
        'id' => (string)$position['id'],
        'name' => ($department !== '' ? $department . ' — ' : '') . (string)$position['name'],
        'selected' => (string)$position['id'] === $positionid,
    ];
}

$data = [
    'positionid' => $positionid,
    'positions' => $options,
    'route' => $routeexists ? $route : null,
    'routeexists' => $routeexists,
    'noroute' => !$routeexists,
    'saved' => $saved,
    'sesskey' => sesskey(),
    'previewurl' => (new moodle_url('/local/ustar/route.php', ['position' => $positionid]))->out(false),
    'positionsurl' => (new moodle_url('/local/ustar/positions.php', ['positionid' => $positionid], 'u-position-evidence'))->out(false),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/route_studio.php', ['position' => $positionid]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Учебные маршруты 2.0 | USTAR');
$PAGE->set_heading('Центр управления USTAR');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/route_v2.css'));
$PAGE->requires->js_call_amd('local_ustar/route_studio', 'init');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/route_studio', $data);
echo $output->footer();
