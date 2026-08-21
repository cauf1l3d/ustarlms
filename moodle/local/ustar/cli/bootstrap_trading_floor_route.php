<?php
#define CLI_SCRIPT before config.
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'apply' => false,
    'positionid' => 'retail_seller',
    'course-shortname' => 'ТЗ-ОСН',
    'rename-position' => true,
], [
    'h' => 'help',
]);

if ($unrecognized) {
    cli_error('Неизвестные параметры: ' . implode(', ', $unrecognized));
}
if (!empty($options['help'])) {
    echo "USTAR: типовой маршрут Торгового зала\n\n";
    echo "php bootstrap_trading_floor_route.php [--apply] [--positionid=retail_seller] [--course-shortname=ТЗ-ОСН] [--rename-position=1]\n";
    exit(0);
}

$positionid = clean_param((string)$options['positionid'], PARAM_ALPHANUMEXT);
$shortname = trim((string)$options['course-shortname']);
$apply = !empty($options['apply']);
$rename = !array_key_exists('rename-position', $options) || filter_var($options['rename-position'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$positionindex = null;
$department = null;
foreach ($structure['positions'] ?? [] as $i => $position) {
    if ((string)$position['id'] === $positionid) {
        $positionindex = $i;
        foreach ($structure['departments'] ?? [] as $dep) {
            if ((string)$dep['id'] === (string)$position['department']) {
                $department = $dep;
                break;
            }
        }
        break;
    }
}
if ($positionindex === null) {
    cli_error('POSITION_NOT_FOUND=' . $positionid);
}
if (!$department || (string)$department['id'] !== 'retail') {
    cli_error('POSITION_IS_NOT_TRADING_FLOOR=' . $positionid);
}

$course = $DB->get_record('course', ['shortname' => $shortname], '*');
if (!$course) {
    $course = $DB->get_record_select('course', $DB->sql_like('fullname', ':name', false), ['name' => '%Работник Торгового зала%'], '*', IGNORE_MULTIPLE);
}
if (!$course) {
    cli_error('TRADING_FLOOR_COURSE_NOT_FOUND shortname=' . $shortname);
}

$modinfo = get_fast_modinfo($course);
$tracked = [];
$untracked = [];
$skippedhidden = [];
$seenuntracked = [];
foreach ($modinfo->get_sections() as $sectionnum => $cmids) {
    foreach ($cmids as $cmid) {
        $cm = $modinfo->get_cm($cmid);
        if (in_array((string)$cm->modname, ['label', 'qbank'], true) || !$cm->url) {
            continue;
        }
        $item = [
            'cmid' => (int)$cm->id,
            'name' => format_string((string)$cm->name),
            'modname' => (string)$cm->modname,
            'completion' => (int)$cm->completion,
            'sectionnum' => (int)$sectionnum,
        ];
        if (empty($cm->visible)) {
            $skippedhidden[] = $item;
            continue;
        }
        if ((int)$cm->completion !== COMPLETION_TRACKING_NONE) {
            $tracked[] = $item;
        } else if (in_array((string)$cm->modname, ['scorm', 'quiz', 'page'], true)) {
            $dedupe = \core_text::strtolower(trim($item['name']));
            if (!isset($seenuntracked[$dedupe])) {
                $seenuntracked[$dedupe] = true;
                $untracked[] = $item;
            }
        }
    }
}

echo 'MODE=' . ($apply ? 'APPLY' : 'DRY_RUN') . PHP_EOL;
echo 'POSITION=' . $positionid . PHP_EOL;
echo 'DEPARTMENT=' . (string)$department['name'] . PHP_EOL;
echo 'COURSE_ID=' . (int)$course->id . PHP_EOL;
echo 'COURSE=' . format_string($course->fullname) . PHP_EOL;
echo 'TRACKED_ACTIVITIES=' . count($tracked) . PHP_EOL;
foreach ($tracked as $item) {
    echo '  PUBLISH cmid=' . $item['cmid'] . ' mod=' . $item['modname'] . ' name=' . $item['name'] . PHP_EOL;
}
echo 'UNTRACKED_REVIEW_ONLY=' . count($untracked) . PHP_EOL;
foreach ($untracked as $item) {
    echo '  REVIEW cmid=' . $item['cmid'] . ' mod=' . $item['modname'] . ' name=' . $item['name'] . PHP_EOL;
}
echo 'HIDDEN_SKIPPED=' . count($skippedhidden) . PHP_EOL;
foreach ($skippedhidden as $item) {
    echo '  SKIP_HIDDEN cmid=' . $item['cmid'] . ' mod=' . $item['modname'] . ' name=' . $item['name'] . PHP_EOL;
}

if (!$apply) {
    echo "NEXT=Run with --apply after reviewing detected content.\n";
    exit(0);
}

$transaction = $DB->start_delegated_transaction();
$actorid = 0;

if ($rename && (string)$structure['positions'][$positionindex]['name'] !== 'Работник Торгового зала') {
    $structure['positions'][$positionindex]['name'] = 'Работник Торгового зала';
    \local_ustar\structure::save(\local_ustar\structure::NAME_STRUCTURE, $structure);
    echo "POSITION_RENAMED=Работник Торгового зала\n";
}

$route = \local_ustar\route_model::ensure_route($positionid, $actorid);
$sort = 10;
$createdpublished = 0;
$createddraft = 0;

foreach ($tracked as $item) {
    $key = 'cm_' . $item['cmid'];
    if (!\local_ustar\route_model::find_point((int)$route->id, $key)) {
        \local_ustar\route_model::add_point((int)$route->id, $key, \local_ustar\route_model::PHASE_ADAPTATION, $sort, [
            'title' => $item['name'],
            'summary' => 'Реальная Moodle-активность из курса «' . format_string($course->fullname) . '». Завершение проверяется по Moodle completion.',
            'requirements' => [[
                'type' => 'cm',
                'sourceid' => $item['cmid'],
                'required' => true,
                'label' => $item['name'],
            ]],
            'renewalpolicy' => \local_ustar\route_model::RENEW_KEEP,
            'validdays' => 0,
            'status' => \local_ustar\route_model::STATUS_PUBLISHED,
            'effectivedate' => 0,
        ], $actorid);
        $createdpublished++;
    }
    $sort += 10;
}

// Untracked or hidden Moodle activities are intentionally not inserted.
// They stay visible in dry-run output until completion tracking/content quality is reviewed.
if (!\local_ustar\route_model::find_point((int)$route->id, 'admission_gate')) {
    \local_ustar\route_model::add_point((int)$route->id, 'admission_gate', \local_ustar\route_model::PHASE_GATE, 900, [
        'title' => 'Допуск к самостоятельной работе',
        'summary' => 'Системная контрольная точка. Закрывается только после всех предыдущих опубликованных обязательных точек адаптации.',
        'requirements' => [[
            'type' => 'previous_adaptation',
            'required' => true,
            'label' => 'Все предыдущие обязательные точки адаптации',
        ]],
        'renewalpolicy' => \local_ustar\route_model::RENEW_KEEP,
        'validdays' => 0,
        'status' => \local_ustar\route_model::STATUS_PUBLISHED,
        'effectivedate' => 0,
    ], $actorid);
    $createdpublished++;
}

$continuous = [
    ['new_products', 'Новинки ассортимента', 'Новые товарные позиции и знания продавца по ним.'],
    ['standards_updates', 'Изменения стандартов', 'Новые версии регламентов, стандартов обслуживания и работы торгового зала.'],
    ['seasonal_learning', 'Сезонное обучение', 'Обязательные сезонные темы и кампании.'],
];
$sort = 1000;
foreach ($continuous as [$key, $title, $summary]) {
    if (!\local_ustar\route_model::find_point((int)$route->id, $key)) {
        \local_ustar\route_model::add_point((int)$route->id, $key, \local_ustar\route_model::PHASE_CONTINUOUS, $sort, [
            'title' => $title,
            'summary' => $summary . ' Точка создана как шаблон и пока не опубликована сотрудникам.',
            'requirements' => [],
            'renewalpolicy' => \local_ustar\route_model::RENEW_ALL,
            'validdays' => 0,
            'status' => \local_ustar\route_model::STATUS_DRAFT,
            'effectivedate' => 0,
        ], $actorid);
        $createddraft++;
    }
    $sort += 10;
}

$transaction->allow_commit();
$route = \local_ustar\route_model::ensure_route($positionid, $actorid);

echo 'ROUTE_ID=' . (int)$route->id . PHP_EOL;
echo 'ROUTE_NAME=' . (string)$route->name . PHP_EOL;
echo 'PUBLISHED_POINTS_CREATED=' . $createdpublished . PHP_EOL;
echo 'DRAFT_POINTS_CREATED=' . $createddraft . PHP_EOL;
echo "TRADING_FLOOR_ROUTE_BOOTSTRAP=OK\n";
