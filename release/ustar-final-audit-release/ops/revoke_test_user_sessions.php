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

$usernames = [
    'audit_employee',
    'audit_retail_head',
    'audit_hr',
    'audit_ceo',
    'audit_superadmin',
];
$before = 0;
$after = 0;

foreach ($usernames as $username) {
    $user = $DB->get_record('user', [
        'username' => $username,
        'mnethostid' => $CFG->mnet_localhost_id,
        'deleted' => 0,
    ], '*', MUST_EXIST);
    $before += $DB->count_records('sessions', ['userid' => (int)$user->id]);
    \core\session\manager::kill_user_sessions((int)$user->id);
    $after += $DB->count_records('sessions', ['userid' => (int)$user->id]);
}

echo json_encode([
    'status' => $after === 0 ? 'PASS' : 'FAIL',
    'users' => count($usernames),
    'sessions_before' => $before,
    'sessions_after' => $after,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($after === 0 ? 0 : 1);
