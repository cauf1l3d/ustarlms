<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$checks = [];
$assert = static function(bool $condition, string $name) use (&$checks): void {
    $checks[$name] = $condition;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) { throw new moodle_exception('Route Studio probe failed: ' . $name); }
};

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$positionid = '';
foreach ($structure['positions'] ?? [] as $position) {
    if (!empty($position['id'])) { $positionid = (string)$position['id']; break; }
}
$assert($positionid !== '', 'position_catalog_available');
$actorid = (int)$DB->get_field('user', 'id', ['id' => 2]) ?: 0;
$courseid = (int)$DB->get_field_sql('SELECT id FROM {course} WHERE id > 1 ORDER BY id ASC', [], IGNORE_MULTIPLE);
$assert($courseid > 0, 'course_catalog_available');
$skills = $structure['skills'] ?? [];
$skillid = (string)($skills[0]['id'] ?? '');
$stamp = 'route_studio_probe_' . time();
$route = \local_ustar\route_model::ensure_route($positionid, $actorid);
$before = \local_ustar\route_model::revision((int)$route->id);
$point = \local_ustar\route_model::add_point((int)$route->id, $stamp, \local_ustar\route_model::PHASE_ADAPTATION, 999900, [
    'title' => 'Проверка редактора маршрута',
    'summary' => 'Версионная проверка Route Studio',
    'requirements' => array_filter([
        ['type' => 'course', 'sourceid' => $courseid, 'required' => true, 'label' => 'Проверочный курс'],
        $skillid !== '' ? ['type' => 'skill', 'sourcekey' => $skillid, 'primary' => true, 'required' => true, 'label' => 'Проверочный навык'] : null,
    ]),
    'renewalpolicy' => \local_ustar\route_model::RENEW_ALL,
    'validdays' => 30,
    'status' => \local_ustar\route_model::STATUS_PUBLISHED,
    'effectivedate' => time(),
], $actorid);
$v1 = \local_ustar\route_model::latest_version((int)$point->id);
$requirements = \local_ustar\route_model::requirements_for_version($v1);
$assert((int)$v1->versionno === 1 && count($requirements) >= 1, 'published_point_has_versioned_requirements');
$assert($skillid === '' || !empty(array_filter($requirements, static fn(array $item): bool => ($item['type'] ?? '') === 'skill' && !empty($item['primary']))), 'one_primary_skill_preserved');

$freshpoint = $DB->get_record('local_ustar_route_points', ['id' => $point->id], '*', MUST_EXIST);
\local_ustar\route_model::update_point((int)$route->id, (int)$point->id, \local_ustar\route_model::PHASE_GATE, true, $actorid, (int)$freshpoint->timemodified);
$v2 = \local_ustar\route_model::create_version((int)$point->id, ['title' => 'Проверка редактора маршрута v2',
    'summary' => 'Новая версия без переписывания v1', 'renewalpolicy' => \local_ustar\route_model::RENEW_ALL,
    'validdays' => 30, 'status' => \local_ustar\route_model::STATUS_DRAFT], $actorid);
$assert((int)$v2->versionno === 2 && (int)$v1->versionno === 1, 'published_history_not_mutated');

$revision = \local_ustar\route_model::revision((int)$route->id);
$ids = array_map(static fn(\stdClass $item): int => (int)$item->id, \local_ustar\route_model::points((int)$route->id));
$ids = array_values(array_unique(array_merge([(int)$point->id], $ids)));
\local_ustar\route_model::reorder((int)$route->id, $ids, $actorid, $revision);
$first = \local_ustar\route_model::points((int)$route->id)[0] ?? null;
$assert($first && (int)$first->id === (int)$point->id, 'reorder_persists_visible_order');
$stale = false;
try { \local_ustar\route_model::reorder((int)$route->id, $ids, $actorid, $revision); } catch (Throwable $ignored) { $stale = true; }
$assert($stale, 'stale_reorder_rejected');
$staleupdate = false;
try { \local_ustar\route_model::update_point((int)$route->id, (int)$point->id, \local_ustar\route_model::PHASE_GATE, true, $actorid, (int)$freshpoint->timemodified); } catch (Throwable $ignored) { $staleupdate = true; }
$assert($staleupdate, 'stale_point_update_rejected');
$assert($before !== \local_ustar\route_model::revision((int)$route->id), 'route_revision_changes_after_edit');
echo 'ROUTE_STUDIO_RUNTIME=PASS checks=' . count($checks) . PHP_EOL;
