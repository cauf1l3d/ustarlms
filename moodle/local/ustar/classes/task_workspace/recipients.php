<?php
namespace local_ustar\task_workspace;

use local_ustar\{learning_tasks, organization_identity, organization_model, people, structure};

defined('MOODLE_INTERNAL') || die();

/** Resolve task recipients within the actor's existing delegation scope. */
final class recipients {
    public const MODES = ['person' => 'Сотрудник', 'department' => 'Отдел', 'position' => 'Должность'];
    public const MAX_BATCH = 100;

    public static function catalog(int $actor, bool $groups = true): array {
        global $DB;
        $result = ['people' => [], 'departments' => [], 'positions' => []];
        $ids = learning_tasks::assignment_recipients($actor);
        if (!$ids) {
            return $result;
        }
        $structure = structure::get(structure::NAME_STRUCTURE);
        $departments = people::department_map($structure);
        $positions = people::position_map($structure);
        $names = service::names($ids);
        $now = time();
        $legacyheads = [];
        foreach ($ids as $id) {
            $identity = organization_identity::resolve($id, $now);
            $department = $identity['departmentid'];
            $position = $identity['positionid'];
            if ($identity['conflicts'] || !isset($departments[$department], $positions[$position], $names[$id])) {
                continue;
            }
            $result['people'][$id] = ['name' => $names[$id], 'department' => $department, 'position' => $position,
                'token' => self::token([$id])];
            $result['departments'][$department] = ['name' => $departments[$department]['name']];
            $result['positions'][$position] ??= ['name' => $positions[$position]['name'], 'department' => $department, 'members' => []];
            $result['positions'][$position]['members'][] = $id;
            if ($identity['source'] === 'legacy' && !empty($positions[$position]['ishead'])) {
                $legacyheads[$department][] = $id;
            }
        }
        if ($groups) {
            // A staffed head place takes precedence over any legacy profile. A vacancy or ambiguous graph
            // is not grounds to guess. Acting assignments are resolved by the same policy used for reporting.
            $headplaces = [];
            foreach ($DB->get_records_select('local_ustar_staff_places',
                    'active = 1 AND effectivefrom <= :from AND (effectiveto IS NULL OR effectiveto = 0 OR effectiveto > :until)',
                    ['from' => $now, 'until' => $now], 'id', 'id,departmentid,positionid') as $place) {
                $position = $positions[$place->positionid] ?? null;
                if (!empty($position['ishead']) && $position['department'] === $place->departmentid) {
                    $headplaces[$place->departmentid][] = (int)$place->id;
                }
            }
            foreach ($departments as $department => $row) {
                $places = $headplaces[$department] ?? [];
                $legacy = $legacyheads[$department] ?? [];
                $head = 0;
                if (count($places) === 1 && organization_model::valid_place_chain($places[0], $now)) {
                    $head = organization_model::occupant_for_place($places[0], $now);
                } else if (!$places && count($legacy) === 1) {
                    $head = $legacy[0];
                }
                if (isset($result['people'][$head])) {
                    $result['departments'][$department] = ['name' => $row['name'], 'head' => $head, 'token' => self::token([$head])];
                }
            }
        }
        foreach ($result['positions'] as &$position) {
            $position['token'] = self::token($position['members']);
        }
        unset($position);
        foreach ($result as &$entries) {
            uasort($entries, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }
        return $result;
    }

    public static function selection(array $catalog, string $mode, string $department, string $position, int $user): array {
        switch ($mode) {
            case 'person':
                $row = $catalog['people'][$user] ?? null;
                return $row && (!$department || $row['department'] === $department)
                    && (!$position || $row['position'] === $position) ? [$user] : [];
            case 'department':
                $head = $catalog['departments'][$department]['head'] ?? 0;
                return $head ? [$head] : [];
            case 'position':
                $row = $catalog['positions'][$position] ?? null;
                return $row && (!$department || $row['department'] === $department) ? $row['members'] : [];
            default:
                return [];
        }
    }

    public static function token(array $ids): string {
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(',', $ids));
    }

    /** The existing reassignment picker still selects a single exact staff position. */
    public static function options(int $actor, string $department = '', string $position = ''): array {
        $catalog = self::catalog($actor, false);
        $positions = array_filter($catalog['positions'], static fn($row) => $row['department'] === $department);
        $people = array_filter($catalog['people'],
            static fn($row) => $row['department'] === $department && $row['position'] === $position);
        return ['departments' => array_map(static fn($row) => $row['name'], $catalog['departments']),
            'positions' => array_map(static fn($row) => $row['name'], $positions),
            'people' => array_map(static fn($row) => $row['name'], $people)];
    }

    public static function validate(int $actor, int $user, string $department, string $position): void {
        $i = organization_identity::resolve($user);
        if (!$department || !$position || $i['conflicts'] || $i['departmentid'] !== $department
                || $i['positionid'] !== $position || !learning_tasks::can_assign($actor, $user)) {
            throw new \invalid_parameter_exception('Назначение сотрудника изменилось. Повторно выберите отдел, должность и сотрудника.');
        }
    }
}
