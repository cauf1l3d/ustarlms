<?php

require_once(__DIR__ . '/../../config.php');

require_login();

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

if ($versionid <= 0) {
    $latest =
        \local_ustar\route_model::latest_version(
            $pointid
        );

    $versionid =
        $latest
            ? (int)$latest->id
            : 0;
}

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
