<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\people;

class hr_save_review extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Employee user id'),
            'score' => new external_value(PARAM_INT, '1..5 score'),
            'category' => new external_value(PARAM_ALPHANUMEXT, 'Review category', VALUE_DEFAULT, 'performance'),
            'period' => new external_value(PARAM_NOTAGS, 'Review period label', VALUE_DEFAULT, ''),
            'summary' => new external_value(PARAM_TEXT, 'Review summary', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $userid, int $score, string $category = 'performance', string $period = '', string $summary = ''): array {
        global $DB, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $context = \context_system::instance();
        require_capability('local/ustar:hrmanage', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('userid', 'score', 'category', 'period', 'summary'));
        $target = $DB->get_record('user', ['id' => $params['userid'], 'deleted' => 0], '*', MUST_EXIST);
        if ($params['score'] < 1 || $params['score'] > 5) {
            throw new \invalid_parameter_exception('Score must be between 1 and 5');
        }
        if (is_siteadmin($target) || has_capability('local/ustar:admin', $context, $target->id)) {
            throw new \required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
        }
        $id = (int)$DB->insert_record('local_ustar_reviews', (object)[
            'userid' => $target->id,
            'reviewerid' => $USER->id,
            'category' => $params['category'] ?: 'performance',
            'period' => trim($params['period']),
            'score' => $params['score'],
            'summary' => trim($params['summary']),
            'timecreated' => time(),
        ]);
        people::log_action((int)$USER->id, (int)$target->id, 'review_created', [
            'reviewid' => $id,
            'score' => $params['score'],
            'category' => $params['category'],
            'period' => $params['period'],
        ]);
        return ['json' => json_encode(['ok' => true, 'reviewid' => $id], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Review save JSON')]);
    }
}
