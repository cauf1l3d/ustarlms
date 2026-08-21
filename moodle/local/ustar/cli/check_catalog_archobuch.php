<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require($config);

$seed = 'archobuch_20260820';
$rows = $DB->get_records('local_ustar_catalog', ['active' => 1]);
$seedrows = [];
foreach ($rows as $row) {
    $attrs = json_decode((string)$row->attributesjson, true);
    if (is_array($attrs) && (string)($attrs['_seed'] ?? '') === $seed) {
        $seedrows[] = $row;
    }
}
$counts = ['product' => 0, 'material' => 0, 'assessment' => 0];
foreach ($seedrows as $row) {
    if (isset($counts[$row->itemtype])) {
        $counts[$row->itemtype]++;
    }
}
$groups = $DB->get_records('local_ustar_catalog', ['active' => 1, 'itemtype' => 'group']);
$expectedgroups = ['Инструменты','Крепеж','Лакокрасочные материалы','Строительный отдел','Электрика','Электроинструменты'];
$foundgroups = [];
foreach ($groups as $g) {
    if (in_array((string)$g->title, $expectedgroups, true)) {
        $foundgroups[(string)$g->title] = true;
    }
}
$context = context_system::instance();
$fs = get_file_storage();
$images = 0;
$sources = 0;
foreach ($seedrows as $row) {
    if ($fs->get_area_files($context->id, 'local_ustar', 'catalog_image', (int)$row->id, 'id', false)) {
        $images++;
    }
    if ($fs->get_area_files($context->id, 'local_ustar', 'catalog_source', (int)$row->id, 'id', false)) {
        $sources++;
    }
}

echo 'SEED=' . $seed . PHP_EOL;
echo 'GROUPS_FOUND=' . count($foundgroups) . '/6' . PHP_EOL;
echo 'CARDS=' . count($seedrows) . '/116' . PHP_EOL;
echo 'PRODUCTS=' . $counts['product'] . '/112' . PHP_EOL;
echo 'MATERIALS=' . $counts['material'] . '/2' . PHP_EOL;
echo 'ASSESSMENTS=' . $counts['assessment'] . '/2' . PHP_EOL;
echo 'CARDS_WITH_IMAGE=' . $images . '/53' . PHP_EOL;
echo 'CARDS_WITH_SOURCE=' . $sources . '/116' . PHP_EOL;
$ok = count($foundgroups) === 6
    && count($seedrows) === 116
    && $counts['product'] === 112
    && $counts['material'] === 2
    && $counts['assessment'] === 2
    && $images === 53
    && $sources === 116;
if (!$ok) {
    echo "CATALOG_ARCHOBUCH_CHECK=FAIL\n";
    exit(1);
}
echo "CATALOG_ARCHOBUCH_CHECK=OK\n";
