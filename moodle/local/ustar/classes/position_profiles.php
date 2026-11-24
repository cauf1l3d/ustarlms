<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Personal development models supplement the published position standard. */
final class position_profiles {
    public const TABLE = 'local_ustar_pos_profiles';
    public const ASSIGNMENTS = 'local_ustar_pos_profile_users';
    public const ROUTE_KIND = 'personal';

    /** @return \stdClass|null Saved model bound to the current employment position. */
    public static function for_user(string $positionid, int $userid): ?\stdClass {
        global $DB;
        if ($userid <= 1 || $positionid === '' ||
                (int)get_config('local_ustar', 'version') < 2026112400) {
            return null;
        }
        $model = $DB->get_record_sql(
            'SELECT p.*, a.staffassignmentid
               FROM {' . self::ASSIGNMENTS . '} a
               JOIN {' . self::TABLE . '} p ON p.id = a.profileid AND p.positionid = a.positionid
               JOIN {local_ustar_routes} r ON r.id = p.routeid AND r.routekind = :routekind AND r.active = 1
              WHERE a.userid = :userid AND a.positionid = :positionid',
            ['routekind' => self::ROUTE_KIND, 'userid' => $userid, 'positionid' => $positionid],
            IGNORE_MISSING
        );
        if (!$model || !accounts::participates($userid) || !employment::is_active($userid)) {
            return null;
        }
        $identity = organization_identity::resolve($userid);
        return !$identity['conflicts'] && $identity['positionid'] === $positionid
            && (int)$identity['assignmentid'] === (int)$model->staffassignmentid ? $model : null;
    }

    public static function grade_options(string $positionid): array {
        $version = (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0);
        return $version ? grade_ladders::grades_for_version($version) ?? [] : [];
    }

    public static function targets(\stdClass $model): array {
        return json_decode((string)$model->targetsjson, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function goal(\stdClass $model): string {
        if ($model->gradekey === '') {
            return '';
        }
        $grades = grade_ladders::grades_for_version((int)$model->gradeversionid) ?? [];
        return (string)(array_column($grades, 'name', 'id')[$model->gradekey] ?? '');
    }

    public static function save(string $positionid, int $id, int $revision, string $name, string $grade,
            array $levels, int $actor): int {
        global $DB;
        self::assert_editor($actor);
        $structure = structure::get(structure::NAME_STRUCTURE);
        if (!isset(people::position_map($structure)[$positionid])) {
            throw self::invalid('Должность больше недоступна.');
        }
        $name = trim(clean_param($name, PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 120) {
            throw self::invalid('Укажите название модели длиной до 120 символов.');
        }

        $targets = [];
        $skills = array_column($structure['skills'] ?? [], null, 'id');
        $base = $structure['matrix'][$positionid] ?? [];
        foreach ($levels as $skill => $value) {
            $level = (int)$value;
            if (!$level) {
                continue;
            }
            if (!isset($skills[$skill]) || $level < 1 || $level > 5) {
                throw self::invalid('Навык должен быть в каталоге, уровень от 1 до 5.');
            }
            if ($level < (int)($base[$skill] ?? 0)) {
                throw self::invalid('Личный целевой уровень должен быть не ниже требования должности.');
            }
            $targets[$skill] = $level;
        }
        ksort($targets);
        $binding = (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0);
        $gradekeys = array_column($binding ? grade_ladders::grades_for_version($binding) ?? [] : [], 'id');
        if ($grade !== '' && !in_array($grade, $gradekeys, true)) {
            throw self::invalid('Выберите целевой грейд из действующей лестницы должности.');
        }
        return self::locked('position-profile-list:' . $positionid, static function() use (
            $DB, $id, $positionid, $revision, $name, $grade, $binding, $targets, $actor
        ): int {
            $row = $id ? $DB->get_record(self::TABLE, ['id' => $id, 'positionid' => $positionid], '*', MUST_EXIST) : null;
            self::check_revision($row, $revision);
            if ($grade !== '' && $binding !== (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0)) {
                throw self::invalid('Лестница изменилась. Обновите страницу и проверьте целевой грейд.');
            }
            $namecheck = ['positionid' => $positionid, 'name' => $name, 'current' => $id];
            if ($DB->record_exists_select(self::TABLE, 'positionid = :positionid AND name = :name AND id <> :current', $namecheck)) {
                throw self::invalid('У должности уже есть модель с таким названием.');
            }
            $now = time();
            $data = (object)['name' => $name, 'gradekey' => $grade, 'gradeversionid' => $grade !== '' ? $binding : 0,
                'targetsjson' => json_encode($targets, JSON_THROW_ON_ERROR), 'usermodified' => $actor,
                'timemodified' => $now, 'revision' => $revision + 1];
            if ($row) {
                $data->id = $id;
                $DB->update_record(self::TABLE, $data);
                $DB->set_field('local_ustar_routes', 'name', $name, ['id' => $row->routeid, 'routekind' => self::ROUTE_KIND]);
            } else {
                $data->positionid = $positionid;
                $data->timecreated = $now;
                $data->routeid = $DB->insert_record('local_ustar_routes', (object)[
                    'routekind' => self::ROUTE_KIND, 'name' => $name, 'active' => 1, 'timecreated' => $now,
                    'timemodified' => $now, 'usermodified' => $actor,
                ]);
                $id = (int)$DB->insert_record(self::TABLE, $data);
            }
            people::log_action($actor, null, 'position_profile_saved', ['positionid' => $positionid, 'profileid' => $id]);
            return $id;
        });
    }

    public static function assign(string $positionid, int $userid, int $profileid, int $revision,
            int $expectedassignment, int $actor): void {
        global $DB;
        self::assert_editor($actor);
        self::locked('position-profile-user:' . $userid, static function() use (
            $DB, $positionid, $userid, $profileid, $revision, $expectedassignment, $actor
        ): void {
            $identity = organization_identity::resolve($userid);
            if (!accounts::participates($userid) || !employment::is_active($userid) || $identity['conflicts']
                    || $identity['positionid'] !== $positionid || (int)$identity['assignmentid'] !== $expectedassignment) {
                throw self::invalid('Сотрудник или его кадровое назначение изменились. Обновите страницу.');
            }
            if ($profileid) {
                $DB->get_record(self::TABLE, ['id' => $profileid, 'positionid' => $positionid], 'id', MUST_EXIST);
            }
            $row = $DB->get_record(self::ASSIGNMENTS, ['userid' => $userid], '*', IGNORE_MISSING) ?: null;
            self::check_revision($row, $revision);
            $previous = (int)($row->profileid ?? 0);
            if ($previous === $profileid && (!$profileid || ((int)$row->staffassignmentid === $expectedassignment
                    && $row->positionid === $positionid))) {
                return;
            }
            $data = (object)['userid' => $userid, 'positionid' => $positionid, 'profileid' => $profileid ?: null,
                'staffassignmentid' => $expectedassignment, 'revision' => $revision + 1, 'timemodified' => time()];
            if ($row) {
                $data->id = $row->id;
                $DB->update_record(self::ASSIGNMENTS, $data);
            } else {
                $DB->insert_record(self::ASSIGNMENTS, $data);
            }
            people::log_action($actor, $userid, 'position_profile_assigned',
                ['positionid' => $positionid, 'previousprofileid' => $previous, 'profileid' => $profileid]);
        });
    }

    /** Moodle already owns SCORM and Quiz attempts and completion. */
    public static function learning_options(int $cmid = 0): array {
        global $DB;
        $filter = $cmid ? ' AND cm.id = :cmid' : '';
        return $DB->get_records_sql(
            "SELECT cm.id, cm.course, c.fullname, cm.instance, m.name AS modname,
                    COALESCE(s.name, q.name) AS title
               FROM {course_modules} cm
               JOIN {course} c ON c.id = cm.course
               JOIN {modules} m ON m.id = cm.module
          LEFT JOIN {scorm} s ON s.id = cm.instance AND m.name = 'scorm'
          LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
              WHERE c.visible = 1 AND c.enablecompletion = 1 AND cm.visible = 1
                AND cm.deletioninprogress = 0 AND cm.completion = 2
                AND (s.id IS NOT NULL OR (q.id IS NOT NULL AND cm.completionpassgrade = 1))
               $filter
           ORDER BY c.fullname, cm.section, cm.id",
            $cmid ? ['cmid' => $cmid] : []
        );
    }

    public static function change_route(string $positionid, int $profileid, string $revision, string $action,
            int $target, int $actor): void {
        global $DB;
        self::assert_editor($actor);
        if (!in_array($action, ['add', 'remove', 'up', 'down'], true)) {
            throw self::invalid('Неизвестное изменение маршрута.');
        }
        self::locked('position-profile-route:' . $profileid, static function() use (
                $DB, $positionid, $profileid, $revision, $action, $target, $actor): void {
            $profile = $DB->get_record(self::TABLE, ['id' => $profileid, 'positionid' => $positionid], '*', MUST_EXIST);
            $rid = (int)$profile->routeid;
            if (!hash_equals(route_model::revision($rid), $revision)) {
                throw self::invalid('Маршрут уже изменился. Обновите страницу.');
            }
            $points = array_column(route_model::points($rid), null, 'id');
            if ($action === 'add') {
                $module = self::learning_options($target)[$target] ?? null;
                if (!$module) {
                    throw self::invalid('Активность недоступна или в Moodle не настроено автоматическое завершение.');
                }
                $key = 'personal_cm_' . $target;
                $existing = route_model::find_point($rid, $key);
                if ($existing && $existing->active) {
                    return;
                }
                if (count($points) >= 50) {
                    throw self::invalid('В дополнительном маршруте доступно до 50 шагов.');
                }
                $last = end($points);
                $point = route_model::add_point($rid, $key, route_model::PHASE_CONTINUOUS,
                    (int)($last->sortorder ?? 0) + 10, [
                        'title' => \core_text::substr((string)$module->title, 0, 255),
                        'requirements' => [['type' => 'cm', 'sourceid' => $target]],
                        'status' => route_model::STATUS_PUBLISHED,
                    ], $actor);
                if ($existing) {
                    $ids = [...array_map('intval', array_keys($points)), (int)$point->id];
                    route_model::reorder($rid, $ids, $actor);
                }
            } else {
                $point = $points[$target] ?? null;
                if (!$point) {
                    throw self::invalid('Шаг отсутствует в дополнительном маршруте.');
                }
                if ($action === 'remove') {
                    route_model::update_point($rid, $target, route_model::PHASE_CONTINUOUS, false, $actor);
                } else {
                    $ids = array_map('intval', array_keys($points));
                    $index = array_search($target, $ids, true);
                    $newindex = $index + ($action === 'up' ? -1 : 1);
                    if (!isset($ids[$newindex])) {
                        return;
                    }
                    [$ids[$index], $ids[$newindex]] = [$ids[$newindex], $ids[$index]];
                    route_model::reorder($rid, $ids, $actor, $revision);
                }
            }
            people::log_action($actor, null, 'position_profile_route_changed',
                ['profileid' => $profileid, 'action' => $action, 'target' => $target]);
        });
    }

    private static function assert_editor(int $actor): void {
        global $USER;
        view_as::assert_writable();
        require_capability('local/ustar:hrmanage', \context_system::instance(), $actor);
        if ($actor !== (int)$USER->id || !hr_access::can_manage_structure($actor)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
    }

    private static function invalid(string $message): \moodle_exception {
        return new \moodle_exception('positionprofileinvalid', 'local_ustar', '', $message);
    }

    private static function check_revision(?\stdClass $row, int $revision): void {
        if ((int)($row->revision ?? 0) !== $revision) {
            throw self::invalid('Настройки были изменены. Обновите страницу перед сохранением.');
        }
    }

    private static function locked(string $key, callable $callback) {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock($key, 10);
        if (!$lock) {
            throw new \moodle_exception('Настройки сейчас меняются. Повторите действие.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $result = $callback();
                $tx->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $tx->rollback($error);
            }
        } finally {
            $lock->release();
        }
    }
}
