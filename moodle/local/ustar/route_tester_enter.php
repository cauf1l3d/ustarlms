<?php
require_once(__DIR__ . '/../../config.php');

global $DB, $SESSION;
\local_ustar\route_tester::assert_tester_host();
$token = required_param('token', PARAM_ALPHANUMEXT);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

// A previous tester-host session must never block a fresh launch. The primary
// administrator session uses another host+cookie and is unaffected.
if (isloggedin() && !isguestuser()) {
    \core\session\manager::terminate_current();
    redirect(\local_ustar\route_tester::tester_url() . '/local/ustar/route_tester_enter.php?token=' . rawurlencode($token));
}

$bridge = \local_ustar\route_tester::consume_token($token);
$realuser = get_complete_user_data('id', (int)$bridge['actorid']);
if (!$realuser || !is_siteadmin($realuser)) {
    throw new moodle_exception('Route Tester real administrator is unavailable');
}

\core\session\manager::login_user($realuser);
\core\session\manager::loginas((int)$bridge['sandboxuserid'], context_system::instance(), true);

$ctx = \local_ustar\route_tester::position_context((string)$bridge['positionid']);
$SESSION->ustar_route_tester = [
    'active' => 1,
    'actorid' => (int)$bridge['actorid'],
    'sandboxuserid' => (int)$bridge['sandboxuserid'],
    'positionid' => (string)$bridge['positionid'],
    'positionname' => (string)($ctx['position']['name'] ?? $bridge['positionid']),
    'departmentname' => (string)($ctx['department']['name'] ?? ''),
    'startedat' => time(),
];

redirect(new moodle_url('/local/ustar/route.php'));
