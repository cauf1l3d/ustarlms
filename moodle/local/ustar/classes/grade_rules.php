<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Immutable rules for one employee-grade transition.
 *
 * A rule pins exact published route-point versions. No thresholds are inferred
 * from grade labels and the whole route is never reused implicitly for every
 * promotion.
 */
final class grade_rules {
    public const STATUS_PUBLISHED = 'published';

    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_grade_rules'));
    }

    public static function can_manage(int $userid): bool {
        return capabilities::has($userid, capabilities::HR_WRITE);
    }

    /** @return array<int,array<string,mixed>> */
    public static function transitions(): array {
        $grades = array_values(career_grades::catalogue());
        $out = [];
        for ($i = 0; $i + 1 < count($grades); $i++) {
            $out[] = [
                'fromgrade' => (string)$grades[$i]['id'],
                'fromlabel' => (string)$grades[$i]['name'],
                'tograde' => (string)$grades[$i + 1]['id'],
                'tolabel' => (string)$grades[$i + 1]['name'],
                'criteria' => array_values($grades[$i + 1]['criteria'] ?? []),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function transition(string $fromgrade): ?array {
        foreach (self::transitions() as $transition) {
            if ($transition['fromgrade'] === $fromgrade) {
                return $transition;
            }
        }
        return null;
    }

    /** @return array<int,array{id:string,name:string}> */
    public static function position_options(): array {
        $out = [];
        foreach (structure::get(structure::NAME_STRUCTURE)['positions'] ?? [] as $position) {
            if (career_grades::key($position) === '') {
                continue;
            }
            $out[] = [
                'id' => (string)($position['id'] ?? ''),
                'name' => (string)($position['name'] ?? $position['id'] ?? ''),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    public static function published(string $positionid, string $fromgrade, string $tograde): ?\stdClass {
        global $DB;
        if (!self::available()) {
            return null;
        }
        $rows = $DB->get_records('local_ustar_grade_rules', [
            'positionid' => $positionid,
            'fromgrade' => $fromgrade,
            'tograde' => $tograde,
            'status' => self::STATUS_PUBLISHED,
        ], 'versionno DESC, id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function requirements(\stdClass $rule): array {
        $decoded = json_decode((string)$rule->requirementsjson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $rows = $decoded['requirements'] ?? $decoded;
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string,mixed> */
    public static function editor(string $positionid, string $fromgrade): array {
        global $DB;
        $transition = self::transition($fromgrade);
        if (!$transition) {
            throw new \invalid_parameter_exception('Неизвестный переход грейда.');
        }
        $route = self::route_for_position($positionid);
        $points = [];
        if ($route) {
            $rows = (string)($route->routekind ?? '') === route_family::KIND_PARENT && route_scope::available()
                ? route_scope::points_for_position((int)$route->id, $positionid)
                : array_values($DB->get_records('local_ustar_route_points', [
                    'routeid' => (int)$route->id, 'active' => 1,
                ], 'sortorder ASC, id ASC'));
            foreach ($rows as $point) {
                $published = route_model::current_published_version((int)$point->id);
                if (!$published) {
                    continue;
                }
                $points[] = [
                    'pointid' => (int)$point->id,
                    'versionid' => (int)$published->id,
                    'title' => format_string((string)$published->title),
                ];
            }
        }
        $current = self::published($positionid, $fromgrade, (string)$transition['tograde']);
        $selected = [];
        foreach ($current ? self::requirements($current) : [] as $requirement) {
            $selected[(int)($requirement['pointid'] ?? 0)] = true;
        }
        foreach ($points as &$point) {
            $point['selected'] = !empty($selected[$point['pointid']]);
        }
        unset($point);
        return [
            'transition' => $transition,
            'routeid' => $route ? (int)$route->id : 0,
            'points' => $points,
            'current' => $current,
        ];
    }

    /**
     * Publish a new immutable rule version.
     *
     * @param array<int,int|string> $pointids
     */
    public static function publish_transition(
        string $positionid,
        string $fromgrade,
        array $pointids,
        int $actorid
    ): \stdClass {
        global $DB;
        if (!self::can_manage($actorid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', ''
            );
        }
        if (!self::available()) {
            throw new \moodle_exception('Правила грейдов будут доступны после обновления базы данных.');
        }
        $transition = self::transition($fromgrade);
        if (!$transition) {
            throw new \invalid_parameter_exception('Неизвестный переход грейда.');
        }
        $positionid = clean_param($positionid, PARAM_ALPHANUMEXT);
        if ($positionid === '') {
            throw new \invalid_parameter_exception('Выберите должность.');
        }
        $route = self::route_for_position($positionid);
        if (!$route) {
            throw new \moodle_exception('Для должности нет активного маршрута.');
        }
        $selected = array_fill_keys(array_values(array_unique(array_filter(
            array_map('intval', $pointids), static fn(int $id): bool => $id > 0
        ))), true);
        if (!$selected) {
            throw new \invalid_parameter_exception('Выберите хотя бы один подтверждаемый этап перехода.');
        }

        $rows = (string)($route->routekind ?? '') === route_family::KIND_PARENT && route_scope::available()
            ? route_scope::points_for_position((int)$route->id, $positionid)
            : array_values($DB->get_records('local_ustar_route_points', [
                'routeid' => (int)$route->id, 'active' => 1,
            ], 'sortorder ASC, id ASC'));
        $allowed = [];
        $requirements = [];
        foreach ($rows as $point) {
            $allowed[(int)$point->id] = true;
            if (empty($selected[(int)$point->id])) {
                continue;
            }
            $published = route_model::current_published_version((int)$point->id);
            if (!$published) {
                throw new \moodle_exception('Выбранный этап не имеет опубликованной версии.');
            }
            $requirements[] = [
                'pointid' => (int)$point->id,
                'versionid' => (int)$published->id,
                'title' => format_string((string)$published->title),
            ];
        }
        foreach (array_keys($selected) as $pointid) {
            if (empty($allowed[$pointid])) {
                throw new \invalid_parameter_exception('Выбранный этап не относится к маршруту этой должности.');
            }
        }
        if (!$requirements) {
            throw new \invalid_parameter_exception('Не найдено опубликованных этапов для правила.');
        }

        $payload = [
            'positionid' => $positionid,
            'fromgrade' => $fromgrade,
            'tograde' => (string)$transition['tograde'],
            'routeid' => (int)$route->id,
            'requirements' => $requirements,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-rule:' . sha1($positionid . ':' . $fromgrade), 10);
        if (!$lock) {
            throw new \moodle_exception('Правило сейчас изменяется в другой сессии.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $maxversion = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(versionno), 0) FROM {local_ustar_grade_rules}
                  WHERE positionid = :positionid AND fromgrade = :fromgrade AND tograde = :tograde',
                [
                    'positionid' => $positionid,
                    'fromgrade' => $fromgrade,
                    'tograde' => (string)$transition['tograde'],
                ]
            );
            $id = (int)$DB->insert_record('local_ustar_grade_rules', (object)[
                'positionid' => $positionid,
                'fromgrade' => $fromgrade,
                'tograde' => (string)$transition['tograde'],
                'versionno' => $maxversion + 1,
                'routeid' => (int)$route->id,
                'requirementsjson' => $json,
                'rulehash' => $hash,
                'status' => self::STATUS_PUBLISHED,
                'createdby' => $actorid,
                'timecreated' => time(),
            ]);
            people::log_action($actorid, null, 'grade_rule_published', [
                'ruleid' => $id,
                'positionid' => $positionid,
                'fromgrade' => $fromgrade,
                'tograde' => (string)$transition['tograde'],
                'versionno' => $maxversion + 1,
                'rulehash' => $hash,
            ]);
            $tx->allow_commit();
            return $DB->get_record('local_ustar_grade_rules', ['id' => $id], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }

    private static function route_for_position(string $positionid): ?\stdClass {
        global $DB;
        $route = route_scope::available() ? route_scope::parent_for_position($positionid) : null;
        $route = $route ?: $DB->get_record('local_ustar_routes', [
            'positionid' => $positionid, 'active' => 1,
        ], '*', IGNORE_MULTIPLE);
        return $route ?: null;
    }
}
