<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$structure =
    \local_ustar\structure::get(
        \local_ustar\structure::NAME_STRUCTURE
    );

$positionmap =
    \local_ustar\people::position_map(
        $structure
    );

$teamdata =
    \local_ustar\team_presenter::build(
        (int)$USER->id
    );

/*
 * Canonical candidate pool for "Кто есть кто?":
 * only people actually shown by route_team.php through team_presenter.
 * Self is rendered separately and is never a quiz subject.
 */
$people = [];

foreach (
    ['leaders', 'peers', 'reports', 'departmentpeople']
    as $bucket
) {
    foreach (($teamdata[$bucket] ?? []) as $person) {
        $id = (int)($person['id'] ?? 0);

        if (
            $id <= 1
            ||
            $id === (int)$USER->id
        ) {
            continue;
        }

        $pid = (string)($person['positionid'] ?? '');

        if (
            $pid === ''
            ||
            !isset($positionmap[$pid])
        ) {
            continue;
        }

        if (empty($person['position'])) {
            $person['position'] =
                (string)($positionmap[$pid]['name'] ?? '');
        }

        $people[$id] = $person;
    }
}

$people = array_values($people);

/*
 * Preserve the existing quiz selection policy:
 * prefer different positions first; if there are fewer than four
 * distinct positions, fill to the existing minimum of four questions.
 * Never select a person outside the visible presenter set.
 */
$selected = [];
$usedpositions = [];

foreach ($people as $person) {
    $pid = (string)$person['positionid'];

    if (!isset($usedpositions[$pid])) {
        $selected[] = $person;
        $usedpositions[$pid] = true;
    }

    if (count($selected) >= 6) {
        break;
    }
}

if (count($selected) < 4) {
    $selectedids = array_column(
        $selected,
        null,
        'id'
    );

    foreach ($people as $person) {
        $id = (int)$person['id'];

        if (isset($selectedids[$id])) {
            continue;
        }

        $selected[] = $person;
        $selectedids[$id] = $person;

        if (count($selected) >= 4) {
            break;
        }
    }
}

/*
 * Position option pool.
 * Real positions only.
 */
$optionmap = [];

foreach ($selected as $person) {
    $optionmap[$person['positionid']] =
        $person['position'];
}

/*
 * Add plausible distractors until we have enough choices.
 */
foreach ($positionmap as $pid => $position) {
    if (count($optionmap) >= 8) {
        break;
    }

    if (
        empty($position['name'])
    ) {
        continue;
    }

    $optionmap[(string)$pid] =
        (string)$position['name'];
}

$submitted =
    $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    require_sesskey();
}

// Answers are keyed by person ID; visual order never encodes hierarchy.
shuffle($selected);

$score = 0;
$questions = [];

foreach ($selected as $i => $person) {
    $name =
        'person_' . $person['id'];

    $answer =
        $submitted
        ? optional_param(
            $name,
            '',
            PARAM_ALPHANUMEXT
        )
        : '';

    $correct =
        $submitted
        &&
        $answer === $person['positionid'];

    if ($correct) {
        $score++;
    }

    $options = [];

    foreach ($optionmap as $pid => $label) {
        $options[] = [
            'id' => $pid,
            'name' => $label,
            'selected' =>
                $answer === $pid,
        ];
    }

    /*
     * Stable visual shuffle per person/user.
     */
    usort(
        $options,
        static function(
            array $a,
            array $b
        ) use ($person, $USER): int {
            return strcmp(
                sha1(
                    $USER->id .
                    ':' .
                    $person['id'] .
                    ':' .
                    $a['id']
                ),
                sha1(
                    $USER->id .
                    ':' .
                    $person['id'] .
                    ':' .
                    $b['id']
                )
            );
        }
    );

    $questions[] = [
        'number' => $i + 1,
        'fullname' =>
            $person['fullname'],
        'avatarurl' =>
            $person['avatarurl'],
        'initials' =>
            $person['initials'],
        'input' => $name,
        'options' => $options,
        'correct' => $correct,
        'incorrect' =>
            $submitted && !$correct,
    ];
}

$total = count($questions);

$percent =
    $total > 0
    ? (int)round(
        ($score / $total) * 100
    )
    : 0;

$passed =
    $submitted
    &&
    $total > 0
    &&
    $percent >= 80;

$recorded = false;
$previewmode = false;

if ($passed) {
    $eventid =
        \local_ustar\native_learning::record(
            (int)$USER->id,
            \local_ustar\native_learning::TEAM_STRUCTURE,
            [
                'score' => $score,
                'total' => $total,
                'percent' => $percent,
                'threshold' => 80,
            ]
        );

    $recorded = $eventid > 0;
    $previewmode = !$recorded;
}

$data = [
    'questions' => $questions,
    'hasquestions' => !empty($questions),

    'submitted' => $submitted,
    'score' => $score,
    'total' => $total,
    'percent' => $percent,

    'passed' => $passed,
    'notpassed' =>
        $submitted && !$passed,

    'recorded' => $recorded,
    'previewmode' => $previewmode,

    'sesskey' => sesskey(),

    'studyurl' =>
        (
            new moodle_url(
                '/local/ustar/route_team.php'
            )
        )->out(false),

    'nextpointurl' => (new moodle_url('/local/ustar/route_next.php', ['sesskey' => sesskey()]))->out(false),
    'routeurl' =>
        (
            new moodle_url(
                '/local/ustar/route.php'
            )
        )->out(false),
];

$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url(
        '/local/ustar/route_team_quiz.php'
    )
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Кто есть кто? | USTAR'
);
$PAGE->set_heading('USTAR Academy');

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/team_hierarchy.css'
    )
);

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/route_native.css'
    )
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/team_quiz',
    $data
);

echo $output->footer();

