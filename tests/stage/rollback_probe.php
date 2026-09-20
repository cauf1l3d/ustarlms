<?php
declare(strict_types=1);

define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';

$phase = $argv[1] ?? '';
$expectedmarker = $argv[2] ?? '';
if (!in_array($phase, ['baseline', 'candidate', 'restored'], true) || $expectedmarker === '') {
    fwrite(STDERR, "usage: rollback_probe.php baseline|candidate|restored EXPECTED_MARKER\n");
    exit(64);
}

global $DB;
$plugin = new stdClass();
require '/stage/moodle/public/local/ustar/version.php';

$dbversion = (int)get_config('local_ustar', 'version');
$codeversion = (int)$plugin->version;
$marker = (string)get_config('local_ustar', 'rollback_drill_marker');
$usercount = $DB->count_records('user');

$checks = [
    'db_code_version_match' => $dbversion === $codeversion,
    'marker_match' => hash_equals($expectedmarker, $marker),
    'route_model_loads' => class_exists('\\local_ustar\\route_model'),
    'users_present' => $usercount > 0,
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "ROLLBACK_PROBE_FAIL {$phase} {$name}\n");
        exit(1);
    }
}

echo json_encode([
    'phase' => $phase,
    'plugin_version' => $codeversion,
    'db_version' => $dbversion,
    'marker' => $marker,
    'user_count' => $usercount,
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
