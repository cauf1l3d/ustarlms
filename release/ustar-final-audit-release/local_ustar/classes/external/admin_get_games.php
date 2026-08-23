<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;

class admin_get_games extends base {
    public static function execute_parameters(): external_function_parameters { return new external_function_parameters([]); }
    public static function execute(): array {
        global $DB;
        self::guard();
        require_capability('local/ustar:admin', \context_system::instance());
        $games = [];
        foreach ($DB->get_records('local_ustar_games', null, 'title ASC') as $game) {
            $questions = [];
            foreach ($DB->get_records('local_ustar_questions', ['gameid' => $game->id], 'sortorder ASC') as $q) {
                $questions[] = [
                    'id' => (int)$q->id, 'question' => $q->question,
                    'imageUrl' => \local_ustar\game_media::question_image_url($q),
                    'options' => array_values(json_decode($q->optionsjson, true) ?: []),
                    'correctOption' => (int)$q->correctoption, 'explanation' => (string)$q->explanation,
                    'xpReward' => (int)$q->xpreward, 'active' => (bool)$q->active,
                ];
            }
            $games[] = [
                'id' => (int)$game->id, 'code' => $game->code, 'title' => $game->title,
                'description' => (string)$game->description, 'type' => $game->type,
                'department' => (string)$game->department, 'difficulty' => (int)$game->difficulty,
                'active' => (bool)$game->active, 'questions' => $questions,
            ];
        }
        return ['json' => json_encode(['games' => $games], JSON_UNESCAPED_UNICODE)];
    }
    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Admin games JSON')]); }
}
