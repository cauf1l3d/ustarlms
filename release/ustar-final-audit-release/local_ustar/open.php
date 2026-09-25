<?php

require_once(__DIR__ . '/../../config.php');

require_login();

$contentid = required_param('contentid', PARAM_INT);
$pointid = required_param('pointid', PARAM_INT);
$versionid = required_param('versionid', PARAM_INT);

\local_ustar\view_as::assert_writable();
\local_ustar\route_model::assert_content_launch(
    (int)$USER->id,
    $contentid,
    $pointid,
    $versionid
);
\local_ustar\learning_events::record_route_open(
    (int)$USER->id,
    $contentid,
    $pointid,
    $versionid
);

$url = \local_ustar\content::open_url($contentid, (int)$USER->id);
if (!$url) {
    throw new moodle_exception('Материал сейчас невозможно открыть');
}

// Preserve route provenance for the version-specific acknowledgement POST.
if (str_starts_with($url->out(false), $CFG->wwwroot . '/local/ustar/view.php')) {
    $url->params(['routepointid' => $pointid, 'routeversionid' => $versionid]);
}

redirect($url);
