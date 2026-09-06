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


/*
 * When point 63 is published, direct URL must not allow
 * premature disclosure before the sequential route reaches it.
 *
 * While point 63 is still draft we allow direct preview.
 */
$point63published =
    $DB->record_exists(
        'local_ustar_route_versions',
        [
            'pointid' => 63,
            'status' =>
                \local_ustar\route_model::STATUS_PUBLISHED,
        ]
    );

$reachable = true;

if ($point63published) {
    $positionid =
        \local_ustar\people::position_id(
            (int)$USER->id
        );

    $snapshot =
        \local_ustar\route_model::read_only_snapshot(
            $positionid,
            (int)$USER->id
        );

    $currentid =
        (int)(
            $snapshot['currentpoint']['id']
            ?? 0
        );

    $point63done = false;

    foreach (($snapshot['points'] ?? []) as $point) {
        if (
            (int)$point['id'] === 63
            &&
            !empty($point['done'])
        ) {
            $point63done = true;
            break;
        }
    }

    $reachable =
        $currentid === 63
        ||
        $point63done;
}


$submitted =
    $_SERVER['REQUEST_METHOD'] === 'POST';

$recorded = false;
$previewmode = false;

if ($submitted) {
    require_sesskey();

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
