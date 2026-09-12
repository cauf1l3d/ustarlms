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

$positionid =
    \local_ustar\people::position_id(
        (int)$USER->id
    );

$position =
    $positionmap[$positionid]
    ?? null;

$matrix =
    $structure['matrix'][$positionid]
    ?? [];

$skillmap = [];

foreach (($structure['skills'] ?? []) as $skill) {
    $skillmap[(string)$skill['id']] = $skill;
}

$levelnames = [
    1 => 'Ознакомительный',
    2 => 'Базовый',
    3 => 'Самостоятельный',
    4 => 'Продвинутый',
    5 => 'Эксперт',
];

$submitted =
    $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    require_sesskey();
}

$selectedskills =
    $submitted
    ? optional_param_array(
        'skills',
        [],
        PARAM_ALPHANUMEXT
    )
    : [];

$selectedskills =
    array_values(
        array_unique(
            array_map(
                'strval',
                $selectedskills
            )
        )
    );

sort($selectedskills);

$requiredids =
    array_map(
        'strval',
        array_keys($matrix)
    );

sort($requiredids);


/*
 * Question 1:
 * Select ALL skills that belong to this exact position.
 */
$skillchoices = [];

foreach ($requiredids as $skillid) {
    $skill = $skillmap[$skillid] ?? [];

    $skillchoices[$skillid] = [
        'id' => $skillid,
        'name' =>
            (string)(
                $skill['name']
                ?? $skillid
            ),
    ];
}

/*
 * Add real skill distractors from the company catalogue.
 */
foreach ($skillmap as $skillid => $skill) {
    if (
        isset($skillchoices[$skillid])
        ||
        count($skillchoices)
            >= count($requiredids) + 4
    ) {
        continue;
    }

    $skillchoices[$skillid] = [
        'id' => $skillid,
        'name' =>
            (string)(
                $skill['name']
                ?? $skillid
            ),
    ];
}

$skillchoices =
    array_values($skillchoices);

/*
 * Stable shuffle, different per user but not jumping
 * around after each form submission.
 */
usort(
    $skillchoices,
    static function(
        array $a,
        array $b
    ) use ($USER): int {
        return strcmp(
            sha1(
                $USER->id .
                ':skills:' .
                $a['id']
            ),
            sha1(
                $USER->id .
                ':skills:' .
                $b['id']
            )
        );
    }
);

foreach ($skillchoices as &$choice) {
    $choice['selected'] =
        in_array(
            $choice['id'],
            $selectedskills,
            true
        );
}
unset($choice);


$setcorrect =
    $submitted
    &&
    $selectedskills === $requiredids;


/*
 * Question 2..N:
 * Required level for every skill in the LIVE matrix.
 */
$levelquestions = [];
$levelcorrectcount = 0;

foreach ($matrix as $skillid => $targetlevel) {
    $skillid = (string)$skillid;
    $targetlevel = (int)$targetlevel;

    $input =
        'level_' . $skillid;

    $answer =
        $submitted
        ? optional_param(
            $input,
            0,
            PARAM_INT
        )
        : 0;

    $correct =
        $submitted
        &&
        $answer === $targetlevel;

    if ($correct) {
        $levelcorrectcount++;
    }

    $options = [];

    foreach ($levelnames as $level => $label) {
        $options[] = [
            'value' => $level,
            'label' =>
                'Уровень ' .
                $level .
                ' · ' .
                $label,
            'selected' =>
                $answer === $level,
        ];
    }

    $skill =
        $skillmap[$skillid]
        ?? [];

    $levelquestions[] = [
        'skillid' => $skillid,
        'name' =>
            (string)(
                $skill['name']
                ?? $skillid
            ),
        'category' =>
            (string)(
                $skill['category']
                ?? ''
            ),
        'input' => $input,
        'options' => $options,
        'correct' => $correct,
        'incorrect' =>
            $submitted && !$correct,
    ];
}


$total =
    !empty($matrix)
    ? 1 + count($matrix)
    : 0;

$score =
    $submitted
    ? (
        ($setcorrect ? 1 : 0)
        +
        $levelcorrectcount
    )
    : 0;

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
    $matrixhash =
        sha1(
            json_encode(
                $matrix,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );

    $eventid =
        \local_ustar\native_learning::record(
            (int)$USER->id,
            \local_ustar\native_learning::ROLE_SKILLS_CHECK,
            [
                'positionid' => $positionid,
                'matrixhash' => $matrixhash,
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
    'hasposition' =>
        $position !== null,

    'positionname' =>
        $position
        ? (string)$position['name']
        : '',

    'hasmatrix' =>
        !empty($matrix),

    'skillchoices' =>
        $skillchoices,

    'levelquestions' =>
        $levelquestions,

    'submitted' =>
        $submitted,

    'setcorrect' =>
        $setcorrect,

    'setincorrect' =>
        $submitted && !$setcorrect,

    'score' => $score,
    'total' => $total,
    'percent' => $percent,

    'passed' => $passed,
    'notpassed' =>
        $submitted && !$passed,

    'recorded' =>
        $recorded,

    'previewmode' =>
        $previewmode,

    'sesskey' =>
        sesskey(),

    'careerurl' =>
        (
            new moodle_url(
                '/local/ustar/route_career.php'
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
        '/local/ustar/route_role_quiz.php'
    )
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Моя должность и навыки | USTAR'
);
$PAGE->set_heading('USTAR Academy');

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/route_native.css'
    )
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/role_quiz',
    $data
);

echo $output->footer();

