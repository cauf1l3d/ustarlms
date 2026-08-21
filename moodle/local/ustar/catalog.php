<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);

$parent = optional_param('parent', 0, PARAM_INT);
$product = optional_param('product', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));

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
echo $output->render_from_template('local_ustar/catalog', $data);
echo $output->footer();
