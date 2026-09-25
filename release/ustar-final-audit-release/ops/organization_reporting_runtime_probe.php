<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB, $USER;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(2);
}

$expected = $argv[1] ?? 'guarded';
if (!in_array($expected, ['unsafe', 'guarded'], true)) {
    fwrite(STDERR, "Expected mode must be unsafe or guarded\n");
    exit(2);
}

$users = [];
foreach (['employee', 'retail_head', 'hr', 'ceo', 'superadmin'] as $scenario) {
    $users[$scenario] = $DB->get_record('user', ['username' => 'audit_' . $scenario], '*', MUST_EXIST);
}

$originaluser = $USER;
$originalaccounttype = \local_ustar\accounts::type_of((int)$users['employee']->id);
$affectedids = [(int)$users['employee']->id, (int)$users['retail_head']->id];
$originalreporting = [];
foreach ($affectedids as $userid) {
    $record = $DB->get_record('local_ustar_reporting', ['userid' => $userid]);
    if ($record) {
        $originalreporting[$userid] = clone $record;
    }
}

function audit_reporting_fingerprint(): string {
    global $DB;
    $rows = [];
    foreach ($DB->get_records('local_ustar_reporting', null, 'userid ASC') as $row) {
        $rows[] = [(int)$row->userid, (int)($row->managerid ?? 0), (string)$row->source];
    }
    return hash('sha256', json_encode($rows));
}

function audit_call_team(stdClass $user): array {
    \core\session\manager::set_user($user);
    $hascapability = has_capability('local/ustar:viewteam', \context_system::instance(), (int)$user->id);
    try {
        $response = \local_ustar\external\get_team::execute();
        $payload = json_decode((string)$response['json'], true) ?: [];
        $nonparticipants = 0;
        foreach (($payload['team'] ?? []) as $person) {
            if (!\local_ustar\accounts::participates((int)$person['id'])) {
                $nonparticipants++;
            }
        }
        return [
            'allowed' => true,
            'viewteam_capability' => $hascapability,
            'scope' => (string)($payload['scope'] ?? ''),
            'people' => count($payload['team'] ?? []),
            'nonparticipating_people' => $nonparticipants,
        ];
    } catch (\required_capability_exception $e) {
        return ['allowed' => false, 'viewteam_capability' => $hascapability, 'scope' => '', 'people' => 0, 'nonparticipating_people' => 0];
    }
}

function audit_call_executive(stdClass $user): array {
    \core\session\manager::set_user($user);
    $hascapability = has_capability('local/ustar:executive', \context_system::instance(), (int)$user->id);
    try {
        $response = \local_ustar\external\executive_get_dashboard::execute();
        $payload = json_decode((string)$response['json'], true) ?: [];
        return ['allowed' => true, 'executive_capability' => $hascapability, 'total_people' => (int)($payload['totalPeople'] ?? 0)];
    } catch (\required_capability_exception $e) {
        return ['allowed' => false, 'executive_capability' => $hascapability, 'total_people' => 0];
    }
}

$baseline = [
    'reporting_rows' => $DB->count_records('local_ustar_reporting'),
    'reporting_fingerprint' => audit_reporting_fingerprint(),
    'employee_account_type' => $originalaccounttype,
];
$result = [
    'expected_mode' => $expected,
    'baseline' => $baseline,
    'inventory' => [],
    'reporting_truth' => [],
    'relation_semantics' => [],
    'team_boundaries' => [],
    'executive_boundaries' => [],
];

try {
    $st = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
    $dbman = $DB->get_manager();
    $result['inventory'] = [
        'active_nonsite_accounts' => $DB->count_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1'),
        'participating_accounts' => count(array_filter(
            array_map('intval', $DB->get_fieldset_select('user', 'id', 'deleted = 0 AND suspended = 0 AND id > 1')),
            static fn(int $id): bool => \local_ustar\accounts::participates($id)
        )),
        'departments_declared' => count($st['departments'] ?? []),
        'positions_declared' => count($st['positions'] ?? []),
        'reporting_table_exists' => $dbman->table_exists(new \xmldb_table('local_ustar_reporting')),
        'reporting_rows' => $DB->count_records('local_ustar_reporting'),
        'distinct_managers' => count($DB->get_fieldset_select('local_ustar_reporting', 'DISTINCT managerid', 'managerid IS NOT NULL')),
    ];

    $hasconfiguredmethod = method_exists(\local_ustar\org::class, 'reporting_configured');
    $result['reporting_truth'] = [
        'table_available' => \local_ustar\org::reporting_available(),
        'configured_method_present' => $hasconfiguredmethod,
        'configured_without_rows' => $hasconfiguredmethod ? \local_ustar\org::reporting_configured() : null,
    ];

    \core\session\manager::set_user($users['superadmin']);
    \local_ustar\org::set_manager((int)$users['employee']->id, (int)$users['retail_head']->id, 'audit_probe');
    $chain = \local_ustar\org::chain((int)$users['employee']->id);
    $reports = \local_ustar\org::direct_reports((int)$users['retail_head']->id);
    $reportids = array_map(static fn(array $person): int => (int)$person['id'], $reports);
    $result['relation_semantics']['valid_relation'] = [
        'manager_resolved' => \local_ustar\org::manager_id((int)$users['employee']->id) === (int)$users['retail_head']->id,
        'chain_depth' => count($chain),
        'direct_report_visible' => in_array((int)$users['employee']->id, $reportids, true),
        'configured_with_valid_row' => $hasconfiguredmethod ? \local_ustar\org::reporting_configured() : null,
    ];
    try {
        \local_ustar\org::set_manager((int)$users['retail_head']->id, (int)$users['employee']->id, 'audit_cycle');
        $result['relation_semantics']['cycle_rejected'] = false;
    } catch (\invalid_parameter_exception $e) {
        $result['relation_semantics']['cycle_rejected'] = true;
    }
    try {
        \local_ustar\org::set_manager((int)$users['employee']->id, (int)$users['employee']->id, 'audit_self');
        $result['relation_semantics']['self_rejected'] = false;
    } catch (\invalid_parameter_exception $e) {
        $result['relation_semantics']['self_rejected'] = true;
    }
    try {
        \local_ustar\org::set_manager((int)$users['employee']->id, 2147483000, 'audit_dangling');
        $result['relation_semantics']['dangling_manager_accepted'] = true;
        $result['relation_semantics']['dangling_manager_rejected'] = false;
    } catch (\invalid_parameter_exception $e) {
        $result['relation_semantics']['dangling_manager_accepted'] = false;
        $result['relation_semantics']['dangling_manager_rejected'] = true;
    }

    // A test/service account must not appear in workforce team metrics.
    \local_ustar\accounts::set_type((int)$users['employee']->id, \local_ustar\accounts::TYPE_TEST);
    foreach ($users as $scenario => $user) {
        $result['team_boundaries'][$scenario] = audit_call_team($user);
        $result['executive_boundaries'][$scenario] = audit_call_executive($user);
    }
} finally {
    \core\session\manager::set_user($originaluser);
    \local_ustar\accounts::set_type((int)$users['employee']->id, $originalaccounttype);
    foreach ($affectedids as $userid) {
        $DB->delete_records('local_ustar_reporting', ['userid' => $userid]);
        if (isset($originalreporting[$userid])) {
            $DB->insert_record('local_ustar_reporting', $originalreporting[$userid], false);
        }
    }
}

$final = [
    'reporting_rows' => $DB->count_records('local_ustar_reporting'),
    'reporting_fingerprint' => audit_reporting_fingerprint(),
    'employee_account_type' => \local_ustar\accounts::type_of((int)$users['employee']->id),
];
$result['final'] = $final;
$result['baseline_restored'] = $baseline === $final;

$common =
    !empty($result['relation_semantics']['valid_relation']['manager_resolved']) &&
    (int)$result['relation_semantics']['valid_relation']['chain_depth'] === 2 &&
    !empty($result['relation_semantics']['valid_relation']['direct_report_visible']) &&
    !empty($result['relation_semantics']['cycle_rejected']) &&
    !empty($result['relation_semantics']['self_rejected']) &&
    empty($result['team_boundaries']['employee']['allowed']) &&
    !empty($result['team_boundaries']['retail_head']['allowed']) &&
    !empty($result['executive_boundaries']['ceo']['allowed']) &&
    empty($result['executive_boundaries']['employee']['allowed']);

$unsafe =
    !$result['reporting_truth']['configured_method_present'] &&
    !empty($result['relation_semantics']['dangling_manager_accepted']) &&
    (int)$result['team_boundaries']['retail_head']['nonparticipating_people'] > 0;

$guarded =
    $result['reporting_truth']['configured_method_present'] &&
    $result['reporting_truth']['configured_without_rows'] === false &&
    $result['relation_semantics']['valid_relation']['configured_with_valid_row'] === true &&
    !empty($result['relation_semantics']['dangling_manager_rejected']) &&
    (int)$result['team_boundaries']['retail_head']['nonparticipating_people'] === 0 &&
    empty($result['team_boundaries']['hr']['allowed']) &&
    (bool)$result['team_boundaries']['ceo']['allowed'] === (bool)$result['team_boundaries']['ceo']['viewteam_capability'] &&
    (bool)$result['team_boundaries']['retail_head']['allowed'] === (bool)$result['team_boundaries']['retail_head']['viewteam_capability'] &&
    (bool)$result['executive_boundaries']['ceo']['allowed'] === (bool)$result['executive_boundaries']['ceo']['executive_capability'];

$result['expected_semantics_pass'] = $common && ($expected === 'unsafe' ? $unsafe : $guarded);
$result['runtime_boundary_pass'] = $result['expected_semantics_pass'] && $result['baseline_restored'];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['runtime_boundary_pass'] ? 0 : 1);
