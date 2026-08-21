<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $DB;

$context = context_system::instance();

$view = optional_param(
    'view',
    'home',
    PARAM_ALPHA
);

$courseid = optional_param(
    'courseid',
    0,
    PARAM_INT
);

$PAGE->set_context($context);

$pageparams = [];

if ($view !== 'home') {
    $pageparams['view'] = $view;
}

if (
    $view === 'learning'
    &&
    $courseid > 0
) {
    $pageparams['courseid'] = $courseid;
}

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/home.php',
        $pageparams
    )
);

$PAGE->set_pagelayout('ustar');

$PAGE->set_title('USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


/** Resolve Moodle course overview image with a branded fallback. */
function local_ustar_course_cover_url(int $courseid, $output): string {
    global $CFG;

    try {
        $course = get_course($courseid);
        $element = new \core_course_list_element($course);

        foreach ($element->get_course_overviewfiles() as $file) {
            if (!$file->is_valid_image()) {
                continue;
            }

            // Course overviewfiles are served without an itemid path segment.
            // Mirror core_course_renderer::course_overview_files(); using
            // make_pluginfile_url(..., itemid=0, ...) produces /overviewfiles/0/...
            // and returns a broken image for this file area.
            $url = \moodle_url::make_file_url(
                $CFG->wwwroot . '/pluginfile.php',
                '/' . $file->get_contextid() .
                '/' . $file->get_component() .
                '/' . $file->get_filearea() .
                $file->get_filepath() .
                $file->get_filename(),
                false
            );

            return $url->out(false);
        }
    } catch (\Throwable $e) {
        // Fallback below keeps Home operational even when course artwork cannot be resolved.
    }

    return $output->image_url('brand/ustar-course-placeholder', 'theme_ustar')->out(false);
}


/*
 * ------------------------------------------------------------
 * REAL EMPLOYEE LEARNING DATA
 * ------------------------------------------------------------
 *
 * user_courses() combines:
 *   - real Moodle enrolments
 *   - Position -> Skills -> linked learning
 *
 * and now returns actual activity completion / nextActivity.
 */

$courses =
    \local_ustar\external\base::user_courses(
        (int)$USER->id
    );


/*
 * Resolve current Moodle course visibility.
 *
 * Site administrators may preview hidden learning content;
 * normal employees must never be given inaccessible hidden cards.
 */

$courseids =
    array_values(
        array_filter(
            array_map(
                static fn($course) =>
                    (int)($course['id'] ?? 0),
                $courses
            )
        )
    );

$visibility = [];

if ($courseids) {

    foreach (
        $DB->get_records_list(
            'course',
            'id',
            $courseids,
            '',
            'id,visible'
        ) as $record
    ) {
        $visibility[
            (int)$record->id
        ] = (bool)$record->visible;
    }
}

$siteadmin =
    is_siteadmin($USER);


/*
 * These are real Moodle surfaces, but they are NOT employee
 * learning catalogue items.
 *
 * They remain untouched in Moodle:
 *
 *   news  -> operational feed
 *   255   -> HR journal
 *   Library -> Knowledge section
 *
 * Later this classification should become explicit content metadata
 * instead of relying on legacy identifiers/names.
 */

$serviceidnumbers = [
    'news',
    '255',
];


$learningcourses = [];


foreach ($courses as $course) {

    $courseid =
        (int)$course['id'];

    $idnumber =
        trim(
            (string)(
                $course['idnumber']
                ?? ''
            )
        );

    $coursename =
        trim(
            (string)(
                $course['name']
                ?? ''
            )
        );

    $visible =
        $visibility[$courseid]
        ?? true;


    /*
     * Operational/system surfaces are not Learning cards.
     */

    if (
        in_array(
            $idnumber,
            $serviceidnumbers,
            true
        )
    ) {
        continue;
    }


    /*
     * Library belongs to Knowledge Market.
     */

    if (
        core_text::strpos(
            core_text::strtolower(
                $coursename
            ),
            'библиотек'
        ) !== false
    ) {
        continue;
    }


    /*
     * Normal employees should not see hidden Moodle courses.
     * Siteadmin can preview them during implementation.
     */

    if (
        !$visible &&
        !$siteadmin
    ) {
        continue;
    }


    $tracked =
        (int)(
            $course['tracked']
            ?? 0
        );

    $done =
        (int)(
            $course['done']
            ?? 0
        );

    $progress =
        (int)(
            $course['progress']
            ?? 0
        );

    $status =
        (string)(
            $course['status']
            ?? 'new'
        );


    $nextactivity =
        $course['nextActivity']
        ?? null;


    $actionurl =
        !empty($nextactivity['url'])
            ? $nextactivity['url']
            : $course['url'];


    $actionlabel =
        $status === 'active'
            ? 'Продолжить'
            : (
                $status === 'done'
                    ? 'Открыть'
                    : 'Начать'
            );


    if ($status === 'done') {

        $statuslabel =
            'Завершено';

    } else if ($status === 'active') {

        $statuslabel =
            'В работе';

    } else {

        $statuslabel =
            'Новое';
    }


    $skills = [];

    foreach (
        $course['skillDetails']
            ?? []
        as $skill
    ) {

        $skills[] = [
            'name' =>
                $skill['name']
                ?? '',
        ];
    }


    $item = [
        'id' =>
            $courseid,

        'name' =>
            $coursename,

        'coverurl' =>
            local_ustar_course_cover_url($courseid, $OUTPUT),

        'category' =>
            trim(
                (string)(
                    $course['category']
                    ?? ''
                )
            ),

        'hascategory' =>
            trim(
                (string)(
                    $course['category']
                    ?? ''
                )
            ) !== '',

        'progress' =>
            $progress,

        'tracked' =>
            $tracked,

        'done' =>
            $done,

        'hasprogress' =>
            $tracked > 0,

        'stepslabel' =>
            $tracked > 0
                ? "{$done} из {$tracked} шагов"
                : 'Прогресс пока не отслеживается',

        'status' =>
            $status,

        'statuslabel' =>
            $statuslabel,

        'isactive' =>
            $status === 'active',

        'isnew' =>
            $status === 'new',

        'isdone' =>
            $status === 'done',

        'actionurl' =>
            $actionurl,

        'actionlabel' =>
            $actionlabel,

        'courseurl' =>
            $course['url'],

        'nextactivityname' =>
            trim(
                (string)(
                    $nextactivity['name']
                    ?? ''
                )
            ),

        'hasnextactivity' =>
            !empty(
                $nextactivity['name']
            ),

        'lastactivity' =>
            (int)(
                $course['lastActivity']
                ?? 0
            ),

        'skills' =>
            $skills,

        'hasskills' =>
            !empty($skills),

        'positionrequired' =>
            !empty(
                $course[
                    'positionRequired'
                ]
            ),

        'enrolled' =>
            !empty(
                $course['enrolled']
            ),

        'hidden' =>
            !$visible,

        'hiddenlabel' =>
            !$visible
                ? 'Скрыт в Moodle · preview'
                : '',
    ];


    $learningcourses[] =
        $item;
}


/*
 * ------------------------------------------------------------
 * GROUP + PRIORITY
 * ------------------------------------------------------------
 */

$activecourses = [];
$newcourses = [];
$donecourses = [];



/*
 * ------------------------------------------------------------
 * USTAR GUIDED LEARNING ROUTES
 * ------------------------------------------------------------
 *
 * Employee course cards stay inside the USTAR shell.
 * Raw Moodle activities are opened only from the guided path.
 */

foreach ($learningcourses as &$learningcourse) {

    $guidedurl =
        (
            new moodle_url(
                '/local/ustar/home.php',
                [
                    'view' =>
                        'learning',

                    'courseid' =>
                        (int)$learningcourse['id'],

                    'theme' =>
                        'ustar',
                ]
            )
        )->out(false);


    /*
     * Existing Learning template uses different URL keys
     * for different card states. Point all of them to the
     * same USTAR route.
     */

    $learningcourse['pathurl'] =
        $guidedurl;

    $learningcourse['actionurl'] =
        $guidedurl;

    $learningcourse['courseurl'] =
        $guidedurl;

    $learningcourse['url'] =
        $guidedurl;
}

unset($learningcourse);


foreach ($learningcourses as $course) {

    if ($course['isdone']) {

        $donecourses[] =
            $course;

    } else if ($course['isactive']) {

        $activecourses[] =
            $course;

    } else {

        $newcourses[] =
            $course;
    }
}


/*
 * Continue:
 *
 *  1. visible before hidden preview content
 *  2. most recently active
 *  3. greatest current progress
 */

usort(
    $activecourses,
    static function(
        array $a,
        array $b
    ): int {

        $hiddencompare =
            ((int)$a['hidden'])
            <=>
            ((int)$b['hidden']);

        if ($hiddencompare !== 0) {
            return $hiddencompare;
        }


        $activitycompare =
            $b['lastactivity']
            <=>
            $a['lastactivity'];

        if ($activitycompare !== 0) {
            return $activitycompare;
        }


        return
            $b['progress']
            <=>
            $a['progress'];
    }
);


usort(
    $newcourses,
    static function(
        array $a,
        array $b
    ): int {

        return
            ((int)$a['hidden'])
            <=>
            ((int)$b['hidden']);
    }
);


$nextcourse =
    $activecourses[0]
    ?? $newcourses[0]
    ?? null;


/*
 * Home needs the exact same source of truth as Learning.
 */

$homenext = null;

if ($nextcourse) {

    $homenext = [
        'name' =>
            $nextcourse['name'],

        'coverurl' =>
            $nextcourse['coverurl'],

        'progress' =>
            $nextcourse['progress'],

        'hasprogress' =>
            $nextcourse[
                'hasprogress'
            ],

        'stepslabel' =>
            $nextcourse[
                'stepslabel'
            ],

        'actionurl' =>
            $nextcourse[
                'actionurl'
            ],

        'actionlabel' =>
            $nextcourse[
                'isactive'
            ]
                ? 'Продолжить'
                : 'Начать',

        'nextactivityname' =>
            $nextcourse[
                'nextactivityname'
            ],

        'hasnextactivity' =>
            $nextcourse[
                'hasnextactivity'
            ],
    ];
}



/*
 * ============================================================
 * USTAR DEVELOPMENT MODEL
 * ============================================================
 *
 * Important:
 *
 * Course completion is learning evidence, not a declaration that
 * a human skill has been fully demonstrated.
 *
 * Therefore the employee UI calls this "learning coverage".
 * A real competency/readiness score can later combine:
 *
 *   learning + assessment + evidence + review + recertification.
 */

$resolveddevelopment =
    \local_ustar\structure::resolve_user(
        (int)$USER->id
    );

$developmentstructure =
    $resolveddevelopment['structure'];

$currentposition =
    $resolveddevelopment['position'];

$currentdepartment =
    $resolveddevelopment['department'];


/*
 * Course progress available to this user, indexed by Moodle
 * course idnumber.
 */

$usercoursebyidnumber = [];

foreach ($courses as $course) {

    $idnumber =
        trim(
            (string)(
                $course['idnumber']
                ?? ''
            )
        );

    if ($idnumber !== '') {
        $usercoursebyidnumber[$idnumber] =
            $course;
    }
}


/*
 * Skill lookup.
 */

$developmentskillmap = [];

foreach (
    $developmentstructure['skills']
        ?? []
    as $skill
) {
    $developmentskillmap[
        $skill['id']
    ] = $skill;
}


/*
 * Current-position requirements.
 */

$currentrequirements = [];

if ($currentposition) {
    $currentrequirements =
        $developmentstructure['matrix'][
            $currentposition['id']
        ] ?? [];
}


/*
 * Find every referenced Moodle course in the CURRENT position,
 * even if the employee has not been enrolled yet.
 */

$requiredidnumbers = [];

foreach (
    $currentrequirements
    as $skillid => $requiredlevel
) {

    $skill =
        $developmentskillmap[$skillid]
        ?? null;

    if (!$skill) {
        continue;
    }

    foreach (
        $skill['courses'] ?? []
        as $idnumber
    ) {

        $idnumber =
            trim((string)$idnumber);

        if ($idnumber !== '') {
            $requiredidnumbers[$idnumber] =
                true;
        }
    }
}


$moodlecoursebyidnumber = [];

if ($requiredidnumbers) {

    [$insql, $params] =
        $DB->get_in_or_equal(
            array_keys(
                $requiredidnumbers
            )
        );

    foreach (
        $DB->get_records_select(
            'course',
            "idnumber {$insql}",
            $params,
            '',
            'id,fullname,idnumber,visible'
        )
        as $course
    ) {

        $moodlecoursebyidnumber[
            trim(
                (string)$course->idnumber
            )
        ] = $course;
    }
}


/*
 * Employee skill requirement cards.
 */

$developmentskills = [];

$configuredskillcount = 0;
$measurableskillcount = 0;

$measuredrequiredlevels = 0;
$measuredearnedlevels = 0;


foreach (
    $currentrequirements
    as $skillid => $requiredlevel
) {

    $skill =
        $developmentskillmap[$skillid]
        ?? [
            'id' => $skillid,
            'name' => $skillid,
            'category' => '',
            'courses' => [],
        ];

    $refs =
        array_values(
            array_filter(
                array_map(
                    'trim',
                    $skill['courses']
                        ?? []
                )
            )
        );


    $found = [];
    $assigned = [];
    $progressvalues = [];


    foreach ($refs as $idnumber) {

        if (
            isset(
                $moodlecoursebyidnumber[
                    $idnumber
                ]
            )
        ) {
            $found[] =
                $idnumber;
        }

        if (
            isset(
                $usercoursebyidnumber[
                    $idnumber
                ]
            )
        ) {

            $assigned[] =
                $idnumber;

            $progressvalues[] =
                (int)(
                    $usercoursebyidnumber[
                        $idnumber
                    ]['progress']
                    ?? 0
                );
        }
    }


    /*
     * configured:
     * every course referenced by the skill physically exists.
     */

    $configured =
        !empty($refs)
        &&
        count($found) ===
        count($refs);


    if ($configured) {
        $configuredskillcount++;
    }


    /*
     * measurable:
     * configured and employee has the complete learning assignment.
     */

    $measurable =
        $configured
        &&
        count($assigned) ===
        count($refs);


    $learningprogress = null;
    $currentlearninglevel = null;
    $gap = null;


    if ($measurable) {

        $measurableskillcount++;

        $learningprogress =
            $progressvalues
                ? (int)round(
                    array_sum(
                        $progressvalues
                    )
                    /
                    count(
                        $progressvalues
                    )
                )
                : 0;

        $currentlearninglevel =
            min(
                (int)$requiredlevel,
                (int)floor(
                    $learningprogress
                    /
                    100
                    *
                    (int)$requiredlevel
                    +
                    .001
                )
            );

        $gap =
            max(
                0,
                (int)$requiredlevel
                -
                $currentlearninglevel
            );

        $measuredrequiredlevels +=
            (int)$requiredlevel;

        $measuredearnedlevels +=
            $currentlearninglevel;
    }


    /*
     * Human-readable state.
     */

    if (!$refs) {

        $state =
            'Учебный маршрут для навыка пока не настроен';

        $statecode =
            'unmapped';

    } else if (!$configured) {

        $state =
            'Связанный курс пока не опубликован в Moodle';

        $statecode =
            'missing';

    } else if (!$measurable) {

        $state =
            'Обучение существует, но назначено не полностью';

        $statecode =
            'unassigned';

    } else if (
        $learningprogress >= 100
    ) {

        $state =
            'Учебное покрытие завершено';

        $statecode =
            'covered';

    } else if (
        $learningprogress > 0
    ) {

        $state =
            'Обучение в процессе';

        $statecode =
            'active';

    } else {

        $state =
            'Обучение ещё не начато';

        $statecode =
            'new';
    }



    /*
     * ========================================================
     * USTAR EVIDENCE UI MODEL
     * ========================================================
     *
     * Prefer normalized evidence over legacy skill->courses.
     */

    $evidenceevaluation =
        \local_ustar\evidence::evaluate_skill(
            $skillid,
            $currentposition['id'] ?? null,
            (int)$USER->id
        );

    $evidenceconfigured =
        !empty(
            $evidenceevaluation['configured']
        );

    $evidenceitems = [];
    $evidenceprogress = null;
    $evidencesatisfied = false;

    if ($evidenceconfigured) {

        $bestpath =
            $evidenceevaluation['bestpath']
            ?? null;

        $evidenceprogress =
            $evidenceevaluation['progress']
            ?? 0;

        $evidencesatisfied =
            !empty(
                $evidenceevaluation['satisfied']
            );

        foreach (
            $bestpath['items'] ?? []
            as $evidenceitem
        ) {

            $type =
                $evidenceitem['type']
                ?? 'learning';

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

            $status =
                $evidenceitem['status']
                ?? 'pending';

            $statuslabels = [
                'pending' =>
                    'Не завершено',

                'active' =>
                    'В процессе',

                'completed' =>
                    'Завершено',

                'passed' =>
                    'Пройдено',

                'failed' =>
                    'Не пройдено',

                'completed_ungraded' =>
                    'Завершено',

                'not_tracked' =>
                    'Нет отслеживания',

                'source_missing' =>
                    'Источник недоступен',

                'source_mismatch' =>
                    'Ошибка настройки',

                'unavailable' =>
                    'Недоступно',
            ];

            $evidenceitems[] = [

                'type' =>
                    $type,

                'typelabel' =>
                    $typelabels[$type]
                    ?? $type,

                'status' =>
                    $status,

                'statuslabel' =>
                    $statuslabels[$status]
                    ?? $status,

                'activityname' =>
                    $evidenceitem[
                        'activityname'
                    ] ?? '',

                'hasactivity' =>
                    !empty(
                        $evidenceitem[
                            'activityname'
                        ]
                    ),

                'progress' =>
                    (int)(
                        $evidenceitem[
                            'progress'
                        ] ?? 0
                    ),

                'completed' =>
                    !empty(
                        $evidenceitem[
                            'completed'
                        ]
                    ),

                'satisfied' =>
                    !empty(
                        $evidenceitem[
                            'satisfied'
                        ]
                    ),

                'failed' =>
                    $status === 'failed',

                'pending' =>
                    in_array(
                        $status,
                        [
                            'pending',
                            'active',
                        ],
                        true
                    ),

                'validdays' =>
                    (int)(
                        $evidenceitem[
                            'validdays'
                        ] ?? 0
                    ),

                'hasvalidity' =>
                    !empty(
                        $evidenceitem[
                            'validdays'
                        ]
                    ),
            ];
        }
    }


    $developmentskills[] = [

        'id' =>
            $skillid,

        'name' =>
            $skill['name'],

        'category' =>
            $skill['category']
            ?? '',

        'hascategory' =>
            !empty(
                $skill['category']
            ),

        'requiredlevel' =>
            (int)$requiredlevel,

        'requiredlabel' =>
            'Уровень '
            .
            (int)$requiredlevel,

        'configured' =>
            $configured,

        'measurable' =>
            $measurable,

        'state' =>
            $state,

        'statecode' =>
            $statecode,

        'learningprogress' =>
            $learningprogress,

        'currentlearninglevel' =>
            $currentlearninglevel,

        'gap' =>
            $gap,

        /*
         * Normalized evidence layer.
         */
        'evidenceconfigured' =>
            $evidenceconfigured,

        'evidenceitems' =>
            $evidenceitems,

        'hasevidenceitems' =>
            !empty(
                $evidenceitems
            ),

        'evidenceprogress' =>
            $evidenceprogress
            ?? 0,

        'evidencesatisfied' =>
            $evidencesatisfied,

        'refcount' =>
            count($refs),

        'foundcount' =>
            count($found),
    ];
}


/*
 * Overall LEARNING coverage.
 *
 * Explicitly not named "competency readiness".
 */

$learningcoverage = null;

if ($measuredrequiredlevels > 0) {

    $learningcoverage =
        (int)round(
            $measuredearnedlevels
            /
            $measuredrequiredlevels
            *
            100
        );
}


/*
 * ============================================================
 * CAREER CHAIN FOR THE CURRENT POSITION
 * ============================================================
 */

$careersteps = [];
$nextposition = null;


if (
    $currentposition
    &&
    $currentdepartment
) {

    $departmentpositions =
        array_values(
            array_filter(
                $developmentstructure[
                    'positions'
                ] ?? [],
                static fn($position) =>
                    (
                        $position[
                            'department'
                        ] ?? null
                    )
                    ===
                    $currentdepartment['id']
            )
        );


    $positionmap = [];
    $inbound = [];


    foreach (
        $departmentpositions
        as $position
    ) {

        $positionmap[
            $position['id']
        ] = $position;

        if (
            !empty(
                $position['next']
            )
        ) {
            $inbound[
                $position['next']
            ] = true;
        }
    }


    /*
     * Find the root which eventually reaches the employee's
     * current position.
     */

    $roots =
        array_values(
            array_filter(
                $departmentpositions,
                static fn($position) =>
                    empty(
                        $inbound[
                            $position['id']
                        ]
                    )
            )
        );


    foreach ($roots as $root) {

        $chain = [];
        $cursor = $root;
        $guard = [];

        while (
            $cursor
            &&
            empty(
                $guard[
                    $cursor['id']
                ]
            )
        ) {

            $guard[
                $cursor['id']
            ] = true;

            $chain[] =
                $cursor;

            $nextid =
                $cursor['next']
                ?? null;

            $cursor =
                (
                    $nextid
                    &&
                    isset(
                        $positionmap[
                            $nextid
                        ]
                    )
                )
                    ? $positionmap[
                        $nextid
                    ]
                    : null;
        }


        $ids =
            array_column(
                $chain,
                'id'
            );


        if (
            in_array(
                $currentposition['id'],
                $ids,
                true
            )
        ) {

            $currentindex =
                array_search(
                    $currentposition['id'],
                    $ids,
                    true
                );


            foreach (
                $chain
                as $index => $position
            ) {

                $careersteps[] = [

                    'id' =>
                        $position['id'],

                    'name' =>
                        $position['name'],

                    'level' =>
                        (int)(
                            $position['level']
                            ?? 0
                        ),

                    'ispast' =>
                        $index < $currentindex,

                    'iscurrent' =>
                        $index === $currentindex,

                    'isfuture' =>
                        $index > $currentindex,

                    'connector' =>
                        $index <
                        count($chain) - 1,
                ];
            }

            break;
        }
    }


    /*
     * Structure may contain an isolated position.
     */

    if (!$careersteps) {

        $careersteps[] = [

            'id' =>
                $currentposition['id'],

            'name' =>
                $currentposition['name'],

            'level' =>
                (int)(
                    $currentposition['level']
                    ?? 0
                ),

            'ispast' =>
                false,

            'iscurrent' =>
                true,

            'isfuture' =>
                false,

            'connector' =>
                false,
        ];
    }


    if (
        !empty(
            $currentposition['next']
        )
        &&
        isset(
            $positionmap[
                $currentposition[
                    'next'
                ]
            ]
        )
    ) {

        $nextposition =
            $positionmap[
                $currentposition[
                    'next'
                ]
            ];
    }
}


$development = [

    'hasposition' =>
        $currentposition !== null,

    'positionname' =>
        $currentposition[
            'name'
        ] ?? '',

    'positionlevel' =>
        (int)(
            $currentposition[
                'level'
            ] ?? 0
        ),

    'departmentname' =>
        $currentdepartment[
            'name'
        ] ?? '',

    'hasdepartment' =>
        $currentdepartment !== null,

    'skills' =>
        $developmentskills,

    'skillcount' =>
        count(
            $developmentskills
        ),

    'hasskills' =>
        !empty(
            $developmentskills
        ),

    'configuredskillcount' =>
        $configuredskillcount,

    'measurableskillcount' =>
        $measurableskillcount,

    'haslearningcoverage' =>
        $learningcoverage !== null,

    'learningcoverage' =>
        $learningcoverage
        ?? 0,

    'careersteps' =>
        $careersteps,

    'hascareer' =>
        !empty(
            $careersteps
        ),

    'hasnextposition' =>
        $nextposition !== null,

    'nextpositionname' =>
        $nextposition[
            'name'
        ] ?? '',

    'istopposition' =>
        $currentposition
        &&
        !$nextposition,
];


/*
 * ------------------------------------------------------------
 * VIEW MODEL
 * ------------------------------------------------------------
 */


/*
 * ------------------------------------------------------------
 * USTAR GUIDED LEARNING DETAIL
 * ------------------------------------------------------------
 *
 * /home.php?view=learning
 *     -> employee Learning catalogue
 *
 * /home.php?view=learning&courseid=N
 *     -> guided route in the SAME USTAR shell
 */

$guidedlearning =
    false;

$guidedlearninghtml =
    '';


if (
    $view === 'learning'
    &&
    $courseid > 0
) {

    $guidedcourse =
        $DB->get_record(
            'course',
            [
                'id' =>
                    $courseid,
            ],
            'id,fullname,shortname,visible',
            MUST_EXIST
        );


    $coursecontext =
        context_course::instance(
            $courseid
        );


    $isenrolled =
        is_enrolled(
            $coursecontext,
            (int)$USER->id,
            '',
            true
        );


    /*
     * Employee access:
     *
     *   published + enrolled
     *
     * Siteadmin may inspect hidden/draft courses while
     * implementing the Academy.
     *
     * HR preview will later live in Control Center and
     * should not rely on employee-route privileges.
     */

    if (
        !$siteadmin
        &&
        (
            !$isenrolled
            ||
            empty($guidedcourse->visible)
        )
    ) {

        throw new required_capability_exception(
            $coursecontext,
            'moodle/course:view',
            'nopermissions',
            ''
        );
    }


    $guidedpath =
        \local_ustar\learning_path::for_user(
            $courseid,
            (int)$USER->id
        );


    $canlaunch =
        $siteadmin
        ||
        (
            !empty($guidedcourse->visible)
            &&
            $isenrolled
        );


    $guidedpath['canlaunch'] =
        $canlaunch;

    $guidedpath['cannotlaunch'] =
        !$canlaunch;


    if (
        !empty(
            $guidedpath['next']
        )
    ) {

        $guidedpath['next']['canlaunch'] =
            $canlaunch;
    }


    $guidedbackurl =
        (
            new moodle_url(
                '/local/ustar/home.php',
                [
                    'view' =>
                        'learning',

                    'theme' =>
                        'ustar',
                ]
            )
        )->out(false);


    $guidedlearninghtml =
        $output->render_from_template(
            'local_ustar/learning_path',
            [
                'path' =>
                    $guidedpath,

                'backurl' =>
                    $guidedbackurl,

                'iselevated' =>
                    $siteadmin,

                'isenrolled' =>
                    $isenrolled,
            ]
        );


    $guidedlearning =
        true;


    $PAGE->set_title(
        $guidedcourse->fullname
        .
        ' | USTAR Academy'
    );
}


$data = [

    'firstname' =>
        $USER->firstname,


    'learningurl' =>
        (
            new moodle_url(
                '/local/ustar/home.php',
                ['view' => 'learning']
            )
        )->out(false),


    'knowledgeurl' =>
        (
            new moodle_url(
                '/local/ustar/knowledge.php',
                ['view' => 'knowledge']
            )
        )->out(false),


    'careerurl' =>
        (
            new moodle_url(
                '/local/ustar/home.php',
                ['view' => 'career']
            )
        )->out(false),

    'homebannerurl' =>
        $OUTPUT->image_url(
            'brand/ustar-academy-banner',
            'theme_ustar'
        )->out(false),

    'profileurl' =>
        (
            new moodle_url('/local/ustar/profile.php')
        )->out(false),

    'gamesurl' =>
        (
            new moodle_url('/local/ustar/games.php')
        )->out(false),

    'achievementsurl' =>
        (
            new moodle_url('/local/ustar/achievements.php')
        )->out(false),

    'checklistsurl' =>
        (
            new moodle_url('/local/ustar/checklists.php')
        )->out(false),

    'teamurl' =>
        (
            new moodle_url('/local/ustar/team.php')
        )->out(false),

    'executiveurl' =>
        (
            new moodle_url('/local/ustar/executive.php')
        )->out(false),

    'hasteam' =>
        has_capability(
            'local/ustar:viewteam',
            $context
        ),

    'hasexecutive' =>
        has_capability(
            'local/ustar:executive',
            $context
        ),

    'bookicon' =>
        \local_ustar\ui::icon(
            'book',
            'u-feature-icon'
        ),

    'knowledgeicon' =>
        \local_ustar\ui::icon(
            'knowledge',
            'u-feature-icon'
        ),

    'sparkicon' =>
        \local_ustar\ui::icon(
            'spark',
            'u-feature-icon'
        ),

    'profileicon' =>
        \local_ustar\ui::icon(
            'profile',
            'u-feature-icon'
        ),

    'gameicon' =>
        \local_ustar\ui::icon(
            'game',
            'u-feature-icon'
        ),

    'trophyicon' =>
        \local_ustar\ui::icon(
            'trophy',
            'u-feature-icon'
        ),

    'checkicon' =>
        \local_ustar\ui::icon(
            'check',
            'u-feature-icon'
        ),

    'teamicon' =>
        \local_ustar\ui::icon(
            'team',
            'u-feature-icon'
        ),

    'executiveicon' =>
        \local_ustar\ui::icon(
            'executive',
            'u-feature-icon'
        ),


    'viewhome' =>
        $view === 'home',

    'viewlearning' =>
        $view === 'learning',

    'guidedlearning' =>
        $guidedlearning,

    'guidedlearninghtml' =>
        $guidedlearninghtml,

    'guidedcourseid' =>
        $courseid,

    'viewknowledge' =>
        $view === 'knowledge',

    'viewcareer' =>
        $view === 'career',

    'development' =>
        $development,


    /*
     * Home.
     */

    'homenext' =>
        $homenext,

    'hashomenext' =>
        $homenext !== null,


    /*
     * Learning.
     */

    'learningcount' =>
        count(
            $learningcourses
        ),

    'activecount' =>
        count(
            $activecourses
        ),

    'newcount' =>
        count(
            $newcourses
        ),

    'donecount' =>
        count(
            $donecourses
        ),


    'haslearning' =>
        !empty(
            $learningcourses
        ),

    'hasactive' =>
        !empty(
            $activecourses
        ),

    'hasnew' =>
        !empty(
            $newcourses
        ),

    'hasdone' =>
        !empty(
            $donecourses
        ),


    'nextcourse' =>
        $nextcourse,

    'hasnextcourse' =>
        $nextcourse !== null,


    'activecourses' =>
        $activecourses,

    'newcourses' =>
        $newcourses,

    'donecourses' =>
        $donecourses,
];


echo $output->header();


echo $output->render_from_template(
    'local_ustar/home',
    $data
);


echo $output->footer();
