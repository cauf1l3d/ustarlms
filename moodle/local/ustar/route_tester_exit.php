<?php
require_once(__DIR__ . '/../../config.php');
require_login();

\local_ustar\route_tester::assert_tester_host();
require_sesskey();
if (!\local_ustar\route_tester::active()) {
    throw new moodle_exception('Route Tester session is not active');
}
$return = \local_ustar\route_tester::primary_url() . '/local/ustar/route_tester.php?closed=1';
\core\session\manager::terminate_current();
redirect($return);
