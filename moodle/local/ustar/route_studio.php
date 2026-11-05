<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;
$context = context_system::instance();
\local_ustar\hr_access::require_structure_manager();

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$departments = [];
foreach ($structure['departments'] ?? [] as $department) {
    $departments[(string)$department['id']] = (string)$department['name'];
}
$positions = array_values($structure['positions'] ?? []);
usort($positions, static function(array $left, array $right) use ($departments): int {
    return [($departments[(string)($left['department'] ?? '')] ?? ''), (string)($left['name'] ?? '')]
        <=> [($departments[(string)($right['department'] ?? '')] ?? ''), (string)($right['name'] ?? '')];
});
$positionmap = [];
foreach ($positions as $position) {
    $positionmap[(string)$position['id']] = $position;
}

$familyid = optional_param('family', 0, PARAM_INT);
$viewmode = optional_param('view', '', PARAM_ALPHA);
$positionid = optional_param('position', '', PARAM_ALPHANUMEXT);

$families = array_values(
    $DB->get_records(
        'local_ustar_route_families',
        ['active' => 1],
        'name ASC, id ASC'
    )
);

/*
 * Old position URLs remain valid. If the position belongs to a route
 * family, Route Studio automatically opens that family.
 */
if ($familyid <= 0 && $positionid !== '') {
    $positionroute =
        \local_ustar\route_model::get_route(
            $positionid
        );

    if (
        $positionroute
        &&
        (int)($positionroute->familyid ?? 0) > 0
    ) {
        $familyid =
            (int)$positionroute->familyid;
    }
}

/*
 * Default Studio landing: first active family -> common route.
 */
if (
    $familyid <= 0
    &&
    $positionid === ''
    &&
    $families
) {
    $familyid = (int)$families[0]->id;
    $viewmode = 'parent';
}

$selectedfamily = null;
$selectedroute = null;

if ($familyid > 0) {
    $selectedfamily =
        \local_ustar\route_family::family(
            $familyid
        );

    if (!$selectedfamily) {
        throw new moodle_exception(
            'Семья маршрутов не найдена'
        );
    }

    $parentroute =
        \local_ustar\route_family::parent_route(
            $familyid
        );

    if (!$parentroute) {
        throw new moodle_exception(
            'У семьи маршрутов нет общего маршрута'
        );
    }

    if (
        $viewmode === 'position'
        &&
        $positionid !== ''
    ) {
        /*
         * Position tabs are resolved views of the one physical
         * parent route. Legacy child routes are used only to prove
         * that the position belongs to this route family.
         */
        $membership =
            $DB->get_record(
                'local_ustar_routes',
                [
                    'familyid' => $familyid,
                    'positionid' => $positionid,
                    'routekind' => 'position',
                    'active' => 1,
                ]
            );

        if (!$membership) {
            throw new invalid_parameter_exception(
                'Должность не относится к выбранной семье маршрутов'
            );
        }

        $viewmode = 'position';
        $selectedroute = $parentroute;

    } else {
        $viewmode = 'parent';
        $positionid = '';
        $selectedroute = $parentroute;
    }

} else {
    /*
     * Compatibility path for positions which do not yet belong
     * to a USTAR route family.
     */
    $viewmode = 'position';

    if ($positionid === '' && $positions) {
        $positionid =
            (string)$positions[0]['id'];
    }

    if ($positionid !== '') {
        $selectedroute =
            \local_ustar\route_model::get_route(
                $positionid
            );
    }
}

$skillmap = [];
foreach ($structure['skills'] ?? [] as $skill) {
    $id = clean_param((string)($skill['id'] ?? ''), PARAM_ALPHANUMEXT);
    if ($id !== '') { $skillmap[$id] = (string)($skill['name'] ?? $id); }
}

$formerror='';$postedform=null;$previewonly=false;
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array(optional_param('action','',PARAM_ALPHANUMEXT),['save_version','add_point'],true)) {
    $postedform=\local_ustar\route_studio_form::posted($_POST);
    $previewonly=in_array(optional_param('intent','',PARAM_ALPHA),['addrow','search'],true);
    if ($previewonly) { require_sesskey();\local_ustar\view_as::assert_writable();if (optional_param('intent','',PARAM_ALPHA)==='addrow') { $postedform['rows'][]=['base'=>'','required'=>'1']; } }
}
// Resolve permitted source choices once per request, without any writer calls.
$learningchoices=[];
$blueprints=\local_ustar\material_studio::available() ? $DB->get_records('local_ustar_content_blueprints',[],'','contentid,kind') : [];
foreach ($DB->get_records_select('local_ustar_content','type<>:folder AND status<>:archived',['folder'=>'folder','archived'=>'archived'],'title','id,title,status,cmid') as $r) {
    if (($blueprints[$r->id]->kind??'')===\local_ustar\material_studio::KIND_SCORM) {
        if ($r->status!=='published' || !\local_ustar\material_studio::runtime_ready((int)$r->id)) { continue; }
        $learningchoices['content:'.$r->id]=['type'=>'cm','sourceid'=>(int)$r->cmid,'label'=>'SCORM · '.$r->title];
    } else { $learningchoices['content:'.$r->id]=['type'=>'content','sourceid'=>(int)$r->id,'label'=>'Материал · '.$r->title,'completionmode'=>'open']; }
}
foreach ($DB->get_records_select('course','id>1',[],'fullname','id,fullname') as $r) {
    $learningchoices['course:'.$r->id]=['type'=>'course','sourceid'=>(int)$r->id,'label'=>'Курс · '.$r->fullname];
}
$modules=$DB->get_records_sql('SELECT cm.id,cm.instance,m.name AS modname,c.fullname FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module JOIN {course} c ON c.id=cm.course WHERE cm.deletioninprogress=0 AND c.id>1 ORDER BY c.fullname,cm.id');
$modulenames=[];
foreach ($modules as $m) { if (preg_match('/^[a-z_]+$/D',$m->modname)) { $modulenames[$m->modname][]=(int)$m->instance; } }
foreach ($modulenames as $name=>$ids) { $modulenames[$name]=$DB->get_records_list($name,'id',array_unique($ids),'','id,name'); }
foreach ($modules as $r) {
    $label=$r->fullname.' · '.($modulenames[$r->modname][$r->instance]->name??$r->modname);
    $learningchoices['cm:'.$r->id]=['type'=>'cm','sourceid'=>(int)$r->id,'label'=>$label];
}
foreach (\local_ustar\development_assessment::catalog() as $r) {
    $learningchoices['assessment:'.$r['key']]=['type'=>'assessment','sourcekey'=>(string)$r['key'],'label'=>$r['title']];
}
foreach (\local_ustar\native_learning::authoring_options() as $r) {
    $learningchoices['native:'.$r['id']]=['type'=>'native','sourcekey'=>(string)$r['id'],'label'=>$r['name']];
}
foreach ($skillmap as $id=>$name) { $learningchoices['skill:'.$id]=['type'=>'skill','sourcekey'=>$id,'required'=>false,'primary'=>false,'label'=>'Навык · '.$name]; }
$learningchoices['previous_adaptation']=['type'=>'previous_adaptation','label'=>'Предыдущие обязательные шаги'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$previewonly) {
    try {
    require_sesskey();
    \local_ustar\view_as::assert_writable();

    $familyid =
        optional_param(
            'family',
            0,
            PARAM_INT
        );

    $viewmode =
        optional_param(
            'view',
            'position',
            PARAM_ALPHA
        );

    $positionid =
        optional_param(
            'position',
            '',
            PARAM_ALPHANUMEXT
        );

    $action =
        required_param(
            'action',
            PARAM_ALPHANUMEXT
        );

    $actorid = (int)$USER->id;

    
    /*
     * Editing target:
     * - parent = common route of family
     * - position = normal permanent position route
     */
    if (
        $familyid > 0
        &&
        $viewmode === 'parent'
    ) {
        $route =
            \local_ustar\route_family::parent_route(
                $familyid
            );

        if (!$route) {
            throw new moodle_exception(
                'У семьи маршрутов нет общего маршрута'
            );
        }

    } else {
        if ($positionid === '') {
            throw new invalid_parameter_exception(
                'Не выбрана должность'
            );
        }

        $route =
            \local_ustar\route_model::ensure_route(
                $positionid,
                $actorid
            );

        if (
            $familyid > 0
            &&
            (int)($route->familyid ?? 0) !== $familyid
        ) {
            throw new invalid_parameter_exception(
                'Должность не относится к выбранной семье'
            );
        }
    }

    $positionediting = $familyid > 0 && $viewmode === 'position';
    if ($positionediting) {
        // The membership was checked above. All writes now target the same physical parent.
        $route = \local_ustar\route_family::parent_route($familyid);
        if (!$route) { throw new invalid_parameter_exception('Нет родительского маршрута'); }
        if (!in_array($action, ['save_version', 'set_point_active', 'make_override', 'revert_parent'], true)) {
            throw new invalid_parameter_exception('Это действие выполняется в общем маршруте');
        }
    }

    $commandlock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('route-studio:' . (int)$route->id, 10);
    if (!$commandlock) { throw new moodle_exception('Маршрут занят другим сохранением. Повторите попытку.'); }
    try {
    $commandtransaction = $DB->start_delegated_transaction();
    try {
    $anchor = '';
    $editafter = 0;
    if ($positionediting && $action === 'save_version') {
        $editingpointid = required_param('pointid', PARAM_INT);
        if (!\local_ustar\route_scope::exclusive_to($editingpointid, $positionid)
                || !in_array($editingpointid,array_map(static fn($p)=>(int)$p->id,\local_ustar\route_scope::points_for_position((int)$route->id,$positionid,true,true)),true)) {
            throw new invalid_parameter_exception('Общий шаг нельзя менять для всех из вкладки должности');
        }
    }


    if ($action === 'ensure') {

        if ($positionid === '') {
            throw new invalid_parameter_exception(
                'Общий маршрут уже существует'
            );
        }

        \local_ustar\route_model::seed_from_required_courses(
            $positionid,
            $actorid
        );

    } else if ($action === 'reorder') {

        /*
         * Inherited common points take their order from parent.
         * A position route with inherited points cannot silently
         * reorder them independently.
         */
        if (
            (string)($route->routekind ?? '') === 'position'
            &&
            $DB->record_exists(
                'local_ustar_route_points',
                [
                    'routeid' =>
                        (int)$route->id,

                    'inheritstate' =>
                        \local_ustar\route_family::INHERITED,

                    'active' =>
                        1,
                ]
            )
        ) {
            throw new moodle_exception(
                'Порядок общих наследуемых шагов '
                . 'изменяется в Общем маршруте'
            );
        }

        \local_ustar\route_commands::reorder(
            (int)$route->id,
            optional_param_array(
                'pointids',
                [],
                PARAM_INT
            ),
            $actorid,
            optional_param(
                'revision',
                '',
                PARAM_ALPHANUM
            )
        );

    } else if (
        $action === 'add_point'
        ||
        $action === 'save_version'
    ) {
        $title =
            required_param(
                'title',
                PARAM_TEXT
            );

        $phase =
            optional_param(
                'phase',
                \local_ustar\route_model::PHASE_ADAPTATION,
                PARAM_ALPHANUMEXT
            );

        $status =
            optional_param(
                'status',
                \local_ustar\route_model::STATUS_DRAFT,
                PARAM_ALPHANUMEXT
            );

        $baseversion=null;$baserequirements=[];
        if ($action==='save_version') {
            $baseversion=\local_ustar\route_model::latest_version(required_param('pointid',PARAM_INT));
            if ((int)($baseversion->id??0)!==required_param('baseversion',PARAM_INT)) {
                throw new moodle_exception('Содержание точки изменено другим редактором. Введённые данные сохранены в форме; обновите страницу и сверьте изменения.');
            }
            $baserequirements=$baseversion?\local_ustar\route_model::requirements_for_version($baseversion):[];
        }
        $requirements=\local_ustar\route_studio_form::decode($_POST,$baserequirements,$learningchoices);

        if (
            $status
                === \local_ustar\route_model::STATUS_PUBLISHED
            &&
            !$requirements
        ) {
            throw new moodle_exception(
                'Нельзя опубликовать шаг без обучения '
                . 'или условия завершения'
            );
        }

        $accesspolicy = null;
        if (optional_param('advancepolicy', '', PARAM_ALPHA) !== '') {
            $unit = optional_param('reviewunit', 'hours', PARAM_ALPHA);
            if (!in_array($unit, ['hours','days'], true)) { throw new invalid_parameter_exception('Неизвестная единица срока.'); }
            $accesspolicy = \local_ustar\route_point_access::normalize([
                'advance'=>required_param('advancepolicy',PARAM_ALPHA),
                'repeat'=>required_param('repeataccess',PARAM_ALPHA),
                'finish'=>required_param('finishaction',PARAM_ALPHA),
                'seconds'=>(int)round(optional_param('reviewduration',24,PARAM_FLOAT) * ($unit === 'days' ? DAYSECS : HOURSECS))]);
        }

        $versiondata = [
            'title' =>
                $title,

            'summary' =>
                optional_param(
                    'summary',
                    '',
                    PARAM_TEXT
                ),

            'requirements' =>
                $requirements,

            'renewalpolicy' =>
                optional_param(
                    'renewalpolicy',
                    \local_ustar\route_model::RENEW_KEEP,
                    PARAM_ALPHANUMEXT
                ),

            'validdays' =>
                optional_param(
                    'validdays',
                    0,
                    PARAM_INT
                ),

            'status' =>
                $status,

            'effectivedate' =>
                $status
                    === \local_ustar\route_model::STATUS_PUBLISHED
                ? time()
                : 0,
        ];

        if ($accesspolicy !== null) { $versiondata['accesspolicy'] = $accesspolicy; }

        if ($action === 'add_point') {
            $sort = 10;

            foreach (
                \local_ustar\route_model::points(
                    (int)$route->id
                )
                as $point
            ) {
                $sort =
                    max(
                        $sort,
                        (int)$point->sortorder + 10
                    );
            }

            $created =
                \local_ustar\route_model::add_point(
                    (int)$route->id,
                    'point_'
                        . substr(
                            sha1(
                                (int)$route->id
                                . ':'
                                . $title
                                . ':'
                                . microtime(true)
                            ),
                            0,
                            12
                        ),
                    $phase,
                    $sort,
                    $versiondata,
                    $actorid
                );

            $anchor =
                '#point-'
                . (int)$created->id;

        } else {
            $pointid =
                required_param(
                    'pointid',
                    PARAM_INT
                );

            $point =
                $DB->get_record(
                    'local_ustar_route_points',
                    [
                        'id' => $pointid,
                        'routeid' => (int)$route->id,
                    ],
                    '*',
                    MUST_EXIST
                );

            if (
                (string)($point->inheritstate ?? '')
                === \local_ustar\route_family::INHERITED
            ) {
                throw new moodle_exception(
                    'Наследуемый общий шаг нельзя '
                    . 'редактировать напрямую. '
                    . 'Сначала выберите '
                    . '«Изменить для этой должности»'
                );
            }

            if ($positionediting && !empty($point->sourcepointid)) {
                \local_ustar\route_scope::assert_replaceable((int)$point->sourcepointid);
            }

            \local_ustar\route_commands::save_version([
                'routeid' => (int)$route->id,
                'pointid' => $pointid,
                'positionid' => $positionid,
                'positionediting' => $positionediting,
                'phase' => $phase,
                'active' => !empty($point->active),
                'versiondata' => $versiondata,
                'baseversion' => (int)($baseversion->id??0),
                'actorid' => $actorid,
                'expectedmodified' => required_param('expectedmodified', PARAM_INT),
            ]);

            $anchor =
                '#point-'
                . $pointid;
        }

    } else if ($action === 'make_override') {
        $pointid =
            required_param(
                'pointid',
                PARAM_INT
            );

        $point =
            $DB->get_record(
                'local_ustar_route_points',
                [
                    'id' => $pointid,
                    'routeid' => (int)$route->id,
                ],
                '*',
                MUST_EXIST
            );

        if (!$positionediting) { throw new invalid_parameter_exception('Выберите должность'); }
        $editafter = \local_ustar\route_commands::create_override(
            (int)$route->id, $pointid, $positionid, $actorid
        );
        $anchor = '#point-' . $editafter;

    } else if ($action === 'revert_parent') {
        $pointid =
            required_param(
                'pointid',
                PARAM_INT
            );

        $point =
            $DB->get_record(
                'local_ustar_route_points',
                [
                    'id' => $pointid,
                    'routeid' => (int)$route->id,
                ],
                '*',
                MUST_EXIST
            );

        if (!$positionediting) { throw new invalid_parameter_exception('Выберите должность'); }
        $sourceid = \local_ustar\route_commands::revert_override(
            (int)$route->id, $pointid, $positionid, $actorid
        );
        $anchor = '#point-' . $sourceid;

    } else if ($action === 'set_point_active') {
        \local_ustar\route_commands::set_point_active([
            'routeid'=>(int)$route->id,'pointid'=>required_param('pointid',PARAM_INT),
            'actorid'=>$actorid,'expectedmodified'=>required_param('expectedmodified',PARAM_INT),
            'positionid'=>$positionid,'positionediting'=>$positionediting,'active'=>required_param('active',PARAM_BOOL)]);
    } else {
        throw new invalid_parameter_exception(
            'Неизвестное действие редактора маршрута'
        );
    }

    $redirectparams = [
        'saved' => 1,
        'family' => $familyid,
        'view' => $viewmode,
    ];

    if ($positionid !== '') {
        $redirectparams['position'] =
            $positionid;
    }

    if ($editafter > 0) {
        $redirectparams['point'] =
            $editafter;
    }

    $redirecturl =
        new moodle_url(
            '/local/ustar/route_studio.php',
            $redirectparams
        );

    if ($anchor !== '') {
        $redirecturl->set_anchor(
            ltrim(
                $anchor,
                '#'
            )
        );
    }

    $commandtransaction->allow_commit();
    } catch (\Throwable $e) { $commandtransaction->rollback($e); }
    } finally { $commandlock->release(); }

    redirect($redirecturl);
    } catch (moodle_exception $e) {
        if (($e instanceof dml_exception && !($e instanceof dml_missing_record_exception)) || $e instanceof required_capability_exception) { throw $e; }
        $formerror=$e instanceof dml_missing_record_exception ? 'Один из выбранных источников или шагов больше недоступен. Обновите список и проверьте обучение.' : \local_ustar\route_studio_presenter::error($e);
    }
}

/*
 * TARGET Studio:
 * - parent tab = editable source of truth;
 * - position tab = resolved view of that same physical parent route.
 */
$resolvedview =
    $selectedfamily !== null
    &&
    $viewmode === 'position'
    &&
    $positionid !== '';

if ($selectedroute) {
    if (
        $selectedfamily
        &&
        (string)($selectedroute->routekind ?? '') === 'parent'
    ) {
        $route =
            \local_ustar\route_model::admin_view_route(
                (int)$selectedroute->id,
                $resolvedview ? $positionid : '', true
            );

        if ($route && $resolvedview) {
            $applicableids=array_map(static fn($p)=>(int)$p->id,\local_ustar\route_scope::points_for_position((int)$selectedroute->id,$positionid,true,true));
            $route['points'] =
                array_values(
                    array_filter(
                        $route['points'] ?? [],
                        static function(array $point) use ($applicableids): bool {
                            return in_array((int)$point['id'],$applicableids,true);
                        }
                    )
                );

            $route['pointcount'] =
                count($route['points']);

            $route['resolvedposition'] = true;
            $route['resolvedpositionid'] = $positionid;

            if (isset($positionmap[$positionid])) {
                $route['resolvedpositionname'] =
                    (string)$positionmap[$positionid]['name'];

                $route['name'] =
                    (string)$selectedfamily->name
                    . ' · '
                    . $route['resolvedpositionname'];
            }
        }

    } else {
        $route =
            \local_ustar\route_model::admin_view_route((int)$selectedroute->id,(string)$selectedroute->positionid,true);
    }

} else {
    $route = null;
}

if ($route && !$resolvedview && $selectedfamily) {
    $route['points'] = array_values(array_filter($route['points'] ?? [],
        static fn(array $p): bool => ($p['inheritstate'] ?? '') !== 'override'));
    $route['pointcount'] = count($route['points']);
    $route['haspoints'] = !empty($route['points']);
}

$routeexists = !empty($route['ok']);
$editpointid = $postedform!==null ? (int)($postedform['pointid']??0) : optional_param('point',0,PARAM_INT);
$newcontentid = optional_param('newcontent', 0, PARAM_INT);
if ($routeexists) {
    $route['hasinherited'] = false;

    foreach ($route['points'] as $candidatepoint) {
        if (
            (string)($candidatepoint['inheritstate'] ?? '')
            === \local_ustar\route_family::INHERITED
        ) {
            $route['hasinherited'] = true;
            break;
        }
    }

    /*
     * Parent controls common order.
     * A child containing inherited points cannot silently
     * reorder the common sequence.
     */
    $route['canreorder'] =
        !empty($route['isparent'])
        ||
        empty($route['hasinherited']);

    $route['routekicker'] =
        !empty($route['isparent'])
        ? 'Общий маршрут семьи'
        : 'Маршрут должности';

    $route['routebadge'] =
        !empty($route['isparent'])
        ? 'Общий маршрут'
        : 'Должностной маршрут';

    $route['routedescription'] =
        !empty($route['isparent'])
        ? 'Общие шаги автоматически распространяются на должностные маршруты семьи, если для конкретной должности не создано переопределение.'
        : 'Маршрут объединяет общие наследуемые шаги и обучение только для этой должности.';

    $route['addkicker'] =
        !empty($route['isparent'])
        ? 'Новый общий шаг'
        : 'Новый должностной шаг';

    $route['addtitle'] =
        !empty($route['isparent'])
        ? 'Добавить общий шаг'
        : 'Добавить шаг только для этой должности';

    if ($resolvedview) {
        $route['canreorder'] = false;
        $route['routekicker'] = 'Редактирование маршрута должности';
        $route['routebadge'] = 'Итоговый маршрут';
        $route['routedescription'] =
            'Личные шаги редактируются напрямую, общие — через «Изменить для этой должности». '
            . 'Черновик переопределения заменит общий шаг для сотрудника после публикации.';

    } else if ($selectedfamily) {
        $route['canreorder'] = true;
        $route['routekicker'] = 'Единый маршрут семьи';
        $route['routebadge'] = 'Источник истины';
        $route['routedescription'] =
            'Один физический маршрут для всей семьи. '
            . 'Применимость каждого шага определяет, какие должности увидят его.';
    }

    foreach ($route['points'] as &$point) {
        $latest = \local_ustar\route_model::latest_version((int)$point['id']);
        $requirements = $latest ? \local_ustar\route_model::requirements_for_version($latest) : [];
        $publishedversion = \local_ustar\route_model::current_published_version((int)$point['id']);
        // Only the newest draft is actionable in Studio. Archived drafts remain
        // available through history but must not be presented as the next publish.
        $draftversion = $latest
            && (string)$latest->status === \local_ustar\route_model::STATUS_DRAFT
            ? $latest
            : null;
        $versiondiff = \local_ustar\route_model::version_diff(
            $publishedversion,
            $draftversion
        );
        $state =
            (string)($point['inheritstate'] ?? 'local');

        $point['isinherited'] =
            empty($route['isparent'])
            &&
            $state === \local_ustar\route_family::INHERITED;

        $point['isoverride'] =
            empty($route['isparent'])
            &&
            $state === \local_ustar\route_family::OVERRIDE;

        $point['islocal'] =
            empty($route['isparent'])
            &&
            $state === \local_ustar\route_family::LOCAL;

        $point['iscommon'] =
            !empty($route['isparent']);

        if (!empty($route['isparent'])) {
            $point['inheritancelabel'] =
                'Общий шаг';

        } else if ($point['isinherited']) {
            $point['inheritancelabel'] =
                'Общий шаг · наследуется';

        } else if ($point['isoverride']) {
            $point['inheritancelabel'] =
                'Общий шаг · изменён для должности';

        } else {
            $point['inheritancelabel'] =
                'Только для этой должности';
        }

        /*
         * TARGET point applicability.
         *
         * confirmed = effective employee route
         * proposed  = suggestion waiting for HR review
         */
        $scoperows =
            $DB->get_records(
                'local_ustar_route_scope',
                [
                    'pointid' => (int)$point['id'],
                    'active' => 1,
                ],
                'state ASC, id ASC'
            );

        $confirmedids = [];
        $proposedids = [];

        foreach ($scoperows as $scoperow) {
            $scopeid = (string)$scoperow->scopeid;

            if ((string)$scoperow->state === 'confirmed') {
                $confirmedids[] = $scopeid;
            } else if ((string)$scoperow->state === 'proposed') {
                $proposedids[] = $scopeid;
            }
        }

        // Compatibility: an old unscoped parent point means common.
        if (!$confirmedids && !$proposedids) {
            $confirmedids = ['all'];
        }

        $scopenames = static function(array $ids) use ($positionmap): array {
            $names = [];

            foreach (array_values(array_unique($ids)) as $id) {
                if ($id === 'all') {
                    $names[] = 'Все должности семьи';
                    continue;
                }

                $names[] =
                    isset($positionmap[$id])
                    ? (string)$positionmap[$id]['name']
                    : $id;
            }

            return $names;
        };

        if (in_array('all', $confirmedids, true)) {
            $point['inheritancelabel'] = 'Общий шаг';
            $point['iscommon'] = true;
        } else if ($confirmedids) {
            $names = $scopenames($confirmedids);

            $point['inheritancelabel'] =
                $resolvedview
                ? 'Для этой должности'
                : 'Для: ' . implode(' · ', $names);

            $point['iscommon'] = false;
        } else {
            $point['inheritancelabel'] =
                'Применимость не подтверждена';

            $point['iscommon'] = false;
        }

        $point['hasscopeproposal'] =
            !$resolvedview
            &&
            !empty($proposedids);

        $point['scopeproposal'] =
            $point['hasscopeproposal']
            ? 'Предложено: '
                . implode(
                    ' · ',
                    $scopenames($proposedids)
                )
                . ' · требуется подтверждение HR'
            : '';

        $exclusive = $resolvedview && \local_ustar\route_scope::exclusive_to((int)$point['id'], $positionid);
        $point['canedit'] = !$resolvedview || $exclusive;
        $point['canoverride'] = $resolvedview && !$exclusive && empty($point['sourcepointid']);
        $point['canrevert'] = $resolvedview && $exclusive && !empty($point['sourcepointid'])
            && ($point['inheritstate'] ?? '') === 'override';
        if ($resolvedview) {
            $point['inheritancelabel'] = $point['canrevert'] ? 'Изменён для этой должности'
                : ($exclusive ? 'Только для этой должности' : 'Общий шаг');
        }

        $point['canmove'] =
            !$resolvedview
            &&
            !empty($route['canreorder']);

        $point['draggable'] =
            $point['canmove']
            ? 'true'
            : 'false';

        $point['draggableflag'] =
            $point['canmove']
            ? '1'
            : '0';

        $point['editing'] =
            $point['canedit']
            &&
            (int)$point['id'] === $editpointid;

        $baseparams = [
            'family' => $familyid,
            'view' => $viewmode,
        ];

        if ($positionid !== '') {
            $baseparams['position'] =
                $positionid;
        }

        $editparams = $baseparams;
        $editparams['point'] =
            (int)$point['id'];

        $point['editurl'] =
            (new moodle_url(
                '/local/ustar/route_studio.php',
                $editparams
            ))->out(false);

        $point['cancelurl'] =
            (new moodle_url(
                '/local/ustar/route_studio.php',
                $baseparams
            ))->out(false)
            . '#point-'
            . (int)$point['id'];

        $point['hidden']=empty($point['active']);
        $point['visible']=!$point['hidden'];
        $point['canvisibility']=$point['canedit'];
        if ($point['hidden']) { $point['canmove']=false;$point['draggable']='false';$point['draggableflag']='0'; }
        $point['expectedmodified'] = (int)$DB->get_field('local_ustar_route_points', 'timemodified', ['id' => (int)$point['id']], MUST_EXIST);
        $point['formtitle'] = $latest ? (string)$latest->title : '';
        $point['formsummary'] = $latest ? (string)$latest->summary : '';
        $point['formvaliddays'] = $latest ? (int)$latest->validdays : 0;
        $accesspolicy = $latest ? \local_ustar\route_point_access::policy($latest) : \local_ustar\route_point_access::normalize([]);
        foreach (['auto','manual','timed'] as $choice) { $point['access'.$choice] = $accesspolicy['advance'] === $choice; }
        foreach (['close','keep'] as $choice) { $point['access'.$choice] = $accesspolicy['repeat'] === $choice; }
        foreach (['route','next','stay'] as $choice) { $point['finish'.$choice] = $accesspolicy['finish'] === $choice; }
        $point['reviewduration'] = $accesspolicy['seconds'] > 0 ? $accesspolicy['seconds'] / HOURSECS : 24;

        $point['hasversiondiff'] = !empty($versiondiff['haschanges']);
        $point['versiondiffrows'] = $versiondiff['rows'];
        $point['versiondiffadded'] = array_map(
            static fn(string $label): array => ['label' => $label],
            $versiondiff['requirementsadded']
        );
        $point['versiondiffremoved'] = array_map(
            static fn(string $label): array => ['label' => $label],
            $versiondiff['requirementsremoved']
        );
        $point['hasversiondiffadded'] = !empty($versiondiff['hasrequirementsadded']);
        $point['hasversiondiffremoved'] = !empty($versiondiff['hasrequirementsremoved']);
        $point['versiondiffpublishedlabel'] = !empty($versiondiff['publishedversion'])
            ? 'v' . (int)$versiondiff['publishedversion']
            : 'нет публикации';
        $point['versiondiffdraftlabel'] = !empty($versiondiff['draftversion'])
            ? 'v' . (int)$versiondiff['draftversion']
            : '';
        if ($point['editing']) {
            $formversion=$latest;
            if ($postedform!==null && (int)($postedform['baseversion']??0)>0) {
                $old=$DB->get_record('local_ustar_route_versions',['id'=>(int)$postedform['baseversion'],'pointid'=>(int)$point['id']]);
                if ($old) { $formversion=$old; }
            }
            $formdata=\local_ustar\route_studio_presenter::form($formversion,$learningchoices,$postedform);
            if ($formdata['expectedmodified']===null) { unset($formdata['expectedmodified']); }
            $point=array_replace($point,$formdata);
        }
        $point['canupload'] =
            empty($route['isparent'])
            &&
            $positionid !== '';

        $point['uploadurl'] =
            $point['canupload']
            ? (
                new moodle_url(
                    '/local/ustar/material_create.php',
                    [
                        'returnto' =>
                            'route_studio',

                        'position' =>
                            $positionid,

                        'routepoint' =>
                            (int)$point['id'],

                        'pointmodified' =>
                            (int)$point['expectedmodified'],
                    ]
                )
            )->out(false)
            : '';
    }
    unset($point);
}
$positionoptions = [];
foreach ($positions as $position) {
    $departmentname = $departments[(string)($position['department'] ?? '')] ?? '';
    $positionoptions[] = ['id' => (string)$position['id'], 'name' => ($departmentname !== '' ? $departmentname . ' — ' : '') . (string)$position['name'], 'selected' => (string)$position['id'] === $positionid];
}
$familyoptions = [];

foreach ($families as $family) {
    $familyoptions[] = [
        'id' => (int)$family->id,
        'name' => (string)$family->name,
        'selected' =>
            (int)$family->id === $familyid,
    ];
}

$familytabs = [];

if ($selectedfamily) {
    $parentroute =
        \local_ustar\route_family::parent_route(
            (int)$selectedfamily->id
        );

    if ($parentroute) {
        $familytabs[] = [
            'label' => 'Общий маршрут',
            'selected' =>
                $viewmode === 'parent',

            'url' =>
                (
                    new moodle_url(
                        '/local/ustar/route_studio.php',
                        [
                            'family' =>
                                (int)$selectedfamily->id,

                            'view' =>
                                'parent',
                        ]
                    )
                )->out(false),
        ];
    }

    foreach (
        \local_ustar\route_family::children(
            (int)$selectedfamily->id
        )
        as $childroute
    ) {
        $childpositionid =
            (string)$childroute->positionid;

        $label =
            isset($positionmap[$childpositionid])
            ? (string)$positionmap[$childpositionid]['name']
            : $childpositionid;

        $familytabs[] = [
            'label' =>
                $label,

            'selected' =>
                $viewmode === 'position'
                &&
                $positionid === $childpositionid,

            'url' =>
                (
                    new moodle_url(
                        '/local/ustar/route_studio.php',
                        [
                            'family' =>
                                (int)$selectedfamily->id,

                            'view' =>
                                'position',

                            'position' =>
                                $childpositionid,
                        ]
                    )
                )->out(false),
        ];
    }
}

$pageparams = [];

if ($familyid > 0) {
    $pageparams['family'] =
        $familyid;

    $pageparams['view'] =
        $viewmode;
}

if ($positionid !== '') {
    $pageparams['position'] =
        $positionid;
}

$PAGE->set_context($context);

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/route_studio.php',
        $pageparams
    )
);

$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Конструктор маршрутов | USTAR');
$PAGE->set_heading('Центр управления USTAR');

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/route_v2.css'
    )
);

$PAGE->requires->js_call_amd(
    'local_ustar/route_studio',
    'init'
);

$canpreview =
    $routeexists
    &&
    $positionid !== ''
    &&
    (
        $resolvedview
        ||
        empty($route['isparent'])
    );

$previewurl =
    $canpreview
    ? (
        new moodle_url(
            '/local/ustar/route.php',
            [
                'position' =>
                    $positionid,
            ]
        )
    )->out(false)
    : '';

$data = [
    'familyid' =>
        $familyid,

    'familyselected' =>
        $selectedfamily !== null,

    'familyname' =>
        $selectedfamily
        ? (string)$selectedfamily->name
        : '',

    'familyoptions' =>
        $familyoptions,

    'familytabs' =>
        $familytabs,

    'viewmode' =>
        $viewmode,

    'resolvedview' =>
        $resolvedview,

    'caneditroute' =>
        !$resolvedview,

    'positionid' =>
        $positionid,

    'positions' =>
        $positionoptions,

    'route' =>
        $routeexists
        ? $route
        : null,

    'routeexists' =>
        $routeexists,

    'noroute' =>
        !$routeexists,

    'saved' =>
        optional_param(
            'saved',
            0,
            PARAM_BOOL
        ),

    'attached' =>
        optional_param(
            'attached',
            0,
            PARAM_BOOL
        ),

    'sesskey' =>
        sesskey(),

    'formerror'=>$formerror,
    'revision' =>
        $routeexists
        ? \local_ustar\route_model::revision(
            (int)$route['routeid']
        )
        : '',

    'canpreview' =>
        $canpreview,

    'previewurl' =>
        $previewurl,

    'materialsurl' =>
        (
            new moodle_url(
                '/local/ustar/materials.php'
            )
        )->out(false),

    'introtransferurl' =>
        (new moodle_url('/local/ustar/route_intro_transfer.php',
            ['target' => $routeexists ? (int)$route['routeid'] : 0]))->out(false),

    'uploadurl' =>
        (
            new moodle_url(
                '/local/ustar/material_create.php',
                [
                    'returnto' =>
                        'route_studio',

                    'position' =>
                        $positionid,
                ]
            )
        )->out(false),


];

$output =
    $PAGE->get_renderer(
        'local_ustar'
    );

$data['formerror']=$formerror;
$data['addform']=\local_ustar\route_studio_presenter::form(null,$learningchoices,$postedform!==null && empty($postedform['pointid'])?$postedform:null);
echo $output->header();

echo $output->render_from_template(
    'local_ustar/route_studio',
    $data
);

echo $output->footer();
