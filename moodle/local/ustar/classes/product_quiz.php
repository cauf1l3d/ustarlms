<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR facade for the existing Moodle product assessment.
 *
 * Moodle remains the single source of truth for:
 * - question bank;
 * - random slots;
 * - attempts;
 * - answers;
 * - automatic grading;
 * - manual grading.
 */
final class product_quiz {
    public const CMID = 69;
    public const QUIZID = 11;
    public const POINTID = 70;

    private static function bootstrap(): void {
        global $CFG;

        require_once(
            $CFG->dirroot . '/mod/quiz/locallib.php'
        );

        require_once(
            $CFG->dirroot . '/question/engine/lib.php'
        );

        require_once(
            $CFG->libdir . '/completionlib.php'
        );
    }

    private static function quiz(): \stdClass {
        global $DB;

        return $DB->get_record(
            'quiz',
            ['id' => self::QUIZID],
            '*',
            MUST_EXIST
        );
    }

    private static function gradepass(): float {
        global $DB;

        $value =
            $DB->get_field(
                'grade_items',
                'gradepass',
                [
                    'itemmodule' => 'quiz',
                    'iteminstance' => self::QUIZID,
                ]
            );

        return $value === false
            ? 0.0
            : (float)$value;
    }

    private static function scaled_grade(
        \stdClass $attempt,
        \stdClass $quiz
    ): float {
        if (
            $attempt->sumgrades === null
            || (float)$quiz->sumgrades <= 0
        ) {
            return 0.0;
        }

        return
            ((float)$attempt->sumgrades
            / (float)$quiz->sumgrades)
            * (float)$quiz->grade;
    }

    private static function position_label(
        int $userid
    ): string {
        try {
            $position =
                position_access::position_for_user(
                    $userid
                );

            if (is_array($position)) {
                foreach (
                    ['title', 'name', 'positionname', 'id']
                    as $key
                ) {
                    if (
                        !empty($position[$key])
                    ) {
                        return
                            (string)$position[$key];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Display-only fallback.
        }

        return '—';
    }

    private static function quba(
        int $usageid
    ): \question_usage_by_activity {
        self::bootstrap();

        return
            \question_engine::load_questions_usage_by_activity(
                $usageid
            );
    }

    private static function pending_count(
        \stdClass $attempt
    ): int {
        if ((int)$attempt->uniqueid <= 0) {
            return 0;
        }

        $quba =
            self::quba(
                (int)$attempt->uniqueid
            );

        $pending = 0;

        foreach ($quba->get_slots() as $slot) {
            $qa =
                $quba->get_question_attempt(
                    $slot
                );

            if (
                $qa->get_state()
                == \question_state::$needsgrading
            ) {
                $pending++;
            }
        }

        return $pending;
    }

    public static function attempts(): array {
        global $DB;

        self::bootstrap();

        $quiz = self::quiz();
        $pass = self::gradepass();

        $attempts =
            $DB->get_records(
                'quiz_attempts',
                ['quiz' => self::QUIZID],
                'timemodified DESC, id DESC'
            );

        $rows = [];

        foreach ($attempts as $attempt) {
            $user =
                $DB->get_record(
                    'user',
                    [
                        'id' => (int)$attempt->userid,
                        'deleted' => 0,
                    ],
                    'id,firstname,lastname,email',
                    IGNORE_MISSING
                );

            if (!$user) {
                continue;
            }

            $pending =
                self::pending_count(
                    $attempt
                );

            $grade =
                self::scaled_grade(
                    $attempt,
                    $quiz
                );

            if (
                (string)$attempt->state
                !== 'finished'
            ) {
                $statuskey = 'inprogress';
                $status = 'В процессе';

            } else if ($pending > 0) {
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
                'attemptid' =>
                    (int)$attempt->id,

                'userid' =>
                    (int)$attempt->userid,

                'employee' =>
                    fullname($user),

                'position' =>
                    self::position_label(
                        (int)$attempt->userid
                    ),

                'attemptno' =>
                    (int)$attempt->attempt,

                'state' =>
                    (string)$attempt->state,

                'pending' =>
                    $pending,

                'grade' =>
                    number_format(
                        $grade,
                        1,
                        ',',
                        ''
                    ),

                'maxgrade' =>
                    number_format(
                        (float)$quiz->grade,
                        0,
                        ',',
                        ''
                    ),

                'status' =>
                    $status,

                'statuskey' =>
                    $statuskey,

                'ispending' =>
                    $statuskey === 'pending',

                'ispassed' =>
                    $statuskey === 'passed',

                'isfailed' =>
                    $statuskey === 'failed',

                'isinprogress' =>
                    $statuskey === 'inprogress',

                'finished' =>
                    !empty($attempt->timefinish)
                        ? userdate(
                            (int)$attempt->timefinish,
                            '%d.%m.%Y %H:%M'
                        )
                        : '—',

                'url' =>
                    (
                        new \moodle_url(
                            '/local/ustar/hr_quiz_attempt.php',
                            ['attemptid' => (int)$attempt->id]
                        )
                    )->out(false),
            ];
        }

        return $rows;
    }

    public static function detail(
        int $attemptid
    ): array {
        global $DB;

        self::bootstrap();

        $attempt =
            $DB->get_record(
                'quiz_attempts',
                [
                    'id' => $attemptid,
                    'quiz' => self::QUIZID,
                ],
                '*',
                MUST_EXIST
            );

        $user =
            $DB->get_record(
                'user',
                ['id' => (int)$attempt->userid],
                '*',
                MUST_EXIST
            );

        $quiz = self::quiz();
        $pass = self::gradepass();

        $quba =
            self::quba(
                (int)$attempt->uniqueid
            );

        $context =
            \context_module::instance(
                self::CMID
            );

        $questions = [];
        $pending = 0;

        foreach ($quba->get_slots() as $slot) {
            $qa =
                $quba->get_question_attempt(
                    $slot
                );

            $question =
                $qa->get_question();

            $state =
                $qa->get_state();

            $need =
                $state
                == \question_state::$needsgrading;

            if ($need) {
                $pending++;
            }

            $response = '';

            try {
                $response =
                    (string)$qa->get_response_summary();
            } catch (\Throwable $e) {
                $response = '';
            }

            $mark = $qa->get_mark();

            if ($need) {
                $statustext = 'Ждёт проверки';
            } else if (
                $state
                == \question_state::$manuallygraded
            ) {
                $statustext = 'Проверено вручную';
            } else {
                $statustext = 'Проверено автоматически';
            }

            $questions[] = [
                'slot' =>
                    (int)$slot,

                'number' =>
                    count($questions) + 1,

                'name' =>
                    format_string(
                        (string)$question->name
                    ),

                'questionhtml' =>
                    format_text(
                        (string)$question->questiontext,
                        (int)$question->questiontextformat,
                        ['context' => $context]
                    ),

                'responsehtml' =>
                    nl2br(
                        s($response)
                    ),

                'maxmark' =>
                    number_format(
                        (float)$qa->get_max_mark(),
                        2,
                        '.',
                        ''
                    ),

                'mark' =>
                    $mark === null
                        ? '—'
                        : number_format(
                            (float)$mark,
                            2,
                            ',',
                            ''
                        ),

                'status' =>
                    $statustext,

                'needgrading' =>
                    $need,
            ];
        }

        $grade =
            self::scaled_grade(
                $attempt,
                $quiz
            );

        $status =
            $pending > 0
                ? 'Ждёт проверки'
                : (
                    $grade >= $pass
                        ? 'Сдано'
                        : 'Не сдано'
                );

        return [
            'attemptid' =>
                (int)$attempt->id,

            'userid' =>
                (int)$attempt->userid,

            'employee' =>
                fullname($user),

            'position' =>
                self::position_label(
                    (int)$attempt->userid
                ),

            'attemptno' =>
                (int)$attempt->attempt,

            'pending' =>
                $pending,

            'haspending' =>
                $pending > 0,

            'grade' =>
                number_format(
                    $grade,
                    1,
                    ',',
                    ''
                ),

            'maxgrade' =>
                number_format(
                    (float)$quiz->grade,
                    0,
                    ',',
                    ''
                ),

            'passgrade' =>
                number_format(
                    $pass,
                    0,
                    ',',
                    ''
                ),

            'status' =>
                $status,

            'questions' =>
                $questions,
        ];
    }

    public static function manual_grade(
        int $attemptid,
        int $slot,
        float $mark,
        string $comment,
        int $graderid
    ): void {
        global $DB;

        self::bootstrap();

        $factory =
            \core\lock\lock_config::get_lock_factory(
                'local_ustar'
            );

        $lock =
            $factory->get_lock(
                'product-quiz-grade:'
                . $attemptid
                . ':'
                . $slot,
                10
            );

        if (!$lock) {
            throw new \moodle_exception(
                'Не удалось получить блокировку оценки.'
            );
        }

        try {
            $attempt =
                $DB->get_record(
                    'quiz_attempts',
                    [
                        'id' => $attemptid,
                        'quiz' => self::QUIZID,
                        'state' => 'finished',
                    ],
                    '*',
                    MUST_EXIST
                );

            $attemptobj =
                \mod_quiz\quiz_attempt::create(
                    $attemptid
                );

            $quba =
                $attemptobj->get_question_usage();

            if (
                !in_array(
                    $slot,
                    $quba->get_slots(),
                    true
                )
            ) {
                throw new \invalid_parameter_exception(
                    'Вопрос не принадлежит этой попытке.'
                );
            }

            $qa =
                $quba->get_question_attempt(
                    $slot
                );

            if (
                $qa->get_state()
                != \question_state::$needsgrading
            ) {
                throw new \moodle_exception(
                    'Этот вопрос уже оценён.'
                );
            }

            $maxmark =
                (float)$qa->get_max_mark();

            if (
                $mark < 0
                || $mark > $maxmark
            ) {
                throw new \invalid_parameter_exception(
                    'Оценка вне допустимого диапазона.'
                );
            }

            $safecomment =
                nl2br(
                    s(
                        trim($comment)
                    )
                );

            $quba->manual_grade(
                $slot,
                $safecomment,
                $mark,
                FORMAT_HTML
            );

            \question_engine::save_questions_usage_by_activity(
                $quba
            );

            $now = time();

            $update = (object)[
                'id' =>
                    $attemptid,

                'timemodified' =>
                    $now,

                'sumgrades' =>
                    $quba->get_total_mark(),

                'gradednotificationsenttime' =>
                    null,
            ];

            $DB->update_record(
                'quiz_attempts',
                $update
            );

            /*
             * Moodle's own grade calculator remains authoritative.
             */
            $attemptobj
                ->get_quizobj()
                ->get_grade_calculator()
                ->recompute_final_grade(
                    (int)$attempt->userid
                );

            /*
             * Re-evaluate activity completion after the
             * final manual mark.
             */
            $cm =
                get_coursemodule_from_id(
                    'quiz',
                    self::CMID,
                    0,
                    false,
                    MUST_EXIST
                );

            $course =
                $DB->get_record(
                    'course',
                    ['id' => (int)$cm->course],
                    '*',
                    MUST_EXIST
                );

            $completion =
                new \completion_info(
                    $course
                );

            $completion->update_state(
                $cm,
                COMPLETION_UNKNOWN,
                (int)$attempt->userid
            );

            $DB->insert_record(
                'local_ustar_workflow_events',
                (object)[
                    'entitytype' =>
                        'quiz_manual_grade',

                    'entityid' =>
                        $attemptid,

                    'eventtype' =>
                        'product_quiz_manual_grade',

                    'actorid' =>
                        $graderid,

                    'reason' =>
                        'Ручная проверка товарной аттестации',

                    'detailsjson' =>
                        json_encode(
                            [
                                'userid' =>
                                    (int)$attempt->userid,

                                'quizid' =>
                                    self::QUIZID,

                                'cmid' =>
                                    self::CMID,

                                'slot' =>
                                    $slot,

                                'mark' =>
                                    $mark,

                                'maxmark' =>
                                    $maxmark,
                            ],
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        ),

                    'timecreated' =>
                        $now,
                ]
            );

            /*
             * Once all essays are graded, let the normal
             * route runtime reconcile cm69 -> point70.
             */
            $fresh =
                $DB->get_record(
                    'quiz_attempts',
                    ['id' => $attemptid],
                    '*',
                    MUST_EXIST
                );

            if (
                self::pending_count($fresh) === 0
            ) {
                try {
                    $position =
                        position_access::position_for_user(
                            (int)$attempt->userid
                        );

                    $positionid =
                        is_array($position)
                            ? (string)($position['id'] ?? '')
                            : '';

                    if ($positionid !== '') {
                        route_model::for_user(
                            $positionid,
                            (int)$attempt->userid
                        );
                    }
                } catch (\Throwable $e) {
                    // The grade itself is already safely persisted.
                }
            }

        } finally {
            $lock->release();
        }
    }
}
