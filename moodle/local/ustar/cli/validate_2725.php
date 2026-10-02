<?php
// Read-only acceptance for USTAR 2725 business-identity hardening.
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $DB;

$failures = [];
$check = static function(bool $ok, string $label, string $detail = '') use (&$failures): void {
    echo $label . '=' . ($ok ? 'OK' : 'FAIL') . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
};

$uid = 2;
$devpos = 'pos_526d0aede50b714e';

$check((int)get_config('local_ustar', 'version') === 2026082725, 'VERSION_2725', (string)get_config('local_ustar', 'version'));
$check(is_siteadmin($uid), 'U2_SITEADMIN');
$check(\local_ustar\people::position_id($uid) === $devpos, 'U2_DEVELOPER_POSITION', \local_ustar\people::position_id($uid));
$check(\local_ustar\accounts::type_of($uid) === \local_ustar\accounts::TYPE_SERVICE, 'U2_SERVICE_TYPE', \local_ustar\accounts::type_of($uid));
$check(!\local_ustar\accounts::is_business_account($uid), 'U2_NOT_BUSINESS_ACCOUNT');
$check(!\local_ustar\accounts::participates($uid), 'U2_NOT_PARTICIPANT');
$check(!\local_ustar\accounts::learning_enabled($uid), 'U2_NOT_LEARNER');
$check($DB->count_records('local_ustar_assignments', ['userid' => $uid, 'status' => 'active']) === 0, 'U2_NO_ACTIVE_ASSIGNMENT');
$check($DB->count_records('local_ustar_reporting', ['userid' => $uid]) === 0, 'U2_NOT_REPORTING_EMPLOYEE');
$check($DB->count_records('local_ustar_reporting', ['managerid' => $uid]) === 0, 'U2_NOT_REPORTING_MANAGER');

$plan = \local_ustar\assignment::plan_user($uid);
$check(($plan['status'] ?? '') === 'account_not_learning', 'U2_ASSIGNMENT_BLOCKED', (string)($plan['status'] ?? ''));
$check(empty($plan['toEnrol'] ?? []), 'U2_NO_AUTO_ENROL_PLAN');

$ctx = \context_system::instance();
foreach (['local/ustar:admin','local/ustar:executive','local/ustar:hr','local/ustar:hrmanage','local/ustar:viewteam','local/ustar:use'] as $cap) {
    $check(has_capability($cap, $ctx, $uid), 'CAP_' . strtoupper(str_replace(['local/ustar:', '/'], ['', '_'], $cap)));
}

$taskrows = $DB->get_records_select(
    'task_scheduled',
    'classname LIKE :classname',
    ['classname' => '%renew_acting_assignments%']
);
$check(class_exists(\local_ustar\task\renew_acting_assignments::class), 'ACTING_TASK_CLASS');
$check(count($taskrows) === 1, 'ACTING_TASK_REGISTERED', 'count=' . count($taskrows));
if (count($taskrows) === 1) {
    $task = reset($taskrows);
    $check((string)$task->hour === '3' && (string)$task->minute === '20', 'ACTING_TASK_SCHEDULE', $task->hour . ':' . $task->minute);
    $check(empty($task->disabled), 'ACTING_TASK_ENABLED', 'disabled=' . (int)$task->disabled);
}

// Confirm the central distinction required by the forthcoming route tester.
$check(method_exists(\local_ustar\accounts::class, 'is_business_account'), 'BUSINESS_ACCOUNT_HELPER');
$check(method_exists(\local_ustar\accounts::class, 'learning_enabled'), 'LEARNING_ENABLED_HELPER');

// Current physical business counts are informational, not hard guards.
echo 'ACTIVE_STAFF_PLACES=' . $DB->count_records('local_ustar_staff_places', ['active' => 1]) . PHP_EOL;
echo 'ACTIVE_ASSIGNMENTS=' . $DB->count_records('local_ustar_assignments', ['status' => 'active']) . PHP_EOL;
echo 'UNIQUE_ACTIVE_PEOPLE=' . $DB->get_field_sql("SELECT COUNT(DISTINCT userid) FROM {local_ustar_assignments} WHERE status = :status", ['status' => 'active']) . PHP_EOL;
echo 'REPORTING_LINES=' . $DB->count_records('local_ustar_reporting') . PHP_EOL;

if ($failures) {
    fwrite(STDERR, "VALIDATION_FAILED\n" . implode("\n", $failures) . "\n");
    exit(20);
}

echo "USTAR_SUPERADMIN_INVISIBLE_2725_OK\n";
