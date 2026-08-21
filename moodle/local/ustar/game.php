<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$gameid = required_param('gameid', PARAM_INT);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $questionid = required_param('questionid', PARAM_INT);
    $option = required_param('option', PARAM_INT);
    $result = \local_ustar\native_data::submit_game_answer($questionid, $option);
}

$payload = \local_ustar\native_data::game_question($gameid);
$question = $payload['question'] ?? null;

if ($question) {
    $options = [];
    foreach (array_values($question['options'] ?? []) as $index => $label) {
        $options[] = ['index' => $index, 'label' => (string)$label];
    }
    $question['options'] = $options;
}

$data = [
    'gameid' => $gameid,
    'question' => $question,
    'hasquestion' => $question !== null,
    'result' => $result,
    'hasresult' => $result !== null,
    'resultcorrect' => $result !== null && !empty($result['correct']),
    'resultwrong' => $result !== null && empty($result['correct']),
    'sesskey' => sesskey(),
    'backurl' => (new moodle_url('/local/ustar/games.php'))->out(false),
    'gameicon' => \local_ustar\ui::icon('game', 'u-feature-icon'),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/game.php', ['gameid' => $gameid]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(($question['gameTitle'] ?? 'Игровые задания') . ' | USTAR');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/game', $data);
echo $output->footer();
