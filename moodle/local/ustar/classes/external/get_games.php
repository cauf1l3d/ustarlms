<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_games extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB, $USER;
        self::guard();
        $resolved = structure::resolve_user($USER->id);
        $dept = $resolved['position']['department'] ?? '';
        $games = [];
        foreach ($DB->get_records('local_ustar_games', ['active' => 1], 'title ASC') as $game) {
            if (!empty($game->department) && $resolved['role'] !== 'superadmin' && $game->department !== $dept) {
                continue;
            }
            $questioncount = (int)$DB->count_records('local_ustar_questions', ['gameid' => $game->id, 'active' => 1]);
            $attempts = (int)$DB->count_records('local_ustar_game_attempts', ['userid' => $USER->id, 'gameid' => $game->id]);
            $correct = (int)$DB->count_records('local_ustar_game_mastery', ['userid' => $USER->id, 'gameid' => $game->id]);
            $xp = (int)$DB->get_field_sql(
                'SELECT COALESCE(SUM(xpearned), 0) FROM {local_ustar_game_mastery} WHERE userid = :uid AND gameid = :gid',
                ['uid' => $USER->id, 'gid' => $game->id]
            );
            $games[] = [
                'id' => (int)$game->id, 'code' => $game->code, 'title' => $game->title,
                'description' => (string)$game->description, 'type' => $game->type,
                'department' => (string)$game->department, 'difficulty' => (int)$game->difficulty,
                'questionCount' => $questioncount, 'attempts' => $attempts, 'correct' => $correct, 'xp' => $xp,
            ];
        }
        $totalxp = (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(xpearned), 0) FROM {local_ustar_game_mastery} WHERE userid = :uid', ['uid' => $USER->id]
        );
        return ['json' => json_encode(['games' => $games, 'totalGameXp' => $totalxp], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Games JSON')]);
    }
}
