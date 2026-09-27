<?php
// Read-only preview of explicit feed publishing grants before role mapping.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'userids' => '',
], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown arguments: ' . implode(', ', $unrecognised));
}
if ($options['help']) {
    echo "Read-only feed-role audit\n";
    echo "  php local/ustar/cli/audit_feed_roles.php [--userids=12,34]\n";
    echo "Supply exact Moodle user IDs for proposed CEO, assistant, manager or moderator grants.\n";
    exit(0);
}

$context = context_system::instance();
$roles = [
    'ustar_feed_academy' => ['local/ustar:feedpublish', 'local/ustar:feedpublishacademy',
        'local/ustar:feedsetaudience'],
    'ustar_feed_department' => ['local/ustar:feedpublish', 'local/ustar:feedpublishdepartment'],
    'ustar_feed_moderator' => ['local/ustar:use', 'local/ustar:feedmoderate'],
];
$report = ['contextid' => $context->id, 'roles' => [], 'users' => [],
    'moderator_capability' => 'local/ustar:feedmoderate'];
foreach ($roles as $shortname => $caps) {
    $role = $DB->get_record('role', ['shortname' => $shortname], 'id,shortname', IGNORE_MISSING);
    $assigned = $role ? $DB->get_records('role_assignments',
        ['roleid' => $role->id, 'contextid' => $context->id], 'userid ASC', 'id,userid') : [];
    $report['roles'][$shortname] = [
        'roleid' => $role ? (int)$role->id : null,
        'capabilities' => $caps,
        'assigned_userids' => array_values(array_unique(array_map(
            static fn($assignment) => (int)$assignment->userid, $assigned))),
    ];
}

$rawids = trim((string)$options['userids']);
if ($rawids !== '' && !preg_match('/^[0-9]+(?:,[0-9]+)*$/D', $rawids)) {
    cli_error('Expected comma-separated numeric Moodle user IDs');
}
$userids = $rawids === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $rawids))));
if (count($userids) > 100) {
    cli_error('At most 100 explicit IDs per audit');
}
foreach ($userids as $userid) {
    $user = $DB->get_record('user', ['id' => $userid], 'id,deleted,suspended', IGNORE_MISSING);
    if (!$user) {
        $report['users'][] = ['userid' => $userid, 'exists' => false];
        continue;
    }
    $active = \local_ustar\accounts::participates($userid);
    $scope = $active ? \local_ustar\organization_model::manager_department_ids($userid) : [];
    $caps = [];
    foreach (['local/ustar:feedpublish', 'local/ustar:feedpublishacademy',
            'local/ustar:feedsetaudience', 'local/ustar:feedpublishdepartment',
            'local/ustar:feedmoderate'] as $capability) {
        $caps[$capability] = has_capability($capability, $context, $userid);
    }
    $report['users'][] = [
        'userid' => $userid, 'exists' => true, 'deleted' => (bool)$user->deleted,
        'suspended' => (bool)$user->suspended, 'active_employee' => $active,
        'department_scope' => array_values($scope), 'effective_capabilities' => $caps,
        'may_publish_department' => $active && $caps['local/ustar:feedpublish']
            && $caps['local/ustar:feedpublishdepartment'] && !empty($scope),
        'may_publish_academy' => $active && $caps['local/ustar:feedpublish']
            && $caps['local/ustar:feedpublishacademy'],
    ];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
