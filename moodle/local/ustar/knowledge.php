<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context =
    context_system::instance();


/*
 * USTAR_KNOWLEDGE_ROLE_GUARD
 *
 * Employee Knowledge is not the administration catalog.
 *
 * Admin / HR normally work in Control Center -> Materials.
 * Explicit preview=1 is reserved for employee-view preview.
 */
$iselevated =
    \local_ustar\content::is_elevated(
        (int)$USER->id
    );

$preview =
    optional_param(
        'preview',
        0,
        PARAM_BOOL
    );


if (
    $iselevated
    &&
    !$preview
) {

    redirect(
        new moodle_url(
            '/local/ustar/materials.php',
            [
                'theme' =>
                    'ustar',
            ]
        )
    );
}


/*
 * Keep employee Knowledge inside the USTAR shell even if somebody
 * opens the route directly from an old Moodle page/bookmark.
 */
$requestedtheme =
    optional_param(
        'theme',
        '',
        PARAM_ALPHANUMEXT
    );


if (
    !$iselevated
    &&
    $requestedtheme !== 'ustar'
) {

    $params = [
        'view' =>
            'knowledge',

        'theme' =>
            'ustar',
    ];


    if (
        isset($_GET['q'])
        &&
        trim((string)$_GET['q']) !== ''
    ) {
        $params['q'] =
            clean_param(
                $_GET['q'],
                PARAM_TEXT
            );
    }


    if (
        isset($_GET['type'])
        &&
        trim((string)$_GET['type']) !== ''
    ) {
        $params['type'] =
            clean_param(
                $_GET['type'],
                PARAM_ALPHANUMEXT
            );
    }


    if (
        isset($_GET['category'])
        &&
        trim((string)$_GET['category']) !== ''
    ) {
        $params['category'] =
            clean_param(
                $_GET['category'],
                PARAM_ALPHANUMEXT
            );
    }


    redirect(
        new moodle_url(
            '/local/ustar/knowledge.php',
            $params
        )
    );
}


$q =
    trim(
        optional_param(
            'q',
            '',
            PARAM_TEXT
        )
    );


$type =
    optional_param(
        'type',
        'all',
        PARAM_ALPHANUMEXT
    );


$categoryfilter =
    optional_param(
        'category',
        'all',
        PARAM_ALPHANUMEXT
    );


/*
 * Content access is resolved by local_ustar\content:
 *
 * user
 *   -> position
 *   -> department
 *   -> published content access rules
 */
$all =
    \local_ustar\content::list_for_user(
        (int)$USER->id
    );

/*
 * Even elevated preview is an employee-catalog preview: drafts/archives
 * never belong in this screen. Control Center is the administrative view.
 */
$all = array_values(
    array_filter(
        $all,
        static fn(array $item): bool =>
            ($item['status'] ?? '')
            ===
            \local_ustar\content::STATUS_PUBLISHED
            &&
            !empty($item['versionid'])
    )
);


$typelabels = [

    'document' =>
        'Документы',

    'article' =>
        'Статьи',

    'video' =>
        'Видео',

    'quiz' =>
        'Тесты',

    'scorm' =>
        'Обучение',

    'lesson' =>
        'Уроки',

    'interactive' =>
        'Интерактив',

    'link' =>
        'Ссылки',

    'collection' =>
        'Коллекции',

    'forum' =>
        'Обсуждения',

    'database' =>
        'Базы данных',

    'assignment' =>
        'Задания',

    'activity' =>
        'Материалы',
];


$categorylabels =
    \local_ustar\content_admin::categories();


/*
 * ------------------------------------------------------------
 * FILTER COUNTS
 * ------------------------------------------------------------
 */

$typecounts = [];

foreach ($all as $item) {

    $itemtype =
        (string)$item['type'];

    $typecounts[$itemtype] =
        ($typecounts[$itemtype] ?? 0)
        + 1;
}


$categorycounts = [];

foreach ($all as $item) {
    $itemcategory = trim((string)($item['category'] ?? ''));
    if ($itemcategory === '') {
        continue;
    }

    $categorycounts[$itemcategory] =
        ($categorycounts[$itemcategory] ?? 0)
        + 1;
}


/*
 * ------------------------------------------------------------
 * FILTERED MATERIALS
 * ------------------------------------------------------------
 */

$materials = [];


foreach ($all as $item) {

    if (
        $type !== 'all'
        &&
        $item['type'] !== $type
    ) {
        continue;
    }


    if (
        $categoryfilter !== 'all'
        &&
        (string)($item['category'] ?? '') !== $categoryfilter
    ) {
        continue;
    }


    if ($q !== '') {

        $haystack =
            core_text::strtolower(
                trim(
                    (string)$item['title']
                    .
                    ' '
                    .
                    (string)$item['summary']
                    .
                    ' '
                    .
                    (string)$item['category']
                )
            );


        $needle =
            core_text::strtolower(
                $q
            );


        if (
            strpos(
                $haystack,
                $needle
            )
            ===
            false
        ) {
            continue;
        }
    }


    /*
     * open_url() performs another access check and chooses
     * the correct runtime:
     *
     * USTAR file -> USTAR Viewer
     * Moodle CM  -> real Moodle activity
     * external   -> external URL
     */
    $url =
        \local_ustar\content::open_url(
            (int)$item['id'],
            (int)$USER->id
        );


    if (!$url) {
        continue;
    }


    switch ($item['type']) {

        case 'quiz':
            $actionlabel = 'Пройти';
            break;

        case 'video':
            $actionlabel = 'Смотреть';
            break;

        case 'scorm':
        case 'lesson':
            $actionlabel = 'Продолжить';
            break;

        default:
            $actionlabel = 'Открыть';
    }


    $category =
        trim(
            (string)$item['category']
        );


    $materials[] = [

        'id' =>
            (int)$item['id'],

        'title' =>
            format_string(
                $item['title']
            ),

        'summary' =>
            (string)$item['summary'],

        'hassummary' =>
            trim(
                (string)$item['summary']
            ) !== '',

        'type' =>
            (string)$item['type'],

        'typelabel' =>
            $typelabels[
                $item['type']
            ]
            ??
            'Материал',

        'category' =>
            $category,

        'categorylabel' =>
            $categorylabels[
                $category
            ]
            ??
            '',

        'hascategory' =>
            $category !== ''
            &&
            isset(
                $categorylabels[
                    $category
                ]
            ),

        'versionlabel' =>
            (string)$item['versionlabel'],

        'ackrequired' =>
            !empty(
                $item['ackrequired']
            ),

        'needsack' =>
            !empty(
                $item['needsack']
            ),

        'acked' =>
            !empty(
                $item['acked']
            ),

        'publishedat' =>
            (int)($item['publishedat'] ?? 0),

        'timemodified' =>
            (int)($item['timemodified'] ?? 0),

        'updatedat' =>
            max((int)($item['publishedat'] ?? 0), (int)($item['timemodified'] ?? 0)),

        'updatedlabel' =>
            max((int)($item['publishedat'] ?? 0), (int)($item['timemodified'] ?? 0)) > 0
                ? userdate(max((int)($item['publishedat'] ?? 0), (int)($item['timemodified'] ?? 0)), '%d.%m.%Y')
                : '',

        'fresh' =>
            max((int)($item['publishedat'] ?? 0), (int)($item['timemodified'] ?? 0)) >= time() - (30 * DAYSECS),

        'icon' =>
            \local_ustar\ui::icon(
                in_array((string)$item['type'], ['quiz', 'scorm', 'lesson', 'interactive'], true)
                    ? 'spark'
                    : (((string)$item['type'] === 'video') ? 'game' : 'knowledge'),
                'u-knowledge-card__icon'
            ),

        'url' =>
            $url->out(false),

        'actionlabel' =>
            $actionlabel,
    ];
}


/*
 * ------------------------------------------------------------
 * TYPE FILTERS
 * ------------------------------------------------------------
 */

$typefilters = [

    [
        'label' =>
            'Все',

        'count' =>
            count($all),

        'selected' =>
            $type === 'all',

        'url' =>
            (
                new moodle_url(
                    '/local/ustar/knowledge.php',
                    [
                        'view' =>
                            'knowledge',

                        'q' =>
                            $q,

                        'category' =>
                            $categoryfilter,

                        'preview' =>
                            $preview ? 1 : 0,
                    ]
                )
            )->out(false),
    ],

];


foreach ($typecounts as $id => $count) {

    $typefilters[] = [

        'label' =>
            $typelabels[$id]
            ??
            ucfirst($id),

        'count' =>
            $count,

        'selected' =>
            $type === $id,

        'url' =>
            (
                new moodle_url(
                    '/local/ustar/knowledge.php',
                    [
                        'view' =>
                            'knowledge',

                        'type' =>
                            $id,

                        'q' =>
                            $q,

                        'category' =>
                            $categoryfilter,

                        'preview' =>
                            $preview ? 1 : 0,
                    ]
                )
            )->out(false),
    ];
}


/*
 * ------------------------------------------------------------
 * CATEGORY FILTERS / ACTION GROUPS
 * ------------------------------------------------------------
 */

$categoryfilters = [
    [
        'label' => 'Все категории',
        'count' => count($all),
        'selected' => $categoryfilter === 'all',
        'url' => (new moodle_url(
            '/local/ustar/knowledge.php',
            [
                'view' => 'knowledge',
                'type' => $type,
                'q' => $q,
                'preview' => $preview ? 1 : 0,
            ]
        ))->out(false),
    ],
];

foreach ($categorycounts as $id => $count) {
    $categoryfilters[] = [
        'label' => $categorylabels[$id] ?? ucfirst($id),
        'count' => $count,
        'selected' => $categoryfilter === $id,
        'url' => (new moodle_url(
            '/local/ustar/knowledge.php',
            [
                'view' => 'knowledge',
                'type' => $type,
                'category' => $id,
                'q' => $q,
                'preview' => $preview ? 1 : 0,
            ]
        ))->out(false),
    ];
}

$actionmaterials = array_values(array_filter(
    $materials,
    static fn(array $item): bool => !empty($item['needsack'])
));

$regularmaterials = array_values(array_filter(
    $materials,
    static fn(array $item): bool => empty($item['needsack'])
));

$recentmaterials = array_values(array_filter(
    $regularmaterials,
    static fn(array $item): bool => !empty($item['fresh'])
));
usort($recentmaterials, static fn(array $a, array $b): int => ($b['updatedat'] ?? 0) <=> ($a['updatedat'] ?? 0));
$recentmaterials = array_slice($recentmaterials, 0, 6);
$recentids = array_fill_keys(array_map(static fn(array $item): int => (int)$item['id'], $recentmaterials), true);
$librarymaterials = array_values(array_filter(
    $regularmaterials,
    static fn(array $item): bool => !isset($recentids[(int)$item['id']])
));

$categoryshortcuts = [];
arsort($categorycounts);
foreach (array_slice($categorycounts, 0, 8, true) as $id => $count) {
    $categoryshortcuts[] = [
        'label' => $categorylabels[$id] ?? ucfirst($id),
        'count' => $count,
        'url' => (new moodle_url('/local/ustar/knowledge.php', [
            'view' => 'knowledge',
            'type' => $type,
            'category' => $id,
            'preview' => $preview ? 1 : 0,
        ]))->out(false),
    ];
}


/*
 * ------------------------------------------------------------
 * USER CONTEXT
 * ------------------------------------------------------------
 */

$scope =
    \local_ustar\content::user_scope(
        (int)$USER->id
    );


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
        '/local/ustar/knowledge.php',
        [
            'view' =>
                'knowledge',
        ]
    )
);

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Знания | USTAR Academy'
);

$PAGE->set_heading(
    'USTAR Academy'
);


$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


$data = [

    'q' =>
        s($q),

    'total' =>
        count($all),

    'visiblecount' =>
        count($materials),

    'hasmaterials' =>
        !empty($materials),

    'materials' =>
        $materials,

    'actionmaterials' =>
        $actionmaterials,

    'hasactionmaterials' =>
        !empty($actionmaterials),

    'needsactioncount' =>
        count($actionmaterials),

    'regularmaterials' =>
        $librarymaterials,

    'hasregularmaterials' =>
        !empty($librarymaterials),

    'recentmaterials' =>
        $recentmaterials,

    'hasrecentmaterials' =>
        !empty($recentmaterials),

    'recentcount' =>
        count($recentmaterials),

    'categoryshortcuts' =>
        $categoryshortcuts,

    'hascategoryshortcuts' =>
        !empty($categoryshortcuts),

    'categorycount' =>
        count($categorycounts),

    'knowledgeicon' =>
        \local_ustar\ui::icon('knowledge', 'u-feature-icon'),

    'clockicon' =>
        \local_ustar\ui::icon('clock', 'u-feature-icon'),

    'checkicon' =>
        \local_ustar\ui::icon('check', 'u-feature-icon'),

    'searchicon' =>
        \local_ustar\ui::icon('search', 'u-feature-icon'),

    'typefilters' =>
        $typefilters,

    'categoryfilters' =>
        $categoryfilters,

    'positionid' =>
        (string)$scope['positionid'],

    'departmentid' =>
        (string)$scope['departmentid'],
];


echo $output->header();

echo $output->render_from_template(
    'local_ustar/knowledge',
    $data
);

echo $output->footer();
