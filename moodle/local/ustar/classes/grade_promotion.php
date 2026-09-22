<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Employee grade progression. A grade is personal to an employee; the
 * position configuration only declares that the career ladder applies.
 */
final class grade_promotion {
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public static function available(): bool {
        global $DB;
        $manager = $DB->get_manager();
        return $manager->table_exists(new \xmldb_table('local_ustar_grade_requests'))
            && $manager->table_exists(new \xmldb_table('local_ustar_employee_grades'));
    }

    /** @return array<string,mixed> */
    public static function current(int $userid): array {
        global $DB;
        $positionid = people::position_id($userid);
        if (!self::position_has_ladder($positionid)) {
            return ['enabled' => false, 'grade' => '', 'label' => '', 'positionid' => $positionid];
        }
        $record = self::available()
            ? $DB->get_record('local_ustar_employee_grades', ['userid' => $userid], '*', IGNORE_MISSING)
            : false;
        $grade = $record && (string)$record->positionid === $positionid
            ? (string)$record->gradekey : self::first_grade();
        return [
            'enabled' => true,
            'grade' => $grade,
            'label' => self::label($grade),
            'positionid' => $positionid,
            'recorded' => (bool)$record,
        ];
    }

    /** @return array<string,mixed> */
    public static function eligibility(int $userid): array {
        global $DB;
        $current = self::current($userid);
        if (empty($current['enabled'])) {
            return array_merge($current, [
                'eligible' => false, 'reason' => 'Для этой должности грейдовая лестница не настроена.',
                'nextgrade' => '', 'nextlabel' => '', 'routeid' => 0, 'requirements' => [],
            ]);
        }
        $next = self::next_grade((string)$current['grade']);
        if ($next === '') {
            return array_merge($current, [
                'eligible' => false, 'reason' => 'Это максимальная ступень грейда.',
                'nextgrade' => '', 'nextlabel' => '', 'routeid' => 0, 'requirements' => [],
            ]);
        }
        $route = $DB->get_record('local_ustar_routes', [
            'positionid' => (string)$current['positionid'], 'active' => 1,
        ], '*', IGNORE_MULTIPLE);
        if (!$route) {
            return array_merge($current, [
                'eligible' => false, 'reason' => 'Для должности ещё не опубликован маршрут перехода.',
                'nextgrade' => $next, 'nextlabel' => self::label($next), 'routeid' => 0, 'requirements' => [],
            ]);
        }

        $requirements = [];
        $missing = [];
        $points = $DB->get_records('local_ustar_route_points', [
            'routeid' => (int)$route->id, 'active' => 1,
        ], 'sortorder ASC, id ASC');
        if (!$points) {
            return array_merge($current, [
                'eligible' => false, 'reason' => 'В маршруте перехода пока нет этапов.',
                'nextgrade' => $next, 'nextlabel' => self::label($next), 'routeid' => (int)$route->id, 'requirements' => [],
            ]);
        }
        foreach ($points as $point) {
            $version = route_model::current_published_version((int)$point->id);
            if (!$version) {
                continue;
            }
            $completed = self::has_confirmed_requirement($userid, (int)$point->id, (int)$version->id);
            $requirements[] = [
                'pointid' => (int)$point->id,
                'versionid' => (int)$version->id,
                'title' => format_string((string)$version->title),
                'complete' => $completed,
            ];
            if (!$completed) {
                $missing[] = format_string((string)$version->title);
            }
        }
        if (!$requirements) {
            return array_merge($current, [
                'eligible' => false, 'reason' => 'В маршруте перехода нет опубликованных этапов.',
                'nextgrade' => $next, 'nextlabel' => self::label($next), 'routeid' => (int)$route->id, 'requirements' => [],
            ]);
        }

        return array_merge($current, [
            'eligible' => !$missing,
            'reason' => $missing
                ? 'Не завершены этапы: ' . implode(', ', array_slice($missing, 0, 5))
                : 'Все этапы маршрута и аттестации подтверждены.',
            'nextgrade' => $next,
            'nextlabel' => self::label($next),
            'routeid' => (int)$route->id,
            'requirements' => $requirements,
        ]);
    }

    /** Creates one idempotent request for the current verified route state. */
    public static function request(int $userid): \stdClass {
        global $DB;
        self::assert_available();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-request:' . $userid, 10);
        if (!$lock) {
            throw new \moodle_exception('Не удалось подготовить заявку на грейд. Повторите попытку.');
        }
        try {
            $eligible = self::eligibility($userid);
            if (empty($eligible['eligible'])) {
                throw new \moodle_exception((string)$eligible['reason']);
            }
            $managerid = self::manager_for($userid);
            if ($managerid <= 0 || $managerid === $userid) {
                throw new \moodle_exception('Для сотрудника не назначен действующий руководитель. Заявка не создана.');
            }
            $fingerprint = hash('sha256', json_encode([
                $userid, $eligible['grade'], $eligible['nextgrade'], $eligible['routeid'],
                array_map(static fn(array $item): array => [
                    (int)$item['pointid'], (int)$item['versionid'], !empty($item['complete']),
                ], $eligible['requirements']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $existing = $DB->get_record('local_ustar_grade_requests', [
                'userid' => $userid, 'fromgrade' => (string)$eligible['grade'],
                'tograde' => (string)$eligible['nextgrade'], 'status' => self::STATUS_PENDING,
            ], '*', IGNORE_MULTIPLE);
            if ($existing) {
                self::refresh_manager($existing);
                return $DB->get_record('local_ustar_grade_requests', ['id' => (int)$existing->id], '*', MUST_EXIST);
            }
            $now = time();
            $id = (int)$DB->insert_record('local_ustar_grade_requests', (object)[
                'userid' => $userid,
                'fromgrade' => (string)$eligible['grade'],
                'tograde' => (string)$eligible['nextgrade'],
                'routeid' => (int)$eligible['routeid'],
                'requirementsjson' => json_encode($eligible['requirements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'managerid' => $managerid,
                'status' => self::STATUS_PENDING,
                'requestkey' => 'grade-v1:' . $fingerprint . ':' . random_string(12),
                'requestedat' => $now,
                'decidedat' => null,
                'decisionby' => null,
                'decisionreason' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            self::event($id, $userid, 'grade_request_created', $userid, [
                'managerid' => $managerid, 'fromgrade' => $eligible['grade'], 'tograde' => $eligible['nextgrade'],
            ]);
            self::notify(
                $managerid,
                'grade_request',
                'Новая заявка на переход грейда',
                'Сотрудник выполнил маршрут и ждёт решения руководителя.',
                '/local/ustar/grades.php?view=team',
                'grade-request:' . $id . ':' . $managerid
            );
            return $DB->get_record('local_ustar_grade_requests', ['id' => $id], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }

    /** Approve or return a pending request. The actual current manager is the only decision maker. */
    public static function decide(int $requestid, int $actorid, bool $approved, string $reason = ''): \stdClass {
        global $DB;
        self::assert_available();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-decision:' . $requestid, 10);
        if (!$lock) {
            throw new \moodle_exception('Заявка сейчас обрабатывается. Повторите попытку.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $request = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_grade_requests} WHERE id = :id FOR UPDATE',
                ['id' => $requestid], MUST_EXIST
            );
            if ((string)$request->status !== self::STATUS_PENDING) {
                throw new \moodle_exception('По этой заявке уже принято решение.');
            }
            $managerid = self::manager_for((int)$request->userid);
            if ($managerid <= 0 || $managerid === (int)$request->userid) {
                throw new \moodle_exception('У сотрудника нет действующего руководителя. Решение недоступно.');
            }
            if ((int)$request->managerid !== $managerid) {
                $oldmanager = (int)$request->managerid;
                $request->managerid = $managerid;
                $request->timemodified = time();
                $DB->update_record('local_ustar_grade_requests', $request);
                self::event((int)$request->id, (int)$request->userid, 'grade_request_reassigned', $actorid, [
                    'frommanagerid' => $oldmanager, 'tomanagerid' => $managerid,
                ]);
            }
            if ($actorid !== $managerid || $actorid === (int)$request->userid) {
                throw new \required_capability_exception(
                    \context_system::instance(), 'local/ustar:viewteam', 'nopermissions', ''
                );
            }
            $eligible = self::eligibility((int)$request->userid);
            if (!$approved && trim($reason) === '') {
                throw new \invalid_parameter_exception('Укажите причину возврата заявки.');
            }
            if ($approved && (!$eligible['eligible']
                    || (string)$eligible['grade'] !== (string)$request->fromgrade
                    || (string)$eligible['nextgrade'] !== (string)$request->tograde)) {
                throw new \moodle_exception('Маршрут сотрудника изменился или больше не подтверждён. Перепроверьте заявку.');
            }

            $now = time();
            $request->status = $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED;
            $request->decidedat = $now;
            $request->decisionby = $actorid;
            $request->decisionreason = trim(clean_param($reason, PARAM_TEXT)) ?: null;
            $request->timemodified = $now;
            $DB->update_record('local_ustar_grade_requests', $request);
            if ($approved) {
                self::set_employee_grade((int)$request->userid, (string)$request->tograde, $actorid, (int)$request->id);
            }
            self::event((int)$request->id, (int)$request->userid,
                $approved ? 'grade_request_approved' : 'grade_request_rejected', $actorid,
                ['reason' => (string)$request->decisionreason, 'tograde' => (string)$request->tograde]);
            self::notify(
                (int)$request->userid,
                $approved ? 'grade_approved' : 'grade_returned',
                $approved ? 'Переход грейда согласован' : 'Заявка на грейд возвращена',
                $approved ? 'Руководитель согласовал переход на следующую ступень.' : (string)$request->decisionreason,
                '/local/ustar/grades.php',
                'grade-decision:' . (int)$request->id
            );
            $tx->allow_commit();
            return $request;
        } finally {
            $lock->release();
        }
    }

    /** @return array<int,\stdClass> */
    public static function own_requests(int $userid): array {
        global $DB;
        if (!self::available()) { return []; }
        return array_values($DB->get_records('local_ustar_grade_requests', ['userid' => $userid], 'timecreated DESC'));
    }

    /** @return array<int,\stdClass> */
    public static function pending_for_manager(int $managerid): array {
        global $DB;
        if (!self::available()) { return []; }
        $rows = $DB->get_records('local_ustar_grade_requests', ['status' => self::STATUS_PENDING], 'requestedat ASC');
        $out = [];
        foreach ($rows as $row) {
            $currentmanager = self::manager_for((int)$row->userid);
            if ($currentmanager <= 0) { continue; }
            if ((int)$row->managerid !== $currentmanager) {
                self::refresh_manager($row);
                $row->managerid = $currentmanager;
            }
            if ($currentmanager === $managerid && $managerid !== (int)$row->userid) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function has_confirmed_requirement(int $userid, int $pointid, int $versionid): bool {
        $cycle = completion_cycle::prior_verified($userid, $pointid, $versionid);
        return $cycle && (empty($cycle->expiresat) || (int)$cycle->expiresat > time());
    }

    private static function manager_for(int $userid): int {
        $assignment = organization_model::primary_assignment($userid);
        if (!$assignment) { return 0; }
        return organization_model::manager_user_for_place((int)$assignment->staffplaceid);
    }

    private static function refresh_manager(\stdClass $request): void {
        global $DB;
        $managerid = self::manager_for((int)$request->userid);
        if ($managerid > 0 && (int)$request->managerid !== $managerid) {
            $request->managerid = $managerid;
            $request->timemodified = time();
            $DB->update_record('local_ustar_grade_requests', $request);
            self::event((int)$request->id, (int)$request->userid, 'grade_request_reassigned', 0, [
                'tomanagerid' => $managerid,
            ]);
            self::notify($managerid, 'grade_request', 'Новая заявка на переход грейда',
                'Заявка перешла к вам после изменения руководителя.',
                '/local/ustar/grades.php?view=team',
                'grade-reassigned:' . (int)$request->id . ':' . $managerid);
        }
    }

    private static function set_employee_grade(int $userid, string $grade, int $actorid, int $requestid): void {
        global $DB;
        $existing = $DB->get_record('local_ustar_employee_grades', ['userid' => $userid], '*', IGNORE_MISSING);
        $now = time();
        if ($existing) {
            $existing->gradekey = $grade;
            $existing->positionid = people::position_id($userid);
            $existing->source = 'manager_approval';
            $existing->requestid = $requestid;
            $existing->timemodified = $now;
            $existing->usermodified = $actorid;
            $DB->update_record('local_ustar_employee_grades', $existing);
            return;
        }
        $DB->insert_record('local_ustar_employee_grades', (object)[
            'userid' => $userid, 'gradekey' => $grade, 'positionid' => people::position_id($userid),
            'source' => 'manager_approval', 'requestid' => $requestid,
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $actorid,
        ]);
    }

    private static function position_has_ladder(string $positionid): bool {
        if ($positionid === '') { return false; }
        $positions = array_column(structure::get(structure::NAME_STRUCTURE)['positions'] ?? [], null, 'id');
        return isset($positions[$positionid]) && career_grades::key($positions[$positionid]) !== '';
    }

    private static function first_grade(): string {
        $grades = career_grades::catalogue();
        return (string)($grades[0]['id'] ?? 'trainee');
    }

    private static function next_grade(string $grade): string {
        $rows = career_grades::catalogue();
        foreach ($rows as $index => $row) {
            if ((string)$row['id'] === $grade) {
                return (string)($rows[$index + 1]['id'] ?? '');
            }
        }
        return '';
    }

    private static function label(string $grade): string {
        foreach (career_grades::catalogue() as $row) {
            if ((string)$row['id'] === $grade) { return (string)$row['name']; }
        }
        return $grade;
    }

    /** @param array<string,mixed> $data */
    private static function event(int $requestid, int $userid, string $eventtype, int $actorid, array $data): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_workflow_events'))) { return; }
        $DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => 'grade_request', 'entityid' => $requestid, 'eventtype' => $eventtype,
            'actorid' => $actorid, 'reason' => null,
            'detailsjson' => json_encode(['userid' => $userid] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function notify(int $userid, string $eventtype, string $subject, string $message, string $url, string $key): void {
        global $DB;
        if ($userid <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'))) { return; }
        $now = time();
        try {
            $DB->insert_record('local_ustar_notifications', (object)[
                'userid' => $userid, 'severity' => 'normal', 'eventtype' => $eventtype,
                'subject' => $subject, 'message' => $message, 'actionurl' => $url, 'dueat' => null,
                'status' => 'unread', 'idempotencykey' => $key, 'ackat' => null,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            // The idempotency index intentionally turns repeat delivery into a no-op.
        }
    }

    private static function assert_available(): void {
        if (!self::available()) {
            throw new \moodle_exception('Контур грейдов будет доступен после обновления базы данных.');
        }
    }
}
