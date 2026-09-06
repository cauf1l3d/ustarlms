<?php
#define CLI_SCRIPT before Moodle bootstrap when invoked directly.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

global $CFG, $DB;
$fail = false;
function rtcheck(string $name, bool $ok, string $detail = ''): void {
    global $fail;
    echo $name . '=' . ($ok ? 'OK' : 'FAIL') . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    if (!$ok) $fail = true;
}

rtcheck('VERSION_2726', (int)get_config('local_ustar', 'version') === 2026082726, (string)get_config('local_ustar', 'version'));
rtcheck('ROUTE_TESTER_CLASS', class_exists('\\local_ustar\\route_tester'));
rtcheck('ROUTE_TESTERS_TABLE', $DB->get_manager()->table_exists(new xmldb_table('local_ustar_route_testers')));
rtcheck('ROUTE_TEST_TOKENS_TABLE', $DB->get_manager()->table_exists(new xmldb_table('local_ustar_route_test_tokens')));
rtcheck('PRIMARY_URL_CONFIG', !empty($CFG->ustar_primary_wwwroot), (string)($CFG->ustar_primary_wwwroot ?? ''));
rtcheck('TESTER_URL_CONFIG', !empty($CFG->ustar_route_tester_wwwroot), (string)($CFG->ustar_route_tester_wwwroot ?? ''));

$uid = 2;
rtcheck('U2_SITEADMIN', is_siteadmin($uid));
rtcheck('U2_SERVICE', \local_ustar\accounts::type_of($uid) === \local_ustar\accounts::TYPE_SERVICE, \local_ustar\accounts::type_of($uid));
rtcheck('U2_NOT_BUSINESS', !\local_ustar\accounts::is_business_account($uid));
rtcheck('U2_NOT_LEARNER', !\local_ustar\accounts::learning_enabled($uid));
rtcheck('U2_NO_ACTIVE_ASSIGNMENT', !$DB->record_exists('local_ustar_assignments', ['userid' => $uid, 'status' => 'active']));
rtcheck('ACTIVE_STAFF_PLACES_89', $DB->count_records('local_ustar_staff_places', ['active' => 1]) === 89, (string)$DB->count_records('local_ustar_staff_places', ['active' => 1]));
rtcheck('ACTIVE_ASSIGNMENTS_75', $DB->count_records('local_ustar_assignments', ['status' => 'active']) === 75, (string)$DB->count_records('local_ustar_assignments', ['status' => 'active']));
rtcheck('REPORTING_73', $DB->count_records('local_ustar_reporting') === 73, (string)$DB->count_records('local_ustar_reporting'));

if ($fail) {
    fwrite(STDERR, "USTAR_ROUTE_TESTER_2726_VALIDATION_FAILED\n");
    exit(10);
}
echo "USTAR_ROUTE_TESTER_2726_OK\n";
