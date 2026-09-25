<?php

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/ustar:use', context_system::instance());
\local_ustar\view_as::assert_writable();

global $DB, $SESSION, $USER;

$cmid = required_param('cmid', PARAM_INT);
$pointid = required_param('pointid', PARAM_INT);
$versionid = optional_param('versionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id(
    'scorm',
    $cmid,
    0,
    false,
    MUST_EXIST
);

$position =
    \local_ustar\position_access::position_for_user(
        (int)$USER->id
    );

$positionid =
    is_array($position)
        ? (string)($position['id'] ?? '')
        : '';

if ($positionid === '') {
    redirect(new moodle_url('/local/ustar/route.php'));
}

$route =
    \local_ustar\route_model::for_user(
        $positionid,
        (int)$USER->id
    );

$current =
    $route['currentpoint'] ?? null;

if (
    !$current
    ||
    (int)($current['id'] ?? 0) !== $pointid
) {
    redirect(new moodle_url('/local/ustar/route.php'));
}

$published = \local_ustar\route_model::current_published_version($pointid);
if (!$published || ($versionid > 0 && $versionid !== (int)$published->id)) {
    throw new moodle_exception('Версия точки изменилась. Откройте маршрут заново.');
}
$versionid = (int)$published->id;
$matches = false;
foreach (\local_ustar\route_model::requirements_for_version($published) as $requirement) {
    if (($requirement['type'] ?? '') === 'cm' && (int)($requirement['sourceid'] ?? 0) === $cmid) { $matches = true; }
}
if (!$matches || empty($current['canlaunch'])) { throw new moodle_exception('Материал не относится к доступной точке.'); }
$course = get_course((int)$cm->course);
require_login($course, false, $cm);
$info = get_fast_modinfo($course, (int)$USER->id)->get_cm($cmid);
if (!$info->uservisible) { throw new moodle_exception('Материал сейчас недоступен.'); }

$scormid = (int)$cm->instance;

$attemptbefore =
    (int)$DB->get_field_sql(
        "SELECT COALESCE(MAX(attempt), 0)
           FROM {scorm_attempt}
          WHERE userid = :userid
            AND scormid = :scormid",
        [
            'userid' => (int)$USER->id,
            'scormid' => $scormid,
        ]
    );

$SESSION->ustar_scorm_route = [
    'launchid' => bin2hex(random_bytes(16)),
    'cmid' => $cmid,
    'scormid' => $scormid,
    'pointid' => $pointid,
    'versionid' => $versionid,
    'attemptbefore' => $attemptbefore,
    'startedat' => time(),
];

redirect(
    new moodle_url(
        '/mod/scorm/view.php',
        ['id' => $cmid]
    )
);

