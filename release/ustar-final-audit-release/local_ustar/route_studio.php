<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;
$context = context_system::instance();
require_capability('local/ustar:hrmanage', $context);

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
$positionid = optional_param('position', '', PARAM_ALPHANUMEXT);
if ($positionid === '' && $positions) { $positionid = (string)$positions[0]['id']; }

$skillmap = [];
foreach ($structure['skills'] ?? [] as $skill) {
    $id = clean_param((string)($skill['id'] ?? ''), PARAM_ALPHANUMEXT);
    if ($id !== '') { $skillmap[$id] = (string)($skill['name'] ?? $id); }
}

/** Convert human-selected catalog values to one immutable route-version payload. */
$requirements_from_request = static function() use ($DB, $skillmap): array {
    $requirements = [];
    $required = optional_param('required', 0, PARAM_BOOL) ? true : false;
    $completionmode = optional_param('completionmode', 'open', PARAM_ALPHA);
    $completionmode = in_array($completionmode, ['open', 'ack'], true) ? $completionmode : 'open';
    foreach (optional_param_array('contentids', [], PARAM_INT) as $contentid) {
        $content = $DB->get_record('local_ustar_content', ['id' => $contentid], 'id,title,type', IGNORE_MISSING);
        if (!$content || (string)$content->type === 'folder') {
            throw new invalid_parameter_exception('Выбранный материал больше недоступен. Обновите форму.');
        }
        $requirements[] = ['type' => 'content', 'sourceid' => (int)$content->id, 'completionmode' => $completionmode,
            'required' => $required, 'label' => (string)$content->title];
    }
    $courseid = optional_param('courseid', 0, PARAM_INT);
    if ($courseid > 0) {
        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', MUST_EXIST);
        $requirements[] = ['type' => 'course', 'sourceid' => (int)$course->id, 'required' => $required,
            'label' => (string)$course->fullname];
    }
    $cmid = optional_param('cmid', 0, PARAM_INT);
    if ($cmid > 0) {
        $module = $DB->get_record_sql('SELECT cm.id, cm.instance, m.name AS modname, c.fullname FROM {course_modules} cm '
            . 'JOIN {modules} m ON m.id = cm.module JOIN {course} c ON c.id = cm.course '
            . 'WHERE cm.id = :id AND cm.deletioninprogress = 0', ['id' => $cmid], MUST_EXIST);
        $activityname = (string)$module->modname;
        if (preg_match('/^[a-z_]+$/', (string)$module->modname)) {
            $candidate = $DB->get_field((string)$module->modname, 'name', ['id' => (int)$module->instance], IGNORE_MISSING);
            if ($candidate) { $activityname = (string)$candidate; }
        }
        $requirements[] = ['type' => 'cm', 'sourceid' => (int)$module->id, 'required' => $required,
            'label' => (string)$module->fullname . ' — ' . $activityname];
    }
    $primaryskillid = optional_param('primaryskillid', '', PARAM_ALPHANUMEXT);
    $skillids = optional_param_array('skillids', [], PARAM_ALPHANUMEXT);
    if ($primaryskillid !== '' && !in_array($primaryskillid, $skillids, true)) { $skillids[] = $primaryskillid; }
    foreach (array_values(array_unique($skillids)) as $skillid) {
        if (!isset($skillmap[$skillid])) {
            throw new invalid_parameter_exception('Выбранный навык больше не существует. Обновите форму.');
        }
        $requirements[] = ['type' => 'skill', 'sourcekey' => $skillid, 'required' => $required,
            'primary' => $skillid === $primaryskillid, 'label' => $skillmap[$skillid]];
    }
    if (optional_param('previousadaptation', 0, PARAM_BOOL)) {
        $requirements[] = ['type' => 'previous_adaptation', 'required' => true,
            'label' => 'Завершить предыдущие обязательные точки'];
    }
    return \local_ustar\route_model::normalize_requirements($requirements);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $positionid = required_param('position', PARAM_ALPHANUMEXT);
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $actorid = (int)$USER->id;
    $route = \local_ustar\route_model::ensure_route($positionid, $actorid);
    $anchor = '';
    if ($action === 'ensure') {
        \local_ustar\route_model::seed_from_required_courses($positionid, $actorid);
    } else if ($action === 'reorder') {
        \local_ustar\route_model::reorder((int)$route->id, optional_param_array('pointids', [], PARAM_INT), $actorid,
            optional_param('revision', '', PARAM_ALPHANUM));
    } else if ($action === 'add_point' || $action === 'save_version') {
        $title = required_param('title', PARAM_TEXT);
        $phase = optional_param('phase', \local_ustar\route_model::PHASE_ADAPTATION, PARAM_ALPHANUMEXT);
        $status = optional_param('status', \local_ustar\route_model::STATUS_DRAFT, PARAM_ALPHANUMEXT);
        $requirements = $requirements_from_request();
        if ($status === \local_ustar\route_model::STATUS_PUBLISHED && !$requirements) {
            throw new moodle_exception('Нельзя опубликовать точку без обучения или условия завершения. Сохраните её как черновик.');
        }
        $versiondata = ['title' => $title, 'summary' => optional_param('summary', '', PARAM_TEXT), 'requirements' => $requirements,
            'renewalpolicy' => optional_param('renewalpolicy', \local_ustar\route_model::RENEW_KEEP, PARAM_ALPHANUMEXT),
            'validdays' => optional_param('validdays', 0, PARAM_INT), 'status' => $status,
            'effectivedate' => $status === \local_ustar\route_model::STATUS_PUBLISHED ? time() : 0];
        if ($action === 'add_point') {
            $sort = 10;
            foreach (\local_ustar\route_model::points((int)$route->id) as $point) { $sort = max($sort, (int)$point->sortorder + 10); }
            $created = \local_ustar\route_model::add_point((int)$route->id,
                'point_' . substr(sha1($positionid . ':' . $title . ':' . microtime(true)), 0, 12), $phase, $sort, $versiondata, $actorid);
            $anchor = '#point-' . (int)$created->id;
        } else {
            $pointid = required_param('pointid', PARAM_INT);
            \local_ustar\route_model::update_point((int)$route->id, $pointid, $phase,
                optional_param('active', 0, PARAM_BOOL), $actorid, required_param('expectedmodified', PARAM_INT));
            \local_ustar\route_model::create_version($pointid, $versiondata, $actorid);
            $anchor = '#point-' . $pointid;
        }
    } else if ($action === 'archive_point') {
        $pointid = required_param('pointid', PARAM_INT);
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'routeid' => (int)$route->id], 'id,phase,timemodified', MUST_EXIST);
        \local_ustar\route_model::update_point((int)$route->id, $pointid, (string)$point->phase, false, $actorid, (int)$point->timemodified);
    } else { throw new invalid_parameter_exception('Неизвестное действие редактора маршрута'); }
    $redirecturl = new moodle_url('/local/ustar/route_studio.php', ['position' => $positionid, 'saved' => 1]);
    if ($anchor !== '') { $redirecturl->set_anchor(ltrim($anchor, '#')); }
    redirect($redirecturl);
}

$route = $positionid !== '' ? \local_ustar\route_model::admin_view($positionid) : null;
$routeexists = !empty($route['ok']);
$editpointid = optional_param('point', 0, PARAM_INT);
$newcontentid = optional_param('newcontent', 0, PARAM_INT);
$contentoptions = [];
foreach ($DB->get_records_select('local_ustar_content', 'type <> :folder AND status <> :archived', ['folder' => 'folder', 'archived' => 'archived'], 'title ASC', 'id,title,type,status') as $item) {
    $contentoptions[(int)$item->id] = ['id' => (int)$item->id, 'name' => (string)$item->title,
        'meta' => ((string)$item->type === 'video' ? 'Видео' : 'Материал') . ((string)$item->status === 'draft' ? ' · Черновик' : '')];
}
$courseoptions = [];
foreach ($DB->get_records_select('course', 'id > 1', [], 'fullname ASC', 'id,fullname') as $course) {
    $courseoptions[(int)$course->id] = ['id' => (int)$course->id, 'name' => (string)$course->fullname];
}
$activityoptions = [];
$activitysql = 'SELECT cm.id, cm.course, cm.instance, m.name AS modname, c.fullname FROM {course_modules} cm '
    . 'JOIN {modules} m ON m.id = cm.module JOIN {course} c ON c.id = cm.course WHERE cm.deletioninprogress = 0 AND c.id > 1 ORDER BY c.fullname, cm.id';
foreach ($DB->get_records_sql($activitysql) as $activity) {
    $activityname = (string)$activity->modname;
    if (preg_match('/^[a-z_]+$/', (string)$activity->modname)) {
        $candidate = $DB->get_field((string)$activity->modname, 'name', ['id' => (int)$activity->instance], IGNORE_MISSING);
        if ($candidate) { $activityname = (string)$candidate; }
    }
    $activityoptions[(int)$activity->id] = ['id' => (int)$activity->id, 'name' => (string)$activity->fullname . ' — ' . $activityname];
}

if ($routeexists) {
    foreach ($route['points'] as &$point) {
        $latest = \local_ustar\route_model::latest_version((int)$point['id']);
        $requirements = $latest ? \local_ustar\route_model::requirements_for_version($latest) : [];
        $selectedcontents = []; $selectedskills = []; $primaryskill = ''; $selectedcourse = 0; $selectedcm = 0; $previous = false;
        foreach ($requirements as $requirement) {
            if (($requirement['type'] ?? '') === 'content') { $selectedcontents[] = (int)$requirement['sourceid']; }
            if (($requirement['type'] ?? '') === 'course') { $selectedcourse = (int)$requirement['sourceid']; }
            if (($requirement['type'] ?? '') === 'cm') { $selectedcm = (int)$requirement['sourceid']; }
            if (($requirement['type'] ?? '') === 'skill') { $selectedskills[] = (string)$requirement['sourcekey']; if (!empty($requirement['primary'])) { $primaryskill = (string)$requirement['sourcekey']; } }
            $previous = $previous || (($requirement['type'] ?? '') === 'previous_adaptation');
        }
        $point['editing'] = (int)$point['id'] === $editpointid;
        $point['expectedmodified'] = (int)$DB->get_field('local_ustar_route_points', 'timemodified', ['id' => (int)$point['id']], MUST_EXIST);
        $point['formtitle'] = $latest ? (string)$latest->title : '';
        $point['formsummary'] = $latest ? (string)$latest->summary : '';
        $point['formvaliddays'] = $latest ? (int)$latest->validdays : 0;
        $point['activechecked'] = !empty($DB->get_field('local_ustar_route_points', 'active', ['id' => (int)$point['id']])) ? 'checked' : '';
        $point['adaptationselected'] = (string)$point['phase'] === \local_ustar\route_model::PHASE_ADAPTATION;
        $point['gateselected'] = (string)$point['phase'] === \local_ustar\route_model::PHASE_GATE;
        $point['continuousselected'] = (string)$point['phase'] === \local_ustar\route_model::PHASE_CONTINUOUS;
        $point['keepselected'] = !$latest || (string)$latest->renewalpolicy === \local_ustar\route_model::RENEW_KEEP;
        $point['allselected'] = $latest && (string)$latest->renewalpolicy === \local_ustar\route_model::RENEW_ALL;
        $point['expiryselected'] = $latest && (string)$latest->renewalpolicy === \local_ustar\route_model::RENEW_EXPIRY;
        $point['manualselected'] = $latest && (string)$latest->renewalpolicy === \local_ustar\route_model::RENEW_MANUAL;
        $point['contentoptions'] = array_values(array_map(static function(array $item) use ($selectedcontents, $newcontentid): array { $item['selected'] = in_array((int)$item['id'], $selectedcontents, true) || (int)$item['id'] === $newcontentid; return $item; }, $contentoptions));
        $point['courseoptions'] = array_values(array_map(static function(array $item) use ($selectedcourse): array { $item['selected'] = (int)$item['id'] === $selectedcourse; return $item; }, $courseoptions));
        $point['activityoptions'] = array_values(array_map(static function(array $item) use ($selectedcm): array { $item['selected'] = (int)$item['id'] === $selectedcm; return $item; }, $activityoptions));
        $point['skilloptions'] = []; $point['primaryskills'] = [];
        foreach ($skillmap as $id => $name) { $point['skilloptions'][] = ['id' => $id, 'name' => $name, 'selected' => in_array($id, $selectedskills, true)]; $point['primaryskills'][] = ['id' => $id, 'name' => $name, 'selected' => $id === $primaryskill]; }
        $point['previouschecked'] = $previous ? 'checked' : '';
        $point['uploadurl'] = (new moodle_url('/local/ustar/material_create.php', [
            'returnto' => 'route_studio',
            'position' => $positionid,
            'routepoint' => (int)$point['id'],
            'pointmodified' => (int)$point['expectedmodified'],
        ]))->out(false);
    }
    unset($point);
}
$positionoptions = [];
foreach ($positions as $position) {
    $departmentname = $departments[(string)($position['department'] ?? '')] ?? '';
    $positionoptions[] = ['id' => (string)$position['id'], 'name' => ($departmentname !== '' ? $departmentname . ' — ' : '') . (string)$position['name'], 'selected' => (string)$position['id'] === $positionid];
}
$addcontentoptions = array_values(array_map(static function(array $item) use ($newcontentid): array { $item['selected'] = (int)$item['id'] === $newcontentid; return $item; }, $contentoptions));
$addskilloptions = []; foreach ($skillmap as $id => $name) { $addskilloptions[] = ['id' => $id, 'name' => $name]; }

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/route_studio.php', ['position' => $positionid]));
$PAGE->set_pagelayout('ustar'); $PAGE->set_title('Конструктор маршрутов | USTAR'); $PAGE->set_heading('Центр управления USTAR');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/route_v2.css')); $PAGE->requires->js_call_amd('local_ustar/route_studio', 'init');
$data = ['positionid' => $positionid, 'positions' => $positionoptions, 'route' => $routeexists ? $route : null, 'routeexists' => $routeexists,
    'noroute' => !$routeexists, 'saved' => optional_param('saved', 0, PARAM_BOOL), 'attached' => optional_param('attached', 0, PARAM_BOOL), 'sesskey' => sesskey(),
    'revision' => $routeexists ? \local_ustar\route_model::revision((int)$route['routeid']) : '',
    'previewurl' => (new moodle_url('/local/ustar/route.php', ['position' => $positionid]))->out(false),
    'materialsurl' => (new moodle_url('/local/ustar/materials.php'))->out(false),
    'uploadurl' => (new moodle_url('/local/ustar/material_create.php', ['returnto' => 'route_studio', 'position' => $positionid]))->out(false),
    'addcontentoptions' => $addcontentoptions, 'courseoptions' => array_values($courseoptions), 'activityoptions' => array_values($activityoptions),
    'skilloptions' => $addskilloptions, 'primaryskills' => $addskilloptions];
$output = $PAGE->get_renderer('local_ustar'); echo $output->header(); echo $output->render_from_template('local_ustar/route_studio', $data); echo $output->footer();
