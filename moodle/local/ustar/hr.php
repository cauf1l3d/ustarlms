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


$query =
    trim(
        optional_param(
            'q',
            '',
            PARAM_TEXT
        )
    );

$departmentfilter =
    optional_param(
        'department',
        '',
        PARAM_ALPHANUMEXT
    );

$positionfilter =
    optional_param(
        'position',
        '',
        PARAM_ALPHANUMEXT
    );

$statusfilter =
    optional_param(
        'status',
        'active',
        PARAM_ALPHA
    );

$userid =
    optional_param(
        'userid',
        0,
        PARAM_INT
    );

$newperson =
    optional_param(
        'new',
        0,
        PARAM_BOOL
    );


/*
 * ------------------------------------------------------------
 * SAVE PERSON
 * ------------------------------------------------------------
 */

if (
    optional_param(
        'action',
        '',
        PARAM_ALPHA
    ) === 'saveperson'
) {

    require_sesskey();

    try {

        $result =
            \local_ustar\hr_people::save(
                [
                    'userid' =>
                        optional_param(
                            'userid',
                            0,
                            PARAM_INT
                        ),

                    'username' =>
                        required_param(
                            'username',
                            PARAM_USERNAME
                        ),

                    'firstname' =>
                        required_param(
                            'firstname',
                            PARAM_NOTAGS
                        ),

                    'lastname' =>
                        required_param(
                            'lastname',
                            PARAM_NOTAGS
                        ),

                    'email' =>
                        required_param(
                            'email',
                            PARAM_EMAIL
                        ),

                    'positionid' =>
                        optional_param(
                            'positionid',
                            '',
                            PARAM_ALPHANUMEXT
                        ),

                    'accounttype' =>
                        optional_param(
                            'accounttype',
                            \local_ustar\accounts::TYPE_EMPLOYEE,
                            PARAM_ALPHANUMEXT
                        ),

                    'suspended' =>
                        optional_param(
                            'suspended',
                            0,
                            PARAM_BOOL
                        ),

                    'password' =>
                        optional_param(
                            'password',
                            '',
                            PARAM_RAW
                        ),
                ],
                (int)$USER->id
            );


        redirect(
            new moodle_url(
                '/local/ustar/hr.php',
                [
                    'userid' =>
                        $result['userid'],
                ]
            ),
            'Сотрудник сохранён',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );

    } catch (\Throwable $e) {

        \core\notification::error(
            $e->getMessage()
        );
    }
}


/*
 * ------------------------------------------------------------
 * STRUCTURE
 * ------------------------------------------------------------
 */

$structure =
    \local_ustar\structure::get(
        \local_ustar\structure::NAME_STRUCTURE
    );


$positionmap = [];

foreach (
    $structure['positions'] ?? []
    as $position
) {
    $positionmap[
        $position['id']
    ] = $position;
}


$departmentmap = [];

foreach (
    $structure['departments'] ?? []
    as $department
) {
    $departmentmap[
        $department['id']
    ] = $department;
}


/*
 * ------------------------------------------------------------
 * PEOPLE LIST
 * ------------------------------------------------------------
 */

$where = [
    'u.deleted = 0',
    'u.id > 1',
];

$params = [];


if ($statusfilter === 'active') {
    $where[] =
        'u.suspended = 0';
}

if ($statusfilter === 'suspended') {
    $where[] =
        'u.suspended = 1';
}


if ($query !== '') {

    $like =
        '%'
        .
        $DB->sql_like_escape(
            $query
        )
        .
        '%';

    $where[] =
        '('
        .
        $DB->sql_like(
            'u.firstname',
            ':q1',
            false
        )
        .
        ' OR '
        .
        $DB->sql_like(
            'u.lastname',
            ':q2',
            false
        )
        .
        ' OR '
        .
        $DB->sql_like(
            'u.username',
            ':q3',
            false
        )
        .
        ' OR '
        .
        $DB->sql_like(
            'u.email',
            ':q4',
            false
        )
        .
        ')';

    $params += [
        'q1' => $like,
        'q2' => $like,
        'q3' => $like,
        'q4' => $like,
    ];
}


$sql = "
    SELECT
        u.id,
        u.username,
        u.firstname,
        u.lastname,
        u.email,
        u.suspended,
        u.lastaccess,
        TRIM(d.data) AS positionid
    FROM {user} u
    LEFT JOIN {user_info_field} f
      ON f.shortname = 'ustar_position'
    LEFT JOIN {user_info_data} d
      ON d.userid = u.id
     AND d.fieldid = f.id
    WHERE "
    .
    implode(
        ' AND ',
        $where
    )
    .
    "
    ORDER BY
        u.lastname,
        u.firstname
";


$records =
    $DB->get_records_sql(
        $sql,
        $params,
        0,
        500
    );


$people = [];


foreach ($records as $person) {

    $positionid =
        trim(
            (string)$person->positionid
        );

    $position =
        $positionmap[
            $positionid
        ] ?? null;


    if (
        $departmentfilter !== ''
        &&
        (
            !$position
            ||
            ($position['department'] ?? '')
                !==
                $departmentfilter
        )
    ) {
        continue;
    }


    if (
        $positionfilter !== ''
        &&
        $positionid !== $positionfilter
    ) {
        continue;
    }


    $department =
        $position
            ? (
                $departmentmap[
                    $position['department']
                ] ?? null
            )
            : null;


    $protected =
        is_siteadmin($person)
        ||
        has_capability(
            'local/ustar:admin',
            $context,
            $person->id
        );


    $accounttype =
        \local_ustar\accounts::type_of(
            (int)$person->id
        );

    $accounttypelabels =
        \local_ustar\accounts::labels();


    $people[] = [

        'id' =>
            (int)$person->id,

        'fullname' =>
            fullname($person),

        'email' =>
            $person->email,

        'position' =>
            $position[
                'name'
            ] ?? 'Должность не назначена',

        'department' =>
            $department[
                'name'
            ] ?? '',

        'hasdepartment' =>
            $department !== null,

        'suspended' =>
            !empty($person->suspended),

        'active' =>
            empty($person->suspended),

        'protected' =>
            $protected,

        'accounttype' =>
            $accounttype,

        'accounttypelabel' =>
            $accounttypelabels[$accounttype]
            ?? $accounttype,

        'isemployee' =>
            $accounttype
            ===
            \local_ustar\accounts::TYPE_EMPLOYEE,

        'selected' =>
            !$newperson
            &&
            (int)$person->id
                ===
                $userid,

        'url' =>
            (
                new moodle_url(
                    '/local/ustar/hr.php',
                    [
                        'userid' =>
                            (int)$person->id,

                        'q' =>
                            $query,

                        'department' =>
                            $departmentfilter,

                        'position' =>
                            $positionfilter,

                        'status' =>
                            $statusfilter,
                    ]
                )
            )->out(false),
    ];
}


/*
 * ------------------------------------------------------------
 * FILTER OPTIONS
 * ------------------------------------------------------------
 */

$departmentoptions = [
    [
        'id' => '',
        'name' => 'Все подразделения',
        'selected' =>
            $departmentfilter === '',
    ],
];

foreach (
    $structure['departments'] ?? []
    as $department
) {

    $departmentoptions[] = [
        'id' =>
            $department['id'],

        'name' =>
            $department['name'],

        'selected' =>
            $departmentfilter
                ===
                $department['id'],
    ];
}


$positionfilteroptions = [
    [
        'id' => '',
        'name' => 'Все должности',
        'selected' =>
            $positionfilter === '',
    ],
];

foreach (
    $structure['positions'] ?? []
    as $position
) {

    $department =
        $departmentmap[
            $position['department']
        ] ?? null;

    $positionfilteroptions[] = [
        'id' =>
            $position['id'],

        'name' =>
            (
                $department[
                    'name'
                ] ?? $position['department']
            )
            .
            ' — '
            .
            $position['name'],

        'selected' =>
            $positionfilter
                ===
                $position['id'],
    ];
}


$statusoptions = [
    [
        'id' => 'active',
        'name' => 'Активные',
        'selected' =>
            $statusfilter === 'active',
    ],
    [
        'id' => 'suspended',
        'name' => 'Приостановленные',
        'selected' =>
            $statusfilter === 'suspended',
    ],
    [
        'id' => 'all',
        'name' => 'Все',
        'selected' =>
            $statusfilter === 'all',
    ],
];


/*
 * ------------------------------------------------------------
 * EDITOR / PERSON DETAIL
 * ------------------------------------------------------------
 */

$editor = null;


if (
    $newperson
    ||
    $userid > 0
) {

    $isedit =
        !$newperson
        &&
        $userid > 0;


    if ($isedit) {

        $edituser =
            $DB->get_record(
                'user',
                [
                    'id' =>
                        $userid,

                    'deleted' =>
                        0,
                ],
                '*',
                MUST_EXIST
            );


        $editpositionid =
            \local_ustar\people::position_id(
                $userid
            );


        $protected =
            is_siteadmin($edituser)
            ||
            (int)$edituser->id
                ===
                (int)$USER->id
            ||
            has_capability(
                'local/ustar:admin',
                $context,
                $edituser->id
            );


        $assignmentplan =
            \local_ustar\assignment::plan_user(
                $userid
            );


        $usercourses =
            \local_ustar\external\base::user_courses(
                $userid
            );


        $courseprogress = [];

        foreach (
            $usercourses
            as $course
        ) {
            $courseprogress[
                (int)$course['id']
            ] = $course;
        }


        $assignmentcourses = [];

        foreach (
            $assignmentplan[
                'required'
            ] ?? []
            as $course
        ) {

            $live =
                $courseprogress[
                    (int)$course['id']
                ] ?? [];


            $next =
                $live[
                    'nextActivity'
                ] ?? null;


            $assignmentcourses[] = [

                'id' =>
                    (int)$course['id'],

                'name' =>
                    $course['name'],

                'published' =>
                    !empty($course['visible']),

                'draft' =>
                    empty($course['visible']),

                'progress' =>
                    (int)(
                        $live[
                            'progress'
                        ] ?? 0
                    ),

                'nextname' =>
                    $next[
                        'name'
                    ] ?? '',

                'hasnext' =>
                    !empty(
                        $next[
                            'name'
                        ]
                    ),

                'pathurl' =>
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
         * HR action history.
         */
        $history = [];

        if (
            $DB->get_manager()
                ->table_exists(
                    new xmldb_table(
                        'local_ustar_hr_actions'
                    )
                )
        ) {

            $actionlabels = [
                'person_created' =>
                    'Сотрудник создан',

                'person_updated' =>
                    'Профиль изменён',

                'person_imported' =>
                    'Сотрудник импортирован',

                'bulk_position_updated' =>
                    'Должность изменена импортом',

                'position_bulk_assigned' =>
                    'Должность назначена',

                'assignment_synced' =>
                    'Обучение синхронизировано',

                'assignment_sync_failed' =>
                    'Ошибка синхронизации обучения',
            ];


            foreach (
                $DB->get_records(
                    'local_ustar_hr_actions',
                    [
                        'targetuserid' =>
                            $userid,
                    ],
                    'timecreated DESC',
                    '*',
                    0,
                    12
                )
                as $action
            ) {

                $history[] = [
                    'label' =>
                        $actionlabels[
                            $action->action
                        ]
                        ??
                        $action->action,

                    'time' =>
                        userdate(
                            $action->timecreated,
                            '%d.%m.%Y %H:%M'
                        ),
                ];
            }
        }


        $editoruser = [
            'userid' =>
                (int)$edituser->id,

            'username' =>
                $edituser->username,

            'firstname' =>
                $edituser->firstname,

            'lastname' =>
                $edituser->lastname,

            'email' =>
                $edituser->email,

            'suspended' =>
                !empty(
                    $edituser->suspended
                ),
        ];



        $editaccounttype =
            \local_ustar\accounts::type_of(
                $userid
            );

        $profile =
            \local_ustar\employee_profile::build(
                $userid
            );

        foreach ($profile['knowledge']['items'] as &$knowledgeitem) {
            $knowledgeitem['acktimeformatted'] =
                !empty($knowledgeitem['acktime'])
                    ? userdate(
                        (int)$knowledgeitem['acktime'],
                        '%d.%m.%Y %H:%M'
                    )
                    : '';
            $knowledgeitem['hasacktime'] =
                $knowledgeitem['acktimeformatted'] !== '';
        }
        unset($knowledgeitem);

    } else {

        $editpositionid = '';
        $protected = false;
        $assignmentcourses = [];
        $history = [];
        $editaccounttype =
            \local_ustar\accounts::TYPE_EMPLOYEE;
        $profile = null;

        $editoruser = [
            'userid' => 0,
            'username' => '',
            'firstname' => '',
            'lastname' => '',
            'email' => '',
            'suspended' => false,
        ];
    }


    $positionoptions = [
        [
            'id' => '',
            'name' => 'Без должности',
            'selected' =>
                $editpositionid === '',
        ],
    ];


    foreach (
        $structure['positions'] ?? []
        as $position
    ) {

        $department =
            $departmentmap[
                $position['department']
            ] ?? null;


        $positionoptions[] = [
            'id' =>
                $position['id'],

            'name' =>
                (
                    $department[
                        'name'
                    ]
                    ??
                    $position['department']
                )
                .
                ' — '
                .
                $position['name'],

            'selected' =>
                $editpositionid
                    ===
                    $position['id'],
        ];
    }


    $accounttypeoptions = [];

    foreach (
        \local_ustar\accounts::labels()
        as $typeid => $typelabel
    ) {
        $accounttypeoptions[] = [
            'id' => $typeid,
            'name' => $typelabel,
            'selected' =>
                $editaccounttype === $typeid,
        ];
    }


    $editor = [

        'isnew' =>
            !$isedit,

        'isedit' =>
            $isedit,

        'protected' =>
            $protected,

        'canedit' =>
            !$protected
            &&
            has_capability(
                'local/ustar:hrmanage',
                $context
            ),

        'user' =>
            $editoruser,

        'positionoptions' =>
            $positionoptions,

        'accounttypeoptions' =>
            $accounttypeoptions,

        'profile' =>
            $profile,

        'hasprofile' =>
            $profile !== null,

        'assignmentcourses' =>
            $assignmentcourses,

        'hasassignments' =>
            !empty(
                $assignmentcourses
            ),

        'history' =>
            $history,

        'hashistory' =>
            !empty(
                $history
            ),

        'sesskey' =>
            sesskey(),
    ];
}


/*
 * ------------------------------------------------------------
 * PAGE
 * ------------------------------------------------------------
 */

$PAGE->set_context(
    $context
);

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/hr.php'
    )
);

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Сотрудники | Центр управления USTAR'
);

$PAGE->set_heading(
    'Центр управления USTAR'
);


$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


$data = [

    'people' =>
        $people,

    'peoplecount' =>
        count($people),

    'haspeople' =>
        !empty($people),

    'query' =>
        $query,

    'departmentoptions' =>
        $departmentoptions,

    'positionoptions' =>
        $positionfilteroptions,

    'statusoptions' =>
        $statusoptions,

    'newurl' =>
        (
            new moodle_url(
                '/local/ustar/hr.php',
                ['new' => 1]
            )
        )->out(false),

    'editor' =>
        $editor,

    'haseditor' =>
        $editor !== null,
];


echo $output->header();

echo $output->render_from_template(
    'local_ustar/hr_people',
    $data
);

echo $output->footer();
