<?php
// Import a product-knowledge hierarchy from CSV.
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'file' => null,
    'dry-run' => false,
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || empty($options['file'])) {
    echo "USTAR product catalog import\n\n";
    echo "php local/ustar/cli/import_catalog.php --file=/path/catalog.csv [--dry-run]\n";
    echo "Columns: group,subgroup,sku,title,summary,description,imageurl,attributes_json\n";
    exit($options['help'] ? 0 : 2);
}
if (!\local_ustar\catalog::available()) {
    cli_error('Catalog table is unavailable. Run Moodle upgrade first.');
}
$file = (string)$options['file'];
if (!is_readable($file)) {
    cli_error('CSV file is not readable: ' . $file);
}

$h = fopen($file, 'rb');
$header = fgetcsv($h);
if (!$header) cli_error('CSV is empty');
$header = array_map(static fn($v) => trim((string)$v), $header);
$required = ['group','subgroup','sku','title','summary','description','imageurl','attributes_json'];
$idx = [];
foreach ($required as $name) {
    $idx[$name] = array_search($name, $header, true);
    if ($idx[$name] === false) cli_error('Missing column: ' . $name);
}

$items = [];
$line = 1;
while (($row = fgetcsv($h)) !== false) {
    $line++;
    $value = static fn(string $name): string => trim((string)($row[$idx[$name]] ?? ''));
    $group = $value('group');
    $subgroup = $value('subgroup');
    $title = $value('title');
    if ($group === '' || $title === '') {
        cli_error("Line {$line}: group and title are required");
    }
    $attrs = $value('attributes_json');
    if ($attrs !== '') {
        json_decode($attrs, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            cli_error("Line {$line}: invalid attributes_json: " . json_last_error_msg());
        }
    }
    $items[] = [
        'group'=>$group,'subgroup'=>$subgroup,'sku'=>$value('sku'),'title'=>$title,
        'summary'=>$value('summary'),'description'=>$value('description'),'imageurl'=>$value('imageurl'),
        'attributesjson'=>$attrs,
    ];
}
fclose($h);

echo 'Validated products: ' . count($items) . PHP_EOL;
if ($options['dry-run']) {
    echo "DRY_RUN=YES\nCATALOG_IMPORT=VALID\n";
    exit(0);
}

$now = time();
$actor = (int)($USER->id ?? 0);
$transaction = $DB->start_delegated_transaction();
$cache = [];
$upsertfolder = static function(string $title, string $type, ?int $parentid) use (&$cache, $DB, $now, $actor): int {
    $key = $type . '|' . ($parentid ?? 0) . '|' . \core_text::strtolower($title);
    if (isset($cache[$key])) return $cache[$key];
    $params = ['itemtype'=>$type, 'title'=>$title, 'active'=>1];
    $select = 'itemtype=:itemtype AND title=:title AND active=:active AND ' . ($parentid ? 'parentid=:parentid' : 'parentid IS NULL');
    if ($parentid) $params['parentid'] = $parentid;
    $existing = $DB->get_record_select('local_ustar_catalog', $select, $params, 'id');
    if ($existing) return $cache[$key] = (int)$existing->id;
    $id = $DB->insert_record('local_ustar_catalog', (object)[
        'parentid'=>$parentid,'itemtype'=>$type,'title'=>$title,'slug'=>null,'sku'=>null,'summary'=>null,'description'=>null,
        'imageurl'=>null,'attributesjson'=>null,'active'=>1,'sortorder'=>0,'timecreated'=>$now,'timemodified'=>$now,'usermodified'=>$actor,
    ]);
    return $cache[$key] = (int)$id;
};

$created = 0; $updated = 0;
foreach ($items as $item) {
    $groupid = $upsertfolder($item['group'], 'group', null);
    $parentid = $groupid;
    if ($item['subgroup'] !== '') {
        $parentid = $upsertfolder($item['subgroup'], 'subgroup', $groupid);
    }
    $existing = null;
    if ($item['sku'] !== '') {
        $existing = $DB->get_record('local_ustar_catalog', ['itemtype'=>'product','sku'=>$item['sku']], '*', IGNORE_MISSING);
    }
    if (!$existing) {
        $existing = $DB->get_record('local_ustar_catalog', ['itemtype'=>'product','parentid'=>$parentid,'title'=>$item['title']], '*', IGNORE_MISSING);
    }
    $record = $existing ?: (object)['timecreated'=>$now];
    $record->parentid = $parentid;
    $record->itemtype = 'product';
    $record->title = $item['title'];
    $record->slug = null;
    $record->sku = $item['sku'] !== '' ? $item['sku'] : null;
    $record->summary = $item['summary'] !== '' ? $item['summary'] : null;
    $record->description = $item['description'] !== '' ? $item['description'] : null;
    $record->imageurl = $item['imageurl'] !== '' ? $item['imageurl'] : null;
    $record->attributesjson = $item['attributesjson'] !== '' ? $item['attributesjson'] : null;
    $record->active = 1;
    $record->sortorder = 0;
    $record->timemodified = $now;
    $record->usermodified = $actor;
    if ($existing) { $DB->update_record('local_ustar_catalog', $record); $updated++; }
    else { $DB->insert_record('local_ustar_catalog', $record); $created++; }
}
$transaction->allow_commit();

echo "Created products: {$created}\nUpdated products: {$updated}\nCATALOG_IMPORT=OK\n";
