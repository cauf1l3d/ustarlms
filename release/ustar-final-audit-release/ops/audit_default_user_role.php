<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

global $CFG, $DB;

$roleid = (int)$CFG->defaultuserroleid;
$role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
$systemcontext = context_system::instance();
$defaults = get_default_capabilities($role->archetype);
$records = $DB->get_records('role_capabilities', [
    'roleid' => $roleid,
    'contextid' => $systemcontext->id,
]);

$current = [];
foreach ($records as $record) {
    $current[$record->capability] = (int)$record->permission;
}

$extra = [];
$changed = [];
$missing = [];
foreach ($current as $capability => $permission) {
    if (!array_key_exists($capability, $defaults)) {
        $extra[$capability] = $permission;
    } elseif ((int)$defaults[$capability] !== $permission) {
        $changed[$capability] = [
            'current' => $permission,
            'default' => (int)$defaults[$capability],
        ];
    }
}
foreach ($defaults as $capability => $permission) {
    if (!array_key_exists($capability, $current)) {
        $missing[$capability] = (int)$permission;
    }
}

$risky = [];
if ($extra) {
    [$insql, $params] = $DB->get_in_or_equal(array_keys($extra), SQL_PARAMS_NAMED);
    $caps = $DB->get_records_select('capabilities', "name {$insql} AND riskbitmask <> 0", $params);
    foreach ($caps as $definition) {
        $capability = $definition->name;
        $risky[$capability] = [
            'permission' => $extra[$capability],
            'riskbitmask' => (int)$definition->riskbitmask,
        ];
    }
}

ksort($extra);
ksort($changed);
ksort($missing);
ksort($risky);

echo json_encode([
    'default_user_role' => [
        'id' => $roleid,
        'shortname' => $role->shortname,
        'archetype' => $role->archetype,
    ],
    'current_system_capabilities' => count($current),
    'archetype_capabilities' => count($defaults),
    'extra_count' => count($extra),
    'changed_count' => count($changed),
    'missing_count' => count($missing),
    'risky_extra_count' => count($risky),
    'extra' => $extra,
    'risky_extra' => $risky,
    'changed' => $changed,
    'missing' => $missing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
