<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER, $DB;
$context = context_system::instance();
require_capability('local/ustar:developmentanalytics', $context);

$assessmentkey = optional_param('assessment', \local_ustar\development_assessment::TEAM_PROFILE_KEY, PARAM_ALPHANUMEXT);
$definition = \local_ustar\development_assessment::published($assessmentkey);
if (!$definition) {
    throw new moodle_exception('Развивающий профиль не найден или пока не опубликован.');
}

$people = [];
$seen = [];
$attempts = $DB->get_records_select(
    'local_ustar_dev_assess_try',
    'assessmentid = :assessmentid AND status = :status',
    ['assessmentid' => (int)$definition['assessment']->id, 'status' => 'submitted'],
    'submittedat DESC, id DESC'
);
foreach ($attempts as $attempt) {
    if (isset($seen[(int)$attempt->userid])) {
        continue;
    }
    $person = $DB->get_record('user', ['id' => (int)$attempt->userid, 'deleted' => 0], 'id,firstname,lastname', IGNORE_MISSING);
    if (!$person || !\local_ustar\accounts::participates((int)$person->id)) {
        continue;
    }
    $result = json_decode((string)$attempt->resultjson, true);
    if (!is_array($result)) {
        continue;
    }
    $seen[(int)$attempt->userid] = true;
    $people[] = [
        'name' => fullname($person),
        'profile' => (string)($result['primary']['title'] ?? 'Не определён'),
        'recommendation' => (string)($result['recommendation'] ?? ''),
        'date' => userdate((int)$attempt->submittedat, get_string('strftimedatetimeshort', 'langconfig')),
        'url' => (new moodle_url('/local/ustar/development_assessment.php', ['assessment' => $assessmentkey, 'userid' => (int)$person->id]))->out(false),
    ];
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/development_assessments.php', ['assessment' => $assessmentkey]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Развивающие профили | USTAR');
$PAGE->set_heading('Центр развития USTAR');
$data = [
    'title' => format_string((string)$definition['assessment']->title),
    'count' => count($people),
    'people' => $people,
    'haspeople' => !empty($people),
    'homeurl' => (new moodle_url('/local/ustar/home.php', ['view' => 'career']))->out(false),
];
$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/development_assessments', $data);
echo $output->footer();
