<?php
require_once(__DIR__ . '/../../config.php');
// Source-only hotfix: an existing component cache may not know this new class yet.
require_once(__DIR__ . '/classes/scorm_route_launch.php');

require_login();
require_capability('local/ustar:use', context_system::instance());
\local_ustar\view_as::assert_writable();

$userid = (int)$USER->id;
if (!\local_ustar\employment::learning_allowed($userid)) {
    throw new required_capability_exception(context_system::instance(),
        'local/ustar:use', 'nopermissions', '');
}
$courseid = required_param('courseid', PARAM_INT);
$pointid = required_param('pointid', PARAM_INT);
$versionid = required_param('versionid', PARAM_INT);
$resolved = \local_ustar\structure::resolve_user($userid);
$positionid = (string)($resolved['position']['id'] ?? '');
if ($positionid === '' || !empty(\local_ustar\adaptation_service::route_card($userid)['blocked'])) {
    redirect(new moodle_url('/local/ustar/route.php'));
}

// This explicit launch command reconciles the reachable point and provisions
// course access. The route page itself reads only persisted progress.
$route = \local_ustar\route_model::for_user($positionid, $userid);
$current = $route['currentpoint'] ?? null;
if (!$current || (int)$current['id'] !== $pointid) {
    foreach ($route['points'] ?? [] as $candidate) {
        if ((int)$candidate['id'] === $pointid && !empty($candidate['done']) && !empty($candidate['canlaunch'])) { $current = $candidate; break; }
    }
}
if (!$current || (int)$current['id'] !== $pointid || empty($current['canlaunch'])) {
    redirect(new moodle_url('/local/ustar/route.php'));
}
$version = \local_ustar\route_model::current_published_version($pointid);
if (!$version || (int)$version->id !== $versionid) {
    redirect(new moodle_url('/local/ustar/route.php'));
}
$matches = false;
foreach (\local_ustar\route_model::requirements_for_version($version) as $requirement) {
    if ((string)($requirement['type'] ?? '') === 'course'
            && (int)($requirement['sourceid'] ?? 0) === $courseid) {
        $matches = true;
        break;
    }
}
if (!$matches) {
    throw new invalid_parameter_exception('Курс не относится к текущему шагу маршрута.');
}
$cmid = \local_ustar\scorm_route_launch::course_cmid($courseid, $userid);
if ($cmid > 0) {
    redirect(new moodle_url('/local/ustar/scorm_launch.php', [
        'cmid' => $cmid, 'pointid' => $pointid, 'versionid' => $versionid,
    ]));
}
redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
