<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $DB;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$key =
    \local_ustar\development_assessment::TEAM_PROFILE_KEY;

if (
    !\local_ustar\development_assessment::can_view_private_result(
        (int)$USER->id,
        (int)$USER->id
    )
) {
    throw new \required_capability_exception(
        $context,
        'local/ustar:use',
        'nopermissions',
        ''
    );
}

$result =
    \local_ustar\development_assessment::latest_for_user(
        $key,
        (int)$USER->id
    );

$definition =
    \local_ustar\development_assessment::published(
        $key
    );


// A published reveal belongs to the employee's position route, not to
// the historical retail point 63. An unpublished activity remains a preview.
$availability = \local_ustar\native_learning::availability(
    (int)$USER->id, \local_ustar\native_learning::TEAM_PROFILE_REVEAL
);
$reachable = empty($availability['configured']) || !empty($availability['reachable']);


$submitted =
    $_SERVER['REQUEST_METHOD'] === 'POST';

$recorded = false;
$previewmode = false;

if ($submitted) {
    require_sesskey();
    \local_ustar\view_as::assert_writable();

    if (!$reachable) {
        throw new \moodle_exception(
            'Сначала завершите предыдущие шаги маршрута.'
        );
    }

    if (!$result) {
        throw new \moodle_exception(
            'Сначала завершите экспресс-профиль командного взаимодействия.'
        );
    }

    $confirm =
        optional_param(
            'confirmreveal',
            0,
            PARAM_BOOL
        );

    if ($confirm) {
        \local_ustar\route_continue::assert_native_reachable((int)$USER->id, \local_ustar\native_learning::TEAM_PROFILE_REVEAL);
        $eventid =
            \local_ustar\native_learning::record(
                (int)$USER->id,
                \local_ustar\native_learning::TEAM_PROFILE_REVEAL,
                [
                    'assessmentkey' => $key,
                    'attemptid' =>
                        (int)($result['attemptid'] ?? 0),
                    'submittedat' =>
                        (int)($result['submittedat'] ?? 0),
                    'primary' =>
                        (string)(
                            $result['primary']['key']
                            ?? ''
                        ),
                    'secondary' =>
                        (string)(
                            $result['secondary']['key']
                            ?? ''
                        ),
                ]
            );

        if ($eventid > 0) {
            redirect(\local_ustar\route_continue::next_url((int)$USER->id, '/local/ustar/route_profile_reveal.php'));
        }
        $recorded = $eventid > 0;
        $previewmode = !$recorded;
    }
}


/*
 * Build score presentation from the published result definitions.
 */
$scoreitems = [];

if ($result && $definition) {
    $scores =
        is_array($result['scores'] ?? null)
        ? $result['scores']
        : [];

    $profiles =
        is_array($definition['results'] ?? null)
        ? $definition['results']
        : [];

    $totalanswers =
        max(
            1,
            array_sum(
                array_map(
                    'intval',
                    $scores
                )
            )
        );

    foreach ($scores as $profilekey => $score) {
        $score = (int)$score;

        $scoreitems[] = [
            'key' => (string)$profilekey,
            'title' =>
                (string)(
                    $profiles[$profilekey]['title']
                    ?? $profilekey
                ),
            'score' => $score,
            'percent' =>
                (int)round(
                    ($score / $totalanswers) * 100
                ),
        ];
    }

    usort(
        $scoreitems,
        static fn(array $a, array $b): int =>
            $b['score'] <=> $a['score']
    );
}


$data = [
    'reachable' => $reachable,
    'notreachable' => !$reachable,

    'hasresult' => !empty($result),
    'noresult' => empty($result),

    'primarytitle' =>
        (string)(
            $result['primary']['title']
            ?? ''
        ),

    'primarysummary' =>
        (string)(
            $result['primary']['summary']
            ?? ''
        ),

    'secondarytitle' =>
        (string)(
            $result['secondary']['title']
            ?? ''
        ),

    'recommendation' =>
        (string)(
            $result['recommendation']
            ?? ''
        ),

    'disclaimer' =>
        (string)(
            $result['disclaimer']
            ?? ''
        ),

    'submittedlabel' =>
        !empty($result['submittedat'])
        ? userdate(
            (int)$result['submittedat'],
            '%d.%m.%Y'
        )
        : '',

    'scoreitems' => $scoreitems,
    'hasscores' => !empty($scoreitems),

    'recorded' => $recorded,
    'previewmode' => $previewmode,

    'sesskey' => sesskey(),

    'assessmenturl' =>
        (
            new moodle_url(
                '/local/ustar/development_assessment.php',
                [
                    'assessment' => $key,
                    'fromroute' => 1,
                ]
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
        '/local/ustar/route_profile_reveal.php'
    )
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Познай себя | USTAR'
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
    'local_ustar/profile_reveal',
    $data
);

echo $output->footer();
