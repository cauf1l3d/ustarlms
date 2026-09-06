<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Writable quiz-attempt adapter for production manual grading.
 *
 * Moodle 5 deliberately restricts quiz_attempt::get_question_usage() to tests.
 * The supported escape hatch is to extend quiz_attempt and operate on the
 * protected QUBA from inside the subclass, while keeping Moodle as the source
 * of truth for question state, marks and final grades.
 */
final class route_quiz_attempt extends \mod_quiz\quiz_attempt {

    public static function from_attemptid(int $attemptid): self {
        $base = \mod_quiz\quiz_attempt::create($attemptid);

        return new self(
            $base->get_attempt(),
            $base->get_quiz(),
            $base->get_cm(),
            $base->get_course(),
            true
        );
    }

    /**
     * @param array<int,array{mark:float,comment:string}> $grades
     */
    public function apply_manual_grades(array $grades): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        foreach ($grades as $slot => $grade) {
            $slot = (int)$slot;
            $state = $this->quba->get_question_state($slot);

            // Manual-grading POST must be idempotent. A retry may arrive after
            // Moodle has already persisted some or all manual grades.
            if ($state !== \question_state::$needsgrading) {
                continue;
            }

            $this->quba->manual_grade(
                $slot,
                (string)$grade['comment'],
                (float)$grade['mark'],
                FORMAT_PLAIN
            );
        }

        \question_engine::save_questions_usage_by_activity($this->quba);

        $this->attempt->timemodified = time();
        $this->attempt->sumgrades = $this->quba->get_total_mark();
        $this->attempt->gradednotificationsenttime = null;

        $DB->update_record('quiz_attempts', $this->attempt);

        if (
            !$this->is_preview()
            && $this->attempt->state === self::FINISHED
        ) {
            $this->get_quizobj()
                ->get_grade_calculator()
                ->recompute_final_grade((int)$this->attempt->userid);
        }

        $transaction->allow_commit();
    }
}

/**
 * USTAR facade for manual grading of every Moodle Quiz used by a published
 * USTAR route.
 *
 * Moodle remains authoritative for attempts, question state, manual marks,
 * final grade and activity completion. USTAR supplies the HR/HRD workflow UI.
 */
final class route_quiz_grading {

    private static function bootstrap(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
    }

    /**
     * Current published Quiz requirements grouped by Moodle quiz id.
     * Historical published route versions are deliberately excluded: a Moodle
     * activity may have been reused by several old logical checkpoints.
     *
     * @return array<int,array<int,array{quizid:int,cmid:int,pointid:int,versionid:int,routeid:int,sortorder:int,quiz:\stdClass}>>
     */
    private static function route_quizzes(): array {
        global $DB;

        self::bootstrap();
        $result = [];
        $points = $DB->get_records(
            'local_ustar_route_points',
            ['active' => 1],
            'routeid ASC,sortorder ASC,id ASC',
            'id,routeid,sortorder'
        );

        foreach ($points as $point) {
            $version = route_model::current_published_version((int)$point->id);
            if (!$version) {
                continue;
            }
            foreach (route_model::requirements_for_version($version) as $requirement) {
                if (
                    (string)($requirement['type'] ?? '') !== 'cm'
                    || empty($requirement['required'])
                    || empty($requirement['sourceid'])
                ) {
                    continue;
                }

                $cmid = (int)$requirement['sourceid'];
                $cm = $DB->get_record(
                    'course_modules',
                    ['id' => $cmid, 'deletioninprogress' => 0],
                    'id,module,instance,course',
                    IGNORE_MISSING
                );
                if (!$cm) {
                    continue;
                }
                $modname = (string)$DB->get_field('modules', 'name', ['id' => (int)$cm->module]);
                if ($modname !== 'quiz') {
                    continue;
                }
                $quiz = $DB->get_record('quiz', ['id' => (int)$cm->instance], '*', IGNORE_MISSING);
                if (!$quiz) {
                    continue;
                }

                $quizid = (int)$quiz->id;
                $result[$quizid][] = [
                    'quizid' => $quizid,
                    'cmid' => $cmid,
                    'pointid' => (int)$point->id,
                    'versionid' => (int)$version->id,
                    'routeid' => (int)$point->routeid,
                    'sortorder' => (int)$point->sortorder,
                    'quiz' => $quiz,
                ];
            }
        }

        return $result;
    }

    /**
     * Resolve a Moodle attempt to the employee's CURRENT logical route point.
     * This prevents reused legacy CM/Quiz ids from being attributed to an old
     * published route version.
     *
     * @return array{quizid:int,cmid:int,pointid:int,versionid:int,routeid:int,sortorder:int,quiz:\stdClass}
     */
    private static function config_for_attempt(\stdClass $attempt): array {
        $map = self::route_quizzes();
        $quizid = (int)$attempt->quiz;
        $candidates = $map[$quizid] ?? [];
        if (!$candidates) {
            throw new \moodle_exception(
                'Эта попытка не относится к текущей опубликованной аттестации USTAR.'
            );
        }

        $position = position_access::position_for_user((int)$attempt->userid);
        $positionid = is_array($position) ? (string)($position['id'] ?? '') : '';
        if ($positionid === '') {
            throw new \moodle_exception('Не удалось определить должность сотрудника для аттестации.');
        }

        $route = null;
        if (route_scope::available()) {
            $route = route_scope::parent_for_position($positionid);
        }
        if (!$route) {
            $route = route_model::get_route($positionid);
        }
        if (!$route) {
            throw new \moodle_exception('Для должности сотрудника не найден текущий маршрут USTAR.');
        }

        $matched = [];
        foreach ($candidates as $candidate) {
            if ((int)$candidate['routeid'] !== (int)$route->id) {
                continue;
            }
            if (
                (string)($route->routekind ?? '') === route_family::KIND_PARENT
                && route_scope::available()
                && !route_scope::point_applies((int)$candidate['pointid'], $positionid)
            ) {
                continue;
            }
            $matched[] = $candidate;
        }

        if (count($matched) === 1) {
            return $matched[0];
        }
        if (count($matched) > 1) {
            // If a quiz is intentionally reused twice in one live route, the
            // current point is the strongest unambiguous runtime discriminator.
            $model = route_model::for_user($positionid, (int)$attempt->userid);
            $currentid = (int)($model['currentpoint']['id'] ?? 0);
            foreach ($matched as $candidate) {
                if ((int)$candidate['pointid'] === $currentid) {
                    return $candidate;
                }
            }
            throw new \moodle_exception(
                'Один Moodle Quiz одновременно используется несколькими текущими точками маршрута; требуется явное разделение аттестаций.'
            );
        }

        throw new \moodle_exception(
            'Попытка не относится к текущему маршруту должности сотрудника.'
        );
    }

    private static function effective_attempt_limit(int $userid, array $cfg, \stdClass $quiz): int {
        global $DB;
        $base = max(1, (int)$quiz->attempts);
        if (class_exists('\\local_ustar\\assessment_lifecycle') && assessment_lifecycle::available()) {
            $runtime = $DB->get_record('local_ustar_assess_runtime', [
                'userid' => $userid,
                'pointid' => (int)$cfg['pointid'],
                'versionid' => (int)$cfg['versionid'],
            ]);
            if ($runtime && (int)$runtime->unlockedattemptlimit > 0) {
                return max($base, (int)$runtime->unlockedattemptlimit);
            }
        }
        return $base;
    }

    private static function gradepass(int $quizid): float {
        global $DB;

        $value = $DB->get_field(
            'grade_items',
            'gradepass',
            ['itemmodule' => 'quiz', 'iteminstance' => $quizid]
        );

        return $value === false ? 0.0 : (float)$value;
    }

    private static function position_label(int $userid): string {
        try {
            $position = position_access::position_for_user($userid);
            if (is_array($position)) {
                foreach (['title', 'name', 'positionname', 'id'] as $key) {
                    if (!empty($position[$key])) {
                        return (string)$position[$key];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Display-only fallback.
        }

        return '—';
    }

    private static function quba(int $usageid): \question_usage_by_activity {
        self::bootstrap();
        return \question_engine::load_questions_usage_by_activity($usageid);
    }

    /**
     * Return a score split that remains meaningful while essay questions are
     * still in needsgrading state.
     *
     * @return array{
     *   pending:int,manualcount:int,
     *   autograde:float,automax:float,
     *   manualgrade:float,manualmax:float,
     *   currentgrade:float,maxgrade:float
     * }
     */
    private static function score_breakdown(
        \stdClass $attempt,
        \stdClass $quiz
    ): array {
        $quba = self::quba((int)$attempt->uniqueid);

        $autoraw = 0.0;
        $automaxraw = 0.0;
        $manualraw = 0.0;
        $manualmaxraw = 0.0;
        $pending = 0;
        $manualcount = 0;

        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $summary = $qa->get_state()->get_summary_state();
            $maxmark = (float)$qa->get_max_mark();
            $mark = $qa->get_mark();

            if ($summary === 'needsgrading') {
                $pending++;
                $manualcount++;
                $manualmaxraw += $maxmark;
                continue;
            }

            if ($summary === 'manuallygraded') {
                $manualcount++;
                $manualmaxraw += $maxmark;
                if ($mark !== null) {
                    $manualraw += (float)$mark;
                }
                continue;
            }

            // Automatic, informational and any finished non-manual state.
            $automaxraw += $maxmark;
            if ($mark !== null) {
                $autoraw += (float)$mark;
            }
        }

        $scale = (float)$quiz->sumgrades > 0
            ? (float)$quiz->grade / (float)$quiz->sumgrades
            : 0.0;

        return [
            'pending' => $pending,
            'manualcount' => $manualcount,
            'autograde' => $autoraw * $scale,
            'automax' => $automaxraw * $scale,
            'manualgrade' => $manualraw * $scale,
            'manualmax' => $manualmaxraw * $scale,
            'currentgrade' => ($autoraw + $manualraw) * $scale,
            'maxgrade' => (float)$quiz->grade,
        ];
    }

    private static function pending_count(\stdClass $attempt): int {
        if ((int)$attempt->uniqueid <= 0) {
            return 0;
        }

        $quba = self::quba((int)$attempt->uniqueid);
        $pending = 0;

        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if ($qa->get_state()->get_summary_state() === 'needsgrading') {
                $pending++;
            }
        }

        return $pending;
    }

    public static function attempts(): array {
        global $DB;

        self::bootstrap();
        $map = self::route_quizzes();

        if (!$map) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(
            array_keys($map),
            SQL_PARAMS_NAMED,
            'rq'
        );
        $params['preview'] = 0;
        $params['state'] = 'finished';

        $attempts = $DB->get_records_select(
            'quiz_attempts',
            "quiz {$insql} AND preview = :preview AND state = :state",
            $params,
            'timemodified DESC, id DESC'
        );

        $rows = [];

        foreach ($attempts as $attempt) {
            try {
                $cfg = self::config_for_attempt($attempt);
            } catch (\Throwable $e) {
                // Historical attempts from routes no longer assigned to this
                // employee must not pollute the current HR queue.
                continue;
            }
            $quiz = $cfg['quiz'];

            $user = $DB->get_record(
                'user',
                ['id' => (int)$attempt->userid, 'deleted' => 0],
                'id,firstname,lastname,email',
                IGNORE_MISSING
            );
            if (!$user) {
                continue;
            }

            $scores = self::score_breakdown($attempt, $quiz);
            $pending = (int)$scores['pending'];
            $grade = (float)$scores['currentgrade'];
            $pass = self::gradepass((int)$quiz->id);

            if ($pending > 0) {
                $statuskey = 'pending';
                $status = 'Ждёт проверки';
            } else if ($grade >= $pass) {
                $statuskey = 'passed';
                $status = 'Сдано';
            } else {
                $statuskey = 'failed';
                $status = 'Не сдано';
            }

            $rows[] = [
                'attemptid' => (int)$attempt->id,
                'userid' => (int)$attempt->userid,
                'quizid' => (int)$quiz->id,
                'cmid' => (int)$cfg['cmid'],
                'pointid' => (int)$cfg['pointid'],
                'versionid' => (int)$cfg['versionid'],
                'quizname' => format_string((string)$quiz->name),
                'employee' => fullname($user),
                'position' => self::position_label((int)$attempt->userid),
                'attemptno' => (int)$attempt->attempt,
                'maxattempts' => self::effective_attempt_limit((int)$attempt->userid, $cfg, $quiz),
                'state' => (string)$attempt->state,
                'pending' => $pending,
                'manualcount' => (int)$scores['manualcount'],
                'hasmanual' => (int)$scores['manualcount'] > 0,
                'autograde' => number_format((float)$scores['autograde'], 1, ',', ''),
                'automax' => number_format((float)$scores['automax'], 0, ',', ''),
                'manualgrade' => number_format((float)$scores['manualgrade'], 1, ',', ''),
                'manualmax' => number_format((float)$scores['manualmax'], 0, ',', ''),
                'grade' => number_format($grade, 1, ',', ''),
                'maxgrade' => number_format((float)$quiz->grade, 0, ',', ''),
                'passgrade' => number_format($pass, 0, ',', ''),
                'status' => $status,
                'statuskey' => $statuskey,
                'ispending' => $statuskey === 'pending',
                'ispassed' => $statuskey === 'passed',
                'isfailed' => $statuskey === 'failed',
                'isinprogress' => false,
                'finished' => !empty($attempt->timefinish)
                    ? userdate((int)$attempt->timefinish, '%d.%m.%Y %H:%M')
                    : '—',
                'url' => (new \moodle_url(
                    '/local/ustar/hr_quiz_attempt.php',
                    ['attemptid' => (int)$attempt->id]
                ))->out(false),
            ];
        }

        return $rows;
    }

    public static function detail(int $attemptid): array {
        global $DB;

        self::bootstrap();

        $attempt = $DB->get_record(
            'quiz_attempts',
            ['id' => $attemptid, 'preview' => 0],
            '*',
            MUST_EXIST
        );
        $cfg = self::config_for_attempt($attempt);
        $quiz = $cfg['quiz'];

        $user = $DB->get_record(
            'user',
            ['id' => (int)$attempt->userid],
            '*',
            MUST_EXIST
        );

        $pass = self::gradepass((int)$quiz->id);
        $quba = self::quba((int)$attempt->uniqueid);
        $context = \context_module::instance((int)$cfg['cmid']);
        $questions = [];
        $pending = 0;

        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();
            $summary = $qa->get_state()->get_summary_state();
            $need = $summary === 'needsgrading';
            if ($need) {
                $pending++;
            }

            $response = '';
            try {
                $response = (string)$qa->get_response_summary();
            } catch (\Throwable $e) {
                $response = '';
            }

            $mark = $qa->get_mark();
            $maxmark = (float)$qa->get_max_mark();
            $markfloat = $mark === null ? null : (float)$mark;

            $isautograded = $summary === 'autograded';
            $ismanuallygraded = $summary === 'manuallygraded';
            $autocorrect = false;
            $autowrong = false;
            $autopartial = false;

            if ($isautograded && $markfloat !== null && $maxmark > 0) {
                if ($markfloat >= $maxmark - 0.00001) {
                    $autocorrect = true;
                } else if ($markfloat <= 0.00001) {
                    $autowrong = true;
                } else {
                    $autopartial = true;
                }
            }

            if ($need) {
                $statustext = 'Ждёт проверки';
                $resultlabel = 'Требует оценки';
            } else if ($ismanuallygraded) {
                $statustext = 'Проверено вручную';
                $resultlabel = 'Проверено вручную';
            } else if ($autocorrect) {
                $statustext = 'Проверено автоматически';
                $resultlabel = 'Верно';
            } else if ($autowrong) {
                $statustext = 'Проверено автоматически';
                $resultlabel = 'Неверно';
            } else if ($autopartial) {
                $statustext = 'Проверено автоматически';
                $resultlabel = 'Частично';
            } else if ($isautograded) {
                $statustext = 'Проверено автоматически';
                $resultlabel = 'Автопроверка';
            } else {
                $statustext = 'В процессе';
                $resultlabel = 'В процессе';
            }

            $questions[] = [
                'slot' => (int)$slot,
                'number' => count($questions) + 1,
                'name' => format_string((string)$question->name),
                'questionhtml' => format_text(
                    (string)$question->questiontext,
                    (int)$question->questiontextformat,
                    ['context' => $context]
                ),
                'responsehtml' => nl2br(s($response)),
                'maxmark' => number_format($maxmark, 2, '.', ''),
                'mark' => $mark === null
                    ? '—'
                    : number_format((float)$mark, 2, ',', ''),
                'status' => $statustext,
                'resultlabel' => $resultlabel,
                'needgrading' => $need,
                'isautograded' => $isautograded,
                'ismanuallygraded' => $ismanuallygraded,
                'autocorrect' => $autocorrect,
                'autowrong' => $autowrong,
                'autopartial' => $autopartial,
            ];
        }

        $scores = self::score_breakdown($attempt, $quiz);
        $grade = (float)$scores['currentgrade'];
        $status = $pending > 0
            ? 'Ждёт проверки'
            : ($grade >= $pass ? 'Сдано' : 'Не сдано');

        return [
            'attemptid' => (int)$attempt->id,
            'userid' => (int)$attempt->userid,
            'quizid' => (int)$quiz->id,
            'cmid' => (int)$cfg['cmid'],
            'pointid' => (int)$cfg['pointid'],
            'versionid' => (int)$cfg['versionid'],
            'quizname' => format_string((string)$quiz->name),
            'employee' => fullname($user),
            'position' => self::position_label((int)$attempt->userid),
            'attemptno' => (int)$attempt->attempt,
            'maxattempts' => self::effective_attempt_limit((int)$attempt->userid, $cfg, $quiz),
            'pending' => $pending,
            'haspending' => $pending > 0,
            'manualcount' => (int)$scores['manualcount'],
            'autograde' => number_format((float)$scores['autograde'], 1, ',', ''),
            'automax' => number_format((float)$scores['automax'], 0, ',', ''),
            'manualgrade' => number_format((float)$scores['manualgrade'], 1, ',', ''),
            'manualmax' => number_format((float)$scores['manualmax'], 0, ',', ''),
            'grade' => number_format($grade, 1, ',', ''),
            'maxgrade' => number_format((float)$quiz->grade, 0, ',', ''),
            'passgrade' => number_format($pass, 0, ',', ''),
            'status' => $status,
            'questions' => $questions,
        ];
    }

    /**
     * Backward-compatible single-slot wrapper.
     */
    public static function manual_grade(
        int $attemptid,
        int $slot,
        float $mark,
        string $comment,
        int $graderid
    ): void {
        self::manual_grade_batch(
            $attemptid,
            [$slot => ['mark' => $mark, 'comment' => $comment]],
            $graderid,
            false
        );
    }

    /**
     * Grade all currently pending manual questions in one atomic HR action.
     *
     * @param array<int,array{mark:float,comment:string}> $grades
     */
    public static function manual_grade_batch(
        int $attemptid,
        array $grades,
        int $graderid,
        bool $requireall = true
    ): void {
        global $DB;

        self::bootstrap();

        if (!$grades) {
            throw new \invalid_parameter_exception(
                'Не передано ни одной ручной оценки.'
            );
        }

        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('route-quiz-grade:' . $attemptid, 10);

        if (!$lock) {
            throw new \moodle_exception(
                'Не удалось получить блокировку оценки.'
            );
        }

        try {
            $attempt = $DB->get_record(
                'quiz_attempts',
                [
                    'id' => $attemptid,
                    'state' => 'finished',
                    'preview' => 0,
                ],
                '*',
                MUST_EXIST
            );

            $cfg = self::config_for_attempt($attempt);
            $quiz = $cfg['quiz'];
            $attemptobj = route_quiz_attempt::from_attemptid($attemptid);

            $pending = [];
            foreach ($attemptobj->get_slots() as $slot) {
                $qa = $attemptobj->get_question_attempt((int)$slot);
                if ($qa->get_state()->get_summary_state() === 'needsgrading') {
                    $pending[(int)$slot] = true;
                }
            }

            if ($requireall) {
                foreach (array_keys($pending) as $slot) {
                    if (!array_key_exists($slot, $grades)) {
                        throw new \invalid_parameter_exception(
                            'Нужно выставить оценку каждому кейсу перед сохранением.'
                        );
                    }
                }
            }

            $normalized = [];
            $events = [];

            foreach ($grades as $slot => $grade) {
                $slot = (int)$slot;

                if (!isset($pending[$slot])) {
                    throw new \moodle_exception(
                        'Один из вопросов уже оценён или не требует ручной проверки.'
                    );
                }

                $qa = $attemptobj->get_question_attempt($slot);
                $maxmark = (float)$qa->get_max_mark();
                $mark = (float)($grade['mark'] ?? -1);
                $comment = trim((string)($grade['comment'] ?? ''));

                if ($mark < 0 || $mark > $maxmark) {
                    throw new \invalid_parameter_exception(
                        'Оценка вне допустимого диапазона.'
                    );
                }

                $normalized[$slot] = [
                    'mark' => $mark,
                    'comment' => $comment,
                ];

                $events[$slot] = \mod_quiz\event\question_manually_graded::create([
                    'objectid' => $qa->get_question_id(),
                    'courseid' => $attemptobj->get_courseid(),
                    'context' => \context_module::instance($attemptobj->get_cmid()),
                    'other' => [
                        'quizid' => $attemptobj->get_quizid(),
                        'attemptid' => $attemptid,
                        'slot' => $slot,
                    ],
                ]);
            }

            // Canonical grade persistence comes first. Moodle remains the source
            // of truth for question state, marks and the final quiz grade.
            $attemptobj->apply_manual_grades($normalized);

            // USTAR audit must not depend on optional event observers/message
            // delivery. The previous order triggered Moodle events before this
            // block, so an observer exception could leave the grade persisted
            // while USTAR audit/lifecycle stayed stale.
            $now = time();
            foreach ($normalized as $slot => $grade) {
                $qa = $attemptobj->get_question_attempt((int)$slot);
                $DB->insert_record(
                    'local_ustar_workflow_events',
                    (object)[
                        'entitytype' => 'quiz_manual_grade',
                        'entityid' => $attemptid,
                        'eventtype' => 'route_quiz_manual_grade',
                        'actorid' => $graderid,
                        'reason' => 'Ручная проверка аттестации USTAR',
                        'detailsjson' => json_encode(
                            [
                                'userid' => (int)$attempt->userid,
                                'quizid' => (int)$quiz->id,
                                'cmid' => (int)$cfg['cmid'],
                                'pointid' => (int)$cfg['pointid'],
                                'versionid' => (int)$cfg['versionid'],
                                'slot' => (int)$slot,
                                'mark' => (float)$grade['mark'],
                                'maxmark' => (float)$qa->get_max_mark(),
                            ],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'timecreated' => $now,
                    ]
                );
            }

            // Completion is a derived Moodle projection. Keep it best-effort so
            // a completion observer cannot invalidate an already persisted grade
            // or block the USTAR lifecycle transition.
            try {
                $cm = get_coursemodule_from_id(
                    'quiz',
                    (int)$cfg['cmid'],
                    0,
                    false,
                    MUST_EXIST
                );
                $course = $DB->get_record(
                    'course',
                    ['id' => (int)$cm->course],
                    '*',
                    MUST_EXIST
                );
                $completion = new \completion_info($course);
                $completion->update_state(
                    $cm,
                    COMPLETION_UNKNOWN,
                    (int)$attempt->userid
                );
            } catch (\Throwable $e) {
                debugging(
                    'USTAR quiz completion refresh after manual grading failed: '
                    . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

            $fresh = $DB->get_record(
                'quiz_attempts',
                ['id' => $attemptid],
                '*',
                MUST_EXIST
            );

            if (self::pending_count($fresh) === 0) {
                try {
                    $position = position_access::position_for_user(
                        (int)$attempt->userid
                    );
                    $positionid = is_array($position)
                        ? (string)($position['id'] ?? '')
                        : '';

                    if (
                        $positionid !== ''
                        && class_exists('\\local_ustar\\assessment_lifecycle')
                        && assessment_lifecycle::available()
                    ) {
                        $policy = assessment_lifecycle::policy_for_version(
                            (int)$cfg['versionid']
                        );
                        if ($policy) {
                            assessment_lifecycle::sync_policy_user(
                                $policy,
                                (int)$attempt->userid,
                                $positionid,
                                true
                            );
                        }
                    }

                    if ($positionid !== '') {
                        route_model::for_user(
                            $positionid,
                            (int)$attempt->userid
                        );
                    }
                } catch (\Throwable $e) {
                    // The canonical Moodle grade is already safely persisted.
                    // Keep the grading action successful; reconciliation can be
                    // rerun idempotently from the lifecycle/runtime probe.
                    debugging(
                        'USTAR lifecycle reconciliation after manual grading failed: '
                        . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }

            // Moodle events are secondary integration signals. A third-party or
            // site observer must never turn an already persisted HR grade into an
            // error page or prevent USTAR lifecycle reconciliation. Trigger each
            // event best-effort and retain the failure in developer diagnostics.
            foreach ($events as $event) {
                try {
                    $event->trigger();
                } catch (\Throwable $e) {
                    debugging(
                        'USTAR manual-grade event dispatch failed: ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
        } finally {
            $lock->release();
        }
    }
}
