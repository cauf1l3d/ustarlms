<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class admin_save_game extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['json' => new external_value(PARAM_RAW, 'Game with questions JSON')]);
    }

    public static function execute(string $json): array {
        global $DB;
        self::guard();
        \local_ustar\view_as::assert_writable();
        require_capability('local/ustar:admin', \context_system::instance());
        ['json' => $json] = self::validate_parameters(self::execute_parameters(), ['json' => $json]);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['code']) || empty($data['title'])) {
            throw new \invalid_parameter_exception('Invalid game JSON');
        }

        $code = clean_param($data['code'], PARAM_ALPHANUMEXT);
        if ($code === '') {
            throw new \invalid_parameter_exception('Game code is required');
        }
        $allowedtypes = ['quiz', 'image_quiz', 'trick_quiz', 'scenario'];
        $type = clean_param($data['type'] ?? 'quiz', PARAM_ALPHANUMEXT);
        if (!in_array($type, $allowedtypes, true)) {
            throw new \invalid_parameter_exception('Unsupported game type');
        }

        $department = clean_param($data['department'] ?? '', PARAM_ALPHANUMEXT);
        if ($department !== '') {
            $st = structure::get(structure::NAME_STRUCTURE);
            $validdepartment = false;
            foreach ($st['departments'] ?? [] as $dept) {
                if (($dept['id'] ?? '') === $department) {
                    $validdepartment = true;
                    break;
                }
            }
            if (!$validdepartment) {
                throw new \invalid_parameter_exception('Unknown USTAR department');
            }
        }

        $gameid = !empty($data['id']) ? (int)$data['id'] : 0;
        if ($gameid && !$DB->record_exists('local_ustar_games', ['id' => $gameid])) {
            throw new \invalid_parameter_exception('Unknown game id');
        }
        $duplicate = $DB->get_record('local_ustar_games', ['code' => $code]);
        if ($duplicate && (int)$duplicate->id !== $gameid) {
            throw new \invalid_parameter_exception('Game code must be unique');
        }

        $questions = $data['questions'] ?? [];
        if (!is_array($questions)) {
            throw new \invalid_parameter_exception('Questions must be an array');
        }
        foreach ($questions as $index => $question) {
            if (!is_array($question) || trim((string)($question['question'] ?? '')) === '') {
                throw new \invalid_parameter_exception('Every question must contain text');
            }
            $options = $question['options'] ?? null;
            if (!is_array($options) || count($options) !== 4) {
                throw new \invalid_parameter_exception('Every Game Hub question must contain exactly four options');
            }
            foreach ($options as $option) {
                if (trim((string)$option) === '') {
                    throw new \invalid_parameter_exception('Game Hub options cannot be empty');
                }
            }
            $correctoption = (int)($question['correctOption'] ?? -1);
            if ($correctoption < 0 || $correctoption > 3) {
                throw new \invalid_parameter_exception('Correct option must be between 0 and 3');
            }
        }

        $now = time();
        $record = (object)[
            'code' => $code,
            'title' => clean_param($data['title'], PARAM_TEXT),
            'description' => clean_param($data['description'] ?? '', PARAM_TEXT),
            'type' => $type,
            'department' => $department,
            'difficulty' => max(1, min(5, (int)($data['difficulty'] ?? 1))),
            'active' => !empty($data['active']) ? 1 : 0,
            'timemodified' => $now,
        ];
        if ($gameid) {
            $record->id = $gameid;
            $DB->update_record('local_ustar_games', $record);
        } else {
            $record->timecreated = $now;
            $gameid = (int)$DB->insert_record('local_ustar_games', $record);
        }

        $seen = [];
        $questionids = [];
        foreach ($questions as $index => $question) {
            $cleanoptions = array_values(array_map(fn($o) => clean_param(trim((string)$o), PARAM_TEXT), $question['options']));
            $qrecord = (object)[
                'gameid' => $gameid,
                'question' => clean_param($question['question'], PARAM_TEXT),
                'imageurl' => clean_param($question['imageUrl'] ?? '', PARAM_URL),
                'optionsjson' => json_encode($cleanoptions, JSON_UNESCAPED_UNICODE),
                'correctoption' => (int)$question['correctOption'],
                'explanation' => clean_param($question['explanation'] ?? '', PARAM_TEXT),
                'xpreward' => max(0, min(500, (int)($question['xpReward'] ?? 25))),
                'active' => array_key_exists('active', $question) ? (!empty($question['active']) ? 1 : 0) : 1,
                'sortorder' => $index,
            ];
            $qid = !empty($question['id']) ? (int)$question['id'] : 0;
            if ($qid && $DB->record_exists('local_ustar_questions', ['id' => $qid, 'gameid' => $gameid])) {
                $qrecord->id = $qid;
                $DB->update_record('local_ustar_questions', $qrecord);
                $seen[$qid] = true;
            } else {
                $qid = (int)$DB->insert_record('local_ustar_questions', $qrecord);
                $seen[$qid] = true;
            }
            $questionids[] = $qid;
        }
        foreach ($DB->get_records('local_ustar_questions', ['gameid' => $gameid]) as $existing) {
            if (!isset($seen[$existing->id])) {
                // Preserve historical attempts/mastery; removing in the editor only unpublishes the question.
                $DB->set_field('local_ustar_questions', 'active', 0, ['id' => $existing->id]);
            }
        }

        return ['json' => json_encode([
            'ok' => true,
            'gameid' => $gameid,
            'questionids' => $questionids,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Save game JSON')]);
    }
}
