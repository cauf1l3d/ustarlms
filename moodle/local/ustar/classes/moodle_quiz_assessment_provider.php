<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Legacy Moodle Quiz adapter. Moodle remains authoritative for attempts/grades. */
final class moodle_quiz_assessment_provider implements assessment_provider {

    private static function bootstrap(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
    }

    private static function cmid(\stdClass $policy): int {
        $ref = trim((string)$policy->providerref);
        if (!preg_match('/^cm:(\d+)$/', $ref, $m)) {
            throw new \moodle_exception('Некорректная ссылка Moodle Quiz в политике аттестации.');
        }
        $cmid = (int)$m[1];
        if ($cmid <= 0) {
            throw new \moodle_exception('Некорректный CMID аттестации.');
        }
        return $cmid;
    }

    /** @return array{cm:\stdClass,quiz:\stdClass} */
    private static function quiz_data(\stdClass $policy): array {
        global $DB;
        self::bootstrap();
        $cmid = self::cmid($policy);
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => (int)$cm->instance], '*', MUST_EXIST);
        return ['cm' => $cm, 'quiz' => $quiz];
    }

    public function inspect(int $userid, \stdClass $policy): array {
        global $DB;
        $data = self::quiz_data($policy);
        $cm = $data['cm'];
        $quiz = $data['quiz'];

        $passraw = $DB->get_field(
            'grade_items',
            'gradepass',
            ['itemmodule' => 'quiz', 'iteminstance' => (int)$quiz->id]
        );
        $passscore = $passraw === false ? 0.0 : (float)$passraw;
        $scale = (float)$quiz->sumgrades > 0
            ? (float)$quiz->grade / (float)$quiz->sumgrades
            : 0.0;

        $attempts = $DB->get_records(
            'quiz_attempts',
            [
                'quiz' => (int)$quiz->id,
                'userid' => $userid,
                'preview' => 0,
                'state' => 'finished',
            ],
            'attempt ASC, id ASC'
        );

        $total = 0;
        $pending = 0;
        $finalized = [];
        $best = 0.0;
        $passed = false;
        $lastattemptid = 0;
        $lastattemptno = 0;
        $lastattemptat = 0;

        foreach ($attempts as $attempt) {
            $total++;
            $lastattemptid = (int)$attempt->id;
            $lastattemptno = (int)$attempt->attempt;
            $lastattemptat = max(
                $lastattemptat,
                (int)$attempt->timefinish,
                (int)($attempt->timemodified ?? 0)
            );

            // In Moodle Quiz an attempt containing questions that still need
            // manual grading has no final sumgrades. Do not exhaust a cycle
            // until HR/HRD has finalized that attempt.
            if ($attempt->sumgrades === null) {
                $pending++;
                continue;
            }

            $score = (float)$attempt->sumgrades * $scale;
            $best = max($best, $score);
            if ($score + 0.000001 >= $passscore) {
                $passed = true;
            }

            $finalizedat = max(
                (int)$attempt->timefinish,
                (int)($attempt->timemodified ?? 0)
            );
            $finalized[(int)$attempt->attempt] = [
                'attemptid' => (int)$attempt->id,
                'attemptno' => (int)$attempt->attempt,
                'score' => $score,
                'timefinish' => (int)$attempt->timefinish,
                // Manual essays make failure authoritative only after grading.
                // Moodle updates quiz_attempts.timemodified when USTAR persists
                // the manual grade, so remediation must happen after this time.
                'finalizedat' => $finalizedat,
            ];
        }

        return [
            'provider' => 'moodle_quiz',
            'cmid' => (int)$cm->id,
            'quizid' => (int)$quiz->id,
            'totalattempts' => $total,
            'finalizedattempts' => count($finalized),
            'pendingattempts' => $pending,
            'passed' => $passed,
            'bestscore' => $best,
            'maxscore' => (float)$quiz->grade,
            'passscore' => $passscore,
            'lastattemptid' => $lastattemptid,
            'lastattemptno' => $lastattemptno,
            'lastattemptat' => $lastattemptat,
            'finalized' => $finalized,
            'launchurl' => $this->launch_url($policy),
        ];
    }

    public function unlock_attempt_limit(int $userid, \stdClass $policy, int $limit): void {
        global $DB;
        $limit = max(1, $limit);
        $data = self::quiz_data($policy);
        $cm = $data['cm'];
        $quiz = $data['quiz'];

        $existing = $DB->get_record('quiz_overrides', [
            'quiz' => (int)$quiz->id,
            'userid' => $userid,
        ]);

        if ($existing) {
            $existinglimit = (int)($existing->attempts ?? 0);
            // Moodle uses 0 for unlimited attempts; never narrow an existing
            // more-permissive user override.
            if ($existinglimit === 0 || $existinglimit >= $limit) {
                return;
            }
        }

        $quizsettings = \mod_quiz\quiz_settings::create_for_cmid((int)$cm->id);
        $manager = $quizsettings->get_override_manager();

        // USTAR_BASE_ATTEMPT_LIMIT.
        // If lifecycle limit equals the Quiz default, remove the narrower
        // user override and let Moodle inherit the default attempt limit.
        $baseattempts = (int)($quiz->attempts ?? 0);
        if ($baseattempts > 0 && $limit === $baseattempts) {
            if ($existing) {
                $manager->delete_overrides([$existing]);
            }
            return;
        }

        $payload = [
            'quiz' => (int)$quiz->id,
            'userid' => $userid,
            'attempts' => $limit,
            'reason' => 'USTAR: дополнительный цикл аттестации после обязательного переобучения',
            'reasonformat' => FORMAT_PLAIN,
        ];
        if ($existing) {
            $payload['id'] = (int)$existing->id;
        }

        // Moodle's override manager performs validation, cache invalidation,
        // events and calendar/open-attempt reconciliation. Capabilities are
        // intentionally not checked by save_override() for trusted callers.
        $manager->save_override($payload);
    }

    public function launch_url(\stdClass $policy): string {
        $data = self::quiz_data($policy);

        return (new \moodle_url(
            '/local/ustar/activity_launch.php',
            [
                'cmid' => (int)$data['cm']->id,
                'pointid' => (int)$policy->pointid,
                'versionid' => (int)$policy->versionid,
            ]
        ))->out(false);
    }
}
