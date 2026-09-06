<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:use', $context);

\local_ustar\view_as::assert_writable();

$cmid = required_param('cmid', PARAM_INT);

$cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$modinfo = get_fast_modinfo((int)$cm->course, (int)$USER->id);
$cminfo = $modinfo->get_cm($cmid);

if (!$cminfo->uservisible) {
    throw new moodle_exception('Активность сейчас недоступна');
}

$resolved = \local_ustar\structure::resolve_user((int)$USER->id);
$positionid = (string)($resolved['position']['id'] ?? '');

if ($positionid === '') {
    redirect(new moodle_url('/local/ustar/route.php'));
}

$route = \local_ustar\route_model::for_user($positionid, (int)$USER->id);

if (empty($route['ok'])) {
    redirect(new moodle_url('/local/ustar/route.php'));
}

\local_ustar\route_continue::assert_reachable_cm($route, $cmid);


// The route engine is the single source of truth for what opens next.
$route = \local_ustar\route_model::for_user($positionid, (int)$USER->id);

if (
    !empty($route['ok'])
    && !empty($route['currentpoint'])
    && !empty($route['currentpoint']['canlaunch'])
    && !empty($route['currentpoint']['launchurl'])
) {
    $target = (string)$route['currentpoint']['launchurl'];

    $parts = parse_url($target);
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $targetcmid = isset($query['id']) ? (int)$query['id'] : 0;
    $targetpath = (string)($parts['path'] ?? '');

    // Never loop back to the same Moodle activity.
    if (
        $targetcmid === $cmid
        && preg_match('~/mod/[^/]+/view\.php$~', $targetpath)
    ) {
        redirect(new moodle_url('/local/ustar/route.php', ['continueerror' => 1]));
    }

    redirect($target);
}

redirect(new moodle_url('/local/ustar/route.php'));
