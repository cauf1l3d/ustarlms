<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require($config);

$username = 'rabadov';
$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id,username');
if (!$user) {
    echo "DEMO_EXECUTIVE_USER=rabadov\n";
    echo "DEMO_EXECUTIVE_STATUS=NOT_FOUND\n";
    exit(0);
}

$context = context_system::instance();
$roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_executive']);
if (!$roleid) {
    $roleid = (int)create_role(
        'USTAR Executive',
        'ustar_executive',
        'Read-only executive analytics in USTAR Academy.'
    );
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
}
assign_capability('local/ustar:use', CAP_ALLOW, $roleid, $context->id, true);
assign_capability('local/ustar:executive', CAP_ALLOW, $roleid, $context->id, true);

if (!$DB->record_exists('role_assignments', [
    'roleid' => $roleid,
    'userid' => (int)$user->id,
    'contextid' => $context->id,
])) {
    role_assign($roleid, (int)$user->id, $context->id, 'local_ustar', 0);
}

echo 'DEMO_EXECUTIVE_USER=' . $username . PHP_EOL;
echo 'DEMO_EXECUTIVE_USER_ID=' . (int)$user->id . PHP_EOL;
echo 'DEMO_EXECUTIVE_ROLE_ID=' . $roleid . PHP_EOL;
echo "DEMO_EXECUTIVE_STATUS=OK\n";
