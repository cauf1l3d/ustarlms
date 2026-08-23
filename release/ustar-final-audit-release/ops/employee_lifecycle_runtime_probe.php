<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB, $USER;
if (!in_array($CFG->wwwroot, ['http://127.0.0.1:18080', 'http://127.0.0.1:18081', 'http://127.0.0.1:18082'], true)
        || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(2);
}
$expected = $argv[1] ?? 'guarded';
if (!in_array($expected, ['unsafe', 'guarded'], true)) exit(2);

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$hr = $DB->get_record('user', ['username' => 'audit_hr'], '*', MUST_EXIST);
$originaluser = $USER;
$originalposition = \local_ustar\people::position_id((int)$employee->id);
$hrposition = \local_ustar\people::position_id((int)$hr->id);
$originalroles = array_values($DB->get_records('role_assignments', [
    'userid' => (int)$employee->id,
    'component' => 'local_ustar',
]));
$originalenrolids = array_map('intval', array_keys($DB->get_records('user_enrolments', ['userid' => (int)$employee->id])));
$originalactionids = array_map('intval', array_keys($DB->get_records_select(
    'local_ustar_hr_actions',
    'actorid = :actor AND (targetuserid = :employee OR targetuserid = :hr)',
    ['actor' => (int)$hr->id, 'employee' => (int)$employee->id, 'hr' => (int)$hr->id]
)));

function audit_employee_owned_roles(int $userid): array {
    global $DB;
    $out = [];
    $sql = "SELECT r.shortname
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid AND ra.component = :component
          ORDER BY r.shortname";
    foreach ($DB->get_fieldset_sql($sql, ['userid' => $userid, 'component' => 'local_ustar']) as $role) {
        $out[] = (string)$role;
    }
    return $out;
}

$baseline = [
    'employee_position' => $originalposition,
    'employee_owned_roles' => audit_employee_owned_roles((int)$employee->id),
    'employee_enrolments' => count($originalenrolids),
    'hr_actions' => $DB->count_records('local_ustar_hr_actions'),
];
$result = ['expected_mode' => $expected, 'baseline' => $baseline, 'inventory' => [], 'boundaries' => []];

try {
    $st = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
    $declared = [];
    foreach (($st['positions'] ?? []) as $position) $declared[(string)$position['id']] = true;
    $positionfieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'ustar_position']);
    $accountfieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => \local_ustar\accounts::FIELD]);
    $unknownpositions = 0;
    if ($positionfieldid) {
        foreach ($DB->get_fieldset_select('user_info_data', 'data', 'fieldid = :fieldid AND TRIM(data) <> :empty', [
            'fieldid' => $positionfieldid, 'empty' => '',
        ]) as $positionid) {
            if (!isset($declared[trim((string)$positionid)])) $unknownpositions++;
        }
    }
    $typecounts = [];
    if ($accountfieldid) {
        foreach ($DB->get_records_sql(
            "SELECT data, COUNT(*) AS total FROM {user_info_data} WHERE fieldid = :fieldid GROUP BY data",
            ['fieldid' => $accountfieldid]
        ) as $row) $typecounts[(string)$row->data] = (int)$row->total;
    }
    ksort($typecounts);
    $dbman = $DB->get_manager();
    $result['inventory'] = [
        'account_rows' => $DB->count_records('user'),
        'undeleted_accounts' => $DB->count_records('user', ['deleted' => 0]),
        'suspended_undeleted' => $DB->count_records('user', ['deleted' => 0, 'suspended' => 1]),
        'declared_positions' => count($declared),
        'used_position_values' => $positionfieldid ? count(array_unique(array_map('trim', $DB->get_fieldset_select(
            'user_info_data', 'data', 'fieldid = :fieldid AND TRIM(data) <> :empty', ['fieldid' => $positionfieldid, 'empty' => '']
        )))) : 0,
        'unknown_position_rows' => $unknownpositions,
        'explicit_account_types' => $typecounts,
        'local_ustar_owned_role_assignments' => $DB->count_records('role_assignments', ['component' => 'local_ustar']),
        'user_enrolments' => $DB->count_records('user_enrolments'),
        'staff_place_table' => $dbman->table_exists(new \xmldb_table('local_ustar_staff_place')),
        'employment_assignment_table' => $dbman->table_exists(new \xmldb_table('local_ustar_employment_assignment')),
        'assignment_history_table' => $dbman->table_exists(new \xmldb_table('local_ustar_assignment_history')),
    ];

    \core\session\manager::set_user($hr);
    $selfresponse = \local_ustar\external\hr_import_people::execute(json_encode([[
        'username' => (string)$hr->username,
        'positionid' => $hrposition,
    ]], JSON_UNESCAPED_SLASHES));
    $selfpayload = json_decode((string)$selfresponse['json'], true) ?: [];
    $result['boundaries']['hr_self_import_accepted'] = (int)($selfpayload['updated'] ?? 0) === 1
        && empty($selfpayload['errors']);
    $result['boundaries']['hr_self_import_rejected'] = (int)($selfpayload['updated'] ?? 0) === 0
        && count($selfpayload['errors'] ?? []) === 1;

    $headresponse = \local_ustar\external\hr_bulk_assign_positions::execute(json_encode([[
        'userid' => (int)$employee->id,
        'positionid' => 'retail_head',
    ]], JSON_UNESCAPED_SLASHES));
    $headpayload = json_decode((string)$headresponse['json'], true) ?: [];
    $result['boundaries']['after_bulk_to_head'] = [
        'position' => \local_ustar\people::position_id((int)$employee->id),
        'viewteam' => has_capability('local/ustar:viewteam', \context_system::instance(), (int)$employee->id),
        'access_synced' => (int)($headpayload['sync']['accessSynced'] ?? 0),
        'owned_roles' => audit_employee_owned_roles((int)$employee->id),
    ];

    // Establish the intended CURRENT projection so the second mutation can detect stale access.
    if (!$result['boundaries']['after_bulk_to_head']['viewteam']) {
        \local_ustar\position_access::sync_user((int)$employee->id);
    }

    $sellerresponse = \local_ustar\external\hr_bulk_assign_positions::execute(json_encode([[
        'userid' => (int)$employee->id,
        'positionid' => 'retail_seller',
    ]], JSON_UNESCAPED_SLASHES));
    $sellerpayload = json_decode((string)$sellerresponse['json'], true) ?: [];
    $result['boundaries']['after_bulk_to_employee'] = [
        'position' => \local_ustar\people::position_id((int)$employee->id),
        'viewteam' => has_capability('local/ustar:viewteam', \context_system::instance(), (int)$employee->id),
        'access_synced' => (int)($sellerpayload['sync']['accessSynced'] ?? 0),
        'owned_roles' => audit_employee_owned_roles((int)$employee->id),
    ];
} finally {
    \core\session\manager::set_user($originaluser);
    \local_ustar\people::set_position_id((int)$employee->id, $originalposition);
    $DB->delete_records('role_assignments', ['userid' => (int)$employee->id, 'component' => 'local_ustar']);
    foreach ($originalroles as $role) $DB->insert_record('role_assignments', $role, false);
    foreach ($DB->get_records('user_enrolments', ['userid' => (int)$employee->id]) as $enrolment) {
        if (!in_array((int)$enrolment->id, $originalenrolids, true)) {
            $DB->delete_records('user_enrolments', ['id' => (int)$enrolment->id]);
        }
    }
    foreach ($DB->get_records_select(
        'local_ustar_hr_actions',
        'actorid = :actor AND (targetuserid = :employee OR targetuserid = :hr)',
        ['actor' => (int)$hr->id, 'employee' => (int)$employee->id, 'hr' => (int)$hr->id]
    ) as $action) {
        if (!in_array((int)$action->id, $originalactionids, true)) $DB->delete_records('local_ustar_hr_actions', ['id' => (int)$action->id]);
    }
    accesslib_clear_all_caches(true);
}

$final = [
    'employee_position' => \local_ustar\people::position_id((int)$employee->id),
    'employee_owned_roles' => audit_employee_owned_roles((int)$employee->id),
    'employee_enrolments' => $DB->count_records('user_enrolments', ['userid' => (int)$employee->id]),
    'hr_actions' => $DB->count_records('local_ustar_hr_actions'),
];
$result['final'] = $final;
$result['baseline_restored'] = $baseline === $final;
$unsafe = $result['boundaries']['hr_self_import_accepted']
    && !$result['boundaries']['after_bulk_to_head']['viewteam']
    && $result['boundaries']['after_bulk_to_employee']['viewteam'];
$guarded = $result['boundaries']['hr_self_import_rejected']
    && $result['boundaries']['after_bulk_to_head']['viewteam']
    && !$result['boundaries']['after_bulk_to_employee']['viewteam']
    && (int)$result['boundaries']['after_bulk_to_head']['access_synced'] === 1
    && (int)$result['boundaries']['after_bulk_to_employee']['access_synced'] === 1;
$result['expected_semantics_pass'] = $expected === 'unsafe' ? $unsafe : $guarded;
$result['runtime_boundary_pass'] = $result['expected_semantics_pass'] && $result['baseline_restored'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['runtime_boundary_pass'] ? 0 : 1);
