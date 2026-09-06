<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * TARGET route-family inheritance.
 *
 * One family:
 *   parent route -> common steps
 *   position routes -> materialised inherited copies + local overrides
 *
 * Employee runtime continues to use ordinary position routes.
 */
final class route_family {

    public const KIND_PARENT = 'parent';
    public const KIND_POSITION = 'position';

    public const INHERITED = 'inherited';
    public const OVERRIDE = 'override';
    public const LOCAL = 'local';

    public static function family(int $familyid): ?\stdClass {
        global $DB;

        return $DB->get_record(
            'local_ustar_route_families',
            [
                'id' => $familyid,
                'active' => 1,
            ]
        ) ?: null;
    }

    public static function family_for_position(string $positionid): ?\stdClass {
        global $DB;

        return $DB->get_record_sql(
            "SELECT f.*
               FROM {local_ustar_route_families} f
               JOIN {local_ustar_routes} r
                 ON r.familyid = f.id
              WHERE r.positionid = :positionid
                AND r.active = 1
                AND f.active = 1",
            ['positionid' => $positionid],
            IGNORE_MULTIPLE
        ) ?: null;
    }

    public static function parent_route(int $familyid): ?\stdClass {
        global $DB;

        return $DB->get_record(
            'local_ustar_routes',
            [
                'familyid' => $familyid,
                'routekind' => self::KIND_PARENT,
                'active' => 1,
            ]
        ) ?: null;
    }

    public static function children(int $familyid): array {
        global $DB;

        return array_values(
            $DB->get_records(
                'local_ustar_routes',
                [
                    'familyid' => $familyid,
                    'routekind' => self::KIND_POSITION,
                    'active' => 1,
                ],
                'id ASC'
            )
        );
    }

    public static function is_parent_route(int $routeid): bool {
        global $DB;

        return $DB->record_exists(
            'local_ustar_routes',
            [
                'id' => $routeid,
                'routekind' => self::KIND_PARENT,
                'active' => 1,
            ]
        );
    }

    /**
     * Materialise one common parent point into all position routes.
     *
     * Existing override/local points are never overwritten.
     */
    public static function sync_parent_point(
        int $parentpointid,
        int $actorid,
        ?\stdClass $sourceversion = null
    ): array {
        global $DB;

        $parentpoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $parentpointid],
            '*',
            MUST_EXIST
        );

        $parentroute = $DB->get_record(
            'local_ustar_routes',
            ['id' => (int)$parentpoint->routeid],
            '*',
            MUST_EXIST
        );

        if ((string)$parentroute->routekind !== self::KIND_PARENT) {
            throw new \coding_exception(
                'sync_parent_point accepts only a family parent point'
            );
        }

        $familyid = (int)$parentroute->familyid;

        if ($familyid <= 0) {
            throw new \coding_exception(
                'Parent route is not attached to a family'
            );
        }

        if ($sourceversion === null) {
            $sourceversion =
                route_model::latest_version($parentpointid);
        }

        $result = [
            'created' => 0,
            'updated' => 0,
            'overrides' => 0,
        ];

        foreach (self::children($familyid) as $childroute) {

            $childpoint = $DB->get_record_sql(
                "SELECT *
                   FROM {local_ustar_route_points}
                  WHERE routeid = :routeid
                    AND sourcepointid = :sourcepointid
               ORDER BY id ASC",
                [
                    'routeid' => (int)$childroute->id,
                    'sourcepointid' => $parentpointid,
                ],
                IGNORE_MULTIPLE
            );

            /*
             * A local override deliberately stops automatic propagation.
             */
            if (
                $childpoint
                &&
                (string)$childpoint->inheritstate !== self::INHERITED
            ) {
                $result['overrides']++;
                continue;
            }

            $now = time();

            if (!$childpoint) {
                $childpoint = (object)[
                    'routeid' => (int)$childroute->id,
                    'pointkey' => 'parent_' . $parentpointid,
                    'sourcepointid' => $parentpointid,
                    'inheritstate' => self::INHERITED,
                    'sourceversionid' => 0,
                    'phase' => (string)$parentpoint->phase,
                    'sortorder' => (int)$parentpoint->sortorder,
                    'active' => (int)$parentpoint->active,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => $actorid,
                ];

                $childpoint->id = (int)$DB->insert_record(
                    'local_ustar_route_points',
                    $childpoint
                );

                $result['created']++;
            } else {
                $childpoint->phase =
                    (string)$parentpoint->phase;

                $childpoint->sortorder =
                    (int)$parentpoint->sortorder;

                $childpoint->active =
                    (int)$parentpoint->active;

                $childpoint->timemodified =
                    max(
                        $now,
                        (int)$childpoint->timemodified + 1
                    );

                $childpoint->usermodified =
                    $actorid;

                $DB->update_record(
                    'local_ustar_route_points',
                    $childpoint
                );

                $result['updated']++;
            }

            /*
             * No new child version is necessary when this exact parent
             * version was already materialised.
             */
            if (
                $sourceversion
                &&
                (int)$childpoint->sourceversionid
                    !== (int)$sourceversion->id
            ) {
                route_model::create_version(
                    (int)$childpoint->id,
                    [
                        'title' =>
                            (string)$sourceversion->title,

                        'summary' =>
                            (string)$sourceversion->summary,

                        'requirements' =>
                            route_model::requirements_for_version(
                                $sourceversion
                            ),

                        'renewalpolicy' =>
                            (string)$sourceversion->renewalpolicy,

                        'validdays' =>
                            (int)$sourceversion->validdays,

                        'status' =>
                            (string)$sourceversion->status,

                        'effectivedate' =>
                            (int)$sourceversion->effectivedate,
                    ],
                    $actorid
                );

                $DB->set_field(
                    'local_ustar_route_points',
                    'sourceversionid',
                    (int)$sourceversion->id,
                    ['id' => (int)$childpoint->id]
                );
            }
        }

        return $result;
    }

    public static function sync_parent_route(
        int $parentrouteid,
        int $actorid
    ): array {
        if (!self::is_parent_route($parentrouteid)) {
            throw new \coding_exception(
                'Route is not a family parent'
            );
        }

        $total = [
            'created' => 0,
            'updated' => 0,
            'overrides' => 0,
        ];

        foreach (
            route_model::points(
                $parentrouteid,
                true
            ) as $point
        ) {
            $result = self::sync_parent_point(
                (int)$point->id,
                $actorid
            );

            foreach ($total as $key => $value) {
                $total[$key] +=
                    (int)$result[$key];
            }
        }

        return $total;
    }

    /**
     * Stop inheritance while preserving the inherited version as the
     * starting point for position-specific editing.
     */
    public static function make_override(
        int $childpointid,
        int $actorid
    ): \stdClass {
        global $DB;

        $point = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $childpointid],
            '*',
            MUST_EXIST
        );

        if (
            (int)$point->sourcepointid <= 0
            ||
            (string)$point->inheritstate !== self::INHERITED
        ) {
            throw new \moodle_exception(
                'Эта точка не является наследуемой'
            );
        }

        $point->inheritstate = self::OVERRIDE;
        $point->timemodified =
            max(
                time(),
                (int)$point->timemodified + 1
            );

        $point->usermodified = $actorid;

        $DB->update_record(
            'local_ustar_route_points',
            $point
        );

        return $point;
    }

    /**
     * Discard current position override and adopt the latest common version.
     *
     * Historical override versions are retained as evidence/history.
     */
    public static function revert_to_parent(
        int $childpointid,
        int $actorid
    ): \stdClass {
        global $DB;

        $point = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $childpointid],
            '*',
            MUST_EXIST
        );

        $sourcepointid =
            (int)$point->sourcepointid;

        if ($sourcepointid <= 0) {
            throw new \moodle_exception(
                'У точки нет общего родительского шага'
            );
        }

        $parentpoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $sourcepointid],
            '*',
            MUST_EXIST
        );

        $point->inheritstate = self::INHERITED;

        /*
         * Force one fresh materialisation even when the override started
         * from the same parent version.
         */
        $point->sourceversionid = 0;

        $point->phase =
            (string)$parentpoint->phase;

        $point->sortorder =
            (int)$parentpoint->sortorder;

        $point->active =
            (int)$parentpoint->active;

        $point->timemodified =
            max(
                time(),
                (int)$point->timemodified + 1
            );

        $point->usermodified =
            $actorid;

        $DB->update_record(
            'local_ustar_route_points',
            $point
        );

        self::sync_parent_point(
            $sourcepointid,
            $actorid
        );

        return $DB->get_record(
            'local_ustar_route_points',
            ['id' => $childpointid],
            '*',
            MUST_EXIST
        );
    }
}
