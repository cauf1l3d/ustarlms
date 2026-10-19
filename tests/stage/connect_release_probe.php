<?php
// Fixture-only assertions. Never include this script in a production package.
define('CLI_SCRIPT', true);
require('/stage/moodle/config.php');
if ($CFG->dbname !== 'ustar_stage1' || $CFG->dbhost !== 'db' || $CFG->prefix !== 'stage_'
        || $CFG->dataroot !== '/stage/data/stage_' || is_dir('/opt/ustar')) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_CONNECT_PAIR');
}
$phase = $argv[1] ?? '';
if (!in_array($phase, ['baseline', 'candidate'], true)) {
    throw new RuntimeException('INVALID_CONNECT_PAIR_PHASE');
}
$versions = [];
foreach (['local_ustar' => 'local/ustar', 'theme_ustar' => 'theme/ustar'] as $component => $scope) {
    $plugin = new stdClass();
    require('/stage/moodle/public/' . $scope . '/version.php');
    $versions[$component] = (int)get_config($component, 'version');
    if ($versions[$component] !== (int)$plugin->version) {
        throw new RuntimeException('CONNECT_PAIR_DISK_DB_VERSION_MISMATCH');
    }
}
$tables = array_values(array_filter($DB->get_tables(), static fn($t) => str_starts_with($t, 'local_ustar_')));
sort($tables);
$expected = $phase === 'candidate' ? 2026101905 : 2026100204;
if ($versions !== ['local_ustar' => $expected, 'theme_ustar' => 2026100202]
        || count($tables) !== ($phase === 'candidate' ? 99 : 93)
        || get_config('local_ustar', 'connect_release_marker') !== $phase
        || ($CFG->ustar_connect_provider_read_enabled ?? false)
        || ($CFG->ustar_connect_notifications_enabled ?? false)) {
    throw new RuntimeException('CONNECT_PAIR_BASELINE_OR_DEFAULTS_MISMATCH');
}
echo json_encode(['versions' => $versions, 'tables' => $tables,
    'marker' => get_config('local_ustar', 'connect_release_marker'),
    'users' => $DB->count_records('user'), 'role_assignments' => $DB->count_records('role_assignments'),
    'provider_read_enabled' => false, 'notifications_enabled' => false], JSON_PRETTY_PRINT), "\n";
