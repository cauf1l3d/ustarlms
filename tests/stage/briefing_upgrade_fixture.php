<?php
// Check that the existing briefing deployment keeps its own records through the follow-up upgrade.
define('CLI_SCRIPT', true);
require('/stage/moodle/config.php');
if ($CFG->wwwroot !== 'http://ustar-stage1.invalid' || $CFG->prefix !== 'botold_' || $CFG->dbname !== 'ustar_stage1') {
    throw new RuntimeException('Expected the dedicated briefing upgrade fixture.');
}

$records = [
    'local_ustar_brief_templates' => ['departmentid' => 'retail', 'title' => 'Existing template',
        'schemajson' => '[{"label":"Revenue","type":"number","required":true}]', 'sortorder' => 4, 'active' => 1, 'revision' => 2],
    'local_ustar_brief_reports' => ['departmentid' => 'retail', 'weekkey' => '2026-11-16', 'userid' => 2, 'revision' => 3,
        'schemajson' => '{"title":"Existing template","fields":[{"label":"Revenue","type":"number","required":true}]}',
        'answersjson' => '["42"]', 'submittedat' => 1794810000, 'timemodified' => 1794810120],
    'local_ustar_brief_minutes' => ['weekkey' => '2026-11-16', 'userid' => 2, 'content' => 'Existing minutes',
        'revision' => 2, 'timemodified' => 1794813600],
    'local_ustar_meeting_rooms' => ['name' => 'Existing room', 'capacity' => 4, 'active' => 1, 'revision' => 2],
    'local_ustar_meetings' => ['roomid' => 1, 'ownerid' => 2, 'requestkey' => 'briefingupgradefixture1234',
        'title' => 'Existing meeting', 'agenda' => 'Existing agenda', 'startat' => 1794813600, 'endat' => 1794817200,
        'cancelled' => 0, 'revision' => 2],
    'local_ustar_meeting_members' => ['meetingid' => 1, 'userid' => 3, 'rsvp' => 'accepted'],
];
$mode = $argv[1] ?? '';
$expected = ['seed' => 2026111700, 'verify' => 2026111800];
if (!isset($expected[$mode]) || (int)get_config('local_ustar', 'version') !== $expected[$mode]) {
    throw new RuntimeException('Unexpected baseline or upgraded plugin version.');
}
foreach ($records as $table => $expectedrow) {
    if ($mode === 'seed') {
        if ((int)$DB->insert_record($table, (object)$expectedrow) !== 1) {
            throw new RuntimeException('Unexpected fixture identity for ' . $table);
        }
    } else {
        $actual = $DB->get_record($table, ['id' => 1], '*', MUST_EXIST);
        foreach ($expectedrow as $field => $value) {
            if ((string)$actual->$field !== (string)$value) {
                throw new RuntimeException('Existing briefing data changed in ' . $table . '.' . $field);
            }
        }
    }
}
if ($mode === 'verify') {
    $report = $DB->get_record('local_ustar_brief_reports', ['id' => 1], '*', MUST_EXIST);
    $status = \local_ustar\briefing\service::report_status(['report' => $report, 'submittedat' => (int)$report->submittedat]);
    if ($status !== 'Сдан') {
        throw new RuntimeException('Legacy report without a deadline must remain readable.');
    }
    echo "BRIEFING_BASELINE_DATA=OK\n";
}
