<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;

$context = context_system::instance();
require_capability('local/ustar:hrmanage', $context);

$attempts = \local_ustar\route_quiz_grading::attempts();
$assessmentescalations = \local_ustar\assessment_lifecycle::hrd_alerts((int)$USER->id);

/*
 * Queue semantics: one visible row is one employee + one logical assessment
 * version. Historical Moodle attempts are kept inside that row instead of
 * multiplying the operational queue and its counters.
 */
$grouped = [];
foreach ($attempts as $row) {
    $key = (int)$row['userid'] . ':' . (int)$row['pointid'] . ':' . (int)$row['versionid'];

    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'userid' => (int)$row['userid'],
            'pointid' => (int)$row['pointid'],
            'versionid' => (int)$row['versionid'],
            'employee' => (string)$row['employee'],
            'position' => (string)$row['position'],
            'quizname' => (string)$row['quizname'],
            'attemptno' => (int)$row['attemptno'],
            'maxattempts' => (int)$row['maxattempts'],
            'grade' => (string)$row['grade'],
            'maxgrade' => (string)$row['maxgrade'],
            'autograde' => (string)$row['autograde'],
            'automax' => (string)$row['automax'],
            'pending' => (int)$row['pending'],
            'manualcount' => (int)$row['manualcount'],
            'status' => (string)$row['status'],
            'statuskey' => (string)$row['statuskey'],
            'ispending' => !empty($row['ispending']),
            'ispassed' => !empty($row['ispassed']),
            'isfailed' => !empty($row['isfailed']),
            'finished' => (string)$row['finished'],
            'url' => (string)$row['url'],
            'attemptcount' => 0,
            'historycount' => 0,
            'history' => [],
            'bestgraderaw' => -INF,
            'bestgrade' => '—',
        ];
    }

    $gradevalue = (float)str_replace(',', '.', (string)$row['grade']);
    if ($gradevalue > $grouped[$key]['bestgraderaw']) {
        $grouped[$key]['bestgraderaw'] = $gradevalue;
        $grouped[$key]['bestgrade'] = number_format($gradevalue, 1, ',', '');
    }

    $grouped[$key]['attemptcount']++;
    $grouped[$key]['history'][] = [
        'attemptno' => (int)$row['attemptno'],
        'maxattempts' => (int)$row['maxattempts'],
        'grade' => (string)$row['grade'],
        'maxgrade' => (string)$row['maxgrade'],
        'status' => (string)$row['status'],
        'statuskey' => (string)$row['statuskey'],
        'finished' => (string)$row['finished'],
        'url' => (string)$row['url'],
    ];
}

$rows = [];
$pending = 0;
$passed = 0;
$failed = 0;

foreach ($grouped as $group) {
    unset($group['bestgraderaw']);
    $group['historycount'] = count($group['history']);
    $group['hasmultiple'] = $group['historycount'] > 1;

    switch ($group['statuskey']) {
        case 'pending':
            $pending++;
            break;
        case 'passed':
            $passed++;
            break;
        case 'failed':
            $failed++;
            break;
    }

    $rows[] = $group;
}

$today = usergetmidnight(time());

/* One reviewed attempt counts once, even when it contained five essay slots. */
$checkedtoday = (int)$DB->get_field_sql(
    "SELECT COUNT(DISTINCT entityid)
       FROM {local_ustar_workflow_events}
      WHERE entitytype = :entitytype
        AND eventtype = :eventtype
        AND timecreated >= :today",
    [
        'entitytype' => 'quiz_manual_grade',
        'eventtype' => 'route_quiz_manual_grade',
        'today' => $today,
    ]
);

$data = [
    'assessments' => $rows,
    'hasassessments' => !empty($rows),
    'pending' => $pending,
    'passed' => $passed,
    'failed' => $failed,
    'checkedtoday' => $checkedtoday,
    'hasassessmentescalations' => !empty($assessmentescalations['alerts']),
    'assessmentescalationcount' => (int)($assessmentescalations['count'] ?? 0),
    'assessmentescalations' => $assessmentescalations['alerts'] ?? [],
    'sesskey' => sesskey(),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/hr_quiz_grading.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Проверка аттестаций | USTAR');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(
    new moodle_url('/local/ustar/styles/route_native.css')
);

$output = $PAGE->get_renderer('local_ustar');

echo $output->header();
echo $output->render_from_template(
    'local_ustar/hr_quiz_grading',
    $data
);
echo $output->footer();
