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
\local_ustar\hr_access::require_structure_manager();

$canmanage = true;
$formerror = '';


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

$tab = optional_param('tab', 'overview', PARAM_ALPHA);
if (!in_array($tab, ['overview', 'requirements', 'learning', 'personal', 'grades', 'application', 'graph'], true)) {
    $tab = 'overview';
}

$positionmap = [];

foreach ($positions as $position) {
    $positionmap[
        $position['id']
    ] = $position;
}


if ($positionid !== '' && !isset($positionmap[$positionid])) {
    throw new \invalid_parameter_exception(
        'Неизвестная должность'
    );
}


$currentposition = $positionid !== '' ? $positionmap[$positionid] : null;


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

if ($action !== '' && $positionid === '') {
    throw new \invalid_parameter_exception('Выберите должность для изменения');
}


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

        if (in_array($action, ['saveprofile', 'assignprofile', 'changeprofileroute'], true)) {
            $tab = 'personal';
            $profileid = optional_param('profileid', 0, PARAM_INT);
            $actor = (int)$USER->id;
            if ($action === 'saveprofile') {
                $profileid = \local_ustar\position_profiles::save($positionid, $profileid, required_param('revision', PARAM_INT),
                    required_param('profilename', PARAM_TEXT), optional_param('targetgrade', '', PARAM_ALPHANUMEXT),
                    optional_param_array('skilllevels', [], PARAM_INT), $actor);
            } else if ($action === 'assignprofile') {
                \local_ustar\position_profiles::assign($positionid, required_param('employeeid', PARAM_INT),
                    required_param('assignedprofileid', PARAM_INT), required_param('revision', PARAM_INT),
                    required_param('staffassignment', PARAM_INT), $actor);
            } else {
                $change = required_param('routechange', PARAM_ALPHA);
                $target = required_param($change === 'add' ? 'cmid' : 'stepid', PARAM_INT);
                \local_ustar\position_profiles::change_route($positionid, $profileid,
                    required_param('routerevision', PARAM_ALPHANUM), $change, $target, $actor);
            }
            redirect(new moodle_url('/local/ustar/positions.php', [
                'positionid' => $positionid, 'tab' => 'personal', 'profileid' => $profileid,
            ]));
        }

        if ($action === 'savegrade') {
            \local_ustar\career_grades::save($positionid,
                required_param('careergrade', PARAM_ALPHANUMEXT),
                required_param('gradeexpected', PARAM_ALPHANUMEXT));
            redirect(new moodle_url('/local/ustar/positions.php', ['positionid'=>$positionid, 'tab'=>'grades']));
        }

        if ($action === 'savecareer') {
            \local_ustar\career_path::save($positionid,
                optional_param('nextpositionid', '', PARAM_ALPHANUMEXT),
                required_param('careerexpected', PARAM_ALPHANUMEXT));
            redirect(new moodle_url('/local/ustar/positions.php', ['positionid'=>$positionid, 'tab'=>'grades']),
                'Карьерный переход сохранён', null, \core\output\notification::NOTIFY_SUCCESS);
        }

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
                (int)$USER->id,
                required_param('matrixexpected', PARAM_ALPHANUMEXT)
            );

            redirect(
                new moodle_url(
                    '/local/ustar/positions.php',
                    [
                        'positionid' =>
                            $positionid,
                        'tab' => 'requirements',
                    ]
                ),
                'Требования должности сохранены',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }

        if ($action === 'publishstandard') {
            \local_ustar\standard_model::publish_position_matrix(
                $positionid, required_param('matrixhash', PARAM_ALPHANUMEXT), (int)$USER->id);
            redirect(new moodle_url('/local/ustar/positions.php', ['positionid' => $positionid, 'tab' => 'application']),
                'Стандарт должности опубликован', null, \core\output\notification::NOTIFY_SUCCESS);
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
                        'tab' => 'learning',
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
                        'tab' => 'learning',
                    ]
                ),
                'Связь отключена',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }

    } catch (\Throwable $e) {

        if (in_array($tab, ['personal', 'learning'], true)) {
            if ($e instanceof \invalid_parameter_exception) {
                $formerror = (string)($e->debuginfo ?: $e->getMessage());
            } else if ($e instanceof \moodle_exception && $e->errorcode === 'positionprofileinvalid') {
                $formerror = $e->getMessage();
            } else if ($e instanceof \dml_missing_record_exception) {
                $formerror = 'Эта модель или запись больше недоступна. Обновите страницу.';
            } else {
                throw $e;
            }
        } else {
            \core\notification::error($e->getMessage());
        }
    }
}

// The catalog is a separate, inexpensive entry point. Detail-only directory,
// graph and learning projections are built only after a position is chosen.
if ($positionid === '') {
    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/local/ustar/positions.php'));
    $PAGE->set_pagelayout('ustar');
    $PAGE->set_title('Модели должностей | Центр управления USTAR');
    $PAGE->set_heading('Центр управления USTAR');
    $catalogcss = __DIR__ . '/styles/positions_catalog.css';
    $PAGE->requires->css(new moodle_url('/local/ustar/styles/positions_catalog.css',
        ['v' => filemtime($catalogcss)]));
    $catalog = \local_ustar\position_catalog::present($positions, $departmentmap, $structure);
    $catalog['canmanage'] = $canmanage;
    $catalog['catalogurl'] = (new moodle_url('/local/ustar/positions.php'))->out(false);
    $catalog['structureurl'] = (new moodle_url('/local/ustar/organization_settings.php'))->out(false)
        . '#ustar-add-position';
    $output = $PAGE->get_renderer('local_ustar');
    echo $output->header();
    echo $output->render_from_template('local_ustar/positions_catalog', $catalog);
    echo $output->footer();
    return;
}


/*
 * ------------------------------------------------------------
 * PEOPLE COUNTS
 * ------------------------------------------------------------
 */

$occupancy = [];
$directoryusers = \local_ustar\organization_directory::users(true);

foreach ($directoryusers as $row) {
    if (!\local_ustar\accounts::participates((int)$row->id)) continue;
    $occupancypositionid = (string)$row->positionid;
    if ($occupancypositionid === '') {
        continue;
    }
    $occupancy[$occupancypositionid] = ($occupancy[$occupancypositionid] ?? 0) + 1;
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
$publishedstandard = \local_ustar\standard_model::current_position($positionid);
$matrixhash = \local_ustar\standard_model::matrix_hash(is_array($required) ? $required : []);

$selectedskillid = '';
$skillmap = [];
foreach ($skills as $skill) {
    $skillid = clean_param((string)($skill['id'] ?? ''), PARAM_ALPHANUMEXT);
    if ($skillid !== '') {
        $skillmap[$skillid] = $skill;
    }
}
$graphpositionrows = [];
$graphskillrows = [];
$graphmaterialrows = [];
if ($tab === 'graph') {
$selectedskillid = optional_param('skillid', '', PARAM_ALPHANUMEXT);
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
    $graphurl = new moodle_url('/local/ustar/positions.php',
        ['positionid' => $positionidvalue, 'tab' => 'graph']);
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
        'tab' => 'graph',
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
    foreach (\local_ustar\career_learning::position_materials((string)$graphpositionid) as $material) {
        if ($selectedskillid !== '' && !in_array($selectedskillid, $material['skillids'], true)) { continue; }
        $names = [];
        foreach ($material['skillids'] as $id) { $names[] = (string)($skillmap[$id]['name'] ?? $id); }
        $graphmaterialrows[] = $material + [
            'positionname'=>(string)($positionmap[$graphpositionid]['name'] ?? $graphpositionid),
            'skills'=>implode(', ', $names), 'hasskills'=>(bool)$names];
    }
}


/*
 * ============================================================
 * USTAR 2706 FOUR COLUMN WORKSPACE READ MODEL
 * ============================================================
 *
 * Canonical read model:
 *
 * employee -> current position -> required skills
 *          -> published Route Studio materials
 *
 * No second source of truth is created here.
 */

$workspacepeople = [];

foreach ($directoryusers as $workspaceperson) {

    if (!\local_ustar\accounts::participates((int)$workspaceperson->id)) {
        continue;
    }

    $workspacepersonpositionid =
        (string)$workspaceperson->positionid;

    $workspacepersonposition =
        $positionmap[$workspacepersonpositionid]
        ?? null;

    $workspacepersondepartment = [];

    if ($workspacepersonposition) {
        $workspacepersondepartment =
            $departmentmap[
                $workspacepersonposition['department']
                ?? ''
            ] ?? [];
    }

    $workspacepersonname =
        trim(
            (string)$workspaceperson->firstname
            .
            ' '
            .
            (string)$workspaceperson->lastname
        );

    if ($workspacepersonname === '') {
        $workspacepersonname =
            'Сотрудник #' . (int)$workspaceperson->id;
    }

    $workspacepeople[] = [
        'id' => (int)$workspaceperson->id,
        'name' => $workspacepersonname,
        'positionid' => $workspacepersonpositionid,
        'positionknown' => !empty($workspacepersonposition),
        'positionname' => $workspacepersonposition
            ? (string)($workspacepersonposition['name'] ?? $workspacepersonpositionid)
            : 'Должность не сопоставлена',
        'departmentid' => $workspacepersonposition
            ? (string)($workspacepersonposition['department'] ?? '')
            : '',
        'department' => $workspacepersonposition
            ? (string)($workspacepersondepartment['name'] ?? '')
            : '',
    ];
}


$workspacepositions = [];

foreach ($positions as $workspaceposition) {

    $workspacepositionid =
        (string)($workspaceposition['id'] ?? '');

    if ($workspacepositionid === '') {
        continue;
    }

    $workspacedepartment =
        $departmentmap[
            $workspaceposition['department']
            ?? ''
        ] ?? [];

    $workspacerequired =
        $structure['matrix'][$workspacepositionid]
        ?? [];

    if (!is_array($workspacerequired)) {
        $workspacerequired = [];
    }

    $workspaceskillrefs = [];

    foreach ($workspacerequired as $workspaceskillid => $workspacelevel) {
        $workspaceskillrefs[] = [
            'id' => (string)$workspaceskillid,
            'level' => (int)$workspacelevel,
        ];
    }

    $workspacepositions[] = [
        'id' => $workspacepositionid,
        'name' => (string)($workspaceposition['name'] ?? $workspacepositionid),
        'departmentid' => (string)($workspaceposition['department'] ?? ''),
        'department' => (string)($workspacedepartment['name'] ?? ''),
        'level' => (int)($workspaceposition['level'] ?? 0),
        'peoplecount' => (int)($occupancy[$workspacepositionid] ?? 0),
        'skillcount' => count($workspacerequired),
        'skills' => $workspaceskillrefs,
        'editurl' => (
            new moodle_url(
                '/local/ustar/positions.php',
                ['positionid' => $workspacepositionid]
            )
        )->out(false),
        'routeurl' => (
            new moodle_url(
                '/local/ustar/route_studio.php',
                ['position' => $workspacepositionid]
            )
        )->out(false),
    ];
}


$workspaceskills = [];

foreach ($skills as $workspaceskill) {

    $workspaceskillid =
        (string)($workspaceskill['id'] ?? '');

    if ($workspaceskillid === '') {
        continue;
    }

    $workspaceaffected = 0;

    foreach ($structure['matrix'] ?? [] as $workspacematrix) {
        if (
            is_array($workspacematrix)
            &&
            array_key_exists(
                $workspaceskillid,
                $workspacematrix
            )
        ) {
            $workspaceaffected++;
        }
    }

    $workspaceskills[] = [
        'id' => $workspaceskillid,
        'name' => (string)($workspaceskill['name'] ?? $workspaceskillid),
        'category' => (string)($workspaceskill['category'] ?? 'Навык'),
        'affectedcount' => $workspaceaffected,
    ];
}


/*
 * Published material relations from the permanent Route Studio route.
 *
 * A material with no skill requirement is preserved and marked unlinked.
 * This is intentional: the workspace must expose modelling gaps, not hide them.
 */
$workspacematerials = [];
foreach ($positions as $workspaceposition) {
    foreach (\local_ustar\career_learning::position_materials((string)$workspaceposition['id']) as $material) {
        $workspacematerials[] = $material;
    }
}

$workspacedepartments = [];

foreach ($structure['departments'] ?? [] as $workspacedepartment) {

    $workspacedepartmentid =
        (string)($workspacedepartment['id'] ?? '');

    if ($workspacedepartmentid === '') {
        continue;
    }

    $workspacedepartments[] = [
        'id' => $workspacedepartmentid,
        'name' => (string)($workspacedepartment['name'] ?? $workspacedepartmentid),
    ];
}


$workspacepayload = [
    'people' => $workspacepeople,
    'positions' => $workspacepositions,
    'skills' => $workspaceskills,
    'materials' => $workspacematerials,
    'departments' => $workspacedepartments,
];

$workspacejson =
    json_encode(
        $workspacepayload,
        JSON_UNESCAPED_UNICODE
        |
        JSON_UNESCAPED_SLASHES
        |
        JSON_HEX_TAG
        |
        JSON_HEX_AMP
        |
        JSON_HEX_APOS
        |
        JSON_HEX_QUOT
    );

if ($workspacejson === false) {
    throw new \coding_exception(
        'Не удалось сформировать USTAR position workspace JSON'
    );
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


$learningcourses = [];
$commonroute = [];
$mandatoryknowledge = [];
$evidencerows = [];
$sourceoptions = [];
$requiredskilloptions = [];
if ($tab === 'learning') {
    $route = \local_ustar\route_scope::base_route($positionid);
    if ($route) {
        $points = $route->routekind === \local_ustar\route_family::KIND_PARENT
            ? \local_ustar\route_scope::points_for_position((int)$route->id, $positionid)
            : \local_ustar\route_model::points((int)$route->id);
        foreach ($points as $point) {
            $version = \local_ustar\route_model::current_published_version((int)$point->id);
            if ($version) {
                $commonroute[] = ['title' => format_string($version->title)];
            }
        }
    }

    $assignment = \local_ustar\assignment::required_courses($positionid);
    foreach ($assignment['courses'] ?? [] as $course) {
        $learningcourses[] = ['name' => $course['name'], 'published' => !empty($course['visible']),
            'url' => (new moodle_url('/course/view.php', ['id' => $course['id']]))->out(false)];
    }
    $mandatoryknowledge = \local_ustar\compliance::for_position($positionid);

    if ($required) {
        [$insql, $params] = $DB->get_in_or_equal(array_keys($required), SQL_PARAMS_NAMED, 'skill');
        $params['positionid'] = $positionid;
        $definitions = $DB->get_records_sql(
            "SELECT * FROM {local_ustar_skill_evidence}
              WHERE active = 1 AND skillid $insql
                AND (positionid = :positionid OR positionid IS NULL OR positionid = '')
           ORDER BY skillid, pathkey, sortorder, id",
            $params
        );
        $typelabels = ['learning' => 'Завершение обучения', 'assessment' => 'Успешная аттестация', 'practice' => 'Практика',
            'manager_review' => 'Оценка руководителя', 'checklist' => 'Чек-лист', 'certification' => 'Сертификация'];
        foreach ($definitions as $definition) {
            $specific = trim((string)$definition->positionid) !== '';
            $course = $DB->get_record('course', ['id' => $definition->courseid], 'id,fullname', IGNORE_MISSING);
            $cm = $course && (int)$definition->cmid > 0
                ? get_fast_modinfo((int)$course->id)->get_cms()[(int)$definition->cmid] ?? null : null;
            $evidencerows[] = ['id' => (int)$definition->id, 'skillname' => $skillmap[$definition->skillid]['name'] ?? $definition->skillid,
                'type' => $typelabels[$definition->evidencetype] ?? $definition->evidencetype,
                'activityname' => $cm?->name ?? ($course && !$definition->cmid ? $course->fullname : 'Источник удалён или не настроен'),
                'coursename' => $cm ? $course->fullname : '', 'pathkey' => $definition->pathkey ?: 'main',
                'weight' => (int)$definition->weight, 'required' => (bool)$definition->required,
                'validdays' => (int)$definition->validdays, 'hasvalidity' => (bool)$definition->validdays,
                'specific' => $specific, 'shared' => !$specific];
        }

        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.visible
               FROM {course} c JOIN {course_modules} cm ON cm.course = c.id
              WHERE c.id <> :site AND cm.deletioninprogress = 0 AND cm.completion > 0
           ORDER BY c.fullname, c.id",
            ['site' => SITEID]
        );
        foreach ($courses as $course) {
            $options = [];
            foreach (get_fast_modinfo((int)$course->id)->get_cms() as $cm) {
                if ($cm->completion > 0 && !$cm->deletioninprogress && !in_array($cm->modname, ['label','qbank'], true)) {
                    $options[] = ['value' => $cm->course . ':' . $cm->id, 'name' => $cm->name];
                }
            }
            if ($options) {
                $sourceoptions[] = ['name' => $course->fullname . ($course->visible ? '' : ' · скрыт'), 'sources' => $options];
            }
        }
        foreach ($required as $skillid => $level) {
            if (isset($skillmap[$skillid])) {
                $requiredskilloptions[] = ['id' => $skillid, 'name' => $skillmap[$skillid]['name']];
            }
        }
    }
}

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

$PAGE->set_url(new moodle_url('/local/ustar/positions.php', ['positionid' => $positionid, 'tab' => $tab]));

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Модели должностей | Центр управления USTAR'
);

$PAGE->set_heading(
    'Центр управления USTAR'
);


/*
 * Four-column workspace runtime.
 */
if ($tab === 'graph') {
$PAGE->requires->js_init_code(
    'window.USTAR_POSITION_WORKSPACE_DATA='
    .
    $workspacejson
    .
    ';'
);

$workspacecssfile =
    __DIR__ . '/positions_workspace.css';

$workspacejsfile =
    __DIR__ . '/positions_workspace.js';

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/positions_workspace.css',
        [
            'v' => file_exists($workspacecssfile)
                ? filemtime($workspacecssfile)
                : time(),
        ]
    )
);

$PAGE->requires->js(
    new moodle_url(
        '/local/ustar/positions_workspace.js',
        [
            'v' => file_exists($workspacejsfile)
                ? filemtime($workspacejsfile)
                : time(),
        ]
    )
);
}

$catalogcss = __DIR__ . '/styles/positions_catalog.css';
$PAGE->requires->css(new moodle_url('/local/ustar/styles/positions_catalog.css',
    ['v' => filemtime($catalogcss)]));

$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


$careertargets = [];
foreach ($positions as $candidate) {
    if ((string)$candidate['id'] === $positionid) {continue;}
    $careertargets[] = ['id'=>(string)$candidate['id'],
        'label'=>(string)($departmentmap[$candidate['department'] ?? '']['name'] ?? '').' — '.(string)$candidate['name'],
        'selected'=>(string)($positionmap[$positionid]['next'] ?? '') === (string)$candidate['id']];
}
$gradeview = \local_ustar\career_grades::view($currentposition);
$PAGE->requires->css(new moodle_url('/local/ustar/styles/consultant_career.css', ['v'=>'20260914-1']));
$application = $tab === 'application'
    ? \local_ustar\position_application::present($positionid,
        is_array($required) ? $required : [], $skillmap, $publishedstandard)
    : [];
$tabnames = [
    'overview' => 'О должности',
    'requirements' => 'Требования',
    'learning' => 'Обучение и проверка',
    'personal' => 'Персональные модели',
    'grades' => 'Грейды',
    'application' => 'Применение и история',
    'graph' => 'Граф связей',
];
$tabs = [];
$taburls = [];
foreach ($tabnames as $key => $name) {
    $url = (new moodle_url('/local/ustar/positions.php',
        ['positionid' => $positionid, 'tab' => $key]))->out(false);
    $tabs[] = ['name' => $name, 'url' => $url, 'active' => $tab === $key];
    $taburls[$key] = $url;
}
$data = [
    'tabs' => $tabs,
    'taburls' => $taburls,
    'catalogurl' => (new moodle_url('/local/ustar/positions.php'))->out(false),
    'showoverview' => $tab === 'overview',
    'showrequirements' => $tab === 'requirements',
    'showlearning' => $tab === 'learning',
    'showpersonal' => $tab === 'personal',
    'profileformerror' => $formerror,
    'learningformerror' => $tab === 'learning' ? $formerror : '',
    'personalprofiles' => $tab === 'personal' ? \local_ustar\position_profile_view::editor(
        $positionid, optional_param('profileid', 0, PARAM_INT), optional_param('newprofile', false, PARAM_BOOL)
            || ($action === 'saveprofile' && empty($profileid)),
        $directoryusers, $skillmap, $required
    ) : [],
    'showgrades' => $tab === 'grades',
    'showapplication' => $tab === 'application',
    'showgraph' => $tab === 'graph',
    'application' => $application,
    'careergrades' => $gradeview,
    'gradeexpected' => \local_ustar\career_grades::fingerprint($currentposition),

    'careertargets' => $careertargets,
    'careerpositionid' => $positionid,
    'careerexpected' => \local_ustar\career_path::fingerprint($structure),

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

    'commonroute' => $commonroute,
    'hascommonroute' => !empty($commonroute),
    'hassources' => !empty($sourceoptions),

    'canmanage' =>
        $canmanage,

    'canpublishstandard' => $canmanage && has_capability('local/ustar:admin', $context)
        && !empty($required) && empty($application['missing'])
        && (!$publishedstandard || !empty($application['haspendingchanges'])),
    'standardpublished' => !empty($publishedstandard),
    'standardversion' => $publishedstandard ? (int)$publishedstandard->versionno : 0,
    'standarddate' => $publishedstandard ? userdate((int)$publishedstandard->effectivedate) : '',
    'matrixhash' => $matrixhash,
    'matrixexpected' => $matrixhash,

    'structureurl' =>
        (new moodle_url('/local/ustar/organization_settings.php'))->out(false),

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
