<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Application commands used by Route Studio.
 *
 * HTTP pages remain responsible for sesskey/capability checks and converting
 * form fields into typed values. This class owns the route-target invariants so
 * the same rules are used by a future web-service or CLI entry point.
 */
final class route_commands {

    /**
     * Save one point version for the common route or one position override.
     *
     * @param array<string,mixed> $command
     */
    public static function save_version(array $command): \stdClass {
        $routeid = (int)($command['routeid'] ?? 0);
        $pointid = (int)($command['pointid'] ?? 0);
        $actorid = (int)($command['actorid'] ?? 0);
        $positionid = clean_param(
            (string)($command['positionid'] ?? ''),
            PARAM_ALPHANUMEXT
        );
        $positionediting = !empty($command['positionediting']);

        if ($routeid <= 0 || $pointid <= 0 || $actorid <= 0) {
            throw new \invalid_parameter_exception(
                'Не хватает данных для сохранения точки маршрута'
            );
        }

        global $DB;
        $point = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $pointid, 'routeid' => $routeid],
            '*',
            MUST_EXIST
        );

        if ((string)($point->inheritstate ?? route_model::LOCAL_POINT)
                === route_family::INHERITED) {
            throw new \invalid_parameter_exception(
                'Наследуемый общий шаг нельзя менять без переопределения'
            );
        }

        if ($positionediting) {
            if ($positionid === '') {
                throw new \invalid_parameter_exception(
                    'Не выбрана должность для локального изменения'
                );
            }
            if (!route_scope::exclusive_to($pointid, $positionid)
                    || !route_scope::point_applies($pointid, $positionid, true)) {
                throw new \invalid_parameter_exception(
                    'Общий шаг нельзя менять для всех из вкладки должности'
                );
            }
            if (!empty($point->sourcepointid)) {
                route_scope::assert_replaceable((int)$point->sourcepointid);
            }
        }

        $versiondata = is_array($command['versiondata'] ?? null)
            ? $command['versiondata']
            : [];

        return route_model::save_point_version(
            $routeid,
            $pointid,
            (string)($command['phase'] ?? route_model::PHASE_ADAPTATION),
            !empty($command['active']),
            $versiondata,
            $actorid,
            (int)($command['expectedmodified'] ?? 0)
        );
    }

    /** Save a complete route order under the optimistic revision contract. */
    public static function reorder(
        int $routeid,
        array $pointids,
        int $actorid,
        string $expectedrevision = ''
    ): void {
        if ($routeid <= 0 || $actorid <= 0) {
            throw new \invalid_parameter_exception(
                'Не хватает данных для изменения порядка маршрута'
            );
        }
        route_model::reorder(
            $routeid,
            $pointids,
            $actorid,
            $expectedrevision
        );
    }

    /** Publish an already reviewed draft without changing its payload. */
    public static function publish_version(
        int $routeid,
        int $pointid,
        int $versionid,
        int $actorid,
        int $expectedmodified = 0
    ): \stdClass {
        if ($routeid <= 0 || $pointid <= 0 || $versionid <= 0 || $actorid <= 0) {
            throw new \invalid_parameter_exception(
                'Не хватает данных для публикации версии'
            );
        }
        return route_model::publish_version(
            $routeid,
            $pointid,
            $versionid,
            $actorid,
            $expectedmodified
        );
    }

    /** Create an explicit position override from the resolved parent view. */
    public static function create_override(
        int $routeid,
        int $pointid,
        string $positionid,
        int $actorid
    ): int {
        if ($routeid <= 0 || $pointid <= 0 || $actorid <= 0 || $positionid === '') {
            throw new \invalid_parameter_exception(
                'Не хватает данных для переопределения шага'
            );
        }
        return route_scope::create_override(
            $routeid,
            $pointid,
            $positionid,
            $actorid
        );
    }

    /** Revert a position override while preserving its history. */
    public static function revert_override(
        int $routeid,
        int $pointid,
        string $positionid,
        int $actorid
    ): int {
        if ($routeid <= 0 || $pointid <= 0 || $actorid <= 0 || $positionid === '') {
            throw new \invalid_parameter_exception(
                'Не хватает данных для возврата общего шага'
            );
        }
        return route_scope::revert_override(
            $routeid,
            $pointid,
            $positionid,
            $actorid
        );
    }
}
