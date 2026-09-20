<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_team extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /** Managers use the canonical managed subtree; authorized administrators see the company. */
    public static function execute(): array {
        global $USER, $DB;
        self::guard();
        $scope = \local_ustar\team_access::learning_scope((int)$USER->id);
        if (empty($scope['allowed'])) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:viewteam', 'nopermissions', '');
        }
        $companyaccess = \local_ustar\team_access::company((int)$USER->id);
        $scopename = $companyaccess ? 'company' : 'managed_subtree';
        if (!$scope['userids']) {
            return ['json' => json_encode(['team' => [], 'scope' => $scopename])];
        }
        $positionsbyid = \local_ustar\people::position_map(structure::get(structure::NAME_STRUCTURE));
        [$insql, $params] = $DB->get_in_or_equal($scope['userids'], SQL_PARAMS_NAMED, 'teamuser');
        $records = $DB->get_records_sql("SELECT u.id AS userid, u.firstname, u.lastname
            FROM {user} u WHERE u.id {$insql} AND u.deleted = 0 AND u.suspended = 0", $params);

        $team = [];
        foreach ($records as $rec) {
            if (!\local_ustar\accounts::participates((int)$rec->userid)) {
                continue;
            }
            $pos = $positionsbyid[\local_ustar\organization_identity::resolve((int)$rec->userid)['positionid']] ?? null;
            if (!$pos) {
                continue;
            }
            $courses = self::user_courses((int)$rec->userid);
            $sum = 0;
            foreach ($courses as $c) {
                $sum += $c['progress'];
            }
            $team[] = [
                'id'         => (int)$rec->userid,
                'fullname'   => $rec->firstname . ' ' . $rec->lastname,
                'position'   => $pos['name'],
                'positionid' => $pos['id'],
                'department' => $pos['department'],
                'avgProgress'=> $courses ? (int)round($sum / count($courses)) : 0,
                'courseCount'=> count($courses),
            ];
        }
        usort($team, fn($a, $b) => $b['avgProgress'] <=> $a['avgProgress']);

        return ['json' => json_encode(['team' => $team, 'scope' => $companyaccess ? 'company' : 'managed_subtree'],
            JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Team JSON'),
        ]);
    }
}
