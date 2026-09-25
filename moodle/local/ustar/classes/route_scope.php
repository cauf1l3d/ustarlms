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

    /** A point is editable in-place only while all active scopes name this position. */
    public static function exclusive_to(int $pointid, string $positionid): bool {
        global $DB;
        if ($positionid === '' || !self::available()) { return false; }
        $confirmed = false;
        foreach ($DB->get_records('local_ustar_route_scope', ['pointid' => $pointid, 'active' => 1]) as $row) {
            if ((string)$row->scopeid !== $positionid) { return false; }
            $confirmed = $confirmed || (string)$row->state === self::CONFIRMED;
        }
        return $confirmed;
    }

    private static function raw_applies(array $rows, string $positionid, bool $override = false): bool {
        if (!$override) { $rows = array_filter($rows, static fn($r): bool => !empty($r->active)); }
        if (!$rows) { return !$override; }
        foreach ($rows as $row) {
            if (!empty($row->active) && (string)$row->state === self::CONFIRMED
                    && in_array((string)$row->scopeid, $override ? [$positionid] : [self::ALL, $positionid], true)) {
                return true;
            }
        }
        return false;
    }

    /** Resolve replacements before rendering, access checks and runtime evaluation. */
    public static function points_for_position(int $routeid, string $positionid,
            bool $includeinactive = false, bool $editing = false): array {
        global $DB;
        $points = route_model::points($routeid, $includeinactive);
        if (!$points || !self::available()) { return array_values($points); }
        $scopes = [];
        foreach ($DB->get_records_list('local_ustar_route_scope', 'pointid', array_column($points, 'id')) as $row) {
            // Retain inactive/reverted rows: they must never trigger the legacy unscoped fallback.
            $scopes[(int)$row->pointid][] = $row;
        }
        $base = []; $overrides = [];
        foreach ($points as $point) {
            $isoverride = (string)($point->inheritstate ?? '') === 'override'
                && (int)($point->sourcepointid ?? 0) > 0;
            if (!self::raw_applies($scopes[(int)$point->id] ?? [], $positionid, $isoverride)) { continue; }
            if ($isoverride) { $overrides[] = $point; } else { $base[(int)$point->id] = $point; }
        }
        $selected = [];
        foreach ($overrides as $point) {
            $source = (int)$point->sourcepointid;
            if (!isset($base[$source])) { continue; }
            if (!$editing && !route_model::current_published_version((int)$point->id)) { continue; }
            if (isset($selected[$source])) {
                throw new \moodle_exception('Несколько активных переопределений одной точки для должности');
            }
            $copy = clone $point;
            $copy->sortorder = $base[$source]->sortorder;
            $selected[$source] = $copy;
        }
        foreach ($selected as $source => $point) { $base[$source] = $point; }
        return array_values($base);
    }

    public static function point_applies(int $pointid, string $positionid, bool $editing = false): bool {
        global $DB;
        if (!self::available()) { return true; }
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid]);
        if (!$point || empty($point->active)) { return false; }
        foreach (self::points_for_position((int)$point->routeid, $positionid, false, $editing) as $visible) {
            if ((int)$visible->id === $pointid) { return true; }
        }
        return false;
    }

    public static function assert_replaceable(int $pointid): void {
        global $DB;
        if ($DB->record_exists('local_ustar_assess_policy', ['remediationpointid' => $pointid])
                || $DB->record_exists('local_ustar_assess_policy', ['pointid' => $pointid])) {
            throw new \moodle_exception('Шаг связан с политикой аттестации/переобучения. Для локального переопределения нужен перенос политики; редактирование общего шага доступно в общем маршруте.');
        }
    }

    /** Caller owns the route-studio parent lock, transaction, capability and sesskey checks. */
    public static function create_override(int $routeid, int $pointid, string $positionid, int $actorid): int {
        global $DB;
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'routeid' => $routeid], '*', MUST_EXIST);
        if (!self::point_applies($pointid, $positionid, true)) {
            // Idempotent resubmission: return the existing local replacement.
            foreach (self::points_for_position($routeid, $positionid, false, true) as $existing) {
                if ((int)($existing->sourcepointid ?? 0) === $pointid) { return (int)$existing->id; }
            }
            throw new \invalid_parameter_exception('Шаг недоступен этой должности');
        }
        if (self::exclusive_to($pointid, $positionid)) { return $pointid; }
        if (!empty($point->sourcepointid)) { throw new \invalid_parameter_exception('Вложенное переопределение запрещено'); }
        self::assert_replaceable($pointid);
        $version = route_model::current_published_version($pointid) ?: route_model::latest_version($pointid);
        if (!$version) { throw new \invalid_parameter_exception('У исходного шага нет версии'); }
        // Always create a fresh draft after a revert. Historical versions/scopes are immutable evidence.
        $created = route_model::add_point($routeid, 'ov_' . bin2hex(random_bytes(12)), (string)$point->phase,
            (int)$point->sortorder, [
                'title' => $version->title, 'summary' => $version->summary,
                'requirements' => json_decode($version->requirementsjson, true),
                'renewalpolicy' => $version->renewalpolicy, 'validdays' => $version->validdays,
                'status' => route_model::STATUS_DRAFT,
            ], $actorid);
        $created->sourcepointid = $pointid;
        $created->inheritstate = 'override';
        $DB->update_record('local_ustar_route_points', $created);
        $DB->insert_record('local_ustar_route_scope', (object)[
            'pointid' => $created->id, 'scopeid' => $positionid, 'state' => self::CONFIRMED,
            'sourcekind' => 'manual', 'active' => 1, 'timecreated' => time(),
            'timemodified' => time(), 'usermodified' => $actorid,
        ]);
        assessment_lifecycle::inherit_policy_for_version($version, route_model::latest_version((int)$created->id), $actorid);
        return (int)$created->id;
    }

    public static function revert_override(int $routeid, int $pointid, string $positionid, int $actorid): int {
        global $DB;
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'routeid' => $routeid], '*', MUST_EXIST);
        if ((string)$point->inheritstate !== 'override' || empty($point->sourcepointid)
                || !self::exclusive_to($pointid, $positionid)) {
            throw new \invalid_parameter_exception('Нет переопределения этой должности');
        }
        $scope = $DB->get_record('local_ustar_route_scope', ['pointid' => $pointid, 'scopeid' => $positionid], '*', MUST_EXIST);
        $scope->state = 'reverted'; $scope->active = 0;
        $scope->timemodified = time(); $scope->usermodified = $actorid;
        $DB->update_record('local_ustar_route_scope', $scope);
        $point->active = 0; $point->timemodified = max(time(), (int)$point->timemodified + 1);
        $point->usermodified = $actorid;
        $DB->update_record('local_ustar_route_points', $point);
        return (int)$point->sourcepointid;
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

