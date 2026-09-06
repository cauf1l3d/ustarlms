<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $PAGE, $USER;

$cmid = required_param('cmid', PARAM_INT);
$pointid = required_param('pointid', PARAM_INT);
$versionid = required_param('versionid', PARAM_INT);

$pagecontext = \context_system::instance();
$PAGE->set_context($pagecontext);
$PAGE->set_url(new moodle_url('/local/ustar/activity_launch.php', [
    'cmid' => $cmid,
    'pointid' => $pointid,
    'versionid' => $versionid,
]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Открытие шага | USTAR');
$PAGE->set_heading('USTAR Academy');

$resolved = \local_ustar\structure::resolve_user((int)$USER->id);
$positionid = (string)($resolved['position']['id'] ?? '');
if ($positionid === '') {
    throw new moodle_exception('Для сотрудника не определена должность USTAR.');
}

/*
 * The USTAR route is the only business availability gate.
 * Do not let a raw Moodle course page decide whether this current step exists.
 */
$route = \local_ustar\route_model::for_user($positionid, (int)$USER->id);
$current = $route['currentpoint'] ?? null;
if (!$current || (int)($current['id'] ?? 0) !== $pointid) {
    redirect(new moodle_url('/local/ustar/route.php'));
}

$version = \local_ustar\route_model::current_published_version($pointid);
if (!$version || (int)$version->id !== $versionid) {
    redirect(new moodle_url('/local/ustar/route.php'));
}

$allowed = false;
foreach (\local_ustar\route_model::requirements_for_version($version) as $requirement) {
    if ((string)($requirement['type'] ?? '') === 'cm'
        && (int)($requirement['sourceid'] ?? 0) === $cmid) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    throw new moodle_exception('Эта учебная активность не относится к текущему шагу USTAR.');
}

$cmrow = $DB->get_record('course_modules', [
    'id' => $cmid,
    'deletioninprogress' => 0,
], 'id,course,module', MUST_EXIST);
$modname = (string)$DB->get_field('modules', 'name', [
    'id' => (int)$cmrow->module,
], MUST_EXIST);

// SCORM keeps its specialised USTAR completion bridge.
if ($modname === 'scorm') {
    redirect(new moodle_url('/local/ustar/scorm_launch.php', [
        'cmid' => $cmid,
        'pointid' => $pointid,
        'versionid' => $versionid,
    ]));
}

try {
    $prepared = \local_ustar\moodle_activity_bridge::prepare((int)$USER->id, $cmid);
} catch (\Throwable $e) {
    debugging('USTAR activity bridge failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    throw new moodle_exception(
        'Не удалось открыть активность из маршрута USTAR. Legacy-курс не используется как fallback. ' . $e->getMessage()
    );
}

// The legacy course is only a backend container; this launcher only targets the authorised activity.
redirect(new moodle_url((string)$prepared['targeturl']));
