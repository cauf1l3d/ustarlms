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
            if (!in_array($type, ['course', 'cm', 'content', 'skill', 'previous_adaptation'], true)) {
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

    public static function create_version(int $pointid, array $data, int $actorid): \stdClass {
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

        return $DB->get_record('local_ustar_route_versions', ['id' => $id], '*', MUST_EXIST);
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
            "SELECT cm.id, cm.course, cm.completion, m.name AS modname, cm.instance
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
            'completion' => (int)$record->completion,
            'modname' => (string)$record->modname,
            'name' => $name,
        ];
    }

    private static function requirement_result(array $requirement, int $userid, string $positionid, array $priorstates, \stdClass $version): array {
        global $DB;
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
            $result['url'] = (new \moodle_url('/mod/' . $info['modname'] . '/view.php', ['id' => $cmid]))->out(false);
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
            $result['completedat'] = $completion ? (int)$completion->timemodified : 0;
            $result['failed'] = $state === 3;
            $result['satisfied'] = in_array($state, [1, 2], true);
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
     * Guard the route-only material gateway against guessed content URLs.
     */
    public static function assert_content_launch(
        int $userid,
        int $contentid,
        int $pointid,
        int $versionid
    ): void {
        global $DB;

        $scope = content::user_scope($userid);
        $positionid = (string)($scope['positionid'] ?? '');
        $point = $DB->get_record('local_ustar_route_points', ['id' => $pointid, 'active' => 1], '*', MUST_EXIST);
        $route = $DB->get_record('local_ustar_routes', [
            'id' => (int)$point->routeid,
            'positionid' => $positionid,
            'active' => 1,
        ], '*', MUST_EXIST);
        $version = self::current_published_version($pointid);
        if (!$version || (int)$version->id !== $versionid) {
            throw new \moodle_exception('Версия точки маршрута больше не является текущей');
        }

        $configured = false;
        foreach (self::requirements($version) as $requirement) {
            if ((string)$requirement['type'] === 'content' && (int)$requirement['sourceid'] === $contentid) {
                $configured = true;
                break;
            }
        }
        if (!$configured) {
            throw new \invalid_parameter_exception('Материал не относится к этой версии точки маршрута');
        }

        $model = self::for_user($positionid, $userid);
        foreach ($model['points'] ?? [] as $viewpoint) {
            if ((int)$viewpoint['id'] !== $pointid || empty($viewpoint['canlaunch'])) {
                continue;
            }
            $query = parse_url((string)$viewpoint['launchurl'], PHP_URL_QUERY);
            parse_str((string)$query, $params);
            if ((int)($params['contentid'] ?? 0) === $contentid) {
                return;
            }
        }

        throw new \required_capability_exception(
            \context_system::instance(), 'local/ustar:use', 'nopermissions', ''
        );
    }

    public static function for_user(string $positionid, int $userid): array {
        $route = self::get_route($positionid);
        if (!$route) {
            return ['ok' => false, 'reason' => 'route_missing', 'positionid' => $positionid];
        }

        $viewpoints = [];
        $priorstates = [];
        $adaptationtotal = 0;
        $adaptationdone = 0;
        $continuoustotal = 0;
        $continuousdone = 0;
        $firstadaptationpending = null;
        $firstcontinuouspending = null;

        $displaynumber = 0;
        foreach (self::points((int)$route->id) as $point) {
            $version = self::current_published_version((int)$point->id);
            if (!$version) {
                continue;
            }
            $fact = self::evaluate_point($point, $version, $userid, $positionid, $priorstates);
            $phase = (string)$point->phase;
            $isadaptation = in_array($phase, [self::PHASE_ADAPTATION, self::PHASE_GATE], true);
            if ($isadaptation) {
                $adaptationtotal++;
                if (!empty($fact['satisfied'])) {
                    $adaptationdone++;
                } else if ($firstadaptationpending === null) {
                    $firstadaptationpending = (int)$point->id;
                }
            } else if ($phase === self::PHASE_CONTINUOUS) {
                $continuoustotal++;
                if (!empty($fact['satisfied'])) {
                    $continuousdone++;
                } else if ($firstcontinuouspending === null) {
                    $firstcontinuouspending = (int)$point->id;
                }
            }

            $status = 'done';
            $statuslabel = 'Завершено';
            if (empty($fact['satisfied'])) {
                if ($isadaptation) {
                    $status = $firstadaptationpending === (int)$point->id ? 'current' : 'locked';
                    $statuslabel = $status === 'current' ? (!empty($fact['failed']) ? 'Нужно повторить' : 'Сейчас') : 'Позже';
                } else {
                    $status = 'current';
                    $statuslabel = !empty($fact['failed']) ? 'Нужно повторить' : 'Актуально';
                }
            }

            $displaynumber++;
            $item = [
                'id' => (int)$point->id,
                'number' => $displaynumber,
                'pointkey' => (string)$point->pointkey,
                'phase' => $phase,
                'phaselabel' => self::phase_label($phase),
                'adaptation' => $phase === self::PHASE_ADAPTATION,
                'gate' => $phase === self::PHASE_GATE,
                'continuous' => $phase === self::PHASE_CONTINUOUS,
                'sortorder' => (int)$point->sortorder,
                'title' => format_string((string)$version->title),
                'summary' => (string)$version->summary,
                'hassummary' => trim((string)$version->summary) !== '',
                'versionno' => (int)$version->versionno,
                'versionlabel' => 'v' . (int)$version->versionno,
                'policylabel' => self::policy_label((string)$version->renewalpolicy),
                'satisfied' => !empty($fact['satisfied']),
                'inherited' => !empty($fact['inherited']),
                'status' => $status,
                'statuslabel' => $statuslabel,
                'done' => $status === 'done',
                'current' => $status === 'current',
                'locked' => $status === 'locked',
                'launchurl' => (string)($fact['launchurl'] ?? ''),
                'canlaunch' => $status !== 'locked' && !empty($fact['launchurl']),
                'requirements' => $fact['requirements'] ?? [],
            ];
            $viewpoints[] = $item;
            $priorstates[] = [
                'pointid' => (int)$point->id,
                'phase' => $phase,
                'satisfied' => !empty($fact['satisfied']),
            ];
        }

        $admitted = $adaptationtotal > 0 && $adaptationdone === $adaptationtotal;
        $adaptationprogress = $adaptationtotal > 0 ? (int)round(($adaptationdone / $adaptationtotal) * 100) : 0;

        // Continuous learning is deliberately unavailable until the admission
        // gate is complete. The permanent route may contain future points, but
        // they must not distract an employee during mandatory adaptation.
        if (!$admitted) {
            foreach ($viewpoints as &$viewpoint) {
                if (!empty($viewpoint['continuous']) && empty($viewpoint['satisfied'])) {
                    $viewpoint['status'] = 'locked';
                    $viewpoint['statuslabel'] = 'После допуска';
                    $viewpoint['current'] = false;
                    $viewpoint['locked'] = true;
                    $viewpoint['canlaunch'] = false;
                }
            }
            unset($viewpoint);
        }

        // "Freshness" is not the completion percentage of an infinite route.
        // Before admission it mirrors adaptation readiness; after admission it
        // measures only currently published continuous checkpoints.
        if (!$admitted) {
            $freshness = $adaptationprogress;
        } else if ($continuoustotal > 0) {
            $freshness = (int)round(($continuousdone / $continuoustotal) * 100);
        } else {
            $freshness = 100;
        }

        $currentpoint = null;
        foreach ($viewpoints as $item) {
            if (!empty($item['current'])) {
                $currentpoint = $item;
                break;
            }
        }

        return [
            'ok' => true,
            'routeid' => (int)$route->id,
            'positionid' => $positionid,
            'name' => format_string((string)$route->name),
            'points' => $viewpoints,
            'haspoints' => !empty($viewpoints),
            'adaptationtotal' => $adaptationtotal,
            'adaptationdone' => $adaptationdone,
            'adaptationprogress' => $adaptationprogress,
            'admitted' => $admitted,
            'notadmitted' => !$admitted,
            'continuoustotal' => $continuoustotal,
            'continuousdone' => $continuousdone,
            'continuouspending' => $admitted ? max(0, $continuoustotal - $continuousdone) : 0,
            'continuousfuture' => max(0, $continuoustotal - $continuousdone),
            'freshness' => $freshness,
            'currentpoint' => $currentpoint,
            'hascurrentpoint' => $currentpoint !== null,
        ];
    }

    public static function admin_view(string $positionid): array {
        $route = self::get_route($positionid);
        if (!$route) {
            return ['ok' => false, 'positionid' => $positionid, 'name' => self::canonical_name($positionid), 'points' => []];
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
                            $label = 'Moodle-курс #' . (int)$requirement['sourceid'];
                        } else if ($requirement['type'] === 'cm') {
                            $info = self::activity_info((int)$requirement['sourceid']);
                            $label = $info && $info['name'] ? $info['name'] : ('Moodle-активность #' . (int)$requirement['sourceid']);
                        } else if ($requirement['type'] === 'skill') {
                            $label = 'Навык ' . (string)$requirement['sourcekey'];
                        } else {
                            $label = 'Все предыдущие обязательные точки';
                        }
                    }
                    $requirements[] = [
                        'type' => (string)$requirement['type'],
                        'label' => $label,
                        'required' => !empty($requirement['required']),
                    ];
                }
                $versions[] = [
                    'id' => (int)$version->id,
                    'versionno' => (int)$version->versionno,
                    'versionlabel' => 'v' . (int)$version->versionno,
                    'title' => format_string((string)$version->title),
                    'summary' => (string)$version->summary,
                    'hassummary' => trim((string)$version->summary) !== '',
                    'status' => (string)$version->status,
                    'published' => (string)$version->status === self::STATUS_PUBLISHED,
                    'draft' => (string)$version->status === self::STATUS_DRAFT,
                    'archived' => (string)$version->status === self::STATUS_ARCHIVED,
                    'renewalpolicy' => (string)$version->renewalpolicy,
                    'policylabel' => self::policy_label((string)$version->renewalpolicy),
                    'validdays' => (int)$version->validdays,
                    'effectivedate' => (int)$version->effectivedate,
                    'requirements' => $requirements,
                    'hasrequirements' => !empty($requirements),
                ];
            }
            $latest = $versions[0] ?? null;
            $points[] = [
                'id' => (int)$point->id,
                'pointkey' => (string)$point->pointkey,
                'phase' => (string)$point->phase,
                'phaselabel' => self::phase_label((string)$point->phase),
                'adaptation' => (string)$point->phase === self::PHASE_ADAPTATION,
                'gate' => (string)$point->phase === self::PHASE_GATE,
                'continuous' => (string)$point->phase === self::PHASE_CONTINUOUS,
                'sortorder' => (int)$point->sortorder,
                'latest' => $latest,
                'versions' => $versions,
                'versioncount' => count($versions),
            ];
        }

        return [
            'ok' => true,
            'routeid' => (int)$route->id,
            'positionid' => $positionid,
            'name' => format_string((string)$route->name),
            'points' => $points,
            'haspoints' => !empty($points),
            'pointcount' => count($points),
        ];
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
