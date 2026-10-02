<?php
/** Read-only route inventory; share output privately because route labels may be internal. */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unknown] = cli_get_params(['help' => false, 'routeid' => 0], ['h' => 'help']);
if ($unknown) { cli_error('Unknown arguments: ' . implode(', ', $unknown)); }
if ($options['help']) {
    echo "Read-only inventory of published route points and dependencies\n";
    echo "  php local/ustar/cli/inspect_intro_route.php --routeid=49\n";
    exit(0);
}
$routeid = filter_var($options['routeid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$routeid) { cli_error('Provide a positive --routeid'); }
$route = $DB->get_record('local_ustar_routes', ['id' => $routeid],
    'id,name,routekind,positionid,familyid,active', MUST_EXIST);
$rows = [];
foreach (\local_ustar\route_model::points((int)$routeid) as $point) {
    $version = \local_ustar\route_model::current_published_version((int)$point->id);
    if (!$version) { continue; }
    $requirements = [];
    foreach (\local_ustar\route_model::requirements_for_version($version) as $item) {
        $requirements[] = ['type' => (string)$item['type'],
            'sourceid' => (int)($item['sourceid'] ?? 0),
            'sourcekey' => (string)($item['sourcekey'] ?? ''),
            'required' => !empty($item['required'])];
    }
    $rows[] = ['pointid' => (int)$point->id, 'pointkey' => (string)$point->pointkey,
        'sortorder' => (int)$point->sortorder, 'phase' => (string)$point->phase,
        'title' => (string)$version->title, 'versionid' => (int)$version->id,
        'versionno' => (int)$version->versionno,
        'scope' => \local_ustar\route_scope::confirmed_scope_ids((int)$point->id),
        'requirements' => $requirements];
}
echo json_encode(['schema' => 1, 'mode' => 'read_only', 'route' => [
    'id' => (int)$route->id, 'name' => (string)$route->name,
    'kind' => (string)$route->routekind, 'positionid' => (string)$route->positionid,
    'familyid' => (int)$route->familyid, 'active' => (bool)$route->active],
    'published_points' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
