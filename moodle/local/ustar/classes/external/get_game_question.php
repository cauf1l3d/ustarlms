<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_game_question extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['gameid' => new external_value(PARAM_INT, 'Game id')]);
    }

    public static function execute(int $gameid): array {
        global $DB, $USER;
        self::guard();
        ['gameid' => $gameid] = self::validate_parameters(self::execute_parameters(), ['gameid' => $gameid]);
        $game = $DB->get_record('local_ustar_games', ['id' => $gameid, 'active' => 1], '*', MUST_EXIST);
        $resolved = structure::resolve_user($USER->id);
        $dept = $resolved['position']['department'] ?? '';
        if (!empty($game->department) && $resolved['role'] !== 'superadmin' && $game->department !== $dept) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }

        $questions = array_values($DB->get_records('local_ustar_questions', ['gameid' => $gameid, 'active' => 1], 'sortorder ASC'));
        if (!$questions) {
            return ['json' => json_encode(['question' => null], JSON_UNESCAPED_UNICODE)];
        }
        $unmastered = [];
        foreach ($questions as $question) {
            $mastered = $DB->record_exists('local_ustar_game_mastery', [
                'userid' => $USER->id, 'questionid' => $question->id,
            ]);
            if (!$mastered) { $unmastered[] = $question; }
        }
        $pool = $unmastered ?: $questions;
        $question = $pool[array_rand($pool)];
        $options = json_decode($question->optionsjson, true);
        if (!is_array($options)) { $options = []; }
        return ['json' => json_encode(['question' => [
            'id' => (int)$question->id,
            'gameid' => (int)$game->id,
            'gameTitle' => $game->title,
            'text' => $question->question,
            'imageUrl' => (string)$question->imageurl,
            'options' => array_values($options),
            'xpReward' => (int)$question->xpreward,
        ]], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Question JSON')]);
    }
}
