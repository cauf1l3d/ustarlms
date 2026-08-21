<?php
// Import USTAR catalog hierarchy and binary assets from a generated manifest.
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require($config);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'dir' => null,
    'dry-run' => false,
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || empty($options['dir'])) {
    echo "USTAR catalog manifest import\n\n";
    echo "php local/ustar/cli/import_catalog_manifest.php --dir=/path/catalog_seed [--dry-run]\n";
    exit($options['help'] ? 0 : 2);
}

if (!\local_ustar\catalog::available()) {
    cli_error('Catalog table is unavailable.');
}

$seeddir = rtrim((string)$options['dir'], '/');
$manifestfile = $seeddir . '/manifest.json';
if (!is_readable($manifestfile)) {
    cli_error('Manifest is not readable: ' . $manifestfile);
}

$manifest = json_decode(file_get_contents($manifestfile), true);
if (!is_array($manifest) || empty($manifest['seed']) || !isset($manifest['items']) || !is_array($manifest['items'])) {
    cli_error('Invalid catalog manifest.');
}

$seed = (string)$manifest['seed'];
$allowedtypes = ['product', 'material', 'assessment'];
$seen = [];
$groups = [];
$subgroups = [];
$images = 0;
$sources = 0;
foreach ($manifest['items'] as $n => $item) {
    $line = $n + 1;
    foreach (['group', 'itemtype', 'slug', 'title'] as $required) {
        if (trim((string)($item[$required] ?? '')) === '') {
            cli_error("Item {$line}: missing {$required}");
        }
    }
    if (!in_array((string)$item['itemtype'], $allowedtypes, true)) {
        cli_error("Item {$line}: unsupported itemtype");
    }
    $slug = (string)$item['slug'];
    if (isset($seen[$slug])) {
        cli_error("Duplicate slug in manifest: {$slug}");
    }
    $seen[$slug] = true;
    $groups[(string)$item['group']] = true;
    if (!empty($item['subgroup'])) {
        $subgroups[(string)$item['group'] . '|' . (string)$item['subgroup']] = true;
    }
    foreach (['image_file', 'source_file'] as $field) {
        if (empty($item[$field])) {
            continue;
        }
        $path = $seeddir . '/' . ltrim((string)$item[$field], '/');
        if (!is_readable($path)) {
            cli_error("Item {$line}: missing asset {$field}: {$path}");
        }
        if ($field === 'image_file') {
            $images++;
        } else {
            $sources++;
        }
    }
}

$counts = array_count_values(array_map(static fn($i) => (string)$i['itemtype'], $manifest['items']));
echo 'SEED=' . $seed . PHP_EOL;
echo 'GROUPS=' . count($groups) . PHP_EOL;
echo 'SUBGROUPS=' . count($subgroups) . PHP_EOL;
echo 'CARDS=' . count($manifest['items']) . PHP_EOL;
echo 'PRODUCTS=' . (int)($counts['product'] ?? 0) . PHP_EOL;
echo 'MATERIALS=' . (int)($counts['material'] ?? 0) . PHP_EOL;
echo 'ASSESSMENTS=' . (int)($counts['assessment'] ?? 0) . PHP_EOL;
echo 'IMAGES=' . $images . PHP_EOL;
echo 'SOURCES=' . $sources . PHP_EOL;

if ($options['dry-run']) {
    echo "CATALOG_MANIFEST_DRY_RUN=OK\n";
    exit(0);
}

$context = context_system::instance();
$fs = get_file_storage();
$now = time();
$actor = 0; // CLI import is system-owned.
$transaction = $DB->start_delegated_transaction();

$foldercache = [];
$upsertfolder = static function(string $title, string $type, ?int $parentid) use (&$foldercache, $DB, $now, $actor): int {
    $key = $type . '|' . ($parentid ?? 0) . '|' . \core_text::strtolower(trim($title));
    if (isset($foldercache[$key])) {
        return $foldercache[$key];
    }
    $params = ['type' => $type, 'title' => $title, 'active' => 1];
    $select = 'itemtype = :type AND title = :title AND active = :active AND ' . ($parentid ? 'parentid = :parentid' : 'parentid IS NULL');
    if ($parentid) {
        $params['parentid'] = $parentid;
    }
    $existing = $DB->get_record_select('local_ustar_catalog', $select, $params, '*', IGNORE_MISSING);
    if ($existing) {
        return $foldercache[$key] = (int)$existing->id;
    }
    $id = $DB->insert_record('local_ustar_catalog', (object)[
        'parentid' => $parentid,
        'itemtype' => $type,
        'title' => $title,
        'slug' => null,
        'sku' => null,
        'summary' => null,
        'description' => null,
        'imageurl' => null,
        'attributesjson' => null,
        'active' => 1,
        'sortorder' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
        'usermodified' => $actor,
    ]);
    return $foldercache[$key] = (int)$id;
};

$created = 0;
$updated = 0;
$filewrites = 0;
foreach ($manifest['items'] as $sort => $item) {
    $groupid = $upsertfolder(trim((string)$item['group']), 'group', null);
    $parentid = $groupid;
    if (trim((string)($item['subgroup'] ?? '')) !== '') {
        $parentid = $upsertfolder(trim((string)$item['subgroup']), 'subgroup', $groupid);
    }

    $slug = trim((string)$item['slug']);
    $existing = $DB->get_record('local_ustar_catalog', ['slug' => $slug], '*', IGNORE_MISSING);
    if (!$existing) {
        $existing = $DB->get_record('local_ustar_catalog', [
            'parentid' => $parentid,
            'itemtype' => (string)$item['itemtype'],
            'title' => trim((string)$item['title']),
        ], '*', IGNORE_MISSING);
    }

    $attrs = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
    $attrs['_seed'] = $seed;
    $attrs['_source'] = (string)($attrs['_source'] ?? $item['source_filename'] ?? '');
    $attrs['_cardtype'] = (string)$item['itemtype'];

    $record = $existing ?: (object)['timecreated' => $now];
    $record->parentid = $parentid;
    $record->itemtype = (string)$item['itemtype'];
    $record->title = trim((string)$item['title']);
    $record->slug = $slug;
    $record->sku = trim((string)($item['sku'] ?? '')) ?: null;
    $record->summary = trim((string)($item['summary'] ?? '')) ?: null;
    $record->description = trim((string)($item['description'] ?? '')) ?: null;
    $record->imageurl = null;
    $record->attributesjson = json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $record->active = 1;
    $record->sortorder = (int)$sort;
    $record->timemodified = $now;
    $record->usermodified = $actor;

    if ($existing) {
        $DB->update_record('local_ustar_catalog', $record);
        $id = (int)$existing->id;
        $updated++;
    } else {
        $id = (int)$DB->insert_record('local_ustar_catalog', $record);
        $created++;
    }

    foreach ([
        'image_file' => \local_ustar\catalog::FILEAREA_IMAGE,
        'source_file' => \local_ustar\catalog::FILEAREA_SOURCE,
    ] as $manifestfield => $filearea) {
        $fs->delete_area_files($context->id, 'local_ustar', $filearea, $id);
        if (empty($item[$manifestfield])) {
            continue;
        }
        $path = $seeddir . '/' . ltrim((string)$item[$manifestfield], '/');
        $filename = $manifestfield === 'source_file'
            ? trim((string)($item['source_filename'] ?? basename($path)))
            : basename($path);
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_ustar',
            'filearea' => $filearea,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => clean_param($filename, PARAM_FILE),
        ], $path);
        $filewrites++;
    }
}

$transaction->allow_commit();

echo "CREATED={$created}\n";
echo "UPDATED={$updated}\n";
echo "FILE_RECORDS_WRITTEN={$filewrites}\n";
echo "CATALOG_MANIFEST_IMPORT=OK\n";
