<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

global $CFG, $DB;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(1);
}

if ($argc !== 2 || !in_array($argv[1], ['add', 'remove', 'status'], true)) {
    fwrite(STDERR, "Usage: php toggle_test_manager_role.php add|remove|status\n");
    exit(2);
}

$action = (string)$argv[1];
$context = context_system::instance();
$user = $DB->get_record('user', [
    'username' => 'audit_employee',
    'mnethostid' => $CFG->mnet_localhost_id,
    'deleted' => 0,
], '*', MUST_EXIST);
$roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_manager'], MUST_EXIST);

if ($action === 'add') {
    if ($DB->record_exists('role_assignments', [
        'roleid' => $roleid,
        'userid' => (int)$user->id,
        'contextid' => $context->id,
    ])) {
        fwrite(STDERR, "Refusing pre-existing manager assignment\n");
        exit(1);
    }
    role_assign($roleid, (int)$user->id, $context->id, 'local_ustar', 0);
} else if ($action === 'remove') {
    role_unassign($roleid, (int)$user->id, $context->id, 'local_ustar', 0);
}

accesslib_clear_all_caches(true);
$assigned = $DB->record_exists('role_assignments', [
    'roleid' => $roleid,
    'userid' => (int)$user->id,
    'contextid' => $context->id,
]);
$hascapability = has_capability('local/ustar:viewteam', $context, (int)$user->id);

echo json_encode([
    'status' => 'PASS',
    'action' => $action,
    'assigned' => $assigned,
    'viewteam' => $hascapability,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (($action === 'add' && (!$assigned || !$hascapability)) ||
    ($action === 'remove' && ($assigned || $hascapability))) {
    exit(1);
}
