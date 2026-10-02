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
    echo "USTAR: диагностика исторического маршрута Торгового зала (только чтение)\n\n";
    echo "php bootstrap_trading_floor_route.php [--positionid=retail_seller] [--course-shortname=ТЗ-ОСН]\n";
    echo "Для переноса вводных шагов используйте Route Studio. --apply отключён.\n";
    exit(0);
}

// This legacy bootstrap encodes retail names and could rewrite an existing
// route/position. Its historical apply path must never run on a live academy.
if (!empty($options['apply'])) {
    cli_error('RETIRED_APPLY: use Route Studio and review the six-point introduction preview');
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
echo "FIRST_STEP=Экспресс-профиль командного взаимодействия\n";
echo "POST_ATTESTATION_VIDEO_SCENARIOS=2\n";
echo "VIDEO_ASSET_STATUS=BLOCKED_OWNER_CONTENT_REQUIRED\n";

echo "NEXT=Use Route Studio to create or transfer reviewed introduction steps.\n";
exit(0);
