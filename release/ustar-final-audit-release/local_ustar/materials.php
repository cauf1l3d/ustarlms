<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;

$context = context_system::instance();

require_capability(
    'local/ustar:hr',
    $context
);


$canmanage =
    is_siteadmin((int)$USER->id)
    ||
    has_capability(
        'local/ustar:hrmanage',
        $context
    )
    ||
    has_capability(
        'local/ustar:admin',
        $context
    );


/*
 * ------------------------------------------------------------
 * FILTERS
 * ------------------------------------------------------------
 */

$q = trim(
    optional_param(
        'q',
        '',
        PARAM_TEXT
    )
);

$type = optional_param(
    'type',
    'all',
    PARAM_ALPHANUMEXT
);

$status = optional_param(
    'status',
    'all',
    PARAM_ALPHANUMEXT
);

$contentid = optional_param(
    'contentid',
    0,
    PARAM_INT
);

$parentid = optional_param(
    'parent',
    0,
    PARAM_INT
);

if ($parentid > 0) {
    $parentrecord = $DB->get_record('local_ustar_content', ['id' => $parentid, 'type' => 'folder'], 'id,parentid,title,type');
    if (!$parentrecord) {
        $parentid = 0;
    }
}


$typelabels = [
    'folder' => 'Папка',
    'document' => 'Документ',
    'article' => 'Статья',
    'quiz' => 'Тест',
    'scorm' => 'SCORM',
    'lesson' => 'Урок',
    'forum' => 'Обсуждение',
    'database' => 'База данных',
    'collection' => 'Коллекция',
    'link' => 'Ссылка',
    'assignment' => 'Задание',
    'interactive' => 'Интерактив',
    'activity' => 'Материал',
];


$statuslabels = [
    'draft' => 'Черновик',
    'published' => 'Опубликован',
    'archived' => 'Архив',
];



/*
 * ------------------------------------------------------------
 * CONTENT EDITOR ACTIONS
 * ------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    require_sesskey();

    if (!$canmanage) {
        throw new required_capability_exception(
            $context,
            'local/ustar:hrmanage',
            'nopermissions',
            ''
        );
    }


    \local_ustar\view_as::assert_writable();

    $action =
        required_param(
            'action',
            PARAM_ALPHANUMEXT
        );

    try {

        if ($action === 'createfolder') {
            $foldertitle = trim(required_param('foldertitle', PARAM_TEXT));
            $folderparent = optional_param('parent', 0, PARAM_INT);

            if ($foldertitle === '') {
                throw new invalid_parameter_exception('Название папки обязательно');
            }
            if ($folderparent > 0 && !$DB->record_exists('local_ustar_content', ['id' => $folderparent, 'type' => 'folder'])) {
                throw new invalid_parameter_exception('Родительская папка не найдена');
            }

            $now = time();
            $newfolderid = $DB->insert_record('local_ustar_content', (object)[
                'parentid' => $folderparent > 0 ? $folderparent : null,
                'type' => 'folder',
                'title' => $foldertitle,
                'summary' => null,
                'category' => null,
                'status' => 'published',
                'sourcekind' => 'folder',
                'courseid' => null,
                'cmid' => null,
                'externalurl' => null,
                'owneruserid' => (int)$USER->id,
                'ackrequired' => 0,
                'publishedat' => $now,
                'sortorder' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int)$USER->id,
            ]);

            redirect(
                new moodle_url('/local/ustar/materials.php', ['parent' => $newfolderid]),
                'Папка создана',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }

        $postedcontentid =
            required_param(
                'contentid',
                PARAM_INT
            );

        if ($action === 'move') {
            $targetparentid = optional_param('targetparentid', 0, PARAM_INT);
            $expectedmodified = required_param('expectedmodified', PARAM_INT);
            $moveresult = \local_ustar\content_admin::move(
                $postedcontentid,
                $targetparentid,
                $expectedmodified,
                (int)$USER->id
            );
            redirect(
                new moodle_url('/local/ustar/materials.php', ['parent' => $targetparentid]),
                $moveresult['changed']
                    ? 'Объект перемещён'
                    : 'Объект уже находится в выбранной папке',
                null,
                $moveresult['changed']
                    ? \core\output\notification::NOTIFY_SUCCESS
                    : \core\output\notification::NOTIFY_INFO
            );
        }

        if ($action === 'save') {

            \local_ustar\content_admin::save(
                $postedcontentid,
                [
                    'title' =>
                        required_param(
                            'title',
                            PARAM_TEXT
                        ),

                    'summary' =>
                        optional_param(
                            'summary',
                            '',
                            PARAM_TEXT
                        ),

                    'category' =>
                        optional_param(
                            'category',
                            '',
                            PARAM_ALPHANUMEXT
                        ),

                    'ackrequired' =>
                        optional_param(
                            'ackrequired',
                            0,
                            PARAM_BOOL
                        ),

                    'parentid' =>
                        optional_param(
                            'parentid',
                            0,
                            PARAM_INT
                        ),

                    'expectedmodified' =>
                        required_param(
                            'expectedmodified',
                            PARAM_INT
                        ),

                    'accessmode' =>
                        optional_param(
                            'accessmode',
                            'custom',
                            PARAM_ALPHA
                        ),

                    'positions' =>
                        optional_param_array(
                            'positions',
                            [],
                            PARAM_ALPHANUMEXT
                        ),

                    'departments' =>
                        optional_param_array(
                            'departments',
                            [],
                            PARAM_ALPHANUMEXT
                        ),
                ],
                (int)$USER->id
            );


            $returnparams = [
                'contentid' =>
                    $postedcontentid,

                'type' =>
                    $type,

                'status' =>
                    $status,

                'parent' =>
                    $parentid,
            ];

            if ($q !== '') {
                $returnparams['q'] = $q;
            }


            redirect(
                new moodle_url(
                    '/local/ustar/materials.php',
                    $returnparams
                ),
                'Материал сохранён',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        if ($action === 'publish') {

            \local_ustar\content_admin::publish(
                $postedcontentid,
                (int)$USER->id
            );


            redirect(
                new moodle_url(
                    '/local/ustar/materials.php',
                    [
                        'contentid' =>
                            $postedcontentid,

                        'status' =>
                            'all',
                    ]
                ),
                'Материал опубликован',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        if ($action === 'unpublish') {

            \local_ustar\content_admin::unpublish(
                $postedcontentid,
                (int)$USER->id
            );


            redirect(
                new moodle_url(
                    '/local/ustar/materials.php',
                    [
                        'contentid' =>
                            $postedcontentid,

                        'status' =>
                            'all',
                    ]
                ),
                'Материал возвращён в черновики',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        if ($action === 'archive') {

            \local_ustar\content_admin::archive(
                $postedcontentid,
                (int)$USER->id
            );

            redirect(
                new moodle_url(
                    '/local/ustar/materials.php',
                    [
                        'contentid' => $postedcontentid,
                        'status' => 'all',
                    ]
                ),
                'Материал перемещён в архив',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        if ($action === 'restore') {

            \local_ustar\content_admin::restore_archived(
                $postedcontentid,
                (int)$USER->id
            );

            redirect(
                new moodle_url(
                    '/local/ustar/materials.php',
                    [
                        'contentid' => $postedcontentid,
                        'status' => 'all',
                    ]
                ),
                'Материал восстановлен и опубликован',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }


        throw new invalid_parameter_exception(
            'Неизвестное действие'
        );

    } catch (\Throwable $e) {

        \core\notification::error(
            $e->getMessage()
        );

        $contentid =
            $postedcontentid;
    }
}


/*
 * ------------------------------------------------------------
 * GLOBAL COUNTS
 * ------------------------------------------------------------
 */

$total =
    $DB->count_records(
        'local_ustar_content'
    );


$typecounts = [];

$sql = "
    SELECT
        type,
        COUNT(*) AS itemcount
      FROM {local_ustar_content}
     GROUP BY type
";

foreach ($DB->get_records_sql($sql) as $row) {
    $typecounts[$row->type] =
        (int)$row->itemcount;
}


/*
 * Duplicate detection is deliberately conservative:
 *
 * same normalized title + same USTAR type.
 *
 * We do NOT delete or merge anything automatically.
 */

$duplicatecounts = [];

$alltitles =
    $DB->get_records(
        'local_ustar_content',
        null,
        '',
        'id,type,title'
    );


foreach ($alltitles as $item) {

    $normalized =
        preg_replace(
            '/\s+/u',
            ' ',
            trim(
                core_text::strtolower(
                    $item->title
                )
            )
        );

    $key =
        $item->type
        .
        '|'
        .
        $normalized;

    $duplicatecounts[$key] =
        ($duplicatecounts[$key] ?? 0)
        + 1;
}


/*
 * ------------------------------------------------------------
 * FILTERED CATALOG
 * ------------------------------------------------------------
 */

$where = [];
$params = [];


if (
    $type !== 'all'
    &&
    isset($typelabels[$type])
) {

    $where[] =
        'uc.type = :type';

    $params['type'] =
        $type;
}


if (
    $status !== 'all'
    &&
    isset($statuslabels[$status])
) {

    $where[] =
        'uc.status = :status';

    $params['status'] =
        $status;
}


if ($parentid > 0) {
    $where[] = 'uc.parentid = :parentid';
    $params['parentid'] = $parentid;
} else {
    $where[] = 'uc.parentid IS NULL';
}


if ($q !== '') {

    $like =
        '%'
        .
        $DB->sql_like_escape($q)
        .
        '%';

    $where[] = '
        (
            '
            .
            $DB->sql_like(
                'uc.title',
                ':qtitle',
                false
            )
            .
            '
            OR
            '
            .
            $DB->sql_like(
                'uc.summary',
                ':qsummary',
                false
            )
            .
            '
            OR
            '
            .
            $DB->sql_like(
                'cr.fullname',
                ':qcourse',
                false
            )
            .
            '
        )
    ';

    $params['qtitle'] = $like;
    $params['qsummary'] = $like;
    $params['qcourse'] = $like;
}


$wheresql =
    $where
        ? 'WHERE ' . implode(' AND ', $where)
        : '';


$sql = "
    SELECT
        uc.*,

        cm.visible AS cmvisible,

        m.name AS modname,

        cr.fullname AS coursename,
        cr.shortname AS courseshortname,
        cr.visible AS coursevisible

      FROM {local_ustar_content} uc

 LEFT JOIN {course_modules} cm
        ON cm.id = uc.cmid

 LEFT JOIN {modules} m
        ON m.id = cm.module

 LEFT JOIN {course} cr
        ON cr.id = uc.courseid

        {$wheresql}

  ORDER BY
        CASE uc.status
            WHEN 'published' THEN 1
            WHEN 'draft' THEN 2
            ELSE 3
        END,
        uc.title ASC,
        uc.id ASC
";


$records =
    $DB->get_records_sql(
        $sql,
        $params
    );


/*
 * ------------------------------------------------------------
 * TYPE FILTERS
 * ------------------------------------------------------------
 */

$typefilters = [
    [
        'id' => 'all',
        'label' => 'Все',
        'count' => $total,
        'selected' => $type === 'all',
        'url' => (
            new moodle_url(
                '/local/ustar/materials.php',
                [
                    'q' => $q,
                    'status' => $status,
                    'parent' => $parentid,
                ]
            )
        )->out(false),
    ],
];


foreach ($typecounts as $typeid => $count) {

    $typefilters[] = [
        'id' => $typeid,

        'label' =>
            $typelabels[$typeid]
            ?? ucfirst($typeid),

        'count' => $count,

        'selected' =>
            $type === $typeid,

        'url' => (
            new moodle_url(
                '/local/ustar/materials.php',
                [
                    'q' => $q,
                    'type' => $typeid,
                    'status' => $status,
                    'parent' => $parentid,
                ]
            )
        )->out(false),
    ];
}


/*
 * ------------------------------------------------------------
 * CATALOG ROWS
 * ------------------------------------------------------------
 */

$rows = [];

$selectedrecord = null;

$movefolders = [[
    'id' => 0,
    'name' => 'Корень',
]];
foreach ($DB->get_records('local_ustar_content', ['type' => 'folder'], 'title ASC', 'id,title') as $folder) {
    $movefolders[] = [
        'id' => (int)$folder->id,
        'name' => format_string($folder->title),
    ];
}


foreach ($records as $record) {

    $normalized =
        preg_replace(
            '/\s+/u',
            ' ',
            trim(
                core_text::strtolower(
                    $record->title
                )
            )
        );

    $duplicatekey =
        $record->type
        .
        '|'
        .
        $normalized;


    $isduplicate =
        ($duplicatecounts[$duplicatekey] ?? 0)
        > 1;


    $coursehidden =
        $record->sourcekind === 'moodle_cm'
        &&
        isset($record->coursevisible)
        &&
        empty($record->coursevisible);


    $activityhidden =
        $record->sourcekind === 'moodle_cm'
        &&
        isset($record->cmvisible)
        &&
        empty($record->cmvisible);


    $isfolder = (string)$record->type === 'folder';

    $urlparams = [
        'type' => $type,
        'status' => $status,
    ];

    if ($isfolder) {
        $urlparams['parent'] = (int)$record->id;
    } else {
        $urlparams['contentid'] = (int)$record->id;
        $urlparams['parent'] = $parentid;
    }

    if ($q !== '') {
        $urlparams['q'] = $q;
    }


    $row = [

        'id' =>
            (int)$record->id,

        'timemodified' =>
            (int)$record->timemodified,

        'canmove' =>
            $canmanage,

        'folderoptions' =>
            array_values(array_filter(
                $movefolders,
                static fn(array $folder): bool =>
                    (int)$folder['id'] !== (int)$record->id
                    && (int)$folder['id'] !== $parentid
            )),

        'title' =>
            format_string(
                $record->title
            ),

        'type' =>
            $record->type,

        'isfolder' =>
            $isfolder,

        'typelabel' =>
            $typelabels[$record->type]
            ?? ucfirst($record->type),

        'status' =>
            $record->status,

        'statuslabel' =>
            $statuslabels[$record->status]
            ?? $record->status,

        'isdraft' =>
            $record->status === 'draft',

        'ispublished' =>
            $record->status === 'published',

        'isarchived' =>
            $record->status === 'archived',

        'sourcekind' =>
            $record->sourcekind,

        'ismoodle' =>
            $record->sourcekind === 'moodle_cm',

        'coursename' =>
            format_string(
                (string)$record->coursename
            ),

        'modname' =>
            strtoupper(
                (string)$record->modname
            ),

        'coursehidden' =>
            $coursehidden,

        'activityhidden' =>
            $activityhidden,

        'duplicate' =>
            $isduplicate,

        'needsreview' =>
            $coursehidden
            ||
            $activityhidden
            ||
            $isduplicate,

        'selected' =>
            !$isfolder && (int)$record->id === $contentid,

        'url' =>
            (
                new moodle_url(
                    '/local/ustar/materials.php',
                    $urlparams
                )
            )->out(false),
    ];


    $rows[] = $row;


    if (
        !$isfolder
        && (int)$record->id === $contentid
    ) {
        $selectedrecord = $record;
    }
}


/*
 * ------------------------------------------------------------
 * DETAIL VIEW (opened deliberately by selecting a material)
 * ------------------------------------------------------------
 */

$detail = null;


if ($selectedrecord) {

    $version =
        \local_ustar\content::current_version(
            (int)$selectedrecord->id
        );



    /*
     * Version history / pending draft.
     */
    $versionrecords =
        $DB->get_records(
            'local_ustar_content_versions',
            [
                'contentid' =>
                    $selectedrecord->id,
            ],
            'versionno DESC, id DESC'
        );


    $versionhistory = [];
    $draftversion = null;

    $versioncreators = [];
    $creatorids = array_values(array_unique(array_filter(array_map(
        static fn($item): int => (int)$item->createdby,
        $versionrecords
    ))));

    if ($creatorids) {
        foreach ($DB->get_records_list(
            'user',
            'id',
            $creatorids,
            '',
            'id,firstname,lastname'
        ) as $creator) {
            $versioncreators[(int)$creator->id] = fullname($creator);
        }
    }

    $versionfs = get_file_storage();
    $versionsystemcontext = context_system::instance();


    foreach ($versionrecords as $itemversion) {

        if (
            empty($itemversion->iscurrent)
            &&
            $itemversion->status === 'draft'
            &&
            !$draftversion
        ) {
            $draftversion =
                $itemversion;
        }


        $versionfileurl = '';
        $versionfilename = '';

        if ($selectedrecord->sourcekind === \local_ustar\content::SOURCE_FILE) {
            $versionfiles = $versionfs->get_area_files(
                $versionsystemcontext->id,
                'local_ustar',
                'content_version',
                (int)$itemversion->id,
                'sortorder DESC, id ASC',
                false
            );

            if ($versionfiles) {
                $versionfile = reset($versionfiles);
                $versionfilename = $versionfile->get_filename();
                $versionfileurl = moodle_url::make_pluginfile_url(
                    $versionsystemcontext->id,
                    'local_ustar',
                    'content_version',
                    (int)$itemversion->id,
                    $versionfile->get_filepath(),
                    $versionfile->get_filename(),
                    true
                )->out(false);
            }
        }


        $versionhistory[] = [
            'id' =>
                (int)$itemversion->id,

            'label' =>
                $itemversion->versionlabel
                ?: 'v'
                    .
                    $itemversion->versionno,

            'statuslabel' =>
                $statuslabels[
                    $itemversion->status
                ]
                ??
                $itemversion->status,

            'current' =>
                !empty(
                    $itemversion->iscurrent
                ),

            'draft' =>
                $itemversion->status
                ===
                'draft',

            'published' =>
                $itemversion->status
                ===
                'published',

            'changenote' =>
                trim(
                    (string)$itemversion->changenote
                ),

            'haschangenote' =>
                trim(
                    (string)$itemversion->changenote
                ) !== '',

            'creator' =>
                $versioncreators[(int)$itemversion->createdby]
                ?? ('User #' . (int)$itemversion->createdby),

            'created' =>
                userdate(
                    (int)$itemversion->timecreated,
                    '%d.%m.%Y %H:%M'
                ),

            'hasfile' =>
                $versionfileurl !== '',

            'fileurl' =>
                $versionfileurl,

            'filename' =>
                $versionfilename,
        ];
    }


    $accesscount =
        $DB->count_records(
            'local_ustar_content_access',
            [
                'contentid' =>
                    $selectedrecord->id,

                'active' =>
                    1,
            ]
        );


    $openurl =
        \local_ustar\content::open_url(
            (int)$selectedrecord->id,
            (int)$USER->id
        );


    $normalized =
        preg_replace(
            '/\s+/u',
            ' ',
            trim(
                core_text::strtolower(
                    $selectedrecord->title
                )
            )
        );

    $duplicatekey =
        $selectedrecord->type
        .
        '|'
        .
        $normalized;


    $coursehidden =
        $selectedrecord->sourcekind === 'moodle_cm'
        &&
        isset($selectedrecord->coursevisible)
        &&
        empty($selectedrecord->coursevisible);


    $activityhidden =
        $selectedrecord->sourcekind === 'moodle_cm'
        &&
        isset($selectedrecord->cmvisible)
        &&
        empty($selectedrecord->cmvisible);



    /*
     * Editable metadata / access model.
     */

    $categories =
        \local_ustar\content_admin::categories();


    $categoryoptions = [
        [
            'id' => '',
            'name' => 'Без категории',
            'selected' =>
                empty(
                    $selectedrecord->category
                ),
        ],
    ];


    foreach ($categories as $id => $name) {

        $categoryoptions[] = [
            'id' => $id,
            'name' => $name,
            'selected' =>
                (string)$selectedrecord->category
                ===
                (string)$id,
        ];
    }


    $folderoptions = [[
        'id' => 0,
        'name' => 'Корень',
        'selected' => empty($selectedrecord->parentid),
    ]];
    foreach ($DB->get_records('local_ustar_content', ['type' => 'folder'], 'title ASC', 'id,parentid,title') as $folder) {
        if ((int)$folder->id === (int)$selectedrecord->id) {
            continue;
        }
        $folderoptions[] = [
            'id' => (int)$folder->id,
            'name' => format_string($folder->title),
            'selected' => (int)$selectedrecord->parentid === (int)$folder->id,
        ];
    }


    $activeaccess =
        $DB->get_records(
            'local_ustar_content_access',
            [
                'contentid' =>
                    $selectedrecord->id,

                'active' =>
                    1,
            ]
        );


    $accessall = false;
    $selectedpositions = [];
    $selecteddepartments = [];


    foreach ($activeaccess as $rule) {

        if ($rule->scopetype === 'all') {

            $accessall = true;

        } else if (
            $rule->scopetype === 'position'
        ) {

            $selectedpositions[
                (string)$rule->scopeid
            ] = true;

        } else if (
            $rule->scopetype === 'department'
        ) {

            $selecteddepartments[
                (string)$rule->scopeid
            ] = true;
        }
    }


    $structure =
        \local_ustar\structure::get(
            \local_ustar\structure::NAME_STRUCTURE
        );


    $departmentoptions = [];

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
                isset(
                    $selecteddepartments[
                        $department['id']
                    ]
                ),
        ];
    }


    $positionoptions = [];

    $departmentnames = [];

    foreach (
        $structure['departments'] ?? []
        as $department
    ) {
        $departmentnames[
            $department['id']
        ] = $department['name'];
    }


    foreach (
        $structure['positions'] ?? []
        as $position
    ) {

        $positionoptions[] = [
            'id' =>
                $position['id'],

            'name' =>
                $position['name'],

            'department' =>
                $departmentnames[
                    $position['department']
                ]
                ??
                $position['department'],

            'selected' =>
                isset(
                    $selectedpositions[
                        $position['id']
                    ]
                ),
        ];
    }


    /*
     * ------------------------------------------------------------
     * USTAR_ACK_REPORT_MODEL
     * ------------------------------------------------------------
     *
     * Current compliance state for the selected material.
     * Audience comes from active access rules.
     * Acknowledgement is tied to the current version.
     */

    $ackreport = null;

    $ackpendingpeople = [];
    $ackdonepeople = [];


    if (
        !empty(
            $selectedrecord->ackrequired
        )
        &&
        $version
    ) {

        $ackreport =
            \local_ustar\content_ack_report::report(
                (int)$selectedrecord->id
            );


        foreach (
            $ackreport['people']
            as $person
        ) {

            $acktimeformatted = '';


            if (
                !empty(
                    $person['acktime']
                )
            ) {

                $acktimeformatted =
                    userdate(
                        (int)$person['acktime'],
                        get_string(
                            'strftimedatetimeshort',
                            'langconfig'
                        )
                    );
            }


            $department =
                trim(
                    (string)(
                        $person['department']
                        ?? ''
                    )
                );


            $row = [

                'userid' =>
                    (int)$person['userid'],

                'fullname' =>
                    (string)$person['fullname'],

                'username' =>
                    (string)$person['username'],

                'position' =>
                    (string)$person['position'],

                'department' =>
                    $department,

                'hasdepartment' =>
                    $department !== '',

                'acktimeformatted' =>
                    $acktimeformatted,

                'hasacktime' =>
                    $acktimeformatted !== '',
            ];


            if (
                !empty(
                    $person['acked']
                )
            ) {

                $ackdonepeople[] =
                    $row;

            } else {

                $ackpendingpeople[] =
                    $row;
            }
        }
    }


    $detail = [

        'id' =>
            (int)$selectedrecord->id,

        'timemodified' =>
            (int)$selectedrecord->timemodified,

        'title' =>
            format_string(
                $selectedrecord->title
            ),

        'summary' =>
            trim(
                (string)$selectedrecord->summary
            ),

        'hassummary' =>
            trim(
                (string)$selectedrecord->summary
            ) !== '',

        'typelabel' =>
            $typelabels[
                $selectedrecord->type
            ]
            ??
            ucfirst(
                $selectedrecord->type
            ),

        'statuslabel' =>
            $statuslabels[
                $selectedrecord->status
            ]
            ??
            $selectedrecord->status,

        'isdraft' =>
            $selectedrecord->status
            ===
            'draft',

        'ispublished' =>
            $selectedrecord->status
            ===
            'published',

        'isarchived' =>
            $selectedrecord->status
            ===
            'archived',

        'sourcekind' =>
            $selectedrecord->sourcekind,

        'ismoodle' =>
            $selectedrecord->sourcekind
            ===
            'moodle_cm',

        'coursename' =>
            format_string(
                (string)$selectedrecord->coursename
            ),

        'courseid' =>
            (int)$selectedrecord->courseid,

        'cmid' =>
            (int)$selectedrecord->cmid,

        'modname' =>
            strtoupper(
                (string)$selectedrecord->modname
            ),

        'coursehidden' =>
            $coursehidden,

        'activityhidden' =>
            $activityhidden,

        'duplicate' =>
            ($duplicatecounts[$duplicatekey] ?? 0)
            > 1,

        'accesscount' =>
            $accesscount,

        'noaccess' =>
            $accesscount === 0,

        'ackrequired' =>
            !empty(
                $selectedrecord->ackrequired
            ),

        'hasackreport' =>
            $ackreport !== null,

        'ackreportversion' =>
            $ackreport
                ? (string)$ackreport['versionlabel']
                : '',

        'ackcsvurl' =>
            $ackreport
                ? (new moodle_url(
                    '/local/ustar/material_ack_export.php',
                    [
                        'contentid' => (int)$selectedrecord->id,
                        'theme' => 'ustar',
                    ]
                ))->out(false)
                : '',

        'acktotal' =>
            $ackreport
                ? (int)$ackreport['total']
                : 0,

        'ackacknowledged' =>
            $ackreport
                ? (int)$ackreport['acknowledged']
                : 0,

        'ackpending' =>
            $ackreport
                ? (int)$ackreport['pending']
                : 0,

        'ackpercent' =>
            $ackreport
                ? (int)$ackreport['percent']
                : 0,

        'hasackpeople' =>
            $ackreport !== null
            &&
            (int)$ackreport['total'] > 0,

        'hasackpending' =>
            !empty(
                $ackpendingpeople
            ),

        'hasackdone' =>
            !empty(
                $ackdonepeople
            ),

        'ackpendingpeople' =>
            $ackpendingpeople,

        'ackdonepeople' =>
            $ackdonepeople,


        'versionlabel' =>
            $version
                ? (
                    $version->versionlabel
                    ?: 'v'
                        .
                        $version->versionno
                )
                : '—',

        'versionstatus' =>
            $version
                ? (
                    $statuslabels[
                        $version->status
                    ]
                    ??
                    $version->status
                )
                : '—',

        'isustarfile' =>
            $selectedrecord->sourcekind
            ===
            \local_ustar\content::SOURCE_FILE,

        'versionhistory' =>
            $versionhistory,

        'hasversions' =>
            !empty(
                $versionhistory
            ),

        'hasdraftversion' =>
            !empty(
                $draftversion
            ),

        'draftversionid' =>
            $draftversion
                ? (int)$draftversion->id
                : 0,

        'draftversionlabel' =>
            $draftversion
                ? (
                    $draftversion->versionlabel
                    ?: 'v'
                        .
                        $draftversion->versionno
                )
                : '',

        'cannewversion' =>
            $canmanage
            &&
            $selectedrecord->sourcekind
                ===
                \local_ustar\content::SOURCE_FILE
            &&
            $selectedrecord->status
                ===
                'published'
            &&
            !$draftversion,

        'newversionurl' =>
            (
                new moodle_url(
                    '/local/ustar/material_version.php',
                    [
                        'id' =>
                            (int)$selectedrecord->id,

                        'theme' =>
                            'ustar',
                    ]
                )
            )->out(false),

        'versionactionurl' =>
            (
                new moodle_url(
                    '/local/ustar/material_version_action.php'
                )
            )->out(false),

        'openurl' =>
            $openurl
                ? $openurl->out(false)
                : '',

        'canopen' =>
            (bool)$openurl,

        'canmanage' =>
            $canmanage,

        'sesskey' =>
            sesskey(),

        'categoryoptions' =>
            $categoryoptions,

        'folderoptions' =>
            $folderoptions,

        'departmentoptions' =>
            $departmentoptions,

        'positionoptions' =>
            $positionoptions,

        'accessall' =>
            $accessall,

        'accesscustom' =>
            !$accessall,

        'hasaccess' =>
            !empty($activeaccess),
    ];
}


/*
 * ------------------------------------------------------------
 * STATUS FILTER
 * ------------------------------------------------------------
 */

$statusoptions = [];

foreach (
    [
        'all' => 'Все статусы',
        'draft' => 'Черновики',
        'published' => 'Опубликованные',
        'archived' => 'Архив',
    ]
    as $id => $label
) {

    $statusoptions[] = [
        'id' => $id,
        'label' => $label,
        'selected' => $status === $id,
    ];
}


/*
 * ------------------------------------------------------------
 * FOLDER BREADCRUMBS
 * ------------------------------------------------------------
 */
$breadcrumbs = [];
$currentfoldertitle = 'Корень';
$parenturl = '';
if ($parentid > 0) {
    $cursor = $DB->get_record('local_ustar_content', ['id' => $parentid, 'type' => 'folder'], 'id,parentid,title');
    $chain = [];
    $guard = 0;
    while ($cursor && $guard++ < 50) {
        array_unshift($chain, $cursor);
        $next = (int)($cursor->parentid ?? 0);
        $cursor = $next > 0 ? $DB->get_record('local_ustar_content', ['id' => $next, 'type' => 'folder'], 'id,parentid,title') : false;
    }
    $lastbreadcrumb = count($chain) - 1;
    foreach ($chain as $index => $folder) {
        $breadcrumbs[] = [
            'id' => (int)$folder->id,
            'title' => format_string($folder->title),
            'url' => (new moodle_url('/local/ustar/materials.php', ['parent' => (int)$folder->id]))->out(false),
            'iscurrent' => $index === $lastbreadcrumb,
        ];
    }
    $current = end($chain);
    if ($current) {
        $currentfoldertitle = format_string($current->title);
        $up = (int)($current->parentid ?? 0);
        $parenturl = (new moodle_url('/local/ustar/materials.php', $up > 0 ? ['parent' => $up] : []))->out(false);
    }
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
        '/local/ustar/materials.php'
    )
);

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Материалы | Центр управления'
);

$PAGE->set_heading(
    'Центр управления'
);

$PAGE->requires->js_call_amd('local_ustar/materials', 'init');


$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


$data = [

    'canmanage' =>
        $canmanage,

    'currentfolder' => $currentfoldertitle,
    'currentparentid' => $parentid,
    'breadcrumbs' => $breadcrumbs,
    'hasparent' => $parentid > 0,
    'parenturl' => $parenturl,
    'sesskey' => sesskey(),

    'createurl' =>
        (
            new moodle_url(
                '/local/ustar/material_create.php',
                [
                    'theme' =>
                        'ustar',
                ]
            )
        )->out(false),


    'bulkurl' =>
        (
            new moodle_url(
                '/local/ustar/material_bulk.php',
                [
                    'theme' =>
                        'ustar',
                ]
            )
        )->out(false),

    'total' =>
        $total,

    'visiblecount' =>
        count($rows),

    'q' =>
        s($q),

    'currenttype' =>
        $type,

    'currentstatus' =>
        $status,

    'hasactivefilters' =>
        $q !== ''
        || $type !== 'all'
        || $status !== 'all',

    'reseturl' =>
        (
            new moodle_url(
                '/local/ustar/materials.php',
                $parentid > 0 ? ['parent' => $parentid] : []
            )
        )->out(false),

    'typefilters' =>
        $typefilters,

    'statusoptions' =>
        $statusoptions,

    'materials' =>
        $rows,

    'hasmaterials' =>
        !empty($rows),

    'detail' =>
        $detail,

    'hasdetail' =>
        !empty($detail),
];


echo $output->header();

echo $output->render_from_template(
    'local_ustar/materials',
    $data
);

echo $output->footer();
