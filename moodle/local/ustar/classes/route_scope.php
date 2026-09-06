<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * TARGET route scope resolver.
 *
 * One physical family parent route is the source of truth.
 * Individual positions receive a resolved view of that route.
 */
final class route_scope {

    public const ALL = 'all';
    public const CONFIRMED = 'confirmed';

    public static function available(): bool {
        global $DB;

        return $DB->get_manager()->table_exists(
            new \xmldb_table('local_ustar_route_scope')
        );
    }

    /**
     * Return the family parent route for a concrete position.
     */
    public static function parent_for_position(string $positionid): ?\stdClass {
        global $DB;

        $positionid = clean_param($positionid, PARAM_ALPHANUMEXT);

        if ($positionid === '') {
            return null;
        }

        $child = $DB->get_record(
            'local_ustar_routes',
            [
                'positionid' => $positionid,
                'active' => 1,
            ]
        );

        if (!$child || empty($child->familyid)) {
            return null;
        }

        return $DB->get_record(
            'local_ustar_routes',
            [
                'familyid' => (int)$child->familyid,
                'routekind' => 'parent',
                'active' => 1,
            ]
        ) ?: null;
    }

    /**
     * Only confirmed scope participates in employee runtime.
     * proposed is an HR review state and never grants applicability.
     */
    public static function point_applies(
        int $pointid,
        string $positionid
    ): bool {
        global $DB;

        if (!self::available()) {
            return true;
        }

        $rows = $DB->get_records(
            'local_ustar_route_scope',
            [
                'pointid' => $pointid,
                'active' => 1,
            ]
        );

        // Compatibility fallback for old unscoped points.
        if (!$rows) {
            return true;
        }

        foreach ($rows as $row) {
            if ((string)$row->state !== self::CONFIRMED) {
                continue;
            }

            $scopeid = (string)$row->scopeid;

            if (
                $scopeid === self::ALL
                || $scopeid === $positionid
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Points of one physical route resolved for an employee position.
     */
    public static function points_for_position(
        int $routeid,
        string $positionid,
        bool $includeinactive = false
    ): array {
        $result = [];

        foreach (
            route_model::points($routeid, $includeinactive)
            as $point
        ) {
            if (
                self::point_applies(
                    (int)$point->id,
                    $positionid
                )
            ) {
                $result[] = $point;
            }
        }

        return $result;
    }

    /**
     * Human-readable confirmed scope values for Route Studio.
     */
    public static function confirmed_scope_ids(int $pointid): array {
        global $DB;

        if (!self::available()) {
            return [];
        }

        $result = [];

        foreach (
            $DB->get_records(
                'local_ustar_route_scope',
                [
                    'pointid' => $pointid,
                    'state' => self::CONFIRMED,
                    'active' => 1,
                ],
                'id ASC'
            )
            as $row
        ) {
            $result[] = (string)$row->scopeid;
        }

        return array_values(array_unique($result));
    }
}
