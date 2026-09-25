<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB, $USER;
set_exception_handler(static function(\Throwable $e): void {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});
if (!in_array($CFG->wwwroot, ['http://127.0.0.1:18080', 'http://127.0.0.1:18081', 'http://127.0.0.1:18082'], true)
        || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n"); exit(2);
}

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$manager = $DB->get_record('user', ['username' => 'audit_retail_head'], '*', MUST_EXIST);
$hr = $DB->get_record('user', ['username' => 'audit_hr'], '*', MUST_EXIST);
$originaluser = $USER;
$tables = [
    'local_ustar_evidence_rec','local_ustar_evidence_evt','local_ustar_gate_defs','local_ustar_gate_decisions',
    'local_ustar_check_submits','local_ustar_official_tasks','local_ustar_personal_tasks',
    'local_ustar_workflow_events','local_ustar_notifications','local_ustar_notify_delivery',
];
$baseline = [];
foreach ($tables as $table) $baseline[$table] = $DB->count_records($table);
$oldreporting = $DB->get_record('local_ustar_reporting', ['userid' => (int)$employee->id], '*', IGNORE_MISSING);
$ids = ['evidence'=>[], 'gate'=>[], 'decision'=>[], 'check'=>[], 'official'=>[], 'personal'=>[], 'notification'=>[]];
$checks = [];

try {
    \core\session\manager::set_user($manager);
    \local_ustar\org::set_manager((int)$employee->id, (int)$manager->id, 'target_probe');

    $team = json_decode((string)\local_ustar\external\get_team::execute()['json'], true) ?: [];
    $teamids = array_map('intval', array_column($team['team'] ?? [], 'id'));
    $checks['team_scope_direct_reports'] = ($team['scope'] ?? '') === 'direct_reports'
        && in_array((int)$employee->id, $teamids, true) && !in_array((int)$hr->id, $teamids, true);

    $evidenceid = \local_ustar\target_core::record_evidence([
        'userid'=>(int)$employee->id, 'skillid'=>'audit_skill', 'positionid'=>'retail_seller',
        'evidencetype'=>'practice', 'sourcekind'=>'checklist', 'sourceid'=>'audit-practice-1',
        'outcome'=>'passed', 'idempotencykey'=>'target-core:evidence:audit-practice-1',
        'details'=>['synthetic'=>true],
    ], (int)$manager->id);
    $ids['evidence'][] = $evidenceid;
    $evidenceagain = \local_ustar\target_core::record_evidence([
        'userid'=>(int)$employee->id, 'skillid'=>'audit_skill', 'positionid'=>'retail_seller',
        'evidencetype'=>'practice', 'sourcekind'=>'checklist', 'sourceid'=>'audit-practice-1',
        'outcome'=>'passed', 'idempotencykey'=>'target-core:evidence:audit-practice-1',
    ], (int)$manager->id);
    $checks['evidence_idempotent'] = $evidenceid === $evidenceagain && \local_ustar\target_core::evidence_is_valid($evidenceid);

    $now = time();
    $gateid = (int)$DB->insert_record('local_ustar_gate_defs', (object)[
        'code'=>'audit_critical_operation','title'=>'Synthetic critical operation','operationkey'=>'audit_operation',
        'riskclass'=>'critical','policyjson'=>'{"required":["practice"]}','versionno'=>1,'status'=>'published',
        'effectivedate'=>$now,'ownerid'=>(int)$hr->id,'timecreated'=>$now,'timemodified'=>$now,
    ]);
    $ids['gate'][] = $gateid;
    try {
        \local_ustar\target_core::decide_gate([
            'gateid'=>$gateid,'userid'=>(int)$employee->id,'decision'=>'granted','reason'=>'self grant',
            'evidenceids'=>[$evidenceid],
        ], (int)$employee->id);
        $checks['gate_self_grant_denied'] = false;
    } catch (\required_capability_exception $e) {
        $checks['gate_self_grant_denied'] = true;
    }
    $decisionid = \local_ustar\target_core::decide_gate([
        'gateid'=>$gateid,'userid'=>(int)$employee->id,'decision'=>'granted','reason'=>'Synthetic manager decision',
        'evidenceids'=>[$evidenceid],
    ], (int)$manager->id);
    $ids['decision'][] = $decisionid;
    $checks['gate_human_decision_recorded'] = $decisionid > 0;

    $employeecheck = \local_ustar\target_core::submit_checklist([
        'checklistkey'=>'audit_adaptation_day','definitionversion'=>1,'userid'=>(int)$employee->id,
        'perspective'=>'employee','workdate'=>date('Y-m-d'),'answers'=>['learned'=>'yes'],'issues'=>[],
    ], (int)$employee->id);
    $managercheck = \local_ustar\target_core::submit_checklist([
        'checklistkey'=>'audit_adaptation_day','definitionversion'=>1,'userid'=>(int)$employee->id,
        'perspective'=>'manager','workdate'=>date('Y-m-d'),'answers'=>['independent'=>'yes'],'issues'=>[],
    ], (int)$manager->id);
    $ids['check'] = [$employeecheck,$managercheck];
    $checks['checklist_mirrored_immutable'] = $employeecheck !== $managercheck
        && $DB->count_records('local_ustar_check_submits', ['userid'=>(int)$employee->id,'checklistkey'=>'audit_adaptation_day']) === 2;

    $officialid = \local_ustar\target_core::create_official_task([
        'userid'=>(int)$employee->id,'sourcekind'=>'manager','sourceid'=>'audit-gap-1','category'=>'gap',
        'title'=>'Synthetic gap action','completion'=>['type'=>'manager_confirmation'],'ownerid'=>(int)$employee->id,
    ], (int)$manager->id);
    $ids['official'][] = $officialid;
    \local_ustar\target_core::transition_official_task($officialid, 'in_progress', (int)$manager->id);
    \local_ustar\target_core::transition_official_task($officialid, 'completed', (int)$manager->id);
    $checks['official_task_history'] = $DB->count_records('local_ustar_workflow_events', ['entitytype'=>'official_task','entityid'=>$officialid]) === 3;

    $personalid = \local_ustar\target_core::save_personal_task([
        'userid'=>(int)$employee->id,'title'=>'Synthetic private reminder','sharedwith'=>[],
    ], (int)$employee->id);
    $ids['personal'][] = $personalid;
    try {
        \local_ustar\target_core::save_personal_task([
            'id'=>$personalid,'userid'=>(int)$employee->id,'title'=>'Manager attempted edit',
        ], (int)$manager->id);
        $checks['personal_task_private'] = false;
    } catch (\required_capability_exception $e) {
        $checks['personal_task_private'] = true;
    }

    $normalid = \local_ustar\target_core::notify([
        'userid'=>(int)$employee->id,'severity'=>'normal','eventtype'=>'audit_normal',
        'subject'=>'Synthetic normal','message'=>'Synthetic notification','idempotencykey'=>'target-core:notify:normal',
    ]);
    $actionid = \local_ustar\target_core::notify([
        'userid'=>(int)$employee->id,'severity'=>'action','eventtype'=>'audit_action',
        'subject'=>'Synthetic action','message'=>'Synthetic action notification','actionurl'=>'/local/ustar/home.php',
        'idempotencykey'=>'target-core:notify:action',
    ]);
    $normalagain = \local_ustar\target_core::notify([
        'userid'=>(int)$employee->id,'severity'=>'normal','eventtype'=>'audit_normal',
        'subject'=>'Synthetic normal','message'=>'Synthetic notification','idempotencykey'=>'target-core:notify:normal',
    ]);
    $ids['notification'] = [$normalid,$actionid];
    $counts = \local_ustar\communication::counts((int)$employee->id);
    $checks['notification_canonical_idempotent'] = $normalid === $normalagain && (int)$counts['notifications'] === 2;
    $checks['notification_outbox'] = $DB->count_records('local_ustar_notify_delivery', ['notificationid'=>$normalid]) === 1
        && $DB->count_records('local_ustar_notify_delivery', ['notificationid'=>$actionid]) === 2;
    \local_ustar\communication::mark_notification((int)$employee->id, $normalid);
    $checks['notification_read_state'] = (int)\local_ustar\communication::counts((int)$employee->id)['notifications'] === 1;

    $eventid = \local_ustar\target_core::append_evidence_event(
        $evidenceid, 'revoked', 'Synthetic revocation', (int)$manager->id
    );
    $checks['evidence_revocation_append_only'] = $eventid > 0 && !\local_ustar\target_core::evidence_is_valid($evidenceid)
        && $DB->record_exists('local_ustar_evidence_rec', ['id'=>$evidenceid]);
} finally {
    \core\session\manager::set_user($originaluser);
    if ($ids['notification']) {
        [$sql,$params] = $DB->get_in_or_equal($ids['notification'], SQL_PARAMS_NAMED, 'nid');
        $DB->delete_records_select('local_ustar_notify_delivery', "notificationid {$sql}", $params);
        $DB->delete_records_select('local_ustar_notifications', "id {$sql}", $params);
    }
    foreach (array_merge($ids['official'],$ids['personal']) as $entityid) {
        $DB->delete_records('local_ustar_workflow_events', ['entityid'=>$entityid]);
    }
    foreach ($ids['official'] as $id) $DB->delete_records('local_ustar_official_tasks', ['id'=>$id]);
    foreach ($ids['personal'] as $id) $DB->delete_records('local_ustar_personal_tasks', ['id'=>$id]);
    foreach ($ids['check'] as $id) $DB->delete_records('local_ustar_check_submits', ['id'=>$id]);
    foreach ($ids['decision'] as $id) $DB->delete_records('local_ustar_gate_decisions', ['id'=>$id]);
    foreach ($ids['evidence'] as $id) {
        $DB->delete_records('local_ustar_evidence_evt', ['evidenceid'=>$id]);
        $DB->delete_records('local_ustar_evidence_rec', ['id'=>$id]);
    }
    foreach ($ids['gate'] as $id) $DB->delete_records('local_ustar_gate_defs', ['id'=>$id]);
    $DB->delete_records('local_ustar_reporting', ['userid'=>(int)$employee->id]);
    if ($oldreporting) $DB->insert_record('local_ustar_reporting', $oldreporting, false);
    accesslib_clear_all_caches(true);
}

$final = [];
foreach ($tables as $table) $final[$table] = $DB->count_records($table);
$checks['baseline_restored'] = $baseline === $final;
$pass = !in_array(false, $checks, true);
echo json_encode(['checks'=>$checks,'baseline'=>$baseline,'final'=>$final,'pass'=>$pass], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($pass ? 0 : 1);
