<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class submit_game_answer extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question id'),
            'option' => new external_value(PARAM_INT, 'Zero-based option index'),
        ]);
    }

    public static function execute(int $questionid, int $option): array {
        global $DB, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $params = self::validate_parameters(self::execute_parameters(), compact('questionid', 'option'));
        $question = $DB->get_record('local_ustar_questions', ['id' => $params['questionid'], 'active' => 1], '*', MUST_EXIST);
        $game = $DB->get_record('local_ustar_games', ['id' => $question->gameid, 'active' => 1], '*', MUST_EXIST);

        // Re-check the same department scope used when serving a question. Knowing a question id must not bypass scoping.
        $resolved = structure::resolve_user($USER->id);
        $dept = $resolved['position']['department'] ?? '';
        if (!empty($game->department) && $resolved['role'] !== 'superadmin' && $game->department !== $dept) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }

        $options = json_decode($question->optionsjson, true) ?: [];
        if ($params['option'] < 0 || $params['option'] >= count($options)) {
            throw new \invalid_parameter_exception('Invalid option index');
        }
        $correct = $params['option'] === (int)$question->correctoption;

        // Unique mastery row is the XP source of truth. The database unique index closes the parallel-request race.
        $alreadymastered = $DB->record_exists('local_ustar_game_mastery', [
            'userid' => $USER->id,
            'questionid' => $question->id,
        ]);
        $xpearned = 0;
        if ($correct && !$alreadymastered) {
            try {
                $DB->insert_record('local_ustar_game_mastery', (object)[
                    'userid' => $USER->id,
                    'gameid' => $question->gameid,
                    'questionid' => $question->id,
                    'xpearned' => (int)$question->xpreward,
                    'timecreated' => time(),
                ]);
                $xpearned = (int)$question->xpreward;
                // First mastery also earns internal USCOIN. Idempotency prevents replay awards.
                if (class_exists('\local_ustar\economy')) {
                    \local_ustar\economy::post(
                        (int)$USER->id,
                        max(1, (int)floor(((int)$question->xpreward) / 5)),
                        'game_mastery',
                        'game_mastery:' . (int)$USER->id . ':' . (int)$question->id,
                        'question',
                        (string)$question->id,
                        'Первое правильное выполнение игрового задания'
                    );
                }
            } catch (\dml_write_exception $e) {
                // Another parallel correct submission may have won the unique key race.
                $alreadymastered = true;
                $xpearned = 0;
            }
        }

        $DB->insert_record('local_ustar_game_attempts', (object)[
            'userid' => $USER->id,
            'gameid' => $question->gameid,
            'questionid' => $question->id,
            'selectedoption' => $params['option'],
            'iscorrect' => $correct ? 1 : 0,
            'xpearned' => $xpearned,
            'timecreated' => time(),
        ]);

        $totalxp = (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(xpearned), 0) FROM {local_ustar_game_mastery} WHERE userid = :uid',
            ['uid' => $USER->id]
        );
        return ['json' => json_encode([
            'correct' => $correct,
            'correctOption' => (int)$question->correctoption,
            'explanation' => (string)$question->explanation,
            'xpEarned' => $xpearned,
            'totalGameXp' => $totalxp,
            'masteredBefore' => $alreadymastered,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Answer JSON')]);
    }
}
