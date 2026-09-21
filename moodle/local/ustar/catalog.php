<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$canmanage = \local_ustar\catalog::can_manage((int)$USER->id);
if (!$canmanage) {
    require_capability('local/ustar:use', $context);
}

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/ustar:managecatalog', 'nopermissions', '');
    }
    $action = required_param('catalogaction', PARAM_ALPHANUMEXT);
    try {
        if ($action === 'save') {
            $id = optional_param('id', 0, PARAM_INT);
            $record = \local_ustar\catalog::save($id, $_POST, (int)$USER->id);
            if (!empty($_FILES['imagefile']['name'])) {
                \local_ustar\catalog::upload_file((int)$record->id, (int)$USER->id,
                    \local_ustar\catalog::FILEAREA_IMAGE, $_FILES['imagefile']);
            }
            if (!empty($_FILES['sourcefile']['name'])) {
                \local_ustar\catalog::upload_file((int)$record->id, (int)$USER->id,
                    \local_ustar\catalog::FILEAREA_SOURCE, $_FILES['sourcefile']);
            }
            redirect(new moodle_url('/local/ustar/catalog.php', ['edit' => (int)$record->id, 'saved' => 1]));
        }
        if ($action === 'archive') {
            \local_ustar\catalog::archive(
                required_param('id', PARAM_INT), (int)$USER->id, required_param('expectedmodified', PARAM_INT)
            );
            redirect(new moodle_url('/local/ustar/catalog.php', ['archived' => 1]));
        }
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

if (!$canmanage && !\local_ustar\catalog_mastery::has_access((int)$USER->id)) {
    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/local/ustar/catalog.php'));
    $PAGE->set_pagelayout('ustar');
    $PAGE->set_title('Каталог товаров | USTAR Academy');
    $PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
$PAGE->set_heading('USTAR Academy');
    $output = $PAGE->get_renderer('local_ustar');
    echo $output->header();
    echo $output->render_from_template('local_ustar/catalog_locked', [
        'learningurl' => (new moodle_url('/local/ustar/home.php', ['view' => 'learning']))->out(false),
        'examurl' => (new moodle_url('/local/ustar/route.php'))->out(false),
    ]);
    echo $output->footer();
    exit;
}

$parent = optional_param('parent', 0, PARAM_INT);
$product = optional_param('product', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));
$edit = optional_param('edit', 0, PARAM_INT);

$current = $parent ? \local_ustar\catalog::get($parent) : null;
$detail = $product ? \local_ustar\catalog::view($product) : null;
$items = \local_ustar\catalog::browse($q !== '' ? null : ($parent ?: null), $q);
$stats = \local_ustar\catalog::stats();
$breadcrumbs = [];
if ($current) {
    $breadcrumbs = \local_ustar\catalog::ancestors((int)$current->id);
}
if ($detail && !empty($detail['parentid'])) {
    $breadcrumbs = \local_ustar\catalog::ancestors((int)$detail['id']);
}

$data = [
    'q' => $q,
    'items' => $items,
    'hasitems' => !empty($items),
    'title' => $current ? format_string($current->title) : 'Каталог товаров',
    'subtitle' => $current
        ? 'Товарные знания по выбранному разделу.'
        : 'Рабочий ассортимент: товары, материалы и проверки знаний для торгового зала.',
    'rooturl' => (new moodle_url('/local/ustar/catalog.php'))->out(false),
    'breadcrumbs' => $breadcrumbs,
    'hasbreadcrumbs' => !empty($breadcrumbs),
    'detail' => $detail,
    'hasdetail' => (bool)$detail,
    'fallbackimage' => $OUTPUT->image_url('brand/ustar-course-placeholder', 'theme_ustar')->out(false),
    'catalogicon' => \local_ustar\ui::icon('knowledge', 'u-feature-icon'),
    'stats' => [
        'groups' => (int)$stats['groups'],
        'subgroups' => (int)$stats['subgroups'],
        'cards' => (int)$stats['cards'],
        'products' => (int)$stats['products'],
        'assessments' => (int)$stats['assessments'],
    ],
    'searching' => $q !== '',
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/catalog.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Каталог товаров | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
if (optional_param('saved', 0, PARAM_BOOL)) {
    echo $OUTPUT->notification('Карточка каталога сохранена.', 'notifysuccess');
}
if (optional_param('archived', 0, PARAM_BOOL)) {
    echo $OUTPUT->notification('Карточка перенесена в архив. История сохранена.', 'notifysuccess');
}
echo $output->render_from_template('local_ustar/catalog', $data);

if ($canmanage) {
    $records = \local_ustar\catalog::editor_records((int)$USER->id);
    $editing = null;
    foreach ($records as $record) {
        if ((int)$record->id === $edit) {
            $editing = $record;
            break;
        }
    }
    $value = static function(string $name, string $default = '') use ($editing): string {
        return $editing && isset($editing->$name) ? (string)$editing->$name : $default;
    };
    $currenttype = $value('itemtype', \local_ustar\catalog::TYPE_GROUP);
    $currentparent = (int)$value('parentid', '0');
    $attributes = $value('attributesjson');
    if ($attributes !== '') {
        $decoded = json_decode($attributes, true);
        if (is_array($decoded)) {
            $attributes = implode("\n", array_map(
                static fn($key, $item): string => $key . ': ' . (is_scalar($item) ? $item : ''),
                array_keys($decoded), array_values($decoded)
            ));
        }
    }
    echo html_writer::start_div('u-catalog-editor');
    echo html_writer::tag('h2', $editing ? 'Редактирование карточки' : 'Новая карточка каталога');
    echo html_writer::tag('p', 'HR может прямо здесь создавать и менять разделы, категории, карточки, свойства, изображения и материалы. История каждой карточки сохраняется.');
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'action' => (new moodle_url('/local/ustar/catalog.php'))->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catalogaction', 'value' => 'save']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $editing ? (int)$editing->id : 0]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'expectedmodified', 'value' => $editing ? (int)$editing->timemodified : 0]);
    echo html_writer::tag('label', 'Название');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required', 'value' => $value('title'), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Тип');
    $options = [
        \local_ustar\catalog::TYPE_GROUP => 'Раздел',
        \local_ustar\catalog::TYPE_SUBGROUP => 'Категория',
        \local_ustar\catalog::TYPE_PRODUCT => 'Товар',
        \local_ustar\catalog::TYPE_MATERIAL => 'Материал',
        \local_ustar\catalog::TYPE_ASSESSMENT => 'Проверка знаний',
    ];
    echo html_writer::select($options, 'itemtype', $currenttype, false, ['class' => 'form-select']);
    echo html_writer::tag('label', 'Родитель');
    $parents = [0 => '— корневой раздел —'];
    foreach ($records as $candidate) {
        if ($editing && (int)$candidate->id === (int)$editing->id) { continue; }
        $parents[(int)$candidate->id] = str_repeat('— ', (int)$candidate->itemtype === \local_ustar\catalog::TYPE_SUBGROUP ? 1 : 0)
            . format_string((string)$candidate->title);
    }
    echo html_writer::select($parents, 'parentid', $currentparent, false, ['class' => 'form-select']);
    echo html_writer::tag('label', 'Краткое описание');
    echo html_writer::tag('textarea', s($value('summary')), ['name' => 'summary', 'rows' => 2, 'class' => 'form-control']);
    echo html_writer::tag('label', 'Полное описание');
    echo html_writer::tag('textarea', s($value('description')), ['name' => 'description', 'rows' => 5, 'class' => 'form-control']);
    echo html_writer::tag('label', 'Артикул');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'sku', 'value' => $value('sku'), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Свойства: по одному в строке «Название: значение»');
    echo html_writer::tag('textarea', s($attributes), ['name' => 'attributes', 'rows' => 4, 'class' => 'form-control']);
    echo html_writer::tag('label', 'Ссылка на изображение (необязательно)');
    echo html_writer::empty_tag('input', ['type' => 'url', 'name' => 'imageurl', 'value' => $value('imageurl'), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Или загрузите изображение');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'imagefile', 'accept' => 'image/jpeg,image/png,image/webp,image/gif']);
    echo html_writer::tag('label', 'Исходный файл карточки (необязательно)');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'sourcefile']);
    echo html_writer::tag('label', 'Порядок');
    echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'sortorder', 'value' => $value('sortorder', '0'), 'class' => 'form-control']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить карточку', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    if ($editing) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/ustar/catalog.php'))->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catalogaction', 'value' => 'archive']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'expectedmodified', 'value' => (int)$editing->timemodified]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Архивировать карточку', 'class' => 'btn btn-outline-danger']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::tag('p', html_writer::link(new moodle_url('/local/ustar/catalog.php'), 'Создать новую карточку'));
    echo html_writer::tag('h3', 'Все карточки для редактирования');
    echo html_writer::start_tag('ul');
    foreach ($records as $record) {
        echo html_writer::tag('li', html_writer::link(
            new moodle_url('/local/ustar/catalog.php', ['edit' => (int)$record->id]),
            format_string((string)$record->title) . ' · ' . s((string)$record->itemtype)
        ));
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_div();
}
echo $output->footer();
