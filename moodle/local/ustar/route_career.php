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

    $confirm =
        optional_param(
            'confirmrole',
            0,
            PARAM_BOOL
        );

    if ($confirm) {
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

        $recorded = $eventid > 0;
        $previewmode = !$recorded;
    }
}

$data = [
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
