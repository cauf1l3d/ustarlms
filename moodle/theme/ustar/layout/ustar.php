<?php
defined('MOODLE_INTERNAL') || die();

global $SITE, $USER, $CFG;

$context = context_system::instance();

/*
 * ------------------------------------------------------------
 * USTAR ACTIVITY RUNTIME
 * ------------------------------------------------------------
 *
 * The same shell is used for:
 *
 *   /local/ustar/... -> USTAR application pages
 *   /mod/...         -> real Moodle learning activities
 *
 * Activity pages have no "view" parameter, therefore they are
 * automatically treated as part of employee Learning.
 */

$pagepath =
    $PAGE->url
        ? (string)$PAGE->url->get_path()
        : '';

$isactivityruntime =
    strpos(
        $pagepath,
        '/mod/'
    ) === 0;


/*
 * ------------------------------------------------------------
 * USTAR COURSE RUNTIME
 * ------------------------------------------------------------
 *
 * Moodle uses a separate layout for /course/view.php.
 * Treat it as part of employee Learning as well.
 */

$iscourseruntime =
    $pagepath === '/course/view.php';


$islearningruntime =
    $isactivityruntime
    ||
    $iscourseruntime;


$view =
    optional_param(
        'view',
        $islearningruntime
            ? 'learning'
            : 'home',
        PARAM_ALPHANUMEXT
    );


/*
 * ------------------------------------------------------------
 * USTAR HR WORKSPACE
 * ------------------------------------------------------------
 */

$hrpages = [
    '/local/ustar/hr.php',
    '/local/ustar/workspace.php',
];

$positionpages = [
    '/local/ustar/positions.php',
    '/local/ustar/route_studio.php',
];

$materialpages = [
    '/local/ustar/materials.php',
    '/local/ustar/material_create.php',
    '/local/ustar/material_bulk.php',
    '/local/ustar/material_version.php',
    '/local/ustar/material_version_action.php',
    '/local/ustar/material_ack_export.php',
];

$operationpages = [
    '/local/ustar/operations.php',
    '/local/ustar/brand.php',
    '/local/ustar/game_studio.php',
    '/local/ustar/checklist_studio.php',
];

$productgrowthpages = [
    '/local/ustar/games.php',
    '/local/ustar/game.php',
    '/local/ustar/achievements.php',
    '/local/ustar/checklists.php',
    '/local/ustar/team.php',
    '/local/ustar/executive.php',
    '/local/ustar/catalog.php',
    '/local/ustar/boards.php',
];

if (in_array($pagepath, $productgrowthpages, true)) {
    $view = 'career';
}

if ($pagepath === '/local/ustar/games.php' || $pagepath === '/local/ustar/game.php') { $view = 'games'; }
if ($pagepath === '/local/ustar/catalog.php') { $view = 'catalog'; }
if ($pagepath === '/local/ustar/team.php' || $pagepath === '/local/ustar/executive.php') { $view = 'team'; }
if ($pagepath === '/local/ustar/achievements.php') { $view = 'achievements'; }
if ($pagepath === '/local/ustar/boards.php') { $view = 'tools'; }

if (in_array($pagepath, [
    '/local/ustar/profile.php',
    '/local/ustar/messages.php',
    '/local/ustar/notifications.php',
], true)) {
    $view = 'home';
}

$ishrworkspace = in_array(
    $pagepath,
    array_merge(
        $hrpages,
        $positionpages,
        $materialpages,
        $operationpages
    ),
    true
);


$icons = [
    'home' => '<svg class="u-icon u-navitem__icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-7h6v7"/>'
        . '</svg>',

    'learning' => '<svg class="u-icon u-navitem__icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4h16v16H6.5A2.5 2.5 0 0 1 4 17.5z"/>'
        . '</svg>',

    'knowledge' => '<svg class="u-icon u-navitem__icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M2 6.5A2.5 2.5 0 0 1 4.5 4H10a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4.5A2.5 2.5 0 0 0 2 20.5z"/>'
        . '<path d="M22 6.5A2.5 2.5 0 0 0 19.5 4H14a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h5.5a2.5 2.5 0 0 1 2.5 2.5z"/>'
        . '</svg>',

    'game' => '<svg class="u-icon u-navitem__icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M8.5 8h7a5.5 5.5 0 0 1 5.3 7l-1 3.4a2.3 2.3 0 0 1-3.8 1l-2-1.8h-4l-2 1.8a2.3 2.3 0 0 1-3.8-1L3.2 15a5.5 5.5 0 0 1 5.3-7z"/>'
        . '<path d="M7 12h4M9 10v4M16.5 11.5h.01M18.5 13.5h.01"/>'
        . '</svg>',

    'growth' => '<svg class="u-icon u-navitem__icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M3 17 9 11l4 4 8-9"/><path d="M14 6h7v7"/>'
        . '</svg>',

    'search' => '<svg class="u-icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>'
        . '</svg>',

    'bell' => '<svg class="u-icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>'
        . '<path d="M10 21h4"/>'
        . '</svg>',

    'message' => '<svg class="u-icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>'
        . '</svg>',

    'collapse' => '<svg class="u-icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<rect x="3" y="3" width="18" height="18" rx="2"/>'
        . '<path d="M9 3v18"/><path d="m15 9-3 3 3 3"/>'
        . '</svg>',

    'admin' => '<svg class="u-icon u-navitem__icon" viewBox="0 0 24 24" aria-hidden="true">'
        . '<circle cx="12" cy="12" r="3"/>'
        . '<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3h4a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1v4H21a1.7 1.7 0 0 0-1.6 1z"/>'
        . '</svg>',
];

$homeurl = new moodle_url('/local/ustar/home.php');

if ($ishrworkspace) {

    $navitems = [
        [
            'label' =>
                'Сотрудники',

            'short' =>
                'Люди',

            'url' =>
                (
                    new moodle_url(
                        '/local/ustar/hr.php'
                    )
                )->out(false),

            'icon' =>
                $icons['home'],

            'active' =>
                in_array(
                    $pagepath,
                    $hrpages,
                    true
                ),
        ],

        [
            'label' =>
                'Модели должностей',

            'short' =>
                'Модели',

            'url' =>
                (
                    new moodle_url(
                        '/local/ustar/positions.php'
                    )
                )->out(false),

            'icon' =>
                $icons['learning'],

            'active' =>
                in_array(
                    $pagepath,
                    $positionpages,
                    true
                ),
        ],

        [
            'label' =>
                'Материалы',

            'short' =>
                'Материалы',

            'url' =>
                (
                    new moodle_url(
                        '/local/ustar/materials.php'
                    )
                )->out(false),

            'icon' =>
                $icons['knowledge'],

            'active' =>
                in_array(
                    $pagepath,
                    $materialpages,
                    true
                ),
        ],

        [
            'label' =>
                'Контроль',

            'short' =>
                'Контроль',

            'url' =>
                (
                    new moodle_url(
                        '/local/ustar/operations.php'
                    )
                )->out(false),

            'icon' =>
                $icons['growth'],

            'active' =>
                in_array(
                    $pagepath,
                    $operationpages,
                    true
                ),
        ],
    ];

} else {

    $navitems = [
        ['label'=>'Главная','short'=>'Главная','url'=>$homeurl->out(false),'icon'=>$icons['home'],'active'=>$view==='home'],
        ['label'=>'Обучение','short'=>'Учёба','url'=>(new moodle_url('/local/ustar/home.php',['view'=>'learning']))->out(false),'icon'=>$icons['learning'],'active'=>$view==='learning'],
        ['label'=>'Игры','short'=>'Игры','url'=>(new moodle_url('/local/ustar/games.php'))->out(false),'icon'=>$icons['game'],'active'=>$view==='games','mobile'=>false],
        ['label'=>'База знаний','short'=>'Знания','url'=>(new moodle_url('/local/ustar/knowledge.php',['view'=>'knowledge']))->out(false),'icon'=>$icons['knowledge'],'active'=>$view==='knowledge'],
        ['label'=>'Каталог','short'=>'Каталог','url'=>(new moodle_url('/local/ustar/catalog.php'))->out(false),'icon'=>$icons['knowledge'],'active'=>$view==='catalog'],
        ['label'=>'Команда','short'=>'Команда','url'=>(new moodle_url('/local/ustar/team.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='team'],
        ['label'=>'Достижения','short'=>'Рейтинг','url'=>(new moodle_url('/local/ustar/achievements.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='achievements'],
        ['label'=>'Инструменты','short'=>'Доска','url'=>(new moodle_url('/local/ustar/boards.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='tools'],
    ];
}

$pagelabels = [
    'home' => 'Главная',
    'learning' => 'Обучение',
    'games' => 'Игры',
    'knowledge' => 'Знания',
    'career' => 'Развитие',
    'catalog' => 'Каталог',
    'team' => 'Команда',
    'achievements' => 'Достижения',
    'tools' => 'Инструменты',
];

$controlpagelabels = [
    '/local/ustar/hr.php' => 'Сотрудники',
    '/local/ustar/workspace.php' => 'Оргпространство',
    '/local/ustar/positions.php' => 'Модели должностей',
    '/local/ustar/route_studio.php' => 'Маршруты обучения',
    '/local/ustar/materials.php' => 'Материалы',
    '/local/ustar/material_create.php' => 'Новый материал',
    '/local/ustar/material_bulk.php' => 'Массовая загрузка',
    '/local/ustar/material_version.php' => 'Новая версия',
    '/local/ustar/material_version_action.php' => 'Версии',
    '/local/ustar/material_ack_export.php' => 'Журнал ознакомления',
    '/local/ustar/operations.php' => 'Контроль',
    '/local/ustar/brand.php' => 'Оформление',
    '/local/ustar/game_studio.php' => 'Игровые задания',
    '/local/ustar/checklist_studio.php' => 'Редактор чек-листов',
    '/local/ustar/games.php' => 'Игровые задания',
    '/local/ustar/game.php' => 'Игровые задания',
    '/local/ustar/achievements.php' => 'Достижения',
    '/local/ustar/checklists.php' => 'Чек-листы',
    '/local/ustar/team.php' => 'Команда',
    '/local/ustar/executive.php' => 'Руководство',
    '/local/ustar/catalog.php' => 'Каталог',
    '/local/ustar/boards.php' => 'Доска',
    '/local/ustar/view_as.php' => 'Просмотр как',
    '/local/ustar/legacy.php' => 'Legacy UI',
    '/local/ustar/profile.php' => 'Личный кабинет',
    '/local/ustar/messages.php' => 'Сообщения',
    '/local/ustar/notifications.php' => 'Уведомления',
];

$pagelabel =
    $controlpagelabels[$pagepath]
    ?? ($pagelabels[$view] ?? 'USTAR Academy');

$firstname = trim((string)($USER->firstname ?? ''));
$lastname = trim((string)($USER->lastname ?? ''));

$initials = '';

if ($firstname !== '') {
    $initials .= core_text::strtoupper(
        core_text::substr($firstname, 0, 1)
    );
}

if ($lastname !== '') {
    $initials .= core_text::strtoupper(
        core_text::substr($lastname, 0, 1)
    );
}

if ($initials === '') {
    $initials = 'U';
}

$branding = [];

if (class_exists('\local_ustar\structure')) {
    try {
        $branding = \local_ustar\structure::get(
            \local_ustar\structure::NAME_BRANDING
        );
    } catch (\Throwable $e) {
        $branding = [];
    }
}

$brandname = trim((string)($branding['brandName'] ?? 'USTAR'));
$productname =
    $ishrworkspace
        ? 'Центр управления'
        : 'Академия';

$lightlogofile = __DIR__ . '/../pix/brand/logo-onlight.png';
$darklogofile = __DIR__ . '/../pix/brand/logo-ondark.png';

$brandlogo = is_readable($lightlogofile)
    ? $OUTPUT->image_url('brand/logo-onlight', 'theme_ustar')->out(false)
    : '';

$brandlogodark = is_readable($darklogofile)
    ? $OUTPUT->image_url('brand/logo-ondark', 'theme_ustar')->out(false)
    : $brandlogo;

$canadmin = is_siteadmin($USER)
    || has_capability('local/ustar:admin', $context);

$canhr = has_capability('local/ustar:hr', $context);
$canexec = has_capability('local/ustar:executive', $context);
$canmanager = has_capability('local/ustar:viewteam', $context);

if ($canadmin) {
    $rolelabel = 'Администратор Академии';
} else if ($canhr) {
    $rolelabel = 'HR / Академия';
} else if ($canexec) {
    $rolelabel = 'Руководство';
} else if ($canmanager) {
    $rolelabel = 'Руководитель';
} else {
    $rolelabel = 'Сотрудник';
}


/*
 * ------------------------------------------------------------
 * POSITION-AWARE PRODUCT SHELL
 * ------------------------------------------------------------
 *
 * The USTAR position is the business identity shown to the user.
 * Protected workspaces still rely on Moodle capabilities.
 */
$positionlabel = '';
$positiondepartment = '';
try {
    if (class_exists('\local_ustar\position_access') && !empty($USER->id)) {
        $shellposition = \local_ustar\position_access::position_for_user((int)$USER->id);
        if ($shellposition) {
            $positionlabel = trim((string)($shellposition['name'] ?? ''));
            $positiondepartment = trim((string)($shellposition['department'] ?? ''));
        }
    }
} catch (\Throwable $e) {
    $positionlabel = '';
    $positiondepartment = '';
}

if (!$canadmin && $positionlabel !== '') {
    $rolelabel = $positionlabel;
}

/* HR always works in the HR shell, not in the generic employee shell. */
if (!$canadmin && $canhr) {
    $productname = 'HR / Академия';
    $homeurl = new moodle_url('/local/ustar/hr.php');
    $navitems = [
        ['label'=>'Панель HR','short'=>'HR','url'=>$homeurl->out(false),'icon'=>$icons['home'],'active'=>in_array($pagepath,$hrpages,true)],
        ['label'=>'Должности','short'=>'Должности','url'=>(new moodle_url('/local/ustar/positions.php'))->out(false),'icon'=>$icons['learning'],'active'=>in_array($pagepath,$positionpages,true)],
        ['label'=>'Материалы','short'=>'Материалы','url'=>(new moodle_url('/local/ustar/materials.php'))->out(false),'icon'=>$icons['knowledge'],'active'=>in_array($pagepath,$materialpages,true)],
        ['label'=>'Контроль','short'=>'Контроль','url'=>(new moodle_url('/local/ustar/operations.php'))->out(false),'icon'=>$icons['growth'],'active'=>in_array($pagepath,$operationpages,true)],
        ['label'=>'Каталог','short'=>'Каталог','url'=>(new moodle_url('/local/ustar/catalog.php'))->out(false),'icon'=>$icons['knowledge'],'active'=>$view==='catalog'],
    ];
}

/* Executive account opens on the company-level dashboard. */
if (!$canadmin && !$canhr && $canexec) {
    $productname = 'Панель руководства';
    $homeurl = new moodle_url('/local/ustar/executive.php');
    $navitems = [
        ['label'=>'Компания','short'=>'Компания','url'=>$homeurl->out(false),'icon'=>$icons['home'],'active'=>$pagepath==='/local/ustar/executive.php'],
        ['label'=>'Команда','short'=>'Команда','url'=>(new moodle_url('/local/ustar/team.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='team' && $pagepath!=='/local/ustar/executive.php'],
        ['label'=>'Каталог','short'=>'Каталог','url'=>(new moodle_url('/local/ustar/catalog.php'))->out(false),'icon'=>$icons['knowledge'],'active'=>$view==='catalog'],
        ['label'=>'Достижения','short'=>'Рейтинг','url'=>(new moodle_url('/local/ustar/achievements.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='achievements'],
        ['label'=>'Доска','short'=>'Доска','url'=>(new moodle_url('/local/ustar/boards.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='tools'],
    ];
}

/* Department heads get a manager-first shell while retaining learning tools. */
if (!$canadmin && !$canhr && !$canexec && $canmanager) {
    $productname = 'Панель руководителя';
    $homeurl = new moodle_url('/local/ustar/team.php');
    $navitems = [
        ['label'=>'Моя команда','short'=>'Команда','url'=>$homeurl->out(false),'icon'=>$icons['home'],'active'=>$view==='team'],
        ['label'=>'Обучение','short'=>'Учёба','url'=>(new moodle_url('/local/ustar/home.php',['view'=>'learning']))->out(false),'icon'=>$icons['learning'],'active'=>$view==='learning'],
        ['label'=>'Игры','short'=>'Игры','url'=>(new moodle_url('/local/ustar/games.php'))->out(false),'icon'=>$icons['game'],'active'=>$view==='games','mobile'=>false],
        ['label'=>'Каталог','short'=>'Каталог','url'=>(new moodle_url('/local/ustar/catalog.php'))->out(false),'icon'=>$icons['knowledge'],'active'=>$view==='catalog'],
        ['label'=>'Достижения','short'=>'Рейтинг','url'=>(new moodle_url('/local/ustar/achievements.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='achievements'],
        ['label'=>'База знаний','short'=>'Знания','url'=>(new moodle_url('/local/ustar/knowledge.php',['view'=>'knowledge']))->out(false),'icon'=>$icons['knowledge'],'active'=>$view==='knowledge'],
        ['label'=>'Доска','short'=>'Доска','url'=>(new moodle_url('/local/ustar/boards.php'))->out(false),'icon'=>$icons['growth'],'active'=>$view==='tools'],
    ];
}

/*
 * ------------------------------------------------------------
 * USTAR ACTIVITY CONTEXT
 * ------------------------------------------------------------
 *
 * Moodle remains the activity runtime.
 * USTAR adds product navigation and a clear return path.
 */

$activityruntime =
    !empty($PAGE->cm)
    &&
    !empty($PAGE->course)
    &&
    (int)$PAGE->course->id !== SITEID;

$activitytitle = '';
$activitycourse = '';
$activitytype = '';
$activitybackurl = '';

if ($activityruntime) {

    $courseid =
        (int)$PAGE->course->id;

    $activitytitle =
        format_string(
            $PAGE->cm->name,
            true,
            [
                'context' =>
                    $PAGE->context,
            ]
        );

    $activitycourse =
        format_string(
            $PAGE->course->fullname,
            true,
            [
                'context' =>
                    context_course::instance(
                        $courseid
                    ),
            ]
        );

    try {

        $activitytype =
            get_string(
                'modulename',
                'mod_' . $PAGE->cm->modname
            );

    } catch (\Throwable $e) {

        $activitytype =
            core_text::strtoupper(
                (string)$PAGE->cm->modname
            );
    }

    $activitybackurl =
        (
            new moodle_url(
                '/local/ustar/home.php',
                [
                    'view' =>
                        'learning',

                    'courseid' =>
                        $courseid,

                ]
            )
        )->out(false);

    /*
     * Activity pages always belong to Learning in the
     * product navigation.
     */
    $pagelabel = 'Обучение';
}


$brandcss = '';
if (class_exists('\local_ustar\branding')) {
    try {
        $brandcss = \local_ustar\branding::inline_css();
    } catch (\Throwable $e) {
        $brandcss = '';
    }
}

$communicationcounts = [
    'messages' => 0,
    'notifications' => 0,
];
if (class_exists('\local_ustar\communication')) {
    try {
        $communicationcounts = \local_ustar\communication::counts((int)$USER->id);
    } catch (\Throwable $e) {
        $communicationcounts = ['messages' => 0, 'notifications' => 0];
    }
}

$preset = (string)get_user_preferences('local_ustar_preset', 'yellow', (int)$USER->id);
if (!in_array($preset, ['yellow','graphite','ocean','forest','berry','sand'], true)) { $preset = 'yellow'; }

$PAGE->requires->js_call_amd('theme_ustar/shell', 'init');




$viewasactive = class_exists('\local_ustar\view_as') && \local_ustar\view_as::active();
$viewasposition = '';
if ($viewasactive) {
    $pid = \local_ustar\view_as::position_id();
    foreach ((\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE)['positions'] ?? []) as $vp) {
        if ((string)$vp['id'] === $pid) { $viewasposition = (string)$vp['name']; break; }
    }
}

$mobilenavitems = array_values(array_filter($navitems, static function(array $item): bool {
    return !array_key_exists('mobile', $item) || !empty($item['mobile']);
}));
$mobilenavitems = array_slice($mobilenavitems, 0, 5);

$templatecontext = [
    'output' => $OUTPUT,

    'bodyattributes' => $OUTPUT->body_attributes(
        $activityruntime
            ? [
                'u-shell-page',
                'u-activity-runtime',
            ]
            : [
                'u-shell-page',
            ]
    ),

    'brandname' => $brandname,
    'productname' => $productname,
    'brandlogo' => $brandlogo,
    'brandlogodark' => $brandlogodark,

    'pagelabel' => $pagelabel,
    'rolelabel' => $rolelabel,

    'activityruntime' =>
        $activityruntime,

    'activitytitle' =>
        $activitytitle,

    'activitycourse' =>
        $activitycourse,

    'activitytype' =>
        $activitytype,

    'activitybackurl' =>
        $activitybackurl,

    'fullname' => fullname($USER),
    'initials' => $initials,

    'navitems' => $navitems,
    'mobilenavitems' => $mobilenavitems,

    'homeurl' => $homeurl->out(false),

    'searchurl' => (
        new moodle_url('/local/ustar/knowledge.php', ['view' => 'knowledge'])
    )->out(false),

    'messagesurl' => (
        new moodle_url('/local/ustar/messages.php')
    )->out(false),

    'notificationsurl' => (
        new moodle_url('/local/ustar/notifications.php')
    )->out(false),

    'profileurl' => (
        new moodle_url('/local/ustar/profile.php')
    )->out(false),

    'messagecount' => (int)($communicationcounts['messages'] ?? 0),
    'hasmessagecount' => !empty($communicationcounts['messages']),
    'notificationcount' => (int)($communicationcounts['notifications'] ?? 0),
    'hasnotificationcount' => !empty($communicationcounts['notifications']),

    'brandcss' => $brandcss,
    'preset' => $preset,
    'searchapiurl' => (new moodle_url('/local/ustar/search_api.php'))->out(false),
    'prefurl' => (new moodle_url('/local/ustar/user_prefs.php'))->out(false),
    'sesskey' => sesskey(),

    'isadmin' => $canadmin,
    'canviewas' => $canadmin || has_capability('local/ustar:viewas', $context),
    'viewasactive' => $viewasactive,
    'viewasposition' => $viewasposition,
    'viewasurl' => (new moodle_url('/local/ustar/view_as.php'))->out(false),
    'canlegacy' => has_capability('local/ustar:legacyui', $context),
    'legacyurl' => (new moodle_url('/local/ustar/legacy.php'))->out(false),
    'adminurl' => (
        new moodle_url('/admin/')
    )->out(false),

    'searchicon' => $icons['search'],
    'messageicon' => $icons['message'],
    'bellicon' => $icons['bell'],
    'collapseicon' => $icons['collapse'],
    'adminicon' => $icons['admin'],
];

echo $OUTPUT->render_from_template(
    'theme_ustar/shell',
    $templatecontext
);
