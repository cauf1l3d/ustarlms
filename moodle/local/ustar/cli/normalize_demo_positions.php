<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require($config);

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$changed = false;
foreach ($structure['positions'] ?? [] as &$position) {
    if ((string)($position['id'] ?? '') === 'retail_head') {
        if ((string)($position['name'] ?? '') !== 'Руководитель розницы') {
            $position['name'] = 'Руководитель розницы';
            $changed = true;
        }
        if (empty($position['ishead'])) {
            $position['ishead'] = true;
            $changed = true;
        }
    }
}
unset($position);

if ($changed) {
    \local_ustar\structure::save(\local_ustar\structure::NAME_STRUCTURE, $structure);
}

echo 'RETAIL_HEAD_NAME=Руководитель розницы' . PHP_EOL;
echo 'RETAIL_HEAD_ISHEAD=YES' . PHP_EOL;
echo 'STRUCTURE_CHANGED=' . ($changed ? 'YES' : 'NO') . PHP_EOL;
echo "DEMO_POSITION_NORMALIZE=OK\n";
