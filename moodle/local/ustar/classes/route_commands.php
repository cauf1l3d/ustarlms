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
     * Run a route mutation with the same lock/transaction contract as version
     * commands. HTTP callers may have an outer transaction, while CLI/API
     * callers must still be safe when they invoke the command directly.
     *
     * @param callable():mixed $operation
     * @return mixed
     */
    private static function with_route_lock(int $routeid, callable $operation) {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_ustar_routes');
        $lock = $factory->get_lock('route:' . $routeid, 10);
        if (!$lock) {
            throw new \moodle_exception(
                'Маршрут сейчас изменяется другим пользователем. Повторите попытку через несколько секунд.'
            );
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }

        throw new \coding_exception('Не удалось изменить область маршрута');
    }

    /** Hide/restore metadata without rebuilding any immutable learning version. */
    public static function set_point_active(array $command): \stdClass {
        global $USER,$DB;
        $actor=(int)($command['actorid']??0);
        view_as::assert_writable();
        if ($actor!==(int)$USER->id || !hr_access::can_manage_structure($actor)) {
            throw new \required_capability_exception(\context_system::instance(),'local/ustar:hrmanage','nopermissions','');
        }
        $routeid=(int)($command['routeid']??0);$pointid=(int)($command['pointid']??0);
        $revision=(int)($command['expectedmodified']??0);
        if ($routeid<=0 || $pointid<=0 || $revision<=0) {
            throw new \invalid_parameter_exception('Форма устарела. Обновите маршрут.');
        }
        return self::with_route_lock($routeid,static function() use($DB,$command,$routeid,$pointid,$revision,$actor): \stdClass {
            $DB->get_record('local_ustar_routes',['id'=>$routeid,'active'=>1],'id',MUST_EXIST);
            $point=$DB->get_record_sql('SELECT * FROM {local_ustar_route_points} WHERE id=:id AND routeid=:route FOR UPDATE',
                ['id'=>$pointid,'route'=>$routeid],MUST_EXIST);
            if ((int)$point->timemodified!==$revision) {
                throw new \moodle_exception('Точка уже изменена в другой сессии. Обновите маршрут и повторите действие.');
            }
            if (($point->inheritstate??'')===route_family::INHERITED) {
                throw new \invalid_parameter_exception('Общий шаг меняется в общем маршруте. Для должности создайте свой вариант.');
            }
            if (!empty($command['positionediting'])) {
                $position=(string)($command['positionid']??'');
                route_scope::assert_route_position($routeid,$position);
                if (!route_scope::exclusive_to($pointid,$position) || !in_array($pointid,array_map(static fn($p)=>(int)$p->id,route_scope::points_for_position($routeid,$position,true,true)),true)) {
                    throw new \invalid_parameter_exception('Нельзя скрыть общий шаг для всех из вкладки должности.');
                }
                if (!empty($point->sourcepointid)) { route_scope::assert_replaceable((int)$point->sourcepointid); }
            } else if (!empty($point->sourcepointid)) {
                throw new \invalid_parameter_exception('Изменяйте этот вариант во вкладке его должности.');
            }
            $active=!empty($command['active']);
            if (!$active && !empty($point->sourcepointid)) {
                throw new \invalid_parameter_exception('Для отмены варианта должности используйте «Вернуть общий вариант». Общий шаг при этом остаётся в маршруте.');
            }
            if ((bool)$point->active===$active) { return $point; }
            return route_model::update_point($routeid,$pointid,(string)$point->phase,$active,$actor,$revision);
        });
    }

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
            route_scope::assert_route_position($routeid, $positionid);
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
            (int)($command['expectedmodified'] ?? 0),
            (int)($command['baseversion'] ?? 0)
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
        return (int)self::with_route_lock($routeid, static function() use (
            $routeid,
            $pointid,
            $positionid,
            $actorid
        ): int {
            return route_scope::create_override(
                $routeid,
                $pointid,
                $positionid,
                $actorid
            );
        });
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
        return (int)self::with_route_lock($routeid, static function() use (
            $routeid,
            $pointid,
            $positionid,
            $actorid
        ): int {
            return route_scope::revert_override(
                $routeid,
                $pointid,
                $positionid,
                $actorid
            );
        });
    }
}
