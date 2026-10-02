<?php
#define CLI_SCRIPT before config.
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

$expectedversion = 2026082301;
$actualversion = (int)get_config('local_ustar', 'version');
if ($actualversion !== $expectedversion) {
    cli_error('LOCAL_USTAR_VERSION_MISMATCH actual=' . $actualversion . ' expected=' . $expectedversion);
}

$tables = [
    'local_ustar_routes',
    'local_ustar_route_points',
    'local_ustar_route_versions',
    'local_ustar_route_progress',
];
foreach ($tables as $name) {
    if (!$DB->get_manager()->table_exists(new xmldb_table($name))) {
        cli_error('MISSING_TABLE=' . $name);
    }
}

echo 'LOCAL_USTAR_VERSION=' . $actualversion . PHP_EOL;
echo "ROUTE_V2_SCHEMA=OK\n";
