<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Permanent, position-owned learning routes with versioned checkpoints.
 *
 * One position owns one permanent route. The route itself is not versioned;
 * individual checkpoints are. Historical user checkpoint completions are kept
 * in local_ustar_route_progress so a new checkpoint version can deliberately
 * preserve or invalidate an older result.
 */
final class route_model {
    public const PHASE_ADAPTATION = 'adaptation';
    public const PHASE_GATE = 'gate';
    public const PHASE_CONTINUOUS = 'continuous';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const RENEW_KEEP = 'keep';
    public const RENEW_ALL = 'all';
    public const RENEW_EXPIRY = 'expiry';
    public const RENEW_MANUAL = 'manual';

    private static function position_context(string $positionid): array {
        $structure = structure::get(structure::NAME_STRUCTURE);
        $position = null;
        $department = null;

        foreach ($structure['positions'] ?? [] as $candidate) {
            if ((string)($candidate['id'] ?? '') === $positionid) {
                $position = $candidate;
                break;
            }
        }

        if (!$position) {
            throw new \invalid_parameter_exception('Неизвестная должность USTAR');
        }

        foreach ($structure['departments'] ?? [] as $candidate) {
            if ((string)($candidate['id'] ?? '') === (string)($position['department'] ?? '')) {
                $department = $candidate;
                break;
            }
        }

        return [
            'structure' => $structure,
            'position' => $position,
            'department' => $department,
        ];
    }

    public static function canonical_name(string $positionid): string {
        $ctx = self::position_context($positionid);
        $department = trim((string)($ctx['department']['name'] ?? 'Подразделение'));
        $position = trim((string)($ctx['position']['name'] ?? $positionid));
        return \core_text::strtoupper($department . ': ' . $position);
    }

    public static function get_route(string $positionid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_routes', [
            'positionid' => $positionid,
            'active' => 1,
        ]) ?: null;
    }

    public static function ensure_route(string $positionid, int $actorid = 0): \stdClass {
        global $DB;
        $current = self::get_route($positionid);
        $ctx = self::position_context($positionid);
        $name = self::canonical_name($positionid);
        $departmentid = (string)($ctx['position']['department'] ?? '');
        $now = time();

        if ($current) {
            $changed = false;
            if ((string)$current->name !== $name) {
                $current->name = $name;
                $changed = true;
            }
            if ((string)$current->departmentid !== $departmentid) {
                $current->departmentid = $departmentid;
                $changed = true;
            }
            if ($changed) {
                $current->timemodified = $now;
                $current->usermodified = $actorid;
                $DB->update_record('local_ustar_routes', $current);
            }
            return $current;
        }

        $id = (int)$DB->insert_record('local_ustar_routes', (object)[
            'positionid' => $positionid,
            'departmentid' => $departmentid,
            'name' => $name,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $actorid,
        ]);

        return $DB->get_record('local_ustar_routes', ['id' => $id], '*', MUST_EXIST);
    }

    public static function points(int $routeid, bool $includeinactive = false): array {
        global $DB;
        $params = ['routeid' => $routeid];
        $where = 'routeid = :routeid';
        if (!$includeinactive) {
            $where .= ' AND active = 1';
        }
        return array_values($DB->get_records_select(
            'local_ustar_route_points',
            $where,
            $params,
            'sortorder ASC, id ASC'
        ));
    }

    public static function versions(int $pointid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_ustar_route_versions',
            ['pointid' => $pointid],
            'versionno DESC, id DESC'
        ));
    }

    public static function latest_version(int $pointid): ?\stdClass {
        global $DB;
        $record = $DB->get_record_sql(
            'SELECT * FROM {local_ustar_route_versions} WHERE pointid = :pointid ORDER BY versionno DESC, id DESC',
            ['pointid' => $pointid],
            IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    public static function current_published_version(int $pointid, ?int $at = null): ?\stdClass {
        global $DB;
        $at = $at ?? time();
        $record = $DB->get_record_sql(
            "SELECT *
               FROM {local_ustar_route_versions}
              WHERE pointid = :pointid
                AND status = :status
                AND (effectivedate IS NULL OR effectivedate = 0 OR effectivedate <= :at)
           ORDER BY versionno DESC, id DESC",
            [
                'pointid' => $pointid,
                'status' => self::STATUS_PUBLISHED,
                'at' => $at,
            ],
            IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    private static function clean_phase(string $phase): string {
        return in_array($phase, [self::PHASE_ADAPTATION, self::PHASE_GATE, self::PHASE_CONTINUOUS], true)
            ? $phase
            : self::PHASE_ADAPTATION;
    }

    private static function clean_status(string $status): string {
        return in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)
            ? $status
            : self::STATUS_DRAFT;
    }

    private static function clean_policy(string $policy): string {
        return in_array($policy, [self::RENEW_KEEP, self::RENEW_ALL, self::RENEW_EXPIRY, self::RENEW_MANUAL], true)
            ? $policy
            : self::RENEW_KEEP;
    }

    public static function normalize_requirements(array $requirements): array {
        $out = [];
        $hasprimaryskill = false;
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $type = clean_param((string)($requirement['type'] ?? ''), PARAM_ALPHANUMEXT);
            if (!in_array($type, ['course', 'cm', 'content', 'assessment', 'native', 'skill', 'previous_adaptation'], true)) {
                continue;
            }
            $item = [
                'type' => $type,
                'required' => array_key_exists('required', $requirement) ? !empty($requirement['required']) : true,
            ];
            if ($type === 'course' || $type === 'cm' || $type === 'content') {
                $item['sourceid'] = max(0, (int)($requirement['sourceid'] ?? 0));
                if ($item['sourceid'] <= 0) {
                    continue;
                }
                if ($type === 'content') {
                    $mode = (string)($requirement['completionmode'] ?? 'open');
                    $item['completionmode'] = in_array($mode, ['open', 'ack'], true) ? $mode : 'open';
                }
            } else if ($type === 'assessment' || $type === 'native') {
                $item['sourcekey'] = clean_param((string)($requirement['sourcekey'] ?? ''), PARAM_ALPHANUMEXT);
                if ($item['sourcekey'] === '') {
                    continue;
                }
            } else if ($type === 'skill') {
                $item['sourcekey'] = clean_param((string)($requirement['sourcekey'] ?? ''), PARAM_ALPHANUMEXT);
                if ($item['sourcekey'] === '') {
                    continue;
                }
                // A learning point may have supporting skills, but only one
                // explicit primary skill. This remains a versioned requirement.
                $item['primary'] = !empty($requirement['primary']) && !$hasprimaryskill;
                $hasprimaryskill = $hasprimaryskill || $item['primary'];
            }
            if (!empty($requirement['label'])) {
                $item['label'] = clean_param((string)$requirement['label'], PARAM_TEXT);
            }
            $out[] = $item;
        }
        return $out;
    }

    /** Stable, user-facing route order cannot be silently overwritten. */
    public static function revision(int $routeid): string {
        $parts = [];
        foreach (self::points($routeid) as $point) {
            $parts[] = (int)$point->id . ':' . (int)$point->sortorder . ':' . (int)$point->timemodified;
        }
        return sha1(implode('|', $parts));
    }

    /** Update current point metadata without ever changing its historical versions. */
    public static function update_point(
        int $routeid,
        int $pointid,
        string $phase,
        bool $active,
        int $actorid,
        int $expectedmodified = 0
    ): \stdClass {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $point = $DB->get_record_sql(
            'SELECT * FROM {local_ustar_route_points} WHERE id = :id AND routeid = :routeid FOR UPDATE',
            ['id' => $pointid, 'routeid' => $routeid], MUST_EXIST
        );
        if ($expectedmodified > 0 && (int)$point->timemodified !== $expectedmodified) {
            $transaction->rollback(new \moodle_exception('Точка уже изменена в другой сессии. Обновите маршрут и повторите действие.'));
        }
        $point->phase = self::clean_phase($phase);
        $point->active = $active ? 1 : 0;
        $point->timemodified = max(time(), (int)$point->timemodified + 1);
        $point->usermodified = $actorid;
        $DB->update_record('local_ustar_route_points', $point);
        $transaction->allow_commit();

        if (
            \local_ustar\route_family::is_parent_route(
                $routeid
            )
            &&
            !\local_ustar\route_scope::available()
        ) {
            \local_ustar\route_family::sync_parent_point(
                $pointid,
                $actorid
            );
        }

        return $point;
    }

    public static function add_point(
        int $routeid,
        string $pointkey,
        string $phase,
        int $sortorder,
        array $version,
        int $actorid
    ): \stdClass {
        global $DB;
        $route = $DB->get_record('local_ustar_routes', ['id' => $routeid, 'active' => 1], '*', MUST_EXIST);
        $pointkey = clean_param(trim($pointkey), PARAM_ALPHANUMEXT);
        if ($pointkey === '') {
            $pointkey = 'point_' . substr(sha1($routeid . ':' . microtime(true)), 0, 12);
        }

        $existing = $DB->get_record('local_ustar_route_points', [
            'routeid' => $routeid,
            'pointkey' => $pointkey,
        ]);
        $now = time();

        if ($existing) {
            if (empty($existing->active)) {
                $existing->active = 1;
                $existing->timemodified = $now;
                $existing->usermodified = $actorid;
                $DB->update_record('local_ustar_route_points', $existing);
            }
            return $existing;
        }

        $pointid = (int)$DB->insert_record('local_ustar_route_points', (object)[
            'routeid' => (int)$route->id,
            'pointkey' => $pointkey,
            'phase' => self::clean_phase($phase),
            'sortorder' => $sortorder,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $actorid,
        ]);

        self::create_version($pointid, $version, $actorid);
        return $DB->get_record('local_ustar_route_points', ['id' => $pointid], '*', MUST_EXIST);
    }

    /*
     * USTAR_ROUTE_CONTENT_POSITION_ACCESS_2706
     *
     * A published route assignment is an explicit access decision.
     * HR must not have to configure the same position twice:
     * once in Route Studio and again in Content ACL.
     */
    private static function ensure_route_content_access(
        int $routeid,
        array $requirements,
        int $actorid
    ): void {
        global $DB;

        $route =
            $DB->get_record(
                'local_ustar_routes',
                [
                    'id' => $routeid,
                    'active' => 1,
                ],
                'id,positionid',
                MUST_EXIST
            );

        $positionid =
            trim(
                (string)$route->positionid
            );

        if ($positionid === '') {
            throw new \moodle_exception(
                'У маршрута не определена должность'
            );
        }

        foreach ($requirements as $requirement) {

            if (
                (string)($requirement['type'] ?? '')
                !==
                'content'
            ) {
                continue;
            }

            $contentid =
                (int)(
                    $requirement['sourceid']
                    ?? 0
                );

            if ($contentid <= 0) {
                continue;
            }

            $contentrecord =
                $DB->get_record(
                    'local_ustar_content',
                    ['id' => $contentid],
                    'id,title,status',
                    MUST_EXIST
                );

            if (
                (string)$contentrecord->status
                !==
                content::STATUS_PUBLISHED
            ) {
                throw new \moodle_exception(
                    'Материал «'
                    . (string)$contentrecord->title
                    . '» сначала нужно опубликовать в разделе Материалы'
                );
            }

            $alreadyallowed =
                $DB->record_exists_select(
                    'local_ustar_content_access',
                    '
                        contentid = :contentid
                        AND active = 1
                        AND (
                            scopetype = :allscope
                            OR (
                                scopetype = :positionscope
                                AND scopeid = :positionid
                            )
                        )
                    ',
                    [
                        'contentid' => $contentid,
                        'allscope' => 'all',
                        'positionscope' => 'position',
                        'positionid' => $positionid,
                    ]
                );

            if ($alreadyallowed) {
                continue;
            }

            $DB->insert_record(
                'local_ustar_content_access',
                (object)[
                    'contentid' => $contentid,
                    'scopetype' => 'position',
                    'scopeid' => $positionid,
                    'active' => 1,
                    'timecreated' => time(),
                    'createdby' => $actorid,
                ]
            );
        }
    }


    public static function create_version(int $pointid, array $data, int $actorid): \stdClass {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('route-version:' . $pointid, 10);
        if (!$lock) { throw new \moodle_exception('Точка занята другим сохранением. Повторите попытку.'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $created = self::create_version_locked($pointid, $data, $actorid);
                $transaction->allow_commit();
                return $created;
            } catch (\Throwable $e) { $transaction->rollback($e); }
        } finally { $lock->release(); }
    }

    private static function create_version_locked(int $pointid, array $data, int $actorid): \stdClass {
        global $DB;
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid], '*', MUST_EXIST);
        $latest = self::latest_version($pointid);
        $versionno = $latest ? ((int)$latest->versionno + 1) : 1;

        $requirements = $data['requirements'] ?? null;
        if ($requirements === null && $latest) {
            $requirements = json_decode((string)$latest->requirementsjson, true);
        }
        if (!is_array($requirements)) {
            $requirements = [];
        }
        $requirements = self::normalize_requirements($requirements);

        $title = trim((string)($data['title'] ?? ($latest->title ?? 'Точка маршрута')));
        if ($title === '') {
            $title = 'Точка маршрута';
        }
        $summary = trim((string)($data['summary'] ?? ($latest->summary ?? '')));
        $policy = self::clean_policy((string)($data['renewalpolicy'] ?? ($latest->renewalpolicy ?? self::RENEW_KEEP)));
        $status = self::clean_status((string)($data['status'] ?? self::STATUS_DRAFT));

        $ownerroute = $DB->get_record(
            'local_ustar_routes',
            ['id' => (int)$point->routeid],
            'id,positionid,familyid,routekind',
            MUST_EXIST
        );

        if (
            $status === self::STATUS_PUBLISHED
            &&
            (string)$ownerroute->routekind ===
                \local_ustar\route_family::KIND_PARENT
        ) {
            foreach ($requirements as $requirement) {
                if (
                    (string)($requirement['type'] ?? '')
                    !== 'content'
                ) {
                    continue;
                }

                $contentid =
                    (int)($requirement['sourceid'] ?? 0);

                if ($contentid <= 0) {
                    continue;
                }

                $contentstatus =
                    (string)$DB->get_field(
                        'local_ustar_content',
                        'status',
                        ['id' => $contentid],
                        MUST_EXIST
                    );

                if (
                    $contentstatus
                    !== \local_ustar\content::STATUS_PUBLISHED
                ) {
                    throw new \moodle_exception(
                        'Общий шаг нельзя опубликовать: '
                        . 'сначала опубликуйте выбранный материал'
                    );
                }
            }
        }

        if (
            $status === self::STATUS_PUBLISHED
            &&
            (string)$ownerroute->routekind !==
                \local_ustar\route_family::KIND_PARENT
        ) {
            self::ensure_route_content_access(
                (int)$point->routeid,
                $requirements,
                $actorid
            );
        }

        $validdays = max(0, min(3650, (int)($data['validdays'] ?? ($latest->validdays ?? 0))));
        $effectivedate = isset($data['effectivedate']) ? max(0, (int)$data['effectivedate']) : 0;
        $now = time();

        $id = (int)$DB->insert_record('local_ustar_route_versions', (object)[
            'pointid' => (int)$point->id,
            'versionno' => $versionno,
            'title' => $title,
            'summary' => $summary,
            'requirementsjson' => json_encode($requirements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'renewalpolicy' => $policy,
            'validdays' => $validdays,
            'status' => $status,
            'effectivedate' => $effectivedate,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $actorid,
        ]);

        $created = $DB->get_record(
            'local_ustar_route_versions',
            ['id' => $id],
            '*',
            MUST_EXIST
        );

        // Lifecycle policy is immutable/version-bound just like the route
        // version. Preserve business policy across ordinary edits while letting
        // the provider adapter refresh its technical reference (e.g. new Quiz CM).
        if (class_exists('\\local_ustar\\assessment_lifecycle')) {
            \local_ustar\assessment_lifecycle::inherit_policy_for_version(
                $latest ?: null, $created, $actorid
            );
        }

        if (
            (string)$ownerroute->routekind ===
                \local_ustar\route_family::KIND_PARENT
            &&
            !\local_ustar\route_scope::available()
        ) {
            \local_ustar\route_family::sync_parent_point(
                (int)$point->id,
                $actorid,
                $created
            );
        }

        return $created;
    }

    /**
     * Attach a freshly published USTAR Content item to one existing point.
     *
     * This is deliberately a human workflow boundary: the editor selects a
     * point, uploads through the native Content engine, and returns to one
     * new published route version. No source key or database identifier is
     * exposed in the normal UI.
     */
    public static function attach_published_content(
        int $routeid,
        int $pointid,
        int $contentid,
        int $actorid,
        int $expectedmodified = 0
    ): \stdClass {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_ustar_routes');
        $lock = $factory->get_lock('route:' . $routeid, 10);
        if (!$lock) {
            throw new \moodle_exception('Маршрут сейчас изменяется другим пользователем. Повторите попытку через несколько секунд.');
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            $point = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_route_points} WHERE id = :id AND routeid = :routeid FOR UPDATE',
                ['id' => $pointid, 'routeid' => $routeid],
                MUST_EXIST
            );
            if ($expectedmodified > 0 && (int)$point->timemodified !== $expectedmodified) {
                $transaction->rollback(new \moodle_exception('Точка маршрута уже изменена в другой сессии. Материал сохранён в USTAR Content, но не был привязан автоматически. Обновите маршрут и добавьте его из списка материалов.'));
            }

            $content = $DB->get_record('local_ustar_content', ['id' => $contentid], 'id,title,status,ackrequired', MUST_EXIST);
            if ((string)$content->status !== content::STATUS_PUBLISHED) {
                $transaction->rollback(new \moodle_exception('В маршрут можно автоматически добавить только опубликованный материал.'));
            }

            $latest = self::latest_version((int)$point->id);
            if (!$latest) {
                $transaction->rollback(new \moodle_exception('Сначала создайте первую версию точки маршрута, затем загрузите в неё файл.'));
            }

            $requirements = self::requirements_for_version($latest);
            foreach ($requirements as $requirement) {
                if (($requirement['type'] ?? '') === 'content' && (int)($requirement['sourceid'] ?? 0) === $contentid) {
                    $transaction->allow_commit();
                    return $latest;
                }
            }
            $requirements[] = [
                'type' => 'content',
                'sourceid' => $contentid,
                'completionmode' => !empty($content->ackrequired) ? 'ack' : 'open',
                'required' => true,
                'label' => (string)$content->title,
            ];

            $point->timemodified = max(time(), (int)$point->timemodified + 1);
            $point->usermodified = $actorid;
            $DB->update_record('local_ustar_route_points', $point);
            $version = self::create_version((int)$point->id, [
                'title' => (string)$latest->title,
                'summary' => (string)$latest->summary,
                'requirements' => $requirements,
                'renewalpolicy' => (string)$latest->renewalpolicy,
                'validdays' => (int)$latest->validdays,
                'status' => self::STATUS_PUBLISHED,
                'effectivedate' => time(),
            ], $actorid);
            $transaction->allow_commit();
            return $version;
        } finally {
            $lock->release();
        }
    }

    public static function reorder(int $routeid, array $pointids, int $actorid, string $expectedrevision = ''): void {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar_routes');
        $lock = $factory->get_lock('route:' . $routeid, 10);
        if (!$lock) {
            throw new \moodle_exception('Маршрут сейчас изменяется другим пользователем. Повторите попытку через несколько секунд.');
        }
        try {
            if ($expectedrevision !== '' && !hash_equals(self::revision($routeid), $expectedrevision)) {
                throw new \moodle_exception('Порядок точек уже изменён в другой сессии. Обновите маршрут и повторите действие.');
            }
            $allowed = [];
            foreach (self::points($routeid) as $point) {
                $allowed[(int)$point->id] = true;
            }
            $seen = [];
            $sort = 10;
            $transaction = $DB->start_delegated_transaction();
            foreach ($pointids as $pointid) {
                $pointid = (int)$pointid;
                if ($pointid <= 0 || empty($allowed[$pointid]) || isset($seen[$pointid])) {
                    continue;
                }
                $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'routeid' => $routeid], 'id,timemodified', MUST_EXIST);
                $DB->set_field('local_ustar_route_points', 'sortorder', $sort, ['id' => $pointid, 'routeid' => $routeid]);
                $DB->set_field('local_ustar_route_points', 'timemodified', max(time(), (int)$point->timemodified + 1), ['id' => $pointid]);
                $DB->set_field('local_ustar_route_points', 'usermodified', $actorid, ['id' => $pointid]);
                $seen[$pointid] = true;
                $sort += 10;
            }
            $transaction->allow_commit();

            if (
                \local_ustar\route_family::is_parent_route(
                    $routeid
                )
                &&
                !\local_ustar\route_scope::available()
            ) {
                \local_ustar\route_family::sync_parent_route(
                    $routeid,
                    $actorid
                );
            }

        } finally {
            $lock->release();
        }
    }

    public static function archive_point(int $routeid, int $pointid, int $actorid): void {
        global $DB;
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'routeid' => $routeid], 'id,phase', MUST_EXIST);
        self::update_point($routeid, $pointid, (string)$point->phase, false, $actorid);
    }

    public static function seed_from_required_courses(string $positionid, int $actorid): array {
        $route = self::ensure_route($positionid, $actorid);
        $required = assignment::required_courses($positionid);
        if (empty($required['ok'])) {
            return ['route' => $route, 'created' => 0];
        }
        $created = 0;
        $sort = 10;
        foreach (learning_route::apply_legacy_order($positionid, $required['courses'] ?? []) as $course) {
            $courseid = (int)($course['id'] ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            $key = 'course_' . $courseid;
            $before = self::find_point((int)$route->id, $key);
            if (!$before) {
                self::add_point((int)$route->id, $key, self::PHASE_ADAPTATION, $sort, [
                    'title' => (string)($course['name'] ?? ('Курс #' . $courseid)),
                    'summary' => 'Автоматически перенесено из маршрута 1.x. Источник — обязательный Moodle-курс должности.',
                    'requirements' => [[
                        'type' => 'course',
                        'sourceid' => $courseid,
                        'required' => true,
                    ]],
                    'renewalpolicy' => self::RENEW_KEEP,
                    'validdays' => 0,
                    'status' => self::STATUS_PUBLISHED,
                    'effectivedate' => 0,
                ], $actorid);
                $created++;
            }
            $sort += 10;
        }
        return ['route' => $route, 'created' => $created];
    }

    public static function find_point(int $routeid, string $pointkey): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_route_points', [
            'routeid' => $routeid,
            'pointkey' => $pointkey,
        ]) ?: null;
    }

    public static function requirements_for_version(\stdClass $version): array {
        $decoded = json_decode((string)$version->requirementsjson, true);
        return is_array($decoded) ? self::normalize_requirements($decoded) : [];
    }

    private static function requirements(\stdClass $version): array {
        return self::requirements_for_version($version);
    }

    private static function activity_info(int $cmid): ?array {
        global $DB;
        $record = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.completion, cm.completionview, m.name AS modname, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND cm.deletioninprogress = 0",
            ['cmid' => $cmid]
        );
        if (!$record) {
            return null;
        }
        $name = '';
        if (in_array($record->modname, ['page', 'quiz', 'scorm', 'lesson', 'resource', 'forum'], true)) {
            $name = (string)$DB->get_field($record->modname, 'name', ['id' => (int)$record->instance]);
        }
        return [
            'id' => (int)$record->id,
            'courseid' => (int)$record->course,
            'instance' => (int)$record->instance,
            'completion' => (int)$record->completion,
            'completionview' => (int)$record->completionview,
            'modname' => (string)$record->modname,
            'name' => $name,
        ];
    }

    /**
     * Read-only quiz summary for route presentation when an assessment point
     * has not yet been migrated to assessment_lifecycle policy.
     *
     * @return array<string,mixed>|null
     */
    private static function quiz_summary_for_version(\stdClass $version, int $userid): ?array {
        global $DB;

        $quizinfo = null;
        foreach (self::requirements($version) as $requirement) {
            if ((string)($requirement['type'] ?? '') !== 'cm') {
                continue;
            }
            $info = self::activity_info((int)($requirement['sourceid'] ?? 0));
            if ($info && (string)$info['modname'] === 'quiz') {
                $quizinfo = $info;
                break;
            }
        }

        if (!$quizinfo) {
            return null;
        }

        $quizid = (int)$quizinfo['instance'];
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,grade,sumgrades,attempts', IGNORE_MISSING);
        if (!$quiz) {
            return null;
        }

        $attemptsused = $DB->count_records('quiz_attempts', [
            'quiz' => $quizid,
            'userid' => $userid,
            'preview' => 0,
        ]);

        $attemptlimit = (int)$quiz->attempts;
        $override = $DB->get_record('quiz_overrides', [
            'quiz' => $quizid,
            'userid' => $userid,
        ], 'id,attempts', IGNORE_MISSING);
        if ($override && $override->attempts !== null) {
            $attemptlimit = (int)$override->attempts;
        }

        $best = 0.0;
        $grade = $DB->get_record('quiz_grades', [
            'quiz' => $quizid,
            'userid' => $userid,
        ], 'grade', IGNORE_MISSING);
        if ($grade && $grade->grade !== null) {
            $best = (float)$grade->grade;
        }

        $passscore = 0.0;
        $gradeitem = $DB->get_record('grade_items', [
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
        ], 'id,gradepass', IGNORE_MULTIPLE);
        if ($gradeitem && $gradeitem->gradepass !== null) {
            $passscore = (float)$gradeitem->gradepass;
        }

        $fmt = static function(float $value): string {
            if (abs($value - round($value)) < 0.00001) {
                return (string)(int)round($value);
            }
            return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        };

        return [
            'hasstats' => true,
            'attemptsused' => (int)$attemptsused,
            'attemptlimit' => $attemptlimit,
            'attemptlimitlabel' => $attemptlimit > 0 ? (string)$attemptlimit : '∞',
            'bestscore' => $fmt($best),
            'maxscore' => $fmt((float)$quiz->grade),
            'passscore' => $fmt($passscore),
        ];
    }

    private static function requirement_result(array $requirement, int $userid, string $positionid, array $priorstates, \stdClass $version): array {
        global $CFG, $DB;
        $type = (string)$requirement['type'];
        $result = [
            'type' => $type,
            'required' => !empty($requirement['required']),
            'label' => (string)($requirement['label'] ?? ''),
            'configured' => true,
            'satisfied' => false,
            'failed' => false,
            'completedat' => 0,
            'url' => '',
            'detail' => '',
        ];

        if ($type === 'course') {
            $courseid = (int)$requirement['sourceid'];
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,visible');
            if (!$course) {
                $result['configured'] = false;
                $result['detail'] = 'Moodle-курс не найден';
                return $result;
            }
            $result['label'] = $result['label'] ?: format_string($course->fullname);
            $result['url'] = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
            $completion = $DB->get_record('course_completions', ['course' => $courseid, 'userid' => $userid], 'timecompleted');
            $completedat = $completion ? (int)$completion->timecompleted : 0;
            $result['completedat'] = $completedat;
            $result['satisfied'] = $completedat > 0;
        } else if ($type === 'cm') {
            $cmid = (int)$requirement['sourceid'];
            $info = self::activity_info($cmid);
            if (!$info) {
                $result['configured'] = false;
                $result['detail'] = 'Активность Moodle не найдена';
                return $result;
            }
            $result['label'] = $result['label'] ?: ($info['name'] ?: ('Активность #' . $cmid));
            if ((string)$info['modname'] === 'scorm') {

                // Enter through USTAR first so route context is stored
                // before Moodle redirects view.php -> player.php.
                $result['url'] = (
                    new \moodle_url(
                        '/local/ustar/scorm_launch.php',
                        [
                            'cmid' => $cmid,
                            'pointid' => (int)$version->pointid,
                            'versionid' => (int)$version->id,
                        ]
                    )
                )->out(false);

            } else {

                // Every Moodle activity is entered through USTAR. The launcher
                // revalidates that this exact CM belongs to the employee's
                // current logical route point and that technical course access
                // has been provisioned before Moodle receives the request.
                $result['url'] = (
                    new \moodle_url(
                        '/local/ustar/activity_launch.php',
                        [
                            'cmid' => $cmid,
                            'pointid' => (int)$version->pointid,
                            'versionid' => (int)$version->id,
                        ]
                    )
                )->out(false);
            }
            // course_modules.completion is defined by Moodle core as:
            // 0 = tracking disabled, 1 = manual, 2 = automatic. Do not depend
            // on completionlib.php being loaded by every CLI/web entry point.
            if ((int)$info['completion'] === 0) {
                $result['configured'] = false;
                $result['detail'] = 'В Moodle не включено отслеживание завершения этой активности';
                return $result;
            }
            $completion = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $cmid,
                'userid' => $userid,
            ], 'completionstate,timemodified');

            $state = $completion ? (int)$completion->completionstate : 0;

            $result['completedat'] =
                $completion
                    ? (int)$completion->timemodified
                    : 0;

            $result['failed'] =
                $state === 3;

            $result['satisfied'] =
                in_array($state, [1, 2], true);

            /*
             * Moodle 5.x may expose completionview=1 + viewed=1 for a Page
             * while course_modules_completion.completionstate is still 0.
             * For a Page, view is the complete automatic criterion, so USTAR
             * must use Moodle's completion API rather than trapping the learner
             * on the same page forever.
             */
            if (
                !$result['satisfied']
                && (string)$info['modname'] === 'page'
                && (int)$info['completion'] === 2
                && !empty($info['completionview'])
            ) {
                require_once($CFG->libdir . '/completionlib.php');

                $course = get_course((int)$info['courseid']);
                $completioninfo = new \completion_info($course);
                $cm = get_coursemodule_from_id(
                    null,
                    $cmid,
                    0,
                    false,
                    MUST_EXIST
                );
                $completiondata = $completioninfo->get_data(
                    $cm,
                    false,
                    $userid
                );

                if (!empty($completiondata->viewed)) {
                    $result['satisfied'] = true;

                    $viewedat = (int)$DB->get_field_sql(
                        "SELECT COALESCE(MAX(timecreated), 0)
                           FROM {logstore_standard_log}
                          WHERE userid = :userid
                            AND contextlevel = :contextlevel
                            AND contextinstanceid = :cmid
                            AND eventname = :eventname",
                        [
                            'userid' => $userid,
                            'contextlevel' => CONTEXT_MODULE,
                            'cmid' => $cmid,
                            'eventname' => '\\mod_page\\event\\course_module_viewed',
                        ]
                    );

                    $result['completedat'] = max(
                        $result['completedat'],
                        $viewedat
                    );
                    $result['detail'] = 'Страница просмотрена';
                }
            }

            /*
             * Moodle course_modules_completion stores the first/current
             * activity completion timestamp and may not move when a SCORM
             * is completed again in a later attempt.
             *
             * USTAR route versions with RENEW_ALL need the timestamp of the
             * actual latest SCORM completion, otherwise a valid retraining
             * attempt is incorrectly treated as old evidence.
             */
            if ((string)$info['modname'] === 'scorm') {

                $scormcompletedat = (int)$DB->get_field_sql(
                    "SELECT COALESCE(MAX(v.timemodified), 0)
                       FROM {scorm_scoes_value} v
                       JOIN {scorm_attempt} a
                         ON a.id = v.attemptid
                       JOIN {scorm_element} e
                         ON e.id = v.elementid
                      WHERE a.scormid = :scormid
                        AND a.userid = :userid
                        AND e.element IN (
                            'cmi.core.lesson_status',
                            'cmi.completion_status'
                        )
                        AND LOWER(v.value) IN (
                            'completed',
                            'passed'
                        )",
                    [
                        'scormid' => (int)$info['instance'],
                        'userid' => $userid,
                    ]
                );

                if ($scormcompletedat > 0) {
                    $result['completedat'] =
                        max(
                            $result['completedat'],
                            $scormcompletedat
                        );

                    $result['satisfied'] = true;
                    $result['failed'] = false;
                }
            }
        } else if ($type === 'content') {
            $contentid = (int)$requirement['sourceid'];
            $item = $DB->get_record('local_ustar_content', ['id' => $contentid]);
            $contentversion = $item ? content::current_version($contentid) : null;
            if (
                !$item
                || (string)$item->type === 'folder'
                || (string)$item->status !== content::STATUS_PUBLISHED
                || !$contentversion
                || empty($contentversion->iscurrent)
                || (string)$contentversion->status !== content::STATUS_PUBLISHED
            ) {
                $result['configured'] = false;
                $result['detail'] = 'Текущая опубликованная версия материала USTAR не найдена';
                return $result;
            }
            if (!content::can_access_record($item, $userid)) {
                $result['configured'] = false;
                $result['detail'] = 'Правила доступа материала не включают этого сотрудника';
                return $result;
            }
            $mode = (string)($requirement['completionmode'] ?? 'open');
            if (
                $mode === 'ack'
                && (empty($item->ackrequired) || (string)$item->sourcekind !== content::SOURCE_FILE)
            ) {
                $result['configured'] = false;
                $result['detail'] = 'Подтверждение доступно только для USTAR File с включённым ознакомлением';
                return $result;
            }
            $result['label'] = $result['label'] ?: format_string((string)$item->title);
            $result['url'] = (new \moodle_url('/local/ustar/open.php', [
                'contentid' => $contentid,
                'pointid' => (int)$version->pointid,
                'versionid' => (int)$version->id,
            ]))->out(false);
            $event = learning_events::route_fact(
                $userid,
                $contentid,
                (int)$version->pointid,
                (int)$version->id,
                $mode
            );
            $result['satisfied'] = !empty($event);
            $result['completedat'] = $event ? (int)$event->timecreated : 0;
            $result['detail'] = $mode === 'ack'
                ? ($result['satisfied'] ? 'Ознакомление подтверждено' : 'Откройте и подтвердите ознакомление')
                : ($result['satisfied'] ? 'Материал открыт из маршрута' : 'Откройте материал из маршрута');
        } else if ($type === 'assessment') {
            $assessmentkey = (string)$requirement['sourcekey'];
            $definition = development_assessment::published($assessmentkey);
            if (!$definition) {
                $result['configured'] = false;
                $result['detail'] = 'Развивающий профиль USTAR не найден или не опубликован';
                return $result;
            }
            $result['label'] = $result['label'] ?: format_string((string)$definition['assessment']->title);
            $result['url'] = (new \moodle_url('/local/ustar/development_assessment.php', [
                'assessment' => $assessmentkey,
                'fromroute' => 1,
            ]))->out(false);
            $attempt = development_assessment::completion_for_user($assessmentkey, $userid);
            $result['satisfied'] = !empty($attempt);
            $result['completedat'] = $attempt ? (int)$attempt->submittedat : 0;
            $result['detail'] = $attempt
                ? 'Личная саморефлексия завершена'
                : 'Пройдите короткую личную саморефлексию';
        } else if ($type === 'native') {
            $factkey =
                (string)$requirement['sourcekey'];

            $fact =
                \local_ustar\native_learning::fact(
                    $userid,
                    (int)$version->pointid,
                    (int)$version->id,
                    $factkey
                );

            $result['label'] =
                $result['label']
                ?: 'Нативная активность USTAR';

            $result['configured'] = true;
            $result['satisfied'] = !empty($fact);

            $result['completedat'] =
                $fact
                ? (int)$fact->timecreated
                : 0;

            $result['url'] =
                \local_ustar\native_learning::url_for(
                    $factkey
                );

            $result['detail'] =
                $fact
                ? 'Активность завершена'
                : 'Завершите активность USTAR';

        } else if ($type === 'skill') {
            $skillid = (string)$requirement['sourcekey'];
            $fact = evidence::evaluate_skill($skillid, $positionid, $userid);
            $result['label'] = $result['label'] ?: ('Навык: ' . $skillid);
            $result['configured'] = !empty($fact['configured']);
            $result['satisfied'] = !empty($fact['satisfied']);
            $result['detail'] = $result['configured'] ? ((int)($fact['progress'] ?? 0) . '% подтверждено') : 'Для навыка не настроены подтверждения';
            // Keep a real evidence timestamp so RENEW_ALL can reject evidence
            // produced before a new checkpoint version became effective.
            $skillcompletedat = 0;
            foreach (($fact['bestpath']['items'] ?? []) as $evidenceitem) {
                $skillcompletedat = max($skillcompletedat, (int)($evidenceitem['completedat'] ?? 0));
            }
            $result['completedat'] = $skillcompletedat;
            $result['url'] = (new \moodle_url('/local/ustar/profile.php'))->out(false);
        } else if ($type === 'previous_adaptation') {
            $relevant = array_filter($priorstates, static fn(array $state): bool => in_array($state['phase'], [self::PHASE_ADAPTATION, self::PHASE_GATE], true));
            $result['label'] = $result['label'] ?: 'Все предыдущие обязательные точки адаптации';
            $result['satisfied'] = !empty($relevant) && count(array_filter($relevant, static fn(array $state): bool => !empty($state['satisfied']))) === count($relevant);
            $result['detail'] = $result['satisfied'] ? 'Предыдущие точки закрыты' : 'Сначала завершите предыдущие обязательные точки';
        }

        // A mandatory re-completion version may only accept evidence produced
        // on/after the effective date. Version 1 and keep-policy versions use 0.
        if (
            $result['satisfied'] &&
            (string)$version->renewalpolicy === self::RENEW_ALL &&
            (int)$version->effectivedate > 0 &&
            $result['completedat'] > 0 &&
            $result['completedat'] < (int)$version->effectivedate
        ) {
            $result['satisfied'] = false;
            $result['detail'] = 'Результат относится к предыдущей версии точки';
        }

        return $result;
    }

    private static function prior_progress(int $userid, int $pointid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_ustar_route_progress',
            ['userid' => $userid, 'pointid' => $pointid, 'status' => 'complete'],
            'completedat DESC, id DESC'
        ));
    }

    private static function record_completion(int $userid, \stdClass $point, \stdClass $version, array $evidence, int $completedat = 0, int $expiresat = 0): void {
        global $DB;
        if ($DB->record_exists('local_ustar_route_progress', [
            'userid' => $userid,
            'pointid' => (int)$point->id,
            'versionid' => (int)$version->id,
        ])) {
            route_rewards::try_progress($userid, (int)$point->id, (int)$version->id);
            return;
        }
        $now = time();
        try {
            $DB->insert_record('local_ustar_route_progress', (object)[
                'userid' => $userid,
                'pointid' => (int)$point->id,
                'versionid' => (int)$version->id,
                'status' => 'complete',
                'completedat' => $completedat > 0 ? $completedat : $now,
                'expiresat' => $expiresat > 0 ? $expiresat : null,
                'evidencejson' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => $now,
                'timemodified' => $now,
                'recordedby' => 0,
            ]);
        } catch (\dml_write_exception $e) {
            // Unique-key race: another request may have reconciled the same fact.
            if (!$DB->record_exists('local_ustar_route_progress', [
                'userid' => $userid,
                'pointid' => (int)$point->id,
                'versionid' => (int)$version->id,
            ])) {
                throw $e;
            }
        }
        route_rewards::try_progress($userid, (int)$point->id, (int)$version->id);
    }

    private static function evaluate_point(\stdClass $point, \stdClass $version, int $userid, string $positionid, array $priorstates): array {
        global $DB;
        $existing = $DB->get_record('local_ustar_route_progress', [
            'userid' => $userid,
            'pointid' => (int)$point->id,
            'versionid' => (int)$version->id,
        ]);
        if ($existing && (string)$existing->status === 'complete') {
            $expired = !empty($existing->expiresat) && (int)$existing->expiresat < time();
            if (!$expired) {
                return [
                    'satisfied' => true,
                    'inherited' => false,
                    'completedat' => (int)$existing->completedat,
                    'expiresat' => (int)($existing->expiresat ?? 0),
                    'requirements' => [],
                    'launchurl' => '',
                    'failed' => false,
                ];
            }
        }

        $older = self::prior_progress($userid, (int)$point->id);
        if ($older && in_array((string)$version->renewalpolicy, [self::RENEW_KEEP, self::RENEW_EXPIRY], true)) {
            foreach ($older as $progress) {
                if ((int)$progress->versionid === (int)$version->id) {
                    continue;
                }
                $valid = true;
                $expiresat = (int)($progress->expiresat ?? 0);
                if ((string)$version->renewalpolicy === self::RENEW_EXPIRY) {
                    // Older progress may predate route 2.0 and have no stored
                    // expiry. Derive it from the current version validity rule.
                    if ($expiresat <= 0 && (int)$version->validdays > 0) {
                        $expiresat = (int)$progress->completedat + ((int)$version->validdays * DAYSECS);
                    }
                    $valid = $expiresat <= 0 || $expiresat >= time();
                }
                if ($valid) {
                    self::record_completion($userid, $point, $version, [
                        'mode' => 'inherited',
                        'fromprogressid' => (int)$progress->id,
                    ], (int)$progress->completedat, $expiresat);
                    return [
                        'satisfied' => true,
                        'inherited' => true,
                        'completedat' => (int)$progress->completedat,
                        'expiresat' => $expiresat,
                        'requirements' => [],
                        'launchurl' => '',
                        'failed' => false,
                    ];
                }
            }
        }

        $requirements = [];
        $requiredcount = 0;
        $requiredsatisfied = 0;
        $failed = false;
        $launchurl = '';
        $latestcompletion = 0;

        foreach (self::requirements($version) as $requirement) {
            $fact = self::requirement_result($requirement, $userid, $positionid, $priorstates, $version);
            $requirements[] = $fact;
            if (!empty($fact['required'])) {
                $requiredcount++;
                if (!empty($fact['satisfied'])) {
                    $requiredsatisfied++;
                } else if ($launchurl === '' && !empty($fact['url'])) {
                    $launchurl = (string)$fact['url'];
                }
            }
            $failed = $failed || !empty($fact['failed']);
            $latestcompletion = max($latestcompletion, (int)($fact['completedat'] ?? 0));
        }

        // A point with only optional resources must never block an employee.
        // Published empty points are rejected by Route Studio, so this cannot
        // convert an unconfigured learning step into a completion.
        $satisfied = $requiredcount === 0 ? !empty($requirements) : $requiredcount === $requiredsatisfied;
        $expiresat = 0;
        if ($satisfied && (int)$version->validdays > 0) {
            $base = $latestcompletion > 0 ? $latestcompletion : time();
            $expiresat = $base + ((int)$version->validdays * DAYSECS);
            if ($expiresat < time()) {
                $satisfied = false;
            }
        }

        if ($satisfied) {
            self::record_completion($userid, $point, $version, [
                'mode' => 'evaluated',
                'requirements' => $requirements,
            ], $latestcompletion, $expiresat);
        }

        return [
            'satisfied' => $satisfied,
            'inherited' => false,
            'completedat' => $latestcompletion,
            'expiresat' => $expiresat,
            'requirements' => $requirements,
            'launchurl' => $launchurl,
            'failed' => $failed,
        ];
    }

    private static function phase_label(string $phase): string {
        return match ($phase) {
            self::PHASE_CONTINUOUS => 'Постоянное обучение',
            self::PHASE_GATE => 'Допуск',
            default => 'Адаптация',
        };
    }

    private static function policy_label(string $policy): string {
        return match ($policy) {
            self::RENEW_ALL => 'Новая версия обязательна всем',
            self::RENEW_EXPIRY => 'Повтор после истечения срока',
            self::RENEW_MANUAL => 'По назначению администратора',
            default => 'Предыдущий результат сохраняется',
        };
    }

    /**
     * Grant technical Moodle course access only when a scoped step
     * actually becomes the employee's current available step.
     */
    private static function ensure_runtime_requirement_access(
        array $requirements,
        int $userid
    ): void {
        global $CFG, $DB;

        if ($userid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/enrollib.php');

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0]
        );

        if (!$user || !empty($user->suspended)) {
            return;
        }

        $courseids = [];

        foreach ($requirements as $requirement) {
            $type = (string)($requirement['type'] ?? '');
            $sourceid = (int)($requirement['sourceid'] ?? 0);

            if ($type === 'course' && $sourceid > 1) {
                $courseids[$sourceid] = true;
            }

            if ($type === 'cm' && $sourceid > 0) {
                $cm = $DB->get_record(
                    'course_modules',
                    [
                        'id' => $sourceid,
                        'deletioninprogress' => 0,
                    ],
                    'id,course'
                );

                if ($cm && (int)$cm->course > 1) {
                    $courseids[(int)$cm->course] = true;
                }
            }
        }

        foreach (array_keys($courseids) as $courseid) {
            $course = $DB->get_record(
                'course',
                ['id' => $courseid],
                '*',
                IGNORE_MISSING
            );

            if (!$course) {
                continue;
            }

            $context = \context_course::instance($courseid);

            if (is_enrolled($context, $user, '', true)) {
                continue;
            }

            // First allow any standard internal enrolment plugin to do its job.
            enrol_try_internal_enrol($courseid, $userid);

            if (is_enrolled($context, $user, '', true)) {
                continue;
            }

            /*
             * Route-only technical containers are valid Moodle courses too.
             * Some legacy containers (for example the hidden quiz container)
             * were created without a manual enrolment instance. Previously
             * USTAR silently gave up here and the employee landed on Moodle's
             * "course unavailable" page even though the route step was open.
             *
             * Create the normal single manual instance only when none exists.
             * This is not user-specific and therefore fixes future employees,
             * while an explicitly existing/disabled instance is respected.
             */
            $manual = enrol_get_plugin('manual');
            if (!$manual) {
                continue;
            }

            $manualinstance = null;
            foreach (enrol_get_instances($courseid, false) as $instance) {
                if ((string)$instance->enrol === 'manual') {
                    $manualinstance = $instance;
                    break;
                }
            }

            if (!$manualinstance) {
                $roleid = (int)$DB->get_field(
                    'role',
                    'id',
                    ['shortname' => 'student']
                );

                if ($roleid <= 0) {
                    continue;
                }

                $instanceid = $manual->add_instance(
                    $course,
                    [
                        'status' => ENROL_INSTANCE_ENABLED,
                        'roleid' => $roleid,
                        'enrolperiod' => 0,
                        'expirynotify' => 0,
                        'notifyall' => 0,
                        'expirythreshold' => 86400,
                        'customint1' => defined('ENROL_DO_NOT_SEND_EMAIL')
                            ? ENROL_DO_NOT_SEND_EMAIL
                            : 0,
                    ]
                );

                if ($instanceid) {
                    $manualinstance = $DB->get_record(
                        'enrol',
                        ['id' => (int)$instanceid],
                        '*',
                        IGNORE_MISSING
                    );
                }
            }

            // Do not override a deliberately disabled enrolment instance.
            if (
                !$manualinstance
                || (int)$manualinstance->status !== ENROL_INSTANCE_ENABLED
            ) {
                continue;
            }

            $roleid = (int)($manualinstance->roleid ?? 0);
            if ($roleid <= 0) {
                $roleid = (int)$DB->get_field(
                    'role',
                    'id',
                    ['shortname' => 'student']
                );
            }

            if ($roleid <= 0) {
                continue;
            }

            $manual->enrol_user(
                $manualinstance,
                $userid,
                $roleid,
                time(),
                0,
                ENROL_USER_ACTIVE
            );
        }
    }

    /**
     * Guard USTAR content launches against guessed URLs.
     * Supports both legacy position routes and TARGET family parent routes.
     */
    /**
     * Side-effect-free route snapshot for management dashboards.
     * Reads persisted USTAR progress only.
     */
    public static function read_only_snapshot(
        string $positionid,
        int $userid
    ): array {
        global $DB;

        $route = null;

        if (\local_ustar\route_scope::available()) {
            $route =
                \local_ustar\route_scope::parent_for_position(
                    $positionid
                );
        }

        if (!$route) {
            $route = self::get_route($positionid);
        }

        if (!$route) {
            return [
                'ok' => false,
                'reason' => 'route_missing',
                'totalpoints' => 0,
                'donepoints' => 0,
                'remaining' => 0,
                'progress' => 0,
                'points' => [],
                'currentpoint' => null,
                'lastprogressat' => 0,
            ];
        }

        $isparent =
            (string)($route->routekind ?? '') ===
            \local_ustar\route_family::KIND_PARENT;

        if (
            $isparent
            &&
            \local_ustar\route_scope::available()
        ) {
            $points =
                \local_ustar\route_scope::points_for_position(
                    (int)$route->id,
                    $positionid
                );
        } else {
            $points = self::points((int)$route->id);
        }

        $rows = [];
        $done = 0;
        $currentpoint = null;
        $sequenceopen = true;
        $lastprogressat = 0;

        foreach ($points as $point) {
            $version =
                self::current_published_version(
                    (int)$point->id
                );

            if (!$version) {
                continue;
            }

            $complete = false;
            $completedat = 0;

            $exact = $DB->get_record(
                'local_ustar_route_progress',
                [
                    'userid' => $userid,
                    'pointid' => (int)$point->id,
                    'versionid' => (int)$version->id,
                    'status' => 'complete',
                ]
            );

            if ($exact) {
                $expired =
                    !empty($exact->expiresat)
                    &&
                    (int)$exact->expiresat < time();

                if (!$expired) {
                    $complete = true;
                    $completedat =
                        (int)$exact->completedat;
                }
            }

            if (
                !$complete
                &&
                (string)$version->renewalpolicy
                    !== self::RENEW_ALL
            ) {
                $prior = $DB->get_records(
                    'local_ustar_route_progress',
                    [
                        'userid' => $userid,
                        'pointid' => (int)$point->id,
                        'status' => 'complete',
                    ],
                    'completedat DESC, id DESC'
                );

                foreach ($prior as $progress) {
                    if (
                        !empty($progress->expiresat)
                        &&
                        (int)$progress->expiresat < time()
                    ) {
                        continue;
                    }

                    $complete = true;
                    $completedat =
                        (int)$progress->completedat;

                    break;
                }
            }

            if ($complete) {
                $status = 'done';
                $done++;

                $lastprogressat =
                    max(
                        $lastprogressat,
                        $completedat
                    );

            } else if ($sequenceopen) {
                $status = 'current';
                $sequenceopen = false;

            } else {
                $status = 'locked';
            }

            $row = [
                'id' => (int)$point->id,
                'title' =>
                    format_string(
                        (string)$version->title
                    ),
                'status' => $status,
                'done' => $status === 'done',
                'current' => $status === 'current',
                'locked' => $status === 'locked',
                'completedat' => $completedat,
                'completedlabel' =>
                    $completedat > 0
                    ? userdate(
                        $completedat,
                        '%d.%m.%Y'
                    )
                    : '',
            ];

            $rows[] = $row;

            if (
                $status === 'current'
                &&
                $currentpoint === null
            ) {
                $currentpoint = $row;
            }
        }

        $total = count($rows);

        return [
            'ok' => true,
            'routeid' => (int)$route->id,
            'name' =>
                format_string(
                    (string)$route->name
                ),
            'totalpoints' => $total,
            'donepoints' => $done,
            'remaining' =>
                max(0, $total - $done),
            'progress' =>
                $total > 0
                ? (int)round(
                    $done / $total * 100
                )
                : 0,
            'complete' =>
                $total > 0
                &&
                $done === $total,
            'points' => $rows,
            'currentpoint' => $currentpoint,
            'hascurrentpoint' =>
                $currentpoint !== null,
            'lastprogressat' =>
                $lastprogressat,
        ];
    }

    public static function assert_content_launch(
        int $userid,
        int $contentid,
        int $pointid,
        int $versionid
    ): void {
        global $DB;

        $scope = content::user_scope($userid);
        $positionid = (string)($scope['positionid'] ?? '');

        $point = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $pointid, 'active' => 1],
            '*',
            MUST_EXIST
        );

        $route = $DB->get_record(
            'local_ustar_routes',
            ['id' => (int)$point->routeid, 'active' => 1],
            '*',
            MUST_EXIST
        );

        if (
            (string)($route->routekind ?? '') ===
            \local_ustar\route_family::KIND_PARENT
        ) {
            $parent = \local_ustar\route_scope::parent_for_position(
                $positionid
            );

            if (
                !$parent ||
                (int)$parent->id !== (int)$route->id ||
                !\local_ustar\route_scope::point_applies(
                    $pointid,
                    $positionid
                )
            ) {
                throw new \required_capability_exception(
                    \context_system::instance(),
                    'local/ustar:use',
                    'nopermissions',
                    ''
                );
            }
        } else if (
            (string)($route->positionid ?? '') !== $positionid
        ) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:use',
                'nopermissions',
                ''
            );
        }

        $version = self::current_published_version($pointid);

        if (!$version || (int)$version->id !== $versionid) {
            throw new \moodle_exception(
                'Версия шага маршрута больше не является текущей'
            );
        }

        $configured = false;

        foreach (self::requirements($version) as $requirement) {
            if (
                (string)$requirement['type'] === 'content' &&
                (int)$requirement['sourceid'] === $contentid
            ) {
                $configured = true;
                break;
            }
        }

        if (!$configured) {
            throw new \invalid_parameter_exception(
                'Материал не относится к этой версии шага маршрута'
            );
        }

        $model = self::for_user($positionid, $userid);

        foreach (($model['points'] ?? []) as $viewpoint) {
            if (
                (int)$viewpoint['id'] !== $pointid ||
                empty($viewpoint['canlaunch'])
            ) {
                continue;
            }

            $query = parse_url(
                (string)$viewpoint['launchurl'],
                PHP_URL_QUERY
            );

            parse_str((string)$query, $params);

            if ((int)($params['contentid'] ?? 0) === $contentid) {
                return;
            }
        }

        throw new \required_capability_exception(
            \context_system::instance(),
            'local/ustar:use',
            'nopermissions',
            ''
        );
    }

    /**
     * TARGET employee runtime.
     *
     * One physical family parent route is resolved by position scope.
     * Only the first unfinished point is evaluated.
     * Future locked points cannot auto-complete or grant Moodle access.
     */
    public static function for_user(
        string $positionid,
        int $userid
    ): array {
        $route = null;

        if (\local_ustar\route_scope::available()) {
            $route = \local_ustar\route_scope::parent_for_position(
                $positionid
            );
        }

        if (!$route) {
            $route = self::get_route($positionid);
        }

        if (!$route) {
            return [
                'ok' => false,
                'reason' => 'route_missing',
                'positionid' => $positionid,
            ];
        }

        $isparent =
            (string)($route->routekind ?? '') ===
            \local_ustar\route_family::KIND_PARENT;

        if ($isparent && \local_ustar\route_scope::available()) {
            $points = \local_ustar\route_scope::points_for_position(
                (int)$route->id,
                $positionid
            );
        } else {
            $points = self::points((int)$route->id);
        }

        $viewpoints = [];
        $priorstates = [];

        $total = 0;
        $done = 0;
        $displaynumber = 0;
        $sequenceopen = true;
        $currentpoint = null;

        foreach ($points as $point) {
            $version = self::current_published_version(
                (int)$point->id
            );

            // Draft and archived versions are invisible to employee runtime.
            if (!$version) {
                continue;
            }

            $total++;
            $displaynumber++;

            $fact = [
                'satisfied' => false,
                'inherited' => false,
                'completedat' => 0,
                'expiresat' => 0,
                'requirements' => [],
                'launchurl' => '',
                'failed' => false,
            ];

            /*
             * Critical TARGET rule:
             * never evaluate a future locked point.
             *
             * evaluate_point() may legitimately write completion based on
             * Moodle activity state, therefore it may only run for the
             * currently reachable sequence.
             */
            if ($sequenceopen) {
                self::ensure_runtime_requirement_access(
                    self::requirements($version),
                    $userid
                );

                $fact = self::evaluate_point(
                    $point,
                    $version,
                    $userid,
                    $positionid,
                    $priorstates
                );
            }

            $assessmentview = null;
            if ($sequenceopen && class_exists('\\local_ustar\\assessment_lifecycle')) {
                try {
                    $assessmentview = assessment_lifecycle::sync_route_point(
                        $userid,
                        $positionid,
                        $point,
                        $version
                    );
                    // A lifecycle-managed assessment PASS is authoritative.
                    // Do not depend on Moodle course_modules_completion catching up.
                    if (
                        $assessmentview
                        && (string)($assessmentview['status'] ?? '') === 'passed'
                        && empty($fact['satisfied'])
                    ) {
                        $completedat = time();

                        self::record_completion(
                            $userid,
                            $point,
                            $version,
                            [
                                'mode' => 'assessment_lifecycle',
                                'verifiedcompletedat' => (int)($assessmentview['verifiedcompletedat'] ?? 0),
                                'status' => 'passed',
                                'attempts' => (int)($assessmentview['attemptsused'] ?? 0),
                                'bestscore' => (float)($assessmentview['bestscore'] ?? 0),
                                'passscore' => (float)($assessmentview['passscore'] ?? 0),
                            ],
                            $completedat,
                            0
                        );

                        $fact['satisfied'] = true;
                        $fact['failed'] = false;
                        $fact['completedat'] = $completedat;
                        $fact['launchurl'] = '';
                    }

                    if ($assessmentview && empty($fact['satisfied'])) {
                        $fact['launchurl'] = (string)($assessmentview['launchurl'] ?? '');
                        if (!empty($assessmentview['remediation']) || !empty($assessmentview['exhausted'])) {
                            $fact['failed'] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    debugging('USTAR assessment lifecycle sync failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            $quizsummary = null;
            if (!$assessmentview) {
                $quizsummary = self::quiz_summary_for_version($version, $userid);
            }
            $assessmentstats = $assessmentview ?: $quizsummary;

            if ($sequenceopen && !empty($fact['satisfied'])) {
                $status = 'done';
                $statuslabel = 'Завершено';
                $done++;
            } else if ($sequenceopen) {
                $status = 'current';
                $statuslabel = $assessmentview
                    ? (string)($assessmentview['statuslabel'] ?? 'Сейчас')
                    : (!empty($fact['failed']) ? 'Нужно повторить' : 'Сейчас');

                $sequenceopen = false;
            } else {
                $status = 'locked';
                $statuslabel = 'Позже';
            }

            $employeerequirements = [];
            $developedskills = [];

            foreach (($fact['requirements'] ?? []) as $requirementfact) {
                if (
                    (string)($requirementfact['type'] ?? '') === 'skill' &&
                    empty($requirementfact['required'])
                ) {
                    $developedskills[] = [
                        'label' => (string)(
                            $requirementfact['label'] ?? ''
                        ),
                    ];
                    continue;
                }

                $employeerequirements[] = $requirementfact;
            }

            $item = [
                'id' => (int)$point->id,
                'number' => $displaynumber,
                'pointkey' => (string)$point->pointkey,
                'sortorder' => (int)$point->sortorder,

                // Legacy metadata kept only for compatibility.
                'phase' => (string)$point->phase,
                'phaselabel' => self::phase_label(
                    (string)$point->phase
                ),
                'adaptation' =>
                    (string)$point->phase === self::PHASE_ADAPTATION,
                'gate' =>
                    (string)$point->phase === self::PHASE_GATE,
                'continuous' =>
                    (string)$point->phase === self::PHASE_CONTINUOUS,

                'title' => format_string((string)$version->title),
                'summary' => (string)$version->summary,
                'hassummary' =>
                    trim((string)$version->summary) !== '',

                'versionno' => (int)$version->versionno,
                'versionlabel' => 'v' . (int)$version->versionno,
                'policylabel' => self::policy_label(
                    (string)$version->renewalpolicy
                ),

                'satisfied' => !empty($fact['satisfied']),
                'inherited' => !empty($fact['inherited']),

                'status' => $status,
                'statuslabel' => $statuslabel,
                'done' => $status === 'done',
                'current' => $status === 'current',
                'locked' => $status === 'locked',

                'launchurl' => (string)($fact['launchurl'] ?? ''),
                'canlaunch' =>
                    $status === 'current' &&
                    !empty($fact['launchurl']) &&
                    (!$assessmentview || !empty($assessmentview['canlaunch'])),
                'actionlabel' => $assessmentview
                    ? (string)($assessmentview['actionlabel'] ?? 'Открыть шаг →')
                    : 'Открыть шаг →',
                'actionlabelshort' => $assessmentview
                    ? (string)($assessmentview['actionlabelshort'] ?? 'Продолжить')
                    : 'Продолжить',
                'assessmentmanaged' => !empty($assessmentview['managed']),
                'assessmenthasstats' => !empty($assessmentstats),
                'assessmentawaiting' => !empty($assessmentview['awaiting']),
                'assessmentremediation' => !empty($assessmentview['remediation']),
                'assessmentreopened' => !empty($assessmentview['reopened']),
                'assessmentexhausted' => !empty($assessmentview['exhausted']),
                'assessmentattemptsused' => (int)($assessmentstats['attemptsused'] ?? 0),
                'assessmentattemptlimit' => (int)($assessmentstats['attemptlimit'] ?? 0),
                'assessmentattemptlimitlabel' => (string)($assessmentstats['attemptlimitlabel'] ?? ($assessmentstats['attemptlimit'] ?? '')),
                'assessmentbestscore' => (string)($assessmentstats['bestscore'] ?? ''),
                'assessmentmaxscore' => (string)($assessmentstats['maxscore'] ?? ''),
                'assessmentpassscore' => (string)($assessmentstats['passscore'] ?? ''),
                'remediationtitle' => (string)($assessmentview['remediationtitle'] ?? ''),
                'nextattemptstart' => (int)($assessmentview['nextattemptstart'] ?? 0),
                'nextattemptend' => (int)($assessmentview['nextattemptend'] ?? 0),
                'managerescalated' => !empty($assessmentview['managerescalated']),
                'assessmentmanagerreview' => !empty($assessmentview['managerreview']),
                'assessmenthrdreview' => !empty($assessmentview['hrdreview']),

                'requirements' => $employeerequirements,
                'developedskills' => $developedskills,
                'hasdevelopedskills' => !empty($developedskills),
            ];

            $viewpoints[] = $item;

            if ($status === 'current' && $currentpoint === null) {
                $currentpoint = $item;
            }

            $priorstates[] = [
                'pointid' => (int)$point->id,
                'phase' => (string)$point->phase,
                'satisfied' => !empty($fact['satisfied']),
            ];
        }

        $progress = $total > 0
            ? (int)round(($done / $total) * 100)
            : 0;

        return [
            'ok' => true,
            'routeid' => (int)$route->id,
            'positionid' => $positionid,
            'name' => format_string((string)$route->name),

            'points' => $viewpoints,
            'haspoints' => !empty($viewpoints),

            'totalpoints' => $total,
            'donepoints' => $done,
            'remaining' => max(0, $total - $done),
            'progress' => $progress,

            'sequencecomplete' =>
                $total > 0 && $done === $total,

            'sequencerunning' =>
                $total > 0 && $done < $total,

            'currentpoint' => $currentpoint,
            'hascurrentpoint' => $currentpoint !== null,

            // Compatibility keys for callers not migrated yet.
            'adaptationtotal' => $total,
            'adaptationdone' => $done,
            'adaptationprogress' => $progress,
            'admitted' => false,
            'notadmitted' => true,
            'continuoustotal' => 0,
            'continuousdone' => 0,
            'continuouspending' => 0,
            'continuousfuture' => 0,
            'freshness' => $progress,
        ];
    }

    /**
     * Route Studio view for either a position route or a family parent route.
     *
     * Employee runtime continues to resolve routes strictly by position.
     */
    public static function admin_view_route(int $routeid, string $positionid = ''): array {
        global $DB;

        $route = $DB->get_record(
            'local_ustar_routes',
            [
                'id' => $routeid,
                'active' => 1,
            ]
        );

        if (!$route) {
            return [
                'ok' => false,
                'positionid' => $positionid,
                'points' => [],
            ];
        }

        if (
            $positionid === ''
            &&
            trim((string)$route->positionid) !== ''
        ) {
            $positionid = (string)$route->positionid;
        }

        $points = [];

        foreach (self::points((int)$route->id) as $point) {
            $versions = [];

            foreach (self::versions((int)$point->id) as $version) {
                $requirements = [];

                foreach (self::requirements($version) as $requirement) {
                    $label = (string)($requirement['label'] ?? '');

                    if ($label === '') {
                        if ($requirement['type'] === 'course') {
                            $label =
                                'Moodle-курс #'
                                . (int)$requirement['sourceid'];

                        } else if ($requirement['type'] === 'cm') {
                            $info =
                                self::activity_info(
                                    (int)$requirement['sourceid']
                                );

                            $label =
                                $info && $info['name']
                                ? $info['name']
                                : (
                                    'Moodle-активность #'
                                    . (int)$requirement['sourceid']
                                );

                        } else if ($requirement['type'] === 'skill') {
                            $label =
                                'Навык '
                                . (string)$requirement['sourcekey'];

                        } else if ($requirement['type'] === 'content') {
                            $label =
                                'Материал #'
                                . (int)$requirement['sourceid'];

                        } else if ($requirement['type'] === 'assessment') {
                            $label =
                                'Развивающий профиль '
                                . (string)$requirement['sourcekey'];

                        } else {
                            $label =
                                'Все предыдущие обязательные шаги';
                        }
                    }

                    $requirements[] = [
                        'type' =>
                            (string)$requirement['type'],

                        'label' =>
                            $label,

                        'required' =>
                            !empty($requirement['required']),
                    ];
                }

                $versions[] = [
                    'id' =>
                        (int)$version->id,

                    'versionno' =>
                        (int)$version->versionno,

                    'versionlabel' =>
                        'v' . (int)$version->versionno,

                    'title' =>
                        format_string(
                            (string)$version->title
                        ),

                    'summary' =>
                        (string)$version->summary,

                    'hassummary' =>
                        trim((string)$version->summary) !== '',

                    'status' =>
                        (string)$version->status,

                    'published' =>
                        (string)$version->status
                        === self::STATUS_PUBLISHED,

                    'draft' =>
                        (string)$version->status
                        === self::STATUS_DRAFT,

                    'archived' =>
                        (string)$version->status
                        === self::STATUS_ARCHIVED,

                    'renewalpolicy' =>
                        (string)$version->renewalpolicy,

                    'policylabel' =>
                        self::policy_label(
                            (string)$version->renewalpolicy
                        ),

                    'validdays' =>
                        (int)$version->validdays,

                    'effectivedate' =>
                        (int)$version->effectivedate,

                    'requirements' =>
                        $requirements,

                    'hasrequirements' =>
                        !empty($requirements),
                ];
            }

            $latest = $versions[0] ?? null;

            $points[] = [
                'id' =>
                    (int)$point->id,

                'pointkey' =>
                    (string)$point->pointkey,

                'phase' =>
                    (string)$point->phase,

                'phaselabel' =>
                    self::phase_label(
                        (string)$point->phase
                    ),

                'adaptation' =>
                    (string)$point->phase
                    === self::PHASE_ADAPTATION,

                'gate' =>
                    (string)$point->phase
                    === self::PHASE_GATE,

                'continuous' =>
                    (string)$point->phase
                    === self::PHASE_CONTINUOUS,

                'sortorder' =>
                    (int)$point->sortorder,

                'sourcepointid' =>
                    (int)($point->sourcepointid ?? 0),

                'sourceversionid' =>
                    (int)($point->sourceversionid ?? 0),

                'inheritstate' =>
                    (string)($point->inheritstate ?? 'local'),

                'latest' =>
                    $latest,

                'versions' =>
                    $versions,

                'versioncount' =>
                    count($versions),
            ];
        }

        return [
            'ok' => true,
            'routeid' => (int)$route->id,
            'positionid' => $positionid,
            'familyid' => (int)($route->familyid ?? 0),
            'routekind' => (string)($route->routekind ?? 'position'),
            'isparent' => (string)($route->routekind ?? '') === 'parent',
            'ispositionroute' => (string)($route->routekind ?? '') === 'position',
            'name' => format_string((string)$route->name),
            'points' => $points,
            'haspoints' => !empty($points),
            'pointcount' => count($points),
        ];
    }

    public static function admin_view(string $positionid): array {
        $route = self::get_route($positionid);

        if (!$route) {
            return [
                'ok' => false,
                'positionid' => $positionid,
                'name' => self::canonical_name($positionid),
                'points' => [],
            ];
        }

        return self::admin_view_route(
            (int)$route->id,
            $positionid
        );
    }

    /** Ordered Moodle courses referenced by current published route versions. */
    public static function ordered_courseids(string $positionid): array {
        global $DB;
        $route = self::get_route($positionid);
        if (!$route) {
            return [];
        }
        $courseids = [];
        $displaynumber = 0;
        foreach (self::points((int)$route->id) as $point) {
            $version = self::current_published_version((int)$point->id);
            if (!$version) {
                continue;
            }
            foreach (self::requirements($version) as $requirement) {
                if ($requirement['type'] === 'course') {
                    $courseids[] = (int)$requirement['sourceid'];
                } else if ($requirement['type'] === 'cm') {
                    $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => (int)$requirement['sourceid']]);
                    if ($courseid > 0) {
                        $courseids[] = $courseid;
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($courseids)));
    }
}

