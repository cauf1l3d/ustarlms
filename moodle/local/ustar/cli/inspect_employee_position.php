<?php
// Read-only inspection of one employee's HR and organization projections.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(['userid' => 0, 'help' => false], ['h' => 'help']);
if ($options['help'] || $unrecognized || (int)$options['userid'] <= 1) {
    cli_writeln('Usage: php local/ustar/cli/inspect_employee_position.php --userid=NUMBER');
    exit(2);
}

$userid = (int)$options['userid'];
$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id,firstname,lastname,suspended', IGNORE_MISSING);
if (!$user) {
    cli_error('Сотрудник с указанным ID не найден.');
}

$identity = \local_ustar\organization_identity::resolve($userid);
$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$positions = \local_ustar\people::position_map($structure);
$assignments = [];
foreach (\local_ustar\organization_model::active_assignments($userid) as $assignment) {
    $place = $DB->get_record('local_ustar_staff_places', ['id' => $assignment->staffplaceid],
        'id,placecode,positionid,departmentid,managerplaceid,active,effectivefrom,effectiveto', IGNORE_MISSING);
    $assignments[] = [
        'id' => (int)$assignment->id,
        'type' => (string)$assignment->assignmenttype,
        'place' => $place ? (array)$place : null,
    ];
}
$suggested = [];
foreach ($positions as $position) {
    $name = (string)($position['name'] ?? '');
    if (($position['companyrole'] ?? '') === 'assistant'
            || str_contains(\core_text::strtolower($name), 'ассистент')) {
        $suggested[] = ['id' => $position['id'], 'name' => $name,
            'departmentid' => $position['department'] ?? '',
            'companyrole' => $position['companyrole'] ?? ''];
    }
}
cli_writeln(json_encode([
    'userid' => $userid, 'name' => fullname($user), 'suspended' => (bool)$user->suspended,
    'businessaccount' => \local_ustar\accounts::is_business_account($userid),
    'employment' => \local_ustar\employment::resolve($userid),
    'identity' => $identity, 'activeassignments' => $assignments,
    'assistantpositions' => $suggested,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
