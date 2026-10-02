<?php
// Synthetic stage only: preserve old rows through the index migration and exercise real HTTP POSTs.
if (getenv('USTAR_STAGE_PREFIX') !== 'stage_') { throw new RuntimeException('ISOLATED_PREFIX_REQUIRED'); }
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
$mode = $argv[1] ?? '';
$file = '/artifacts/adaptation-reset-upgrade-seed.json';
$table = new xmldb_table('local_ustar_adaptations');
$admin = get_admin();

if ($mode === 'seed' || $mode === 'http') {
    if ($mode === 'seed' && !$DB->get_manager()->index_exists($table,
            new xmldb_index('staffing_request_uix', XMLDB_INDEX_UNIQUE, ['staffingrequestid']))) {
        throw new RuntimeException('OLD_UNIQUE_INDEX_NOT_EXERCISED');
    }
    $userid = $mode === 'http' ? (int)$DB->get_field('user', 'id', ['username' => 'chatone'], MUST_EXIST) : (int)$admin->id;
    $requestid = $DB->insert_record('local_ustar_staff_requests', (object)[
        'requesttype' => 'hire', 'departmentid' => 'retail', 'positionid' => 'retail_seller',
        'requestedby' => $admin->id, 'createduserid' => $userid, 'status' => 'approved',
    ]);
    $id = $DB->insert_record('local_ustar_adaptations', (object)[
        'staffingrequestid' => $requestid, 'userid' => $userid, 'managerid' => $admin->id,
        'assignmentid' => 0, 'positionid' => 'retail_seller', 'createdby' => $admin->id,
        'startdate' => date('Y-m-d'), 'plannedworkdays' => 10,
        'status' => $mode === 'seed' ? 'cancelled' : 'active',
    ]);
    if ($mode === 'http') {
        file_put_contents('/artifacts/adaptation-reset-http.json', json_encode(['id' => $id]));
        echo $id;
        exit;
    }
    $eventid = $DB->insert_record('local_ustar_workflow_events', (object)[
        'entitytype' => 'adaptation', 'entityid' => $id, 'eventtype' => 'adaptation_final_report',
        'actorid' => $admin->id, 'detailsjson' => '{"round":1,"perspective":"employee","ready":"yes"}',
    ]);
    $submissionid = $DB->insert_record('local_ustar_check_submits', (object)[
        'adaptationid' => $id, 'checklistkey' => 'adaptation_standard', 'userid' => $userid,
        'perspective' => 'employee', 'workdate' => date('Y-m-d'), 'submittedby' => $admin->id,
        'answersjson' => '{"ready":"yes"}', 'issuesjson' => '{"items":[]}',
    ]);
    file_put_contents($file, json_encode(['id' => $id, 'requestid' => $requestid, 'eventid' => $eventid,
        'submissionid' => $submissionid, 'before' => $DB->get_record('local_ustar_adaptations', ['id' => $id])]));
    echo "ADAPTATION_OLD_UNIQUE_AND_HISTORY_SEEDED=OK\n";
} else if ($mode === 'verify') {
    $seed = json_decode(file_get_contents($file), true);
    if ((array)$DB->get_record('local_ustar_adaptations', ['id' => $seed['id']]) !== $seed['before']
            || !$DB->record_exists('local_ustar_workflow_events', ['id' => $seed['eventid']])
            || !$DB->record_exists('local_ustar_check_submits', ['id' => $seed['submissionid']])) {
        throw new RuntimeException('ADAPTATION_HISTORY_CHANGED_BY_UPGRADE');
    }
    if ($DB->get_manager()->index_exists($table, new xmldb_index('staffing_request_uix', XMLDB_INDEX_UNIQUE, ['staffingrequestid']))
            || !$DB->get_manager()->index_exists($table, new xmldb_index('staffing_request_idx', XMLDB_INDEX_NOTUNIQUE, ['staffingrequestid']))) {
        throw new RuntimeException('ADAPTATION_INDEX_MIGRATION_FAILED');
    }
    if (empty($seed['newid'])) {
        $new = (object)$seed['before'];
        unset($new->id);
        $new->status = 'active';
        $seed['newid'] = $DB->insert_record('local_ustar_adaptations', $new);
        file_put_contents($file, json_encode($seed));
    }
    if ($DB->count_records('local_ustar_adaptations', ['staffingrequestid' => $seed['requestid']]) !== 2) {
        throw new RuntimeException('MULTIPLE_HISTORY_CYCLES_NOT_PRESERVED');
    }
    echo "ADAPTATION_INDEX_UPGRADE_REPEAT_AND_HISTORY=OK\n";
} else if ($mode === 'status') {
    $data = json_decode(file_get_contents('/artifacts/adaptation-reset-http.json'), true);
    echo $DB->get_field('local_ustar_adaptations', 'status', ['id' => $data['id']]), '|',
        $DB->count_records('local_ustar_workflow_events', ['entitytype' => 'adaptation', 'entityid' => $data['id'],
            'eventtype' => 'adaptation_assignment_reset']);
} else {
    throw new RuntimeException('UNKNOWN_MODE');
}
