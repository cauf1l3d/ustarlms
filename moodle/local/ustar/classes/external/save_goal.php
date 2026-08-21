<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;

class save_goal extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action'  => new external_value(PARAM_ALPHA, 'create | complete | delete'),
            'id'      => new external_value(PARAM_INT, 'Goal id (for complete/delete)', VALUE_DEFAULT, 0),
            'title'   => new external_value(PARAM_TEXT, 'Goal title (for create)', VALUE_DEFAULT, ''),
            'duedate' => new external_value(PARAM_INT, 'Unix due date', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $action, int $id = 0, string $title = '', int $duedate = 0): array {
        global $USER, $DB;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $params = self::validate_parameters(self::execute_parameters(),
            compact('action', 'id', 'title', 'duedate'));

        if ($params['action'] === 'create') {
            if (trim($params['title']) === '') {
                throw new \invalid_parameter_exception('Empty goal title');
            }
            $newid = $DB->insert_record('local_ustar_goals', (object)[
                'userid' => $USER->id,
                'title' => trim($params['title']),
                'targettype' => 'custom',
                'duedate' => $params['duedate'] ?: null,
                'completed' => 0,
                'timecreated' => time(),
            ]);
            return ['status' => 'ok', 'id' => (int)$newid];
        }

        $goal = $DB->get_record('local_ustar_goals',
            ['id' => $params['id'], 'userid' => $USER->id], '*', MUST_EXIST);

        if ($params['action'] === 'complete') {
            $goal->completed = 1;
            $DB->update_record('local_ustar_goals', $goal);
        } else if ($params['action'] === 'delete') {
            $DB->delete_records('local_ustar_goals', ['id' => $goal->id]);
        }
        return ['status' => 'ok', 'id' => (int)$goal->id];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'status' => new external_value(PARAM_TEXT),
            'id'     => new external_value(PARAM_INT),
        ]);
    }
}
