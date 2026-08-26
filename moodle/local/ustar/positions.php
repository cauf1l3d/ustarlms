<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;

$context =
    context_system::instance();

require_capability(
    'local/ustar:hr',
    $context
);

$canmanage =
    has_capability(
        'local/ustar:hrmanage',
        $context
    );


$structure =
    \local_ustar\structure::get(
        \local_ustar\structure::NAME_STRUCTURE
    );


$positions =
    array_values(
        $structure['positions']
        ?? []
    );

$skills =
    array_values(
        $structure['skills']
        ?? []
    );


$departmentmap = [];

foreach (
    $structure['departments'] ?? []
    as $department
) {
    $departmentmap[
        $department['id']
    ] = $department;
}


$positionid =
    optional_param(
        'positionid',
        '',
        PARAM_ALPHANUMEXT
    );

if (
    $positionid === ''
    &&
    !empty($positions)
) {
    $positionid =
        $positions[0]['id'];
}


$positionmap = [];

foreach ($positions as $position) {
    $positionmap[
        $position['id']
    ] = $position;
}


if (
    !isset(
        $positionmap[$positionid]
    )
) {
    throw new \invalid_parameter_exception(
        'Неизвестная должность'
    );
}


$currentposition =
    $positionmap[$positionid];


/*
 * ------------------------------------------------------------
 * WRITE ACTIONS
 * ------------------------------------------------------------
 */

$action =
    optional_param(
        'action',
        '',
        PARAM_ALPHANUMEXT
    );


if (
    $action !== ''
    &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    require_sesskey();
    \local_ustar\view_as::assert_writable();

    require_capability(
        'local/ustar:hrmanage',
        $context
    );


    try {

        if ($action === 'savematrix') {

            $selected =
                optional_param_array(
                    'skills',
                    [],
                    PARAM_ALPHANUMEXT
                );

            $levels = [];

            foreach ($selected as $skillid) {

                $level =
                    optional_param(
                        'level_' . $skillid,
                        1,
                        PARAM_INT
                    );

                $levels[$skillid] =
                    $level;
            }

            \local_ustar\position_model::save_matrix(
                $positionid,
                $levels,
                (int)$USER->id
            );

            redirect(
                new moodle_url(
                    '/local/ustar/positions.php',
                    [
                        'positionid' =>
                            $positionid,
                    ]
                ),
                'Требования должности сохранены',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        if ($action === 'addevidence') {

            $source =
                required_param(
                    'source',
                    PARAM_TEXT
                );

            [$courseid, $cmid] =
                array_map(
                    'intval',
                    explode(
                        ':',
                        $source,
                        2
                    )
                );


            \local_ustar\position_model::add_evidence(
                [
                    'positionid' =>
                        $positionid,

                    'skillid' =>
                        required_param(
                            'skillid',
                            PARAM_ALPHANUMEXT
                        ),

                    'courseid' =>
                        $courseid,

                    'cmid' =>
                        $cmid,

                    'evidencetype' =>
                        required_param(
                            'evidencetype',
                            PARAM_ALPHANUMEXT
                        ),

                    'weight' =>
                        optional_param(
                            'weight',
                            100,
                            PARAM_INT
                        ),

                    'required' =>
                        optional_param(
                            'required',
                            0,
                            PARAM_BOOL
                        ),

                    'validdays' =>
                        optional_param(
                            'validdays',
                            0,
                            PARAM_INT
                        ),

                    'pathkey' =>
                        optional_param(
                            'pathkey',
                            'main',
                            PARAM_ALPHANUMEXT
                        ),
                ],
                (int)$USER->id
            );


            redirect(
                new moodle_url(
                    '/local/ustar/positions.php',
                    [
                        'positionid' =>
                            $positionid,
                    ]
                ),
                'Подтверждение добавлено',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        if ($action === 'deleteevidence') {

            $evidenceid =
                required_param(
                    'evidenceid',
                    PARAM_INT
                );


            \local_ustar\position_model::deactivate_evidence(
                $evidenceid,
                $positionid,
                (int)$USER->id
            );


            redirect(
                new moodle_url(
                    '/local/ustar/positions.php',
                    [
                        'positionid' =>
                            $positionid,
                    ]
                ),
                'Связь отключена',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }

    } catch (\Throwable $e) {

        \core\notification::error(
            $e->getMessage()
        );
    }
}


/*
 * ------------------------------------------------------------
 * PEOPLE COUNTS
 * ------------------------------------------------------------
 */

$occupancy = [];

$sql = "
    SELECT
        TRIM(d.data) AS positionid,
        COUNT(u.id) AS peoplecount
      FROM {user_info_data} d
      JOIN {user_info_field} f
        ON f.id = d.fieldid
       AND f.shortname = 'ustar_position'
      JOIN {user} u
        ON u.id = d.userid
       AND u.deleted = 0
     GROUP BY TRIM(d.data)
";

foreach (
    $DB->get_records_sql($sql)
    as $row
) {
    $occupancy[
        $row->positionid
    ] = (int)$row->peoplecount;
}


/*
 * ------------------------------------------------------------
 * POSITION LIST
 * ------------------------------------------------------------
 */

$positionrows = [];

foreach ($positions as $position) {

    $department =
        $departmentmap[
            $position['department']
        ] ?? null;

    $positionrows[] = [

        'id' =>
            $position['id'],

        'name' =>
            $position['name'],

        'department' =>
            $department[
                'name'
            ] ?? $position['department'],

        'level' =>
            (int)(
                $position['level']
                ?? 0
            ),

        'peoplecount' =>
            (int)(
                $occupancy[
                    $position['id']
                ] ?? 0
            ),

        'selected' =>
            $position['id']
                ===
                $positionid,

        'url' =>
            (
                new moodle_url(
                    '/local/ustar/positions.php',
                    [
                        'positionid' =>
                            $position['id'],
                    ]
                )
            )->out(false),
    ];
}


/*
 * ------------------------------------------------------------
 * SKILL MATRIX EDITOR
 * ------------------------------------------------------------
 */

$required =
    $structure['matrix'][$positionid]
    ?? [];

$selectedskillid = optional_param('skillid', '', PARAM_ALPHANUMEXT);
$skillmap = [];
foreach ($skills as $skill) {
    $skillid = clean_param((string)($skill['id'] ?? ''), PARAM_ALPHANUMEXT);
    if ($skillid !== '') {
        $skillmap[$skillid] = $skill;
    }
}
if ($selectedskillid !== '' && !isset($skillmap[$selectedskillid])) {
    $selectedskillid = '';
}

/*
 * ------------------------------------------------------------
 * POSITION / SKILL / MATERIAL GRAPH AND CAREER LADDER
 * ------------------------------------------------------------
 * The graph is a read model assembled from the canonical structure matrix
 * and currently published route versions. It never creates a second source
 * of truth and deliberately exposes human labels in the normal UI.
 */

$graphpositionrows = [];
$positionsrequiringselectedskill = [];
foreach ($positions as $position) {
    $positionrequired = $structure['matrix'][$position['id']] ?? [];
    if ($selectedskillid !== '' && array_key_exists($selectedskillid, $positionrequired)) {
        $positionsrequiringselectedskill[$position['id']] = true;
    }
}
foreach ($positions as $position) {
    $positionidvalue = (string)$position['id'];
    $department = $departmentmap[$position['department'] ?? ''] ?? [];
    $positionrequired = $structure['matrix'][$positionidvalue] ?? [];
    $graphurl = new moodle_url('/local/ustar/positions.php', ['positionid' => $positionidvalue]);
    if ($selectedskillid !== '') {
        $graphurl->param('skillid', $selectedskillid);
    }
    $graphpositionrows[] = [
        'id' => $positionidvalue,
        'name' => (string)($position['name'] ?? $positionidvalue),
        'department' => (string)($department['name'] ?? ($position['department'] ?? '')),
        'level' => (int)($position['level'] ?? 0),
        'selected' => $positionidvalue === $positionid,
        'connected' => $selectedskillid === '' || array_key_exists($selectedskillid, $positionrequired),
        'skillcount' => count($positionrequired),
        'url' => $graphurl->out(false),
    ];
}

$graphskillrows = [];
foreach ($skills as $skill) {
    $skillid = (string)($skill['id'] ?? '');
    if ($skillid === '' || ($selectedskillid === '' && !array_key_exists($skillid, $required))) {
        continue;
    }
    $skillurl = new moodle_url('/local/ustar/positions.php', [
        'positionid' => $positionid,
        'skillid' => $skillid,
    ]);
    $affectedcount = 0;
    foreach ($structure['matrix'] ?? [] as $matrix) {
        if (array_key_exists($skillid, $matrix)) {
            $affectedcount++;
        }
    }
    $graphskillrows[] = [
        'id' => $skillid,
        'name' => (string)($skill['name'] ?? $skillid),
        'category' => (string)($skill['category'] ?? 'Навык'),
        'level' => (int)($required[$skillid] ?? 0),
        'required' => array_key_exists($skillid, $required),
        'selected' => $skillid === $selectedskillid,
        'affectedcount' => $affectedcount,
        'url' => $skillurl->out(false),
    ];
}

$graphmaterialrows = [];
$graphmaterialseen = [];
$graphpositionids = $selectedskillid !== ''
    ? array_keys($positionsrequiringselectedskill)
    : [$positionid];
foreach ($graphpositionids as $graphpositionid) {
    $route = \local_ustar\route_model::get_route((string)$graphpositionid);
    if (!$route) {
        continue;
    }
    foreach (\local_ustar\route_model::points((int)$route->id) as $point) {
        $version = \local_ustar\route_model::current_published_version((int)$point->id);
        if (!$version) {
            continue;
        }
        $pointskillids = [];
        foreach (\local_ustar\route_model::requirements_for_version($version) as $requirement) {
            if (($requirement['type'] ?? '') === 'skill') {
                $pointskillids[] = (string)($requirement['sourcekey'] ?? '');
            }
        }
        if ($selectedskillid !== '' && !in_array($selectedskillid, $pointskillids, true)) {
            continue;
        }
        foreach (\local_ustar\route_model::requirements_for_version($version) as $requirement) {
            if (($requirement['type'] ?? '') !== 'content') {
                continue;
            }
            $contentid = (int)($requirement['sourceid'] ?? 0);
            if ($contentid <= 0 || isset($graphmaterialseen[$contentid])) {
                continue;
            }
            $content = $DB->get_record('local_ustar_content', ['id' => $contentid], 'id,title,type,status', IGNORE_MISSING);
            if (!$content || (string)$content->status !== 'published') {
                continue;
            }
            $materialurl = new moodle_url('/local/ustar/materials.php', ['contentid' => $contentid]);
            $routeurl = new moodle_url('/local/ustar/route_studio.php', ['position' => $graphpositionid]);
            $materialskillnames = [];
            foreach ($pointskillids as $pointskillid) {
                $materialskillnames[] = (string)($skillmap[$pointskillid]['name'] ?? $pointskillid);
            }
            $graphmaterialrows[] = [
                'id' => $contentid,
                'name' => format_string((string)$content->title),
                'typelabel' => (string)$content->type === 'video' ? 'Видео' : 'Материал',
                'positionname' => (string)($positionmap[$graphpositionid]['name'] ?? $graphpositionid),
                'skills' => implode(', ', array_values(array_unique($materialskillnames))),
                'hasskills' => !empty($materialskillnames),
                'url' => $materialurl->out(false),
                'routeurl' => $routeurl->out(false),
            ];
            $graphmaterialseen[$contentid] = true;
        }
    }
}

$nextpositionmap = [];
$previouspositionmap = [];
foreach ($positions as $position) {
    $positionkey = (string)$position['id'];
    $nextkey = trim((string)($position['next'] ?? ''));
    if ($nextkey !== '' && isset($positionmap[$nextkey])) {
        $nextpositionmap[$positionkey] = $nextkey;
        $previouspositionmap[$nextkey] = $positionkey;
    }
}
$ladderroot = $positionid;
$ladderguard = [];
while (isset($previouspositionmap[$ladderroot]) && !isset($ladderguard[$ladderroot])) {
    $ladderguard[$ladderroot] = true;
    $ladderroot = $previouspositionmap[$ladderroot];
}
$ladderrows = [];
$laddercursor = $ladderroot;
$ladderguard = [];
while (isset($positionmap[$laddercursor]) && !isset($ladderguard[$laddercursor])) {
    $ladderguard[$laddercursor] = true;
    $ladderposition = $positionmap[$laddercursor];
    $ladderdepartment = $departmentmap[$ladderposition['department'] ?? ''] ?? [];
    $ladderrequired = $structure['matrix'][$laddercursor] ?? [];
    $ladderskills = [];
    foreach (array_keys($ladderrequired) as $ladderskillid) {
        $ladderskills[] = (string)($skillmap[$ladderskillid]['name'] ?? $ladderskillid);
    }
    $ladderrows[] = [
        'id' => $laddercursor,
        'name' => (string)($ladderposition['name'] ?? $laddercursor),
        'department' => (string)($ladderdepartment['name'] ?? ($ladderposition['department'] ?? '')),
        'level' => (int)($ladderposition['level'] ?? 0),
        'periodlabel' => 'Уровень ' . (int)($ladderposition['level'] ?? 0),
        'selected' => $laddercursor === $positionid,
        'skills' => implode(', ', $ladderskills),
        'hasskills' => !empty($ladderskills),
        'nextname' => isset($nextpositionmap[$laddercursor]) ? (string)($positionmap[$nextpositionmap[$laddercursor]]['name'] ?? '') : '',
        'hasnext' => isset($nextpositionmap[$laddercursor]),
        'url' => (new moodle_url('/local/ustar/positions.php', ['positionid' => $laddercursor]))->out(false),
    ];
    if (!isset($nextpositionmap[$laddercursor])) {
        break;
    }
    $laddercursor = $nextpositionmap[$laddercursor];
}

$skillrows = [];

foreach ($skills as $skill) {

    $level =
        (int)(
            $required[
                $skill['id']
            ] ?? 1
        );

    $leveloptions = [];

    $levelnames = [
        1 => 'Ознакомительный',
        2 => 'Базовый',
        3 => 'Самостоятельный',
        4 => 'Продвинутый',
        5 => 'Эксперт',
    ];
    foreach (range(1, 5) as $candidate) {
        $leveloptions[] = [
            'value' => $candidate,
            'label' => $levelnames[$candidate],
            'selected' => $candidate === $level,
        ];
    }

    $skillrows[] = [

        'id' =>
            $skill['id'],

        'name' =>
            $skill['name'],

        'category' =>
            $skill['category']
            ?? 'Общее',

        'required' =>
            isset(
                $required[
                    $skill['id']
                ]
            ),

        'leveloptions' =>
            $leveloptions,
    ];
}


/*
 * ------------------------------------------------------------
 * CURRENT LEARNING ASSIGNMENT
 * ------------------------------------------------------------
 */

$assignment =
    \local_ustar\assignment::required_courses(
        $positionid
    );

$learningcourses = [];

foreach (
    $assignment['courses'] ?? []
    as $course
) {

    $learningcourses[] = [

        'id' =>
            (int)$course['id'],

        'name' =>
            $course['name'],

        'published' =>
            !empty(
                $course['visible']
            ),

        'draft' =>
            empty(
                $course['visible']
            ),

        'url' =>
            (
                new moodle_url(
                    '/local/ustar/home.php',
                    [
                        'view' =>
                            'learning',

                        'courseid' =>
                            (int)$course['id'],
                    ]
                )
            )->out(false),
    ];
}


/*
 * ------------------------------------------------------------
 * MANDATORY KNOWLEDGE
 * ------------------------------------------------------------
 *
 * Derived from the same live content access rules employees use.
 * There is no duplicated per-position document mapping.
 */

$mandatoryknowledge =
    \local_ustar\compliance::for_position(
        $positionid
    );


/*
 * ------------------------------------------------------------
 * EVIDENCE
 * ------------------------------------------------------------
 */

$evidencerows = [];

if (!empty($required)) {

    [$insql, $params] =
        $DB->get_in_or_equal(
            array_keys($required),
            SQL_PARAMS_NAMED,
            'skill'
        );

    $params['positionid'] =
        $positionid;

    $sql = "
        SELECT *
          FROM {local_ustar_skill_evidence}
         WHERE active = 1
           AND skillid {$insql}
           AND (
                positionid = :positionid
                OR positionid IS NULL
                OR positionid = ''
           )
         ORDER BY
             skillid,
             pathkey,
             sortorder,
             id
    ";

    foreach (
        $DB->get_records_sql(
            $sql,
            $params
        )
        as $definition
    ) {

        $evaluated =
            \local_ustar\evidence::evaluate_definition(
                $definition,
                (int)$USER->id
            );

        $skillname =
            $definition->skillid;

        foreach ($skills as $skill) {
            if (
                $skill['id']
                ===
                $definition->skillid
            ) {
                $skillname =
                    $skill['name'];

                break;
            }
        }

        $typelabels = [
            'learning' =>
                'Обучение',

            'assessment' =>
                'Аттестация',

            'practice' =>
                'Практика',

            'manager_review' =>
                'Оценка руководителя',

            'checklist' =>
                'Чек-лист',

            'certification' =>
                'Сертификация',
        ];

        $specific =
            trim(
                (string)(
                    $definition->positionid
                    ?? ''
                )
            ) !== '';

        $evidencerows[] = [

            'id' =>
                (int)$definition->id,

            'skillname' =>
                $skillname,

            'type' =>
                $typelabels[
                    $definition->evidencetype
                ]
                ??
                $definition->evidencetype,

            'activityname' =>
                $evaluated[
                    'activityname'
                ] ?? 'Источник',

            'modname' =>
                $evaluated[
                    'modname'
                ] ?? '',

            'pathkey' =>
                $definition->pathkey
                ?: 'default',

            'weight' =>
                (int)$definition->weight,

            'required' =>
                !empty(
                    $definition->required
                ),

            'validdays' =>
                (int)$definition->validdays,

            'hasvalidity' =>
                !empty(
                    $definition->validdays
                ),

            'specific' =>
                $specific,

            'shared' =>
                !$specific,
        ];
    }
}


/*
 * ------------------------------------------------------------
 * ACTIVITY SOURCE OPTIONS
 * ------------------------------------------------------------
 */

$sourceoptions = [];

$sourcequery = "
    SELECT
        cm.id AS cmid,
        cm.course AS courseid,
        cm.instance,
        m.name AS modname,
        c.fullname AS coursename,
        c.visible AS coursevisible
      FROM {course_modules} cm
      JOIN {modules} m
        ON m.id = cm.module
      JOIN {course} c
        ON c.id = cm.course
     WHERE c.id <> :siteid
       AND m.name NOT IN ('qbank', 'label')
     ORDER BY
         c.fullname,
         cm.course,
         cm.id
";

foreach (
    $DB->get_records_sql(
        $sourcequery,
        ['siteid' => SITEID]
    )
    as $row
) {

    try {

        $activityname =
            (string)$DB->get_field(
                $row->modname,
                'name',
                [
                    'id' =>
                        $row->instance,
                ]
            );

    } catch (\Throwable $e) {

        $activityname =
            $row->modname
            .
            ' #'
            .
            $row->cmid;
    }

    if ($activityname === '') {
        continue;
    }

    $sourceoptions[] = [

        'value' =>
            $row->courseid
            .
            ':'
            .
            $row->cmid,

        'name' =>
            $row->coursename
            .
            ' → '
            .
            $activityname
            .
            ' ['
            .
            strtoupper(
                $row->modname
            )
            .
            ']'
            .
            (
                empty(
                    $row->coursevisible
                )
                    ? ' · Черновик'
                    : ''
            ),
    ];
}


/*
 * Only required skills may receive evidence.
 */
$requiredskilloptions = [];

foreach ($skills as $skill) {

    if (
        !isset(
            $required[
                $skill['id']
            ]
        )
    ) {
        continue;
    }

    $requiredskilloptions[] = [
        'id' =>
            $skill['id'],

        'name' =>
            $skill['name'],
    ];
}


$typeoptions = [
    [
        'id' => 'learning',
        'name' => 'Обучение',
    ],
    [
        'id' => 'assessment',
        'name' => 'Аттестация',
    ],
];


/*
 * ------------------------------------------------------------
 * CURRENT POSITION VIEW MODEL
 * ------------------------------------------------------------
 */

$currentdepartment =
    $departmentmap[
        $currentposition[
            'department'
        ]
    ] ?? null;


$PAGE->set_context(
    $context
);

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/positions.php',
        [
            'positionid' =>
                $positionid,
        ]
    )
);

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Модели должностей | Центр управления USTAR'
);

$PAGE->set_heading(
    'Центр управления USTAR'
);

$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


$data = [

    'positions' =>
        $positionrows,

    'position' => [

        'id' =>
            $currentposition['id'],

        'name' =>
            $currentposition['name'],

        'department' =>
            $currentdepartment[
                'name'
            ]
            ??
            $currentposition[
                'department'
            ],

        'level' =>
            (int)(
                $currentposition[
                    'level'
                ] ?? 0
            ),

        'peoplecount' =>
            (int)(
                $occupancy[
                    $positionid
                ] ?? 0
            ),

        'skillcount' =>
            count(
                $required
            ),
    ],

    'graphpositions' => $graphpositionrows,
    'graphpositioncount' => count($graphpositionrows),
    'graphskills' => $graphskillrows,
    'graphskillcount' => count($graphskillrows),
    'graphmaterials' => $graphmaterialrows,
    'graphmaterialcount' => count($graphmaterialrows),
    'hasgraphmaterials' => !empty($graphmaterialrows),
    'selectedskillid' => $selectedskillid,
    'selectedskillname' => $selectedskillid !== ''
        ? (string)($skillmap[$selectedskillid]['name'] ?? $selectedskillid)
        : '',
    'hasselectedskill' => $selectedskillid !== '',
    'ladder' => $ladderrows,
    'hasladder' => !empty($ladderrows),

    'skills' =>
        $skillrows,

    'learningcourses' =>
        $learningcourses,

    'haslearning' =>
        !empty(
            $learningcourses
        ),

    'mandatoryknowledge' =>
        $mandatoryknowledge,

    'hasknowledge' =>
        !empty(
            $mandatoryknowledge
        ),

    'mandatoryknowledgecount' =>
        count(
            $mandatoryknowledge
        ),

    'evidence' =>
        $evidencerows,

    'hasevidence' =>
        !empty(
            $evidencerows
        ),

    'requiredskilloptions' =>
        $requiredskilloptions,

    'hasrequiredskills' =>
        !empty(
            $requiredskilloptions
        ),

    'sourceoptions' =>
        $sourceoptions,

    'typeoptions' =>
        $typeoptions,

    'canmanage' =>
        $canmanage,

    'routestudiourl' =>
        (
            new moodle_url(
                '/local/ustar/route_studio.php',
                ['position' => $positionid]
            )
        )->out(false),

    'sesskey' =>
        sesskey(),
];


echo $output->header();

echo $output->render_from_template(
    'local_ustar/positions',
    $data
);

echo $output->footer();
