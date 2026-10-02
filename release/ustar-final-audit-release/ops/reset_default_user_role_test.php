<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

global $CFG, $DB;

if ($CFG->wwwroot !== 'http://127.0.0.1:18080') {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(1);
}

$roleid = (int)$CFG->defaultuserroleid;
$role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
if ($role->shortname !== 'user' || $role->archetype !== 'user') {
    fwrite(STDERR, "Refusing unexpected default role\n");
    exit(1);
}

reset_role_capabilities($roleid);

$systemcontext = context_system::instance();
$count = $DB->count_records('role_capabilities', [
    'roleid' => $roleid,
    'contextid' => $systemcontext->id,
]);

echo "default_user_role_reset_to_archetype=true\n";
echo "roleid={$roleid}\n";
echo "system_capabilities={$count}\n";
