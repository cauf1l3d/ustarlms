<?php
// USTAR 2725 maintenance CLI: reconcile scheduled tasks after hardening deployment.
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

defined('MOODLE_INTERNAL') || die();

global $DB;

if (!method_exists(\core\task\manager::class, 'reset_scheduled_tasks_for_component')) {
    fwrite(STDERR, "TASK_MANAGER_RESET_METHOD_MISSING\n");
    exit(10);
}

\core\task\manager::reset_scheduled_tasks_for_component('local_ustar');

$rows = $DB->get_records_select(
    'task_scheduled',
    'classname LIKE :classname',
    ['classname' => '%renew_acting_assignments%']
);

if (count($rows) !== 1) {
    fwrite(STDERR, 'RENEW_ACTING_TASK_COUNT=' . count($rows) . "\n");
    exit(11);
}

$row = reset($rows);
echo 'RENEW_ACTING_TASK=' . $row->classname
    . ' | hour=' . $row->hour
    . ' | minute=' . $row->minute
    . ' | disabled=' . $row->disabled . PHP_EOL;

echo "TASK_RECONCILIATION_OK\n";
