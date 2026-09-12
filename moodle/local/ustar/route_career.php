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

$profile =
    \local_ustar\employee_profile::build(
        (int)$USER->id
    );

$identity = $profile['identity'];

$positionmap =
    \local_ustar\people::position_map(
        $structure
    );

$departmentmap =
    \local_ustar\people::department_map(
        $structure
    );

$currentid =
    (string)$identity['positionid'];

$current =
    $positionmap[$currentid]
    ?? null;

$previous = null;
$next = null;

if ($current) {
    foreach (($structure['positions'] ?? []) as $position) {
        if (
            (string)($position['next'] ?? '')
            === $currentid
        ) {
            $previous = $position;
            break;
        }
    }

    $nextid =
        trim(
            (string)(
                $current['next']
                ?? ''
            )
        );

    if (
        $nextid !== ''
        &&
        isset($positionmap[$nextid])
    ) {
        $next = $positionmap[$nextid];
    }
}

$levelnames = [
    1 => 'Ознакомительный',
    2 => 'Базовый',
    3 => 'Самостоятельный',
    4 => 'Продвинутый',
    5 => 'Эксперт',
];

$careerlearning = \local_ustar\career_learning::build($currentid, (int)$USER->id);
$skillrows = [];

foreach (($profile['skills']['items'] ?? []) as $skill) {
    $sources = [];

    foreach (($skill['sources'] ?? []) as $source) {
        $sources[] = [
            'label' =>
                (string)$source['label'],
            'satisfied' =>
                !empty($source['satisfied']),
        ];
    }

    foreach ($careerlearning['skills'][(string)($skill['skillid'] ?? '')] ?? [] as $source) {
        $sources[] = $source;
    }

    if (($skill['skillid'] ?? '') === 'product_know' && !empty($careerlearning['skills']['product_know'])) {
        // Display the employee's route material; achievement still comes from Evidence.
        $sources = $careerlearning['skills']['product_know'];
    }

    $targetlevel =
        (int)$skill['targetlevel'];

    $skillrows[] = [
        'name' => (string)$skill['name'],
        'category' =>
            (string)($skill['category'] ?? ''),

        'targetlevel' => $targetlevel,

        'targetlabel' =>
            $levelnames[$targetlevel]
            ?? ('Уровень ' . $targetlevel),

        'satisfied' =>
            !empty($skill['satisfied']),

        'gap' =>
            !empty($skill['gap']),

        'statuslabel' =>
            !empty($skill['satisfied'])
            ? 'Подтверждено'
            : 'Нужно развить',

        'sources' => $sources,
        'hassources' => !empty($sources),
    ];
}

$nextskills = [];

if ($next) {
    foreach (
        (
            $structure['matrix'][
                (string)$next['id']
            ] ?? []
        )
        as $skillid => $level
    ) {
        $skillname = $skillid;

        foreach (($structure['skills'] ?? []) as $skill) {
            if (
                (string)$skill['id']
                === (string)$skillid
            ) {
                $skillname =
                    (string)$skill['name'];
                break;
            }
        }

        $nextskills[] = [
            'name' => $skillname,
            'level' => (int)$level,
            'label' =>
                $levelnames[(int)$level]
                ?? ('Уровень ' . (int)$level),
        ];
    }
}

$submitted =
    $_SERVER['REQUEST_METHOD'] === 'POST';

$recorded = false;
$previewmode = false;

if ($submitted) {
    require_sesskey();
    \local_ustar\view_as::assert_writable();

    $confirm =
        optional_param(
            'confirmrole',
            0,
            PARAM_BOOL
        );

    if ($confirm) {
        \local_ustar\route_continue::assert_native_reachable((int)$USER->id, \local_ustar\native_learning::ROLE_DEVELOPMENT);
        $eventid =
            \local_ustar\native_learning::record(
                (int)$USER->id,
                \local_ustar\native_learning::ROLE_DEVELOPMENT,
                [
                    'positionid' => $currentid,
                    'requiredskills' =>
                        (int)$profile['skills']['required'],
                    'confirmedskills' =>
                        (int)$profile['skills']['confirmed'],
                ]
            );

        if ($eventid > 0) {
            redirect(\local_ustar\route_continue::next_url((int)$USER->id, '/local/ustar/route_career.php'));
        }
        $recorded = $eventid > 0;
        $previewmode = !$recorded;
    }
}

$data = [
    'consultantcurrent' => \local_ustar\consultant_career::is_consultant((string)($current['name'] ?? '')),
    'retailgrades' => \local_ustar\career_learning::retail((string)$identity['department'], (string)($current['name'] ?? '')),
    'learningpoints' => $careerlearning['points'],
    'haslearningpoints' => !empty($careerlearning['points']),
    'fullname' =>
        (string)$identity['fullname'],

    'department' =>
        (string)$identity['department'],

    'hasposition' =>
        $current !== null,

    'currentname' =>
        $current
        ? (string)$current['name']
        : 'Должность пока не назначена',

    'currentlevel' =>
        $current
        ? (int)($current['level'] ?? 0)
        : 0,

    'hasprevious' =>
        $previous !== null,

    'previousname' =>
        $previous
        ? (string)$previous['name']
        : '',

    'hasnext' =>
        $next !== null,

    'nextname' =>
        $next
        ? (string)$next['name']
        : '',

    'nextapproved' =>
        $next !== null,

    'nextpending' =>
        $current !== null
        &&
        $next === null,

    'readiness' =>
        (int)$profile['readiness']['percent'],

    'confirmedskills' =>
        (int)$profile['skills']['confirmed'],

    'requiredskills' =>
        (int)$profile['skills']['required'],

    'skillgaps' =>
        (int)$profile['skills']['gaps'],

    'submitted' => $submitted,
    'recorded' => $recorded,
    'previewmode' => $previewmode,
    'sesskey' => sesskey(),

    'skills' => $skillrows,
    'hasskills' => !empty($skillrows),

    'nextskills' => $nextskills,
    'hasnextskills' => !empty($nextskills),

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
        '/local/ustar/route_career.php'
    )
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Моя должность и развитие | USTAR'
);
$PAGE->set_heading('USTAR Academy');

$PAGE->requires->css(new moodle_url('/local/ustar/styles/consultant_career.css', ['v' => '20260911-2']));

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/route_native.css'
    )
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/route_career',
    $data
);

echo $output->footer();

