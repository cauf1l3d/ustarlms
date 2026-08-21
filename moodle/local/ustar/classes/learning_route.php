<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Learning route integration layer.
 *
 * 1.x kept only a course order overlay in the versioned structure JSON.
 * 2.0 introduces one permanent route per position and versioned checkpoints.
 * The legacy overlay is intentionally retained as a read fallback so existing
 * installations and historical configuration are never destroyed by upgrade.
 */
final class learning_route {
    private const KEY = 'learningRoutes';

    /** Legacy 1.x order only, without consulting route 2.0. */
    public static function legacy_order_for(string $positionid): array {
        $positionid = trim($positionid);
        if ($positionid === '') {
            return [];
        }
        $structure = structure::get(structure::NAME_STRUCTURE);
        $routes = $structure[self::KEY] ?? [];
        $order = $routes[$positionid] ?? [];
        return array_values(array_unique(array_filter(
            array_map('intval', is_array($order) ? $order : []),
            static fn(int $id): bool => $id > 0
        )));
    }

    public static function order_for(string $positionid): array {
        global $DB;
        try {
            $table = new \xmldb_table('local_ustar_routes');
            if ($DB->get_manager()->table_exists($table)) {
                $v2 = route_model::ordered_courseids($positionid);
                if ($v2) {
                    return $v2;
                }
            }
        } catch (\Throwable $e) {
            // Upgrade/maintenance fallback: legacy route order remains valid.
        }
        return self::legacy_order_for($positionid);
    }

    private static function apply(array $order, array $courses): array {
        if (!$order || !$courses) {
            return array_values($courses);
        }
        $rank = array_flip($order);
        $original = [];
        foreach (array_values($courses) as $i => $course) {
            $original[(int)($course['id'] ?? 0)] = $i;
        }
        usort($courses, static function(array $a, array $b) use ($rank, $original): int {
            $aid = (int)($a['id'] ?? 0);
            $bid = (int)($b['id'] ?? 0);
            $ar = $rank[$aid] ?? (100000 + ($original[$aid] ?? 0));
            $br = $rank[$bid] ?? (100000 + ($original[$bid] ?? 0));
            return $ar <=> $br;
        });
        return array_values($courses);
    }

    /** Current order: route 2.0 when available, legacy overlay otherwise. */
    public static function apply_order(string $positionid, array $courses): array {
        return self::apply(self::order_for($positionid), $courses);
    }

    /** Used only by migration/bootstrap to avoid consulting a newly created route. */
    public static function apply_legacy_order(string $positionid, array $courses): array {
        return self::apply(self::legacy_order_for($positionid), $courses);
    }

    /** Compatibility payload for older callers. */
    public static function route_for_position(string $positionid): array {
        $required = assignment::required_courses($positionid);
        if (empty($required['ok'])) {
            return [
                'ok' => false,
                'positionid' => $positionid,
                'position' => null,
                'courses' => [],
            ];
        }
        $courses = self::apply_order($positionid, $required['courses'] ?? []);
        foreach ($courses as $index => &$course) {
            $course['order'] = $index + 1;
            $course['published'] = !empty($course['visible']);
            $course['draft'] = empty($course['visible']);
        }
        unset($course);
        return [
            'ok' => true,
            'positionid' => $positionid,
            'position' => $required['position'],
            'courses' => $courses,
            'skills' => $required['skills'] ?? [],
        ];
    }

    /**
     * Legacy course-order writer retained for rollback compatibility.
     * Route Studio 2.0 no longer uses this method for normal editing.
     */
    public static function save(string $positionid, array $courseids): array {
        $context = \context_system::instance();
        require_capability('local/ustar:hrmanage', $context);
        $current = assignment::required_courses($positionid);
        if (empty($current['ok'])) {
            throw new \moodle_exception('Unknown USTAR position');
        }
        $allowed = array_values(array_map('intval', $current['courseids'] ?? []));
        sort($allowed);
        $requested = array_values(array_unique(array_filter(
            array_map('intval', $courseids),
            static fn(int $id): bool => $id > 0
        )));
        $check = $requested;
        sort($check);
        if ($check !== $allowed) {
            throw new \invalid_parameter_exception(
                'Learning route must contain exactly the current evidence-derived required courses.'
            );
        }
        $structure = structure::get(structure::NAME_STRUCTURE);
        if (!isset($structure[self::KEY]) || !is_array($structure[self::KEY])) {
            $structure[self::KEY] = [];
        }
        $structure[self::KEY][$positionid] = $requested;
        structure::save(structure::NAME_STRUCTURE, $structure);
        return self::route_for_position($positionid);
    }
}
