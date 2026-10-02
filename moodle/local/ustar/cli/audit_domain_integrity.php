<?php
/** Read-only inventory before Org/Evidence migration. No names, emails or secrets in output. */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;
$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$positions = \local_ustar\people::position_map($structure);
$departments = \local_ustar\people::department_map($structure);
$now = time();
$report = ['schema' => 1, 'generated_at' => gmdate('c'), 'mode' => 'read_only', 'findings' => []];
$checks = [
    'versions_without_point' => 'SELECT COUNT(*) FROM {local_ustar_route_versions} v LEFT JOIN {local_ustar_route_points} p ON p.id=v.pointid WHERE p.id IS NULL',
    'progress_wrong_version_point' => 'SELECT COUNT(*) FROM {local_ustar_route_progress} p JOIN {local_ustar_route_versions} v ON v.id=p.versionid WHERE p.pointid<>v.pointid',
    'progress_missing_version' => 'SELECT COUNT(*) FROM {local_ustar_route_progress} p LEFT JOIN {local_ustar_route_versions} v ON v.id=p.versionid WHERE v.id IS NULL',
    'scope_without_point' => 'SELECT COUNT(*) FROM {local_ustar_route_scope} s LEFT JOIN {local_ustar_route_points} p ON p.id=s.pointid WHERE p.id IS NULL',
    'evidence_without_user' => 'SELECT COUNT(*) FROM {local_ustar_evidence_rec} e LEFT JOIN {user} u ON u.id=e.userid WHERE u.id IS NULL',
    'coin_projection_mismatch' => 'SELECT COUNT(*) FROM {local_ustar_coin_balance} b LEFT JOIN (SELECT userid,SUM(amount) AS total FROM {local_ustar_coin_ledger} GROUP BY userid) l ON l.userid=b.userid WHERE b.balance<>COALESCE(l.total,0)',
    'coin_projection_missing' => 'SELECT COUNT(*) FROM (SELECT DISTINCT userid FROM {local_ustar_coin_ledger}) l LEFT JOIN {local_ustar_coin_balance} b ON b.userid=l.userid WHERE b.id IS NULL',
];
foreach ($checks as $name => $sql) {
    try { $report['findings'][$name] = ['count' => (int)$DB->get_field_sql($sql)]; }
    catch (Throwable $e) { $report['findings'][$name] = ['status' => 'unavailable', 'error_type' => get_class($e)]; }
}
$unknownpositions = 0; $unknowndepartments = 0; $parentmissing = 0; $cycles = [];
$places = $DB->get_records('local_ustar_staff_places', ['active' => 1], '', 'id,positionid,departmentid,managerplaceid');
foreach ($places as $place) {
    if (!isset($positions[(string)$place->positionid])) { $unknownpositions++; }
    if (!isset($departments[(string)$place->departmentid])) { $unknowndepartments++; }
    if (!empty($place->managerplaceid) && !isset($places[(int)$place->managerplaceid])) { $parentmissing++; }
    $seen = []; $current = (int)$place->id;
    while ($current && isset($places[$current])) {
        if (isset($seen[$current])) { $cycles[(int)$place->id] = true; break; }
        $seen[$current] = true; $current = (int)$places[$current]->managerplaceid;
    }
}
$report['findings']['staff_unknown_position'] = ['count' => $unknownpositions];
$report['findings']['staff_unknown_department'] = ['count' => $unknowndepartments];
$report['findings']['staff_missing_manager_place'] = ['count' => $parentmissing];
$report['findings']['staff_places_reaching_cycle'] = ['count' => count($cycles)];
$active = $DB->get_records_select('local_ustar_assignments',
    'status=:status AND (effectivefrom=0 OR effectivefrom<=:startnow) AND (effectiveto IS NULL OR effectiveto=0 OR effectiveto>=:endnow)',
    ['status' => 'active', 'startnow' => $now, 'endnow' => $now], '', 'id,userid,staffplaceid,assignmenttype');
$primary = []; $occupants = []; $missingplaces = 0; $mismatch = 0;
$profiles = $DB->get_records_sql("SELECT d.userid,d.data FROM {user_info_data} d JOIN {user_info_field} f ON f.id=d.fieldid WHERE f.shortname=:field", ['field' => 'ustar_position']);
foreach ($active as $a) {
    if (!isset($places[(int)$a->staffplaceid])) { $missingplaces++; continue; }
    if ((string)$a->assignmenttype !== 'primary') { continue; }
    $primary[(int)$a->userid] = ($primary[(int)$a->userid] ?? 0) + 1;
    $occupants[(int)$a->staffplaceid] = ($occupants[(int)$a->staffplaceid] ?? 0) + 1;
    if (trim((string)($profiles[(int)$a->userid]->data ?? '')) !== (string)$places[(int)$a->staffplaceid]->positionid) { $mismatch++; }
}
$report['findings']['multiple_active_primary_per_user'] = ['count' => count(array_filter($primary, static fn($v) => $v > 1))];
$report['findings']['multiple_primary_occupants_per_place'] = ['count' => count(array_filter($occupants, static fn($v) => $v > 1))];
$report['findings']['assignment_missing_active_place'] = ['count' => $missingplaces];
$report['findings']['primary_profile_position_mismatch'] = ['count' => $mismatch];
$badscopes = 0;
foreach ($DB->get_records('local_ustar_route_scope', ['active' => 1], '', 'id,scopeid') as $row) {
    if ((string)$row->scopeid !== 'all' && !isset($positions[(string)$row->scopeid])) { $badscopes++; }
}
$report['findings']['scope_unknown_position'] = ['count' => $badscopes];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
