<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER, $SESSION, $DB;

$context = context_system::instance();
require_capability('local/ustar:use', $context);
$assessmentkey = optional_param('assessment', \local_ustar\development_assessment::TEAM_PROFILE_KEY, PARAM_ALPHANUMEXT);
$definition = \local_ustar\development_assessment::published($assessmentkey);
if (!$definition) {
    throw new moodle_exception('Развивающий профиль не найден или пока не опубликован.');
}

$subjectid = optional_param('userid', (int)$USER->id, PARAM_INT);
if (!\local_ustar\development_assessment::can_view_private_result((int)$USER->id, $subjectid)) {
    throw new required_capability_exception($context, 'local/ustar:developmentanalytics', 'nopermissions', '');
}
$isself = $subjectid === (int)$USER->id;
$subject = $DB->get_record('user', ['id' => $subjectid, 'deleted' => 0], 'id,firstname,lastname', MUST_EXIST);
$sessionkey = 'ustar_dev_assessment_' . $assessmentkey . '_nonce';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$isself) {
        throw new required_capability_exception($context, 'local/ustar:developmentanalytics', 'nopermissions', '');
    }
    $nonce = required_param('submissionkey', PARAM_ALPHANUMEXT);
    if (empty($SESSION->{$sessionkey}) || !hash_equals((string)$SESSION->{$sessionkey}, $nonce)) {
        throw new invalid_parameter_exception('Форма уже была отправлена или устарела. Обновите страницу и повторите попытку.');
    }
    $answers = optional_param_array('answer', [], PARAM_ALPHANUMEXT);
    \local_ustar\development_assessment::submit($assessmentkey, (int)$USER->id, $answers, $nonce, time());
    unset($SESSION->{$sessionkey});
    redirect(new moodle_url('/local/ustar/development_assessment.php', ['assessment' => $assessmentkey, 'saved' => 1]));
}

$retry = $isself && optional_param('retry', 0, PARAM_BOOL);
$result = $retry ? null : \local_ustar\development_assessment::latest_for_user($assessmentkey, $subjectid);
if ($isself && empty($SESSION->{$sessionkey})) {
    $SESSION->{$sessionkey} = bin2hex(random_bytes(24));
}
$questions = [];
foreach ($definition['questions'] as $number => $question) {
    $options = [];
    foreach (($question['options'] ?? []) as $option) {
        $options[] = [
            'key' => (string)$option['key'],
            'text' => (string)$option['text'],
            'questionkey' => (string)$question['key'],
        ];
    }
    $questions[] = ['number' => $number + 1, 'key' => (string)$question['key'], 'text' => (string)$question['text'], 'options' => $options];
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/development_assessment.php', ['assessment' => $assessmentkey, 'userid' => $isself ? null : $subjectid]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title((string)$definition['assessment']->title . ' | USTAR');
$PAGE->set_heading('Центр развития USTAR');
$data = [
    'title' => format_string((string)$definition['assessment']->title),
    'summary' => format_text((string)$definition['assessment']->summary, FORMAT_PLAIN),
    'intro' => format_text((string)$definition['version']->intro, FORMAT_PLAIN),
    'isself' => $isself,
    'subjectname' => fullname($subject),
    'saved' => optional_param('saved', 0, PARAM_BOOL),
    'hasresult' => !empty($result),
    'result' => $result ? [
        'primarytitle' => (string)($result['primary']['title'] ?? ''),
        'primarysummary' => (string)($result['primary']['summary'] ?? ''),
        'secondarytitle' => (string)($result['secondary']['title'] ?? ''),
        'recommendation' => (string)($result['recommendation'] ?? ''),
        'disclaimer' => (string)($result['disclaimer'] ?? ''),
        'submitteddate' => userdate((int)($result['submittedat'] ?? 0), get_string('strftimedatetimeshort', 'langconfig')),
    ] : null,
    'canstart' => $isself,
    'questions' => $questions,
    'sesskey' => sesskey(),
    'submissionkey' => $isself ? (string)$SESSION->{$sessionkey} : '',
    'retryurl' => (new moodle_url('/local/ustar/development_assessment.php', ['assessment' => $assessmentkey, 'retry' => 1]))->out(false),
    'homeurl' => (new moodle_url('/local/ustar/home.php', ['view' => 'career']))->out(false),
    'analyticsurl' => (new moodle_url('/local/ustar/development_assessments.php'))->out(false),
    'canviewanalytics' => has_capability('local/ustar:developmentanalytics', $context) || is_siteadmin(),
];
$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/development_assessment', $data);
echo $output->footer();
