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
            && $manager->table_exists(new \xmldb_table('local_ustar_employee_grades'))
            && grade_rules::available();
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
        $recorded = $record && (string)$record->positionid === $positionid;
        $grade = $recorded ? (string)$record->gradekey : '';
        return [
            'enabled' => true,
            'grade' => $grade,
            'label' => $recorded ? self::label($positionid, $grade) : 'Не назначен',
            'positionid' => $positionid,
            'recorded' => (bool)$recorded,
            'ladderversionid' => (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0),
            'recordversionid' => $recorded ? (int)($record->ladderversionid ?? 0) : 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function eligibility(int $userid): array {
        global $DB;
        $current = self::current($userid);
        $emptyrule = ['ruleid' => 0, 'ruleversion' => 0, 'rulehash' => ''];
        if (empty($current['enabled'])) {
            return array_merge($current, $emptyrule, [
                'eligible' => false, 'reason' => 'Для этой должности грейдовая лестница не настроена.',
                'nextgrade' => '', 'nextlabel' => '', 'routeid' => 0, 'requirements' => [],
            ]);
        }
        if (empty($current['recorded'])) {
            return array_merge($current, $emptyrule, [
                'eligible' => false, 'reason' => 'Стартовый грейд для этой должности ещё не назначен HR.',
                'nextgrade' => '', 'nextlabel' => '', 'routeid' => 0, 'requirements' => [],
            ]);
        }
        if ((int)$current['ladderversionid'] > 0
                && (int)$current['recordversionid'] !== (int)$current['ladderversionid']) {
            return array_merge($current, $emptyrule, [
                'eligible' => false, 'reason' => 'Версия лестницы должности изменилась. HR должен подтвердить перенос.',
                'nextgrade' => '', 'nextlabel' => '', 'routeid' => 0, 'requirements' => [],
            ]);
        }
        $next = self::next_grade((string)$current['positionid'], (string)$current['grade']);
        if ($next === '') {
            return array_merge($current, $emptyrule, [
                'eligible' => false, 'reason' => 'Это максимальная ступень грейда.',
                'nextgrade' => '', 'nextlabel' => '', 'routeid' => 0, 'requirements' => [],
            ]);
        }

        $rule = grade_rules::published((string)$current['positionid'], (string)$current['grade'], $next);
        if (!$rule) {
            return array_merge($current, $emptyrule, [
                'eligible' => false,
                'reason' => 'Критерии перехода «' . self::label((string)$current['positionid'], (string)$current['grade'])
                    . ' → ' . self::label((string)$current['positionid'], $next) . '» ещё не опубликованы.',
                'nextgrade' => $next, 'nextlabel' => self::label((string)$current['positionid'], $next),
                'routeid' => 0, 'requirements' => [],
            ]);
        }

        $common = [
            'ruleid' => (int)$rule->id,
            'ruleversion' => (int)$rule->versionno,
            'rulehash' => (string)$rule->rulehash,
            'nextgrade' => $next,
            'nextlabel' => self::label((string)$current['positionid'], $next),
            'routeid' => (int)$rule->routeid,
        ];
        if (!$DB->record_exists('local_ustar_routes', ['id' => (int)$rule->routeid, 'active' => 1])) {
            return array_merge($current, $common, [
                'eligible' => false, 'reason' => 'Маршрут опубликованного правила больше не активен.',
                'requirements' => [],
            ]);
        }

        $requirements = [];
        $missing = [];
        foreach (grade_rules::requirements($rule) as $requirement) {
            $pointid = (int)($requirement['pointid'] ?? 0);
            $versionid = (int)($requirement['versionid'] ?? 0);
            $title = format_string((string)($requirement['title'] ?? ('Этап #' . $pointid)));
            $configured = $pointid > 0 && $versionid > 0
                && $DB->record_exists('local_ustar_route_points', ['id' => $pointid, 'active' => 1])
                && $DB->record_exists('local_ustar_route_versions', [
                    'id' => $versionid, 'pointid' => $pointid, 'status' => route_model::STATUS_PUBLISHED,
                ]);
            $completed = $configured && self::has_confirmed_requirement($userid, $pointid, $versionid);
            $requirements[] = [
                'pointid' => $pointid,
                'versionid' => $versionid,
                'title' => $title,
                'complete' => $completed,
                'configured' => $configured,
            ];
            if (!$configured) {
                $missing[] = $title . ' (правило требует перепубликации)';
            } else if (!$completed) {
                $missing[] = $title;
            }
        }
        if (!$requirements) {
            return array_merge($current, $common, [
                'eligible' => false, 'reason' => 'В опубликованном правиле нет подтверждаемых этапов.',
                'requirements' => [],
            ]);
        }

        return array_merge($current, $common, [
            'eligible' => !$missing,
            'reason' => $missing
                ? 'Не подтверждены критерии: ' . implode(', ', array_slice($missing, 0, 5))
                : 'Все критерии этой ступени подтверждены.',
            'requirements' => $requirements,
        ]);
    }

    /** Creates one idempotent request for one exact published transition rule. */
    public static function request(int $userid, string $source = 'manual'): \stdClass {
        global $DB;
        $source = in_array($source, ['manual', 'auto'], true) ? $source : 'manual';
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
            $snapshot = [
                'rule' => [
                    'id' => (int)$eligible['ruleid'],
                    'version' => (int)$eligible['ruleversion'],
                    'hash' => (string)$eligible['rulehash'],
                ],
                'positionid' => (string)$eligible['positionid'],
                'ladderversionid' => (int)$eligible['ladderversionid'],
                'requirements' => $eligible['requirements'],
            ];
            $fingerprint = hash('sha256', json_encode([
                $userid, $eligible['grade'], $eligible['nextgrade'], $eligible['routeid'], $snapshot,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $tx = $DB->start_delegated_transaction();
            $existing = $DB->get_record('local_ustar_grade_requests', [
                'userid' => $userid,
                'fromgrade' => (string)$eligible['grade'],
                'tograde' => (string)$eligible['nextgrade'],
                'status' => self::STATUS_PENDING,
            ], '*', IGNORE_MULTIPLE);
            if ($existing) {
                $old = json_decode((string)$existing->requirementsjson, true);
                $oldhash = is_array($old) ? (string)($old['rule']['hash'] ?? '') : '';
                $oldsnapshotversion = is_array($old) ? (int)($old['ladderversionid'] ?? 0) : 0;
                $oldsnapshotposition = is_array($old) ? (string)($old['positionid'] ?? '') : '';
                if ($oldhash !== '' && hash_equals((string)$eligible['rulehash'], $oldhash)
                        && $oldsnapshotversion === (int)$eligible['ladderversionid']
                        && ($oldsnapshotposition === (string)$eligible['positionid']
                            || ($oldsnapshotposition === '' && $oldsnapshotversion === 0))) {
                    self::refresh_manager($existing);
                    $tx->allow_commit();
                    return $DB->get_record(
                        'local_ustar_grade_requests', ['id' => (int)$existing->id], '*', MUST_EXIST
                    );
                }
                $existing->status = self::STATUS_REJECTED;
                $existing->decidedat = time();
                $existing->decisionby = 0;
                $existing->decisionreason = 'Критерии, должность или версия лестницы изменены; заявка заменена.';
                $existing->timemodified = time();
                $DB->update_record('local_ustar_grade_requests', $existing);
                self::event((int)$existing->id, $userid, 'grade_request_superseded', 0, [
                    'previousrulehash' => $oldhash,
                    'newrulehash' => (string)$eligible['rulehash'],
                ]);
            }

            $now = time();
            $id = (int)$DB->insert_record('local_ustar_grade_requests', (object)[
                'userid' => $userid,
                'fromgrade' => (string)$eligible['grade'],
                'tograde' => (string)$eligible['nextgrade'],
                'routeid' => (int)$eligible['routeid'],
                'ladderversionid' => (int)$eligible['ladderversionid'] ?: null,
                'requirementsjson' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'managerid' => $managerid,
                'status' => self::STATUS_PENDING,
                'requestkey' => 'grade-v2:' . $fingerprint . ':' . random_string(12),
                'requestedat' => $now,
                'decidedat' => null,
                'decisionby' => null,
                'decisionreason' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            self::event($id, $userid, 'grade_request_created', $source === 'auto' ? 0 : $userid, [
                'managerid' => $managerid,
                'fromgrade' => $eligible['grade'],
                'tograde' => $eligible['nextgrade'],
                'ruleid' => (int)$eligible['ruleid'],
                'ruleversion' => (int)$eligible['ruleversion'],
                'rulehash' => (string)$eligible['rulehash'],
                'source' => $source,
            ]);
            self::notify(
                $managerid,
                'grade_request',
                'Новая заявка на переход грейда',
                'Сотрудник выполнил опубликованные критерии перехода и ждёт решения руководителя.',
                '/local/ustar/grades.php?view=team',
                'grade-request:' . $id . ':' . $managerid
            );
            $tx->allow_commit();
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
            $targetid = (int)$DB->get_field('local_ustar_grade_requests', 'userid',
                ['id' => $requestid], MUST_EXIST);
            $statelock = \core\lock\lock_config::get_lock_factory('local_ustar')
                ->get_lock('grade-state:' . $targetid, 10);
            if (!$statelock) { throw new \moodle_exception('Грейд сотрудника сейчас изменяется.'); }
            try {
            $tx = $DB->start_delegated_transaction();
            try {
            $request = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_grade_requests} WHERE id = :id FOR UPDATE',
                ['id' => $requestid], MUST_EXIST
            );
            if ((int)$request->userid !== $targetid) {
                throw new \moodle_exception('Сотрудник заявки изменился. Обновите страницу.');
            }
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
            $snapshot = json_decode((string)$request->requirementsjson, true);
            $requestrulehash = is_array($snapshot) ? (string)($snapshot['rule']['hash'] ?? '') : '';
            if ($approved && ($requestrulehash === ''
                    || empty($eligible['eligible'])
                    || !hash_equals((string)$eligible['rulehash'], $requestrulehash)
                    || (int)($snapshot['ladderversionid'] ?? 0) !== (int)$eligible['ladderversionid']
                    || ((int)$eligible['ladderversionid'] > 0 && empty($snapshot['positionid']))
                    || (!empty($snapshot['positionid'])
                        && (string)$snapshot['positionid'] !== (string)$eligible['positionid'])
                    || (string)$eligible['grade'] !== (string)$request->fromgrade
                    || (string)$eligible['nextgrade'] !== (string)$request->tograde)) {
                throw new \moodle_exception(
                    'Критерии, маршрут или ступень сотрудника изменились. Создайте заявку по актуальному правилу.'
                );
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
            } catch (\Throwable $e) { $tx->rollback($e); }
            } finally { $statelock->release(); }
        } finally {
            $lock->release();
        }
    }

    /** Compatibility hook: reads and route completion must never create a request. */
    public static function reconcile(int $userid): ?\stdClass {
        return null;
    }

    /**
     * Automatically attach the first grade of the explicitly bound ladder.
     *
     * Used only by trusted HR approval flows after the employee already has
     * the confirmed canonical position. Existing grade state is never
     * overwritten here; corrections remain an explicit HR action.
     */
    public static function assign_initial_if_bound(
        int $userid,
        int $actorid,
        string $reason = 'Автоматически при подтверждении учётной записи'
    ): ?\stdClass {
        global $DB;

        self::assert_available();
        $positionid = people::position_id($userid);
        if ($positionid === '') {
            return null;
        }

        $binding = grade_ladders::binding($positionid);
        if (!$binding || empty($binding->ladderversionid)) {
            return null;
        }

        $existing = $DB->get_record(
            'local_ustar_employee_grades',
            ['userid' => $userid],
            '*',
            IGNORE_MISSING
        );
        if ($existing) {
            return (string)$existing->positionid === $positionid ? $existing : null;
        }

        return self::assign_initial($userid, $actorid, $reason);
    }

    /** Explicit, audited first assignment. Never inferred from reading a profile or finishing a route. */
    public static function assign_initial(int $userid, int $actorid, string $reason): \stdClass {
        global $DB, $USER;
        self::assert_available();
        if ($actorid <= 0 || (int)$USER->id !== $actorid
                || !has_capability('local/ustar:hrmanage', \context_system::instance(), $actorid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
        view_as::assert_writable();
        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($reason === '') {
            throw new \invalid_parameter_exception('Укажите основание назначения стартового грейда.');
        }
        if (!accounts::is_business_account($userid) || !employment::is_active($userid)) {
            throw new \invalid_parameter_exception('Выберите действующего сотрудника.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-state:' . $userid, 10);
        if (!$lock) {
            throw new \moodle_exception('Назначение грейда уже выполняется. Повторите попытку.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $positionid = people::position_id($userid);
                if (!self::position_has_ladder($positionid)) {
                    throw new \invalid_parameter_exception('Для должности сотрудника лестница не настроена.');
                }
                $existing = $DB->get_record_sql(
                    'SELECT * FROM {local_ustar_employee_grades} WHERE userid = :userid FOR UPDATE',
                    ['userid' => $userid], IGNORE_MISSING
                );
                if ($existing) {
                    if ((string)$existing->positionid !== $positionid) {
                        throw new \invalid_parameter_exception(
                            'У сотрудника есть грейд прежней должности. Требуется отдельное решение о переносе.'
                        );
                    }
                    $tx->allow_commit();
                    return $existing;
                }
                $now = time();
                $id = (int)$DB->insert_record('local_ustar_employee_grades', (object)[
                    'userid' => $userid, 'gradekey' => self::first_grade($positionid), 'positionid' => $positionid,
                    'ladderversionid' => (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0) ?: null,
                    'revision' => 1,
                    'source' => 'initial_hr', 'requestid' => null,
                    'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $actorid,
                ]);
                people::log_action($actorid, $userid, 'grade_initial_assigned', [
                    'positionid' => $positionid, 'gradekey' => self::first_grade($positionid), 'reason' => $reason,
                ]);
                $tx->allow_commit();
                return $DB->get_record('local_ustar_employee_grades', ['id' => $id], '*', MUST_EXIST);
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
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

    /** Compact grade context for the employee route page; never mutates state on GET. */
    public static function route_card(int $userid): ?array {
        global $DB;
        if (!self::available()) {
            return null;
        }

        $eligibility = self::eligibility($userid);
        if (empty($eligibility['enabled']) || empty($eligibility['recorded'])
                || empty($eligibility['nextgrade'])) {
            return null;
        }

        $rule = !empty($eligibility['ruleid'])
            ? $DB->get_record('local_ustar_grade_rules', ['id' => (int)$eligibility['ruleid']], '*', IGNORE_MISSING)
            : false;
        $policy = $rule ? grade_rules::submission_policy($rule) : [
            'mode' => grade_rules::MODE_MANUAL,
            'triggerpointid' => 0,
            'triggerversionid' => 0,
            'triggertitle' => '',
        ];

        $pending = self::latest_transition_request(
            $userid,
            (string)$eligibility['grade'],
            (string)$eligibility['nextgrade'],
            self::STATUS_PENDING
        );
        $latest = self::latest_transition_request(
            $userid,
            (string)$eligibility['grade'],
            (string)$eligibility['nextgrade']
        );
        $samehashrejected = $latest
            && (string)$latest->status === self::STATUS_REJECTED
            && !empty($eligibility['rulehash'])
            && self::request_rule_hash($latest) !== ''
            && hash_equals((string)$eligibility['rulehash'], self::request_rule_hash($latest));

        $missing = array_values(array_filter(
            (array)($eligibility['requirements'] ?? []),
            static fn(array $requirement): bool => empty($requirement['complete'])
        ));
        $remaining = count($missing);
        $firstmissing = $missing ? (string)($missing[0]['title'] ?? '') : '';

        $auto = $policy['mode'] === grade_rules::MODE_AUTO;
        $eligible = !empty($eligibility['eligible']);
        $canrequest = !$pending && $eligible && (!$auto || $samehashrejected);

        return [
            'currentlabel' => (string)$eligibility['label'],
            'nextlabel' => (string)$eligibility['nextlabel'],
            'reason' => (string)$eligibility['reason'],
            'eligible' => $eligible,
            'pending' => (bool)$pending,
            'autmode' => $auto,
            'manualmode' => !$auto,
            'autoreturned' => $auto && $samehashrejected,
            'canrequest' => $canrequest,
            'requestlabel' => $samehashrejected ? 'Повторно отправить заявку' : 'Отправить заявку на повышение',
            'remaining' => $remaining,
            'hasremaining' => $remaining > 0,
            'firstmissing' => $firstmissing,
            'hasfirstmissing' => $firstmissing !== '',
            'triggerlabel' => (string)$policy['triggertitle'],
            'hastrigger' => (string)$policy['triggertitle'] !== '',
            'gradesurl' => (new \moodle_url('/local/ustar/grades.php'))->out(false),
        ];
    }

    /**
     * Bounded auto-submission worker. It calls the same canonical request()
     * method as the employee button and never approves/promotes anyone.
     */
    public static function process_automatic_requests(int $limit = 50): array {
        global $DB;
        $limit = max(1, min(200, $limit));
        $stats = ['checked' => 0, 'submitted' => 0, 'waiting' => 0, 'returned' => 0, 'errors' => 0];

        foreach (grade_rules::automatic_rules() as $rule) {
            if ($stats['checked'] >= $limit) {
                break;
            }
            $policy = (array)($rule->submissionpolicy ?? grade_rules::submission_policy($rule));
            $employees = array_values($DB->get_records(
                'local_ustar_employee_grades',
                [
                    'positionid' => (string)$rule->positionid,
                    'gradekey' => (string)$rule->fromgrade,
                ],
                'id ASC'
            ));

            foreach ($employees as $employeegrade) {
                if ($stats['checked'] >= $limit) {
                    break 2;
                }
                $stats['checked']++;
                $userid = (int)$employeegrade->userid;

                if (!accounts::is_business_account($userid) || !employment::is_active($userid)) {
                    $stats['waiting']++;
                    continue;
                }

                if (!self::has_confirmed_requirement(
                    $userid,
                    (int)$policy['triggerpointid'],
                    (int)$policy['triggerversionid']
                )) {
                    $stats['waiting']++;
                    continue;
                }

                $eligibility = self::eligibility($userid);
                if (empty($eligibility['eligible']) || (int)$eligibility['ruleid'] !== (int)$rule->id) {
                    $stats['waiting']++;
                    continue;
                }

                $latest = self::latest_transition_request(
                    $userid,
                    (string)$rule->fromgrade,
                    (string)$rule->tograde
                );
                if ($latest && (string)$latest->status === self::STATUS_PENDING) {
                    $stats['waiting']++;
                    continue;
                }

                if ($latest && (string)$latest->status === self::STATUS_REJECTED) {
                    $oldhash = self::request_rule_hash($latest);
                    if ($oldhash !== '' && hash_equals((string)$rule->rulehash, $oldhash)) {
                        // Avoid a rejection/resubmit loop. Employee can explicitly
                        // resubmit from the route card, or HR can publish a new rule.
                        $stats['returned']++;
                        continue;
                    }
                }

                try {
                    self::request($userid, 'auto');
                    $stats['submitted']++;
                } catch (\Throwable $e) {
                    debugging(
                        'USTAR automatic grade request failed for user ' . $userid . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Human-readable immutable request snapshot for UI.
     *
     * Historical requests use their published ladder version first, so changing
     * the current position binding never rewrites what the employee requested.
     *
     * @return array{fromlabel:string,tolabel:string,statuslabel:string}
     */
    public static function request_display(\stdClass $request): array {
        $grades = null;
        $versionid = (int)($request->ladderversionid ?? 0);
        if ($versionid > 0) {
            $grades = grade_ladders::grades_for_version($versionid);
        }

        if (!$grades) {
            $snapshot = json_decode((string)($request->requirementsjson ?? ''), true);
            $positionid = is_array($snapshot) ? (string)($snapshot['positionid'] ?? '') : '';
            if ($positionid === '' && !empty($request->userid)) {
                $positionid = people::position_id((int)$request->userid);
            }
            if ($positionid !== '') {
                $grades = career_grades::catalogue_for_position($positionid);
            }
        }

        $labels = [];
        foreach ((array)$grades as $grade) {
            $key = (string)($grade['id'] ?? '');
            if ($key !== '') {
                $labels[$key] = (string)($grade['name'] ?? $key);
            }
        }

        $from = (string)($request->fromgrade ?? '');
        $to = (string)($request->tograde ?? '');
        return [
            'fromlabel' => $labels[$from] ?? $from,
            'tolabel' => $labels[$to] ?? $to,
            'statuslabel' => self::status_label((string)($request->status ?? '')),
        ];
    }

    public static function status_label(string $status): string {
        return match ($status) {
            self::STATUS_PENDING => 'На рассмотрении',
            self::STATUS_APPROVED => 'Согласовано',
            self::STATUS_REJECTED => 'Возвращено',
            default => $status,
        };
    }

    /** An HR correction or transfer is an explicit audited decision, never a promotion request. */
    public static function correct(int $userid, string $gradekey, int $expectedrevision,
            int $actorid, string $reason): \stdClass {
        global $DB, $USER;
        self::assert_available();
        if ($actorid <= 0 || (int)$USER->id !== $actorid
                || !has_capability('local/ustar:hrmanage', \context_system::instance(), $actorid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
        view_as::assert_writable();
        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($reason === '') { throw new \invalid_parameter_exception('Укажите основание коррекции.'); }
        if (!accounts::is_business_account($userid) || !employment::is_active($userid)) {
            throw new \invalid_parameter_exception('Выберите действующего сотрудника.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-state:' . $userid, 10);
        if (!$lock) { throw new \moodle_exception('Грейд сейчас изменяется. Повторите попытку.'); }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $positionid = people::position_id($userid);
                if (!self::position_has_ladder($positionid)
                        || !in_array($gradekey,
                            array_column(career_grades::catalogue_for_position($positionid), 'id'), true)) {
                    throw new \invalid_parameter_exception('Выберите ступень действующей лестницы должности.');
                }
                $record = $DB->get_record_sql(
                    'SELECT * FROM {local_ustar_employee_grades} WHERE userid = :userid FOR UPDATE',
                    ['userid' => $userid], MUST_EXIST);
                if ((int)$record->revision !== $expectedrevision) {
                    throw new \invalid_parameter_exception('Грейд сотрудника изменился. Обновите страницу.');
                }
                $before = ['positionid' => (string)$record->positionid,
                    'gradekey' => (string)$record->gradekey,
                    'ladderversionid' => (int)$record->ladderversionid];
                $newversionid = (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0);
                if ($before['positionid'] === $positionid && $before['gradekey'] === $gradekey
                        && $before['ladderversionid'] === $newversionid) {
                    throw new \invalid_parameter_exception('Грейд и версия не изменились.');
                }
                $record->positionid = $positionid;
                $record->gradekey = $gradekey;
                $record->ladderversionid = $newversionid ?: null;
                $record->source = 'manual_hr';
                $record->requestid = null;
                $record->revision++;
                $record->timemodified = time();
                $record->usermodified = $actorid;
                $DB->update_record('local_ustar_employee_grades', $record);
                people::log_action($actorid, $userid, 'grade_corrected', [
                    'from' => $before, 'to' => ['positionid' => $positionid, 'gradekey' => $gradekey,
                        'ladderversionid' => (int)$record->ladderversionid], 'reason' => $reason,
                ]);
                $tx->allow_commit();
                return $record;
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
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
            // Resolve current scope without mutating a request on GET.
            if ($currentmanager === $managerid && $managerid !== (int)$row->userid) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function request_rule_hash(\stdClass $request): string {
        $snapshot = json_decode((string)($request->requirementsjson ?? ''), true);
        return is_array($snapshot) ? (string)($snapshot['rule']['hash'] ?? '') : '';
    }

    private static function latest_transition_request(
        int $userid,
        string $fromgrade,
        string $tograde,
        ?string $status = null
    ): ?\stdClass {
        global $DB;
        $conditions = [
            'userid' => $userid,
            'fromgrade' => $fromgrade,
            'tograde' => $tograde,
        ];
        if ($status !== null) {
            $conditions['status'] = $status;
        }
        $rows = $DB->get_records(
            'local_ustar_grade_requests',
            $conditions,
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
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
            $existing->ladderversionid = (int)(grade_ladders::binding((string)$existing->positionid)->ladderversionid ?? 0) ?: null;
            $existing->source = 'manager_approval';
            $existing->requestid = $requestid;
            $existing->revision++;
            $existing->timemodified = $now;
            $existing->usermodified = $actorid;
            $DB->update_record('local_ustar_employee_grades', $existing);
            return;
        }
        $DB->insert_record('local_ustar_employee_grades', (object)[
            'userid' => $userid, 'gradekey' => $grade, 'positionid' => people::position_id($userid),
            'ladderversionid' => (int)(grade_ladders::binding(people::position_id($userid))->ladderversionid ?? 0) ?: null,
            'revision' => 1,
            'source' => 'manager_approval', 'requestid' => $requestid,
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $actorid,
        ]);
    }

    private static function position_has_ladder(string $positionid): bool {
        if ($positionid === '') { return false; }
        $positions = array_column(structure::get(structure::NAME_STRUCTURE)['positions'] ?? [], null, 'id');
        return isset($positions[$positionid]) && career_grades::key($positions[$positionid]) !== '';
    }

    private static function first_grade(string $positionid): string {
        $grades = career_grades::catalogue_for_position($positionid);
        return (string)($grades[0]['id'] ?? 'trainee');
    }

    private static function next_grade(string $positionid, string $grade): string {
        $rows = career_grades::catalogue_for_position($positionid);
        foreach ($rows as $index => $row) {
            if ((string)$row['id'] === $grade) {
                return (string)($rows[$index + 1]['id'] ?? '');
            }
        }
        return '';
    }

    private static function label(string $positionid, string $grade): string {
        foreach (career_grades::catalogue_for_position($positionid) as $row) {
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
        workflow_notifications::enqueue($userid, $eventtype, $subject, $message, $url, $key);
    }

    private static function assert_available(): void {
        if (!self::available()) {
            throw new \moodle_exception('Контур грейдов будет доступен после обновления базы данных.');
        }
    }
}
