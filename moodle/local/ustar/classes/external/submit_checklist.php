<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\checklist_service;

class submit_checklist extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'checklistid' => new external_value(PARAM_ALPHANUMEXT, 'Checklist id'),
            'answersjson' => new external_value(PARAM_RAW, 'JSON object itemid => {done,comment}'),
            'comment' => new external_value(PARAM_TEXT, 'Overall comment', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $checklistid, string $answersjson, string $comment = ''): array {
        global $USER;
        self::guard();
        $params = self::validate_parameters(self::execute_parameters(),
            compact('checklistid', 'answersjson', 'comment'));
        $answers = json_decode($params['answersjson'], true);
        if (!is_array($answers) || array_is_list($answers) && $answers !== []) {
            throw new \invalid_parameter_exception('answersjson must be an object');
        }
        return ['json' => json_encode(checklist_service::submit((int)$USER->id,
            $params['checklistid'], $answers, $params['comment']), JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Checklist submit result'),
        ]);
    }
}
