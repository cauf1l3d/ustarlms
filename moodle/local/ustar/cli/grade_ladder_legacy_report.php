<?php
// Read-only inventory for manually confirming former name-based grade links.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

$positions = \local_ustar\people::position_map(
    \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE));
$unbound = [];
foreach ($positions as $positionid => $position) {
    if (\local_ustar\grade_ladders::binding((string)$positionid)) { continue; }
    $saved = get_config('local_ustar', 'careergrade_' . $positionid);
    if ($saved !== false || !\local_ustar\consultant_career::is_consultant((string)($position['name'] ?? ''))) {
        continue;
    }
    $unbound[] = [
        'positionid' => (string)$positionid,
        'position' => (string)($position['name'] ?? ''),
        'personalgraderecords' => (int)$DB->count_records('local_ustar_employee_grades',
            ['positionid' => (string)$positionid]),
        'decision' => 'manual_confirmation_required',
    ];
}
echo json_encode(['read_only' => true, 'legacy_name_links' => $unbound],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
