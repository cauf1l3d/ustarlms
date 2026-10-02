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

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$hr = $DB->get_record('user', ['username' => 'audit_hr'], '*', MUST_EXIST);
$definition = $DB->get_record_sql(
    "SELECT *
       FROM {local_ustar_skill_evidence}
      WHERE evidencetype = :type AND active = :active
   ORDER BY id ASC",
    ['type' => 'assessment', 'active' => 1],
    IGNORE_MULTIPLE
);
if (!$definition) {
    fwrite(STDERR, "No active assessment evidence fixture\n");
    exit(2);
}
if (empty($definition->cmid)) {
    fwrite(STDERR, "Assessment fixture must use an activity-level source\n");
    exit(2);
}

$originaluser = $USER;
$completionparams = [
    'coursemoduleid' => (int)$definition->cmid,
    'userid' => (int)$employee->id,
];
$originalcompletion = $DB->get_record('course_modules_completion', $completionparams) ?: null;
$structurerecord = $DB->get_record('local_ustar_structure', ['name' => 'checklists'], '*', MUST_EXIST);
$originalstructure = clone $structurerecord;
$originalactionids = array_map('intval', array_keys($DB->get_records('local_ustar_hr_actions', [
    'actorid' => (int)$hr->id,
    'action' => 'checklists_published',
])));

$baseline = [
    'skill_evidence' => $DB->count_records('local_ustar_skill_evidence'),
    'check_runs' => $DB->count_records('local_ustar_check_runs'),
    'check_answers' => $DB->count_records('local_ustar_check_answers'),
    'route_progress' => $DB->count_records('local_ustar_route_progress'),
    'structure_hash' => hash('sha256', (string)$originalstructure->jsondata),
    'structure_version' => (int)$originalstructure->version,
];

$result = [
    'expected_mode' => $expected,
    'baseline' => $baseline,
    'assessment_state_1' => null,
    'assessment_state_2' => null,
    'assessment_state_3' => null,
    'unsupported_manager_review' => null,
    'course_level_assessment' => null,
    'duplicate_checklist_item_accepted' => false,
    'duplicate_checklist_item_rejected' => false,
    'stale_checklist_version_accepted' => false,
    'stale_checklist_version_rejected' => false,
    'employee_hr_publish_denied' => false,
    'employee_unassigned_submit_denied' => false,
    'gate_inventory' => [],
    'history_inventory' => [],
];

function audit_set_completion_state(stdClass $definition, stdClass $employee, int $state): void {
    global $DB;
    $params = [
        'coursemoduleid' => (int)$definition->cmid,
        'userid' => (int)$employee->id,
    ];
    $record = $DB->get_record('course_modules_completion', $params);
    if ($record) {
        $record->completionstate = $state;
        $record->timemodified = time();
        $DB->update_record('course_modules_completion', $record);
        return;
    }
    $DB->insert_record('course_modules_completion', (object)[
        'coursemoduleid' => (int)$definition->cmid,
        'userid' => (int)$employee->id,
        'completionstate' => $state,
        'viewed' => 0,
        'overrideby' => null,
        'timemodified' => time(),
    ]);
}

function audit_checklist_publish(array $payload): array {
    try {
        \local_ustar\external\hr_save_checklists::execute(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        return ['accepted' => true, 'rejected' => false];
    } catch (\invalid_parameter_exception $e) {
        return ['accepted' => false, 'rejected' => true];
    }
}

try {
    foreach ([1, 2, 3] as $state) {
        audit_set_completion_state($definition, $employee, $state);
        $evaluated = \local_ustar\evidence::evaluate_definition($definition, (int)$employee->id);
        $result['assessment_state_' . $state] = [
            'status' => (string)$evaluated['status'],
            'satisfied' => !empty($evaluated['satisfied']),
            'passed' => $evaluated['passed'],
        ];
    }

    audit_set_completion_state($definition, $employee, 2);
    $unsupported = clone $definition;
    $unsupported->evidencetype = 'manager_review';
    $evaluated = \local_ustar\evidence::evaluate_definition($unsupported, (int)$employee->id);
    $result['unsupported_manager_review'] = [
        'status' => (string)$evaluated['status'],
        'configured' => !empty($evaluated['configured']),
        'satisfied' => !empty($evaluated['satisfied']),
    ];

    $courseassessment = clone $definition;
    $courseassessment->cmid = null;
    $evaluated = \local_ustar\evidence::evaluate_definition($courseassessment, (int)$employee->id);
    $result['course_level_assessment'] = [
        'status' => (string)$evaluated['status'],
        'satisfied' => !empty($evaluated['satisfied']),
    ];

    \core\session\manager::set_user($employee);
    $employeecatalog = \local_ustar\checklists::get();
    try {
        \local_ustar\external\hr_save_checklists::execute(json_encode(
            $employeecatalog,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    } catch (\required_capability_exception $e) {
        $result['employee_hr_publish_denied'] = true;
    }
    $resolved = \local_ustar\structure::resolve_user((int)$employee->id);
    $employeeposition = (string)($resolved['position']['id'] ?? '');
    foreach (($employeecatalog['items'] ?? []) as $catalogchecklist) {
        if (!\local_ustar\checklists::applies_to($catalogchecklist, $employeeposition)) {
            try {
                \local_ustar\external\submit_checklist::execute(
                    (string)$catalogchecklist['id'],
                    '{}',
                    ''
                );
            } catch (\required_capability_exception $e) {
                $result['employee_unassigned_submit_denied'] = true;
            }
            break;
        }
    }

    \core\session\manager::set_user($hr);
    $catalog = \local_ustar\checklists::get();
    $version = (int)($catalog['version'] ?? 0);
    $duplicatepayload = [
        'version' => $version,
        'items' => [[
            'id' => '__audit_duplicate_items__',
            'title' => 'Audit duplicate item boundary',
            'active' => true,
            'recurrence' => 'manual',
            'positionIds' => [],
            'sections' => [[
                'id' => 'audit',
                'title' => 'Audit',
                'items' => [
                    ['id' => 'same_item', 'title' => 'First'],
                    ['id' => 'same_item', 'title' => 'Second'],
                ],
            ]],
        ]],
    ];
    $duplicate = audit_checklist_publish($duplicatepayload);
    $result['duplicate_checklist_item_accepted'] = $duplicate['accepted'];
    $result['duplicate_checklist_item_rejected'] = $duplicate['rejected'];
    if ($duplicate['accepted']) {
        $DB->update_record('local_ustar_structure', $originalstructure);
    }

    $stalepayload = $duplicatepayload;
    $stalepayload['version'] = max(0, $version - 1);
    $stalepayload['items'][0]['sections'][0]['items'][1]['id'] = 'other_item';
    $stale = audit_checklist_publish($stalepayload);
    $result['stale_checklist_version_accepted'] = $stale['accepted'];
    $result['stale_checklist_version_rejected'] = $stale['rejected'];
    if ($stale['accepted']) {
        $DB->update_record('local_ustar_structure', $originalstructure);
    }

    $gateversions = $DB->get_records_sql(
        "SELECT v.*
           FROM {local_ustar_route_versions} v
           JOIN {local_ustar_route_points} p ON p.id = v.pointid
          WHERE p.phase = :phase AND v.status = :status",
        ['phase' => 'gate', 'status' => 'published']
    );
    $gatetypes = [];
    foreach ($gateversions as $versionrow) {
        foreach ((json_decode((string)$versionrow->requirementsjson, true) ?: []) as $requirement) {
            $type = (string)($requirement['type'] ?? 'missing');
            $gatetypes[$type] = ($gatetypes[$type] ?? 0) + 1;
        }
    }
    ksort($gatetypes);
    $dbman = $DB->get_manager();
    $result['gate_inventory'] = [
        'published_gate_versions' => count($gateversions),
        'requirement_types' => $gatetypes,
        'versions_with_validity' => count(array_filter(
            $gateversions,
            static fn(stdClass $row): bool => (int)$row->validdays > 0
        )),
        'progress_with_expiry' => $DB->count_records_select(
            'local_ustar_route_progress',
            'expiresat IS NOT NULL AND expiresat > 0'
        ),
        'gate_decision_table' => $dbman->table_exists(new xmldb_table('local_ustar_gate_decisions')),
        'gate_revocation_table' => $dbman->table_exists(new xmldb_table('local_ustar_gate_revocations')),
    ];
    $runstable = new xmldb_table('local_ustar_check_runs');
    $answerstable = new xmldb_table('local_ustar_check_answers');
    $result['history_inventory'] = [
        'runs' => $DB->count_records('local_ustar_check_runs'),
        'answers' => $DB->count_records('local_ustar_check_answers'),
        'run_definition_version_field' => $dbman->field_exists($runstable, new xmldb_field('definitionversion')),
        'answer_revision_field' => $dbman->field_exists($answerstable, new xmldb_field('revision')),
        'answer_correction_field' => $dbman->field_exists($answerstable, new xmldb_field('correctionid')),
    ];
} finally {
    \core\session\manager::set_user($originaluser);
    if ($originalcompletion) {
        $current = $DB->get_record('course_modules_completion', $completionparams);
        if ($current && (int)$current->id !== (int)$originalcompletion->id) {
            $DB->delete_records('course_modules_completion', $completionparams);
            $DB->insert_record('course_modules_completion', $originalcompletion, false);
        } else if ($current) {
            $DB->update_record('course_modules_completion', $originalcompletion);
        } else {
            $DB->insert_record('course_modules_completion', $originalcompletion, false);
        }
    } else {
        $DB->delete_records('course_modules_completion', $completionparams);
    }
    $DB->update_record('local_ustar_structure', $originalstructure);
    foreach ($DB->get_records('local_ustar_hr_actions', [
        'actorid' => (int)$hr->id,
        'action' => 'checklists_published',
    ]) as $action) {
        if (!in_array((int)$action->id, $originalactionids, true)) {
            $DB->delete_records('local_ustar_hr_actions', ['id' => (int)$action->id]);
        }
    }
}

$finalstructure = $DB->get_record('local_ustar_structure', ['name' => 'checklists'], '*', MUST_EXIST);
$final = [
    'skill_evidence' => $DB->count_records('local_ustar_skill_evidence'),
    'check_runs' => $DB->count_records('local_ustar_check_runs'),
    'check_answers' => $DB->count_records('local_ustar_check_answers'),
    'route_progress' => $DB->count_records('local_ustar_route_progress'),
    'structure_hash' => hash('sha256', (string)$finalstructure->jsondata),
    'structure_version' => (int)$finalstructure->version,
];
$result['final'] = $final;
$result['baseline_restored'] = $baseline === $final;

$unsafe =
    !empty($result['assessment_state_1']['satisfied']) &&
    !empty($result['unsupported_manager_review']['satisfied']) &&
    $result['duplicate_checklist_item_accepted'] &&
    $result['stale_checklist_version_accepted'] &&
    $result['employee_hr_publish_denied'] &&
    $result['employee_unassigned_submit_denied'];
$guarded =
    empty($result['assessment_state_1']['satisfied']) &&
    (string)$result['assessment_state_1']['status'] === 'completed_ungraded' &&
    empty($result['unsupported_manager_review']['satisfied']) &&
    (string)$result['unsupported_manager_review']['status'] === 'unsupported_type' &&
    (string)$result['course_level_assessment']['status'] === 'unsupported_source' &&
    $result['duplicate_checklist_item_rejected'] &&
    $result['stale_checklist_version_rejected'] &&
    $result['employee_hr_publish_denied'] &&
    $result['employee_unassigned_submit_denied'];
$result['expected_semantics_pass'] = $expected === 'unsafe' ? $unsafe : $guarded;
$result['runtime_boundary_pass'] = $result['expected_semantics_pass'] && $result['baseline_restored'];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['runtime_boundary_pass'] ? 0 : 1);
