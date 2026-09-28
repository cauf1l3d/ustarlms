<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/ustar:hrmanage', context_system::instance());

global $DB, $USER, $PAGE, $OUTPUT;
$sourceid = optional_param('source', 0, PARAM_INT);
$targetid = optional_param('target', 0, PARAM_INT);
$pointids = optional_param_array('pointids', [], PARAM_INT);
$routes = $DB->get_records('local_ustar_routes', ['active' => 1], 'name ASC, id ASC',
    'id,name,routekind,positionid,familyid');
$sourcepoints = [];
if ($sourceid > 0 && isset($routes[$sourceid])) {
    foreach (\local_ustar\route_model::points($sourceid) as $point) {
        $version = \local_ustar\route_model::current_published_version((int)$point->id);
        if ($version) {
            $sourcepoints[] = ['id' => (int)$point->id, 'title' => (string)$version->title,
                'selected' => in_array((int)$point->id, $pointids, true)];
        }
    }
}

$preview = null;
if ($sourceid > 0 && $targetid > 0 && $pointids) {
    $preview = \local_ustar\route_intro_transfer::preview($sourceid, $targetid, $pointids);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $ids = \local_ustar\route_intro_transfer::create_drafts($sourceid, $targetid, $pointids,
        required_param('fingerprint', PARAM_ALPHANUM), (int)$USER->id);
    $target = $routes[$targetid];
    $params = ['saved' => 1];
    if ((string)$target->routekind === 'parent') {
        $params['family'] = (int)$DB->get_field('local_ustar_routes', 'familyid', ['id' => $targetid]);
        $params['view'] = 'parent';
    } else {
        $params['position'] = (string)$target->positionid;
    }
    redirect(new moodle_url('/local/ustar/route_studio.php', $params));
}

$PAGE->set_url(new moodle_url('/local/ustar/route_intro_transfer.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Перенос вводного блока');
$PAGE->set_heading('Перенос вводного блока');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/route_v2.css'));
echo $OUTPUT->header();
echo html_writer::start_div('u-product-page u-route-studio u-route-editor');
echo html_writer::tag('header', html_writer::div(html_writer::tag('p', 'Учебный маршрут', ['class' => 'u-page-kicker'])
    . html_writer::tag('h1', 'Перенос вводного блока')
    . html_writer::tag('p', 'Выберите шесть опубликованных шагов исходного маршрута. Проверьте зависимости и создайте черновики в целевом маршруте. Сотрудники увидят их после отдельной публикации.')),
    ['class' => 'u-route-editor__head']);

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'u-route-editor__add u-route-editor__form']);
echo html_writer::tag('h2', 'Выбор маршрутов и шагов');
foreach (['source' => 'Исходный маршрут', 'target' => 'Целевой маршрут'] as $name => $label) {
    $options = html_writer::tag('option', 'Выберите маршрут', ['value' => '0']);
    foreach ($routes as $route) {
        if ($name === 'target' && (int)$route->id === $sourceid) { continue; }
        if ($name === 'target' && (string)$route->routekind === 'position' && !empty($route->familyid)) {
            continue;
        }
        $options .= html_writer::tag('option', (string)$route->name, [
            'value' => (int)$route->id,
            'selected' => (int)$route->id === ($name === 'source' ? $sourceid : $targetid)]);
    }
    echo html_writer::tag('label', html_writer::tag('span', $label)
        . html_writer::tag('select', $options, ['name' => $name, 'required' => true]));
}
if ($sourcepoints) {
    echo html_writer::tag('p', 'Выберите ровно шесть опубликованных шагов в нужном порядке маршрута.');
    foreach ($sourcepoints as $point) {
        echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox',
            'name' => 'pointids[]', 'value' => $point['id'], 'checked' => $point['selected']])
            . ' ' . s($point['title']), ['class' => 'u-route-editor__check']);
    }
}
echo html_writer::tag('button', 'Проверить перенос', ['type' => 'submit', 'class' => 'u-btn u-btn--primary']);
echo html_writer::end_tag('form');

if ($preview) {
    echo html_writer::start_tag('section', ['class' => 'u-route-editor__add u-route-editor__form']);
    echo html_writer::tag('h2', 'Предпросмотр шести шагов');
    foreach ($preview['rows'] as $row) {
        $notes = $row['issues'] ?: ($row['existing']
            ? ['Черновик уже перенесён, повтор не создаст копию.']
            : ['Будет создан неопубликованный шаг. Проверьте ссылки на материалы и Moodle-активности для сотрудников цели.']);
        echo html_writer::tag('h3', s($row['title']));
        echo html_writer::tag('p', 'Версия источника ' . (int)$row['versionid'] . ' · '
            . implode('; ', array_map('s', $notes)));
    }
    if ($preview['ready']) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        foreach (['source' => $sourceid, 'target' => $targetid] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        foreach ($pointids as $id) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pointids[]', 'value' => (int)$id]);
        }
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'fingerprint', 'value' => $preview['fingerprint']]);
        echo html_writer::tag('button', 'Создать шесть черновиков', ['type' => 'submit', 'class' => 'u-btn u-btn--primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_tag('section');
}
echo html_writer::end_div();
echo $OUTPUT->footer();
