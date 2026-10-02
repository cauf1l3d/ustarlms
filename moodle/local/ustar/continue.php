<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:use', $context);

\local_ustar\view_as::assert_writable();
require_sesskey();

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


if (!empty(\local_ustar\adaptation_service::route_card((int)$USER->id)['blocked'])) {
    redirect(new moodle_url('/local/ustar/route.php'));
}
redirect(\local_ustar\route_continue::destination($route, '', $cmid));
